<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/core/security.php';
asr_configure_secure_session();
session_start();
asr_send_security_headers();
asr_csrf_token();
asr_start_csrf_output_filter();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../oca_interpretation/oca_interpretation.php';
require_once __DIR__ . '/core/permissions.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/url_shortener.php';
require_once __DIR__ . '/lib/menu.php';

// Функции для шифрования ID (защита от перебора)
function encodeId($id) {
    $salt = 'OcaAvmSecureSalt2026!'; // Секретный ключ для подписи
    $data = $id . '-' . substr(md5($id . $salt), 0, 12);
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function decodeId($hash) {
    $salt = 'OcaAvmSecureSalt2026!';
    $data = base64_decode(strtr($hash, '-_', '+/'));
    if ($data && strpos($data, '-') !== false) {
        list($id, $sign) = explode('-', $data, 2);
        // Проверяем, совпадает ли подпись (MD5)
        if (substr(md5($id . $salt), 0, 12) === $sign) {
            return (int)$id;
        }
    }
    return null; // Если хэш подделан
}


function asr_ensure_users_role_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `oca_users` LIKE 'role'");
        $col = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = strtolower((string)($col['Type'] ?? ''));
        if ($col && (strpos($type, 'enum') !== false || preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 20)) {
            $pdo->exec("ALTER TABLE `oca_users` MODIFY `role` VARCHAR(20) NOT NULL DEFAULT 'operator'");
        }
    } catch (Throwable $e) {
        // Если у пользователя БД нет прав на ALTER TABLE, интерфейс продолжит работать,
        // а миграцию можно выполнить вручную из migration_fix_users_role.sql.
    }
}


function asr_ensure_users_password_plain_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!asr_table_column_exists_fresh($pdo, 'oca_users', 'password_plain')) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `password_plain` VARCHAR(255) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // Если у пользователя БД нет прав на ALTER TABLE, выполните migration_users_password_plain.sql вручную.
    }
}

function asr_current_role(): string {
    $role = (string)($_SESSION['user_role'] ?? 'operator');
    if ($role === 'user' || $role === 'viewer' || $role === 'observer') return 'operator';
    return $role;
}

function isAdmin() {
    return in_array(asr_current_role(), ['admin', 'superadmin'], true);
}

function asr_can_manage_system(): bool {
    return isAdmin();
}

function asr_can_work_results(): bool {
    return in_array(asr_current_role(), ['admin', 'superadmin', 'manager'], true);
}

function asr_can_view_results(): bool {
    return in_array(asr_current_role(), ['admin', 'superadmin', 'manager', 'operator'], true);
}

function asr_normalize_admin_role(string $role): string {
    $role = trim($role);
    if (in_array($role, ['user', 'viewer', 'observer'], true)) return 'operator';
    return in_array($role, ['admin', 'superadmin', 'manager', 'operator'], true) ? $role : 'operator';
}

function asr_role_label(string $role): string {
    $labels = [
        'admin' => 'Администратор',
        'manager' => 'Менеджер',
        'operator' => 'Оператор',
        'superadmin' => 'Суперадмин',
    ];
    return $labels[$role] ?? $role;
}

function asr_is_protected_user(array $user): bool {
    $id = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    return $id === 1 || $role === 'superadmin';
}

function asr_table_column_exists(PDO $pdo, string $table, string $column): bool {
    // Без кэша: структура таблиц у нас иногда обновляется прямо во время запроса.
    // Старый кэш мог запомнить, что колонки нет, а после ALTER TABLE продолжал возвращать false.
    try {
        $safeTable = str_replace('`', '``', $table);
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    } catch (Throwable $e) {
        // Пойдём запасным путём ниже.
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function asr_table_column_exists_fresh(PDO $pdo, string $table, string $column): bool {
    return asr_table_column_exists($pdo, $table, $column);
}

function asr_user_is_active(PDO $pdo, int $userId): bool {
    if ($userId <= 0 || !asr_table_column_exists($pdo, 'oca_users', 'is_active')) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT is_active FROM oca_users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return !$row || (int)($row['is_active'] ?? 1) === 1;
}

// Проверяем, это публичный просмотр графика респондентом или нет
$is_shared_view = isset($_GET['shared']) && !empty($_GET['shared']);
$shared_hashes = $is_shared_view ? explode(',', $_GET['shared']) : [];
$shared_ids = [];
foreach($shared_hashes as $hash) {
    $dec = decodeId($hash);
    if ($dec) $shared_ids[] = $dec;
}

// Защита: если передали параметр shared, но ни один хэш не подошел
if ($is_shared_view && empty($shared_ids)) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px; color: #555;'><h2>Ошибка доступа</h2><p>Неверная или устаревшая ссылка.</p></div>");
}

// Долгая авторизация: если PHP-сессия на хостинге уже протухла,
// восстанавливаем вход по защищённому remember-токену.
if (!$is_shared_view && empty($_SESSION['logged_in']) && function_exists('asr_try_remember_login')) {
    asr_try_remember_login($pdo);
}

// Если у пользователя включено «не выходить из системы», продлеваем cookie сессии на каждом заходе.
if (!$is_shared_view && !empty($_SESSION['logged_in']) && !empty($_SESSION['remember_365_days']) && function_exists('asr_persist_current_session_for_days')) {
    asr_persist_current_session_for_days(365);
}

// Если пользователя деактивировали, его текущая сессия тоже закрывается.
if (!$is_shared_view && isset($_SESSION['logged_in'], $_SESSION['user_id']) && !asr_user_is_active($pdo, (int)$_SESSION['user_id'])) {
    $_SESSION = [];
    if (function_exists('asr_expire_current_session_cookie')) {
        asr_expire_current_session_cookie();
    }
    session_destroy();
    session_start();
    $_SESSION['deactivated_notice'] = 'Ваш доступ временно заблокирован администратором.';
    header('Location: admin.php');
    exit;
}
require_once __DIR__ . '/core/modules.php';
