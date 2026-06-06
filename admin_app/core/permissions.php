<?php
defined('ASR_ADMIN') || exit;

/**
 * Универсальные точечные права пользователей.
 * Таблица: oca_user_permissions(user_id, permission_key, is_allowed).
 */

function asr_permissions_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'oca_user_permissions'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function asr_known_module_permissions(): array {
    return [
        'access_vault' => [
            'title' => 'Доступы',
            'roles' => ['admin', 'manager'],
            'permissions' => [
                'access_vault.view' => 'Просмотр',
                'access_vault.create' => 'Добавление',
                'access_vault.edit' => 'Редактирование',
                'access_vault.archive' => 'Архивирование',
                'access_vault.restore' => 'Восстановление',
                'access_vault.copy' => 'Копирование',
                'access_vault.share' => 'Отправка / поделиться',
                'access_vault.import_export' => 'Импорт / экспорт',
                'access_vault.audit' => 'Журнал',
                'access_vault.individual_access' => 'Задавать индивидуальные доступы',
            ],
        ],
        'telegram_bots' => [
            'title' => 'Telegram-боты',
            'roles' => ['admin', 'manager'],
            'permissions' => [
                'telegram_bots.view' => 'Просмотр',
                'telegram_bots.manage' => 'Подключение и настройка ботов',
                'telegram_bots.broadcast' => 'Рассылки',
                'telegram_bots.flows' => 'Сценарии',
                'telegram_bots.logs' => 'Журнал',
            ],
        ],
    ];
}

function asr_all_known_permission_keys(): array {
    $keys = [];
    foreach (asr_known_module_permissions() as $module) {
        foreach (($module['permissions'] ?? []) as $key => $_label) {
            $keys[] = (string)$key;
        }
    }
    return array_values(array_unique($keys));
}

function asr_user_permission_allowed_by_default(?int $userId, string $permissionKey): bool {
    $role = function_exists('asr_current_role') ? asr_current_role() : (string)($_SESSION['user_role'] ?? 'operator');
    if (in_array($role, ['admin', 'superadmin'], true)) {
        return true;
    }
    return false;
}

function asr_user_has_permission(string $permissionKey, ?int $userId = null): bool {
    global $pdo;
    $permissionKey = trim($permissionKey);
    if ($permissionKey === '' || $permissionKey === 'any') return true;

    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }

    $userId = $userId ?: (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return false;

    if (!$pdo instanceof PDO || !asr_permissions_table_exists($pdo)) {
        return asr_user_permission_allowed_by_default($userId, $permissionKey);
    }

    try {
        $stmt = $pdo->prepare('SELECT is_allowed FROM oca_user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
        $stmt->execute([$userId, $permissionKey]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return asr_user_permission_allowed_by_default($userId, $permissionKey);
        }
        return (int)$value === 1;
    } catch (Throwable $e) {
        return asr_user_permission_allowed_by_default($userId, $permissionKey);
    }
}

function asr_user_permissions_for_user(PDO $pdo, int $userId): array {
    if ($userId <= 0 || !asr_permissions_table_exists($pdo)) return [];
    try {
        $stmt = $pdo->prepare('SELECT permission_key, is_allowed FROM oca_user_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['permission_key']] = (int)$row['is_allowed'] === 1;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function asr_save_user_permissions(PDO $pdo, int $userId, array $permissionKeys): void {
    if ($userId <= 0 || !asr_permissions_table_exists($pdo)) return;

    $known = asr_all_known_permission_keys();
    $allowedMap = array_fill_keys($known, 0);
    foreach ($permissionKeys as $key) {
        $key = trim((string)$key);
        if (in_array($key, $known, true)) {
            $allowedMap[$key] = 1;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO oca_user_permissions (user_id, permission_key, is_allowed, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_at = NOW()");
    foreach ($allowedMap as $key => $allowed) {
        $stmt->execute([$userId, $key, $allowed]);
    }
}
