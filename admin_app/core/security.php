<?php
/**
 * Общие защитные функции админки.
 * Подключается из bootstrap.php до старта сессии.
 */

defined('ASR_ADMIN') || exit;

function asr_is_https_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function asr_configure_secure_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => asr_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(365 * 24 * 60 * 60));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (asr_is_https_request()) {
        ini_set('session.cookie_secure', '1');
    }
}


function asr_persist_current_session_for_days(int $days = 365): void {
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    $days = max(1, min(3650, $days));
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + ($days * 24 * 60 * 60),
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => asr_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function asr_expire_current_session_cookie(): void {
    if (headers_sent()) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => asr_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}



function asr_remember_cookie_name(): string {
    return 'asr_admin_remember';
}

function asr_cookie_domain(): string {
    $params = session_get_cookie_params();
    return (string)($params['domain'] ?? '');
}

function asr_set_cookie(string $name, string $value, int $expires): void {
    if (headers_sent()) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => $params['path'] ?: '/',
        'domain' => asr_cookie_domain(),
        'secure' => asr_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function asr_forget_remember_cookie(): void {
    asr_set_cookie(asr_remember_cookie_name(), '', time() - 3600);
}

function asr_ensure_remember_tokens_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_user_remember_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `selector` VARCHAR(64) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_selector` (`selector`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // Если нет прав на CREATE TABLE, сохранение сессии всё равно продолжит работать через cookie PHP-сессии.
    }
}

function asr_issue_remember_token(PDO $pdo, int $userId, int $days = 365): void {
    if ($userId <= 0 || headers_sent()) {
        return;
    }
    $days = max(1, min(3650, $days));
    asr_ensure_remember_tokens_schema($pdo);
    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $hash = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 24 * 60 * 60));
        $pdo->prepare('DELETE FROM `oca_user_remember_tokens` WHERE user_id = ? AND expires_at < NOW()')->execute([$userId]);
        $pdo->prepare('INSERT INTO `oca_user_remember_tokens` (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $selector, $hash, $expiresAt]);
        asr_set_cookie(asr_remember_cookie_name(), $selector . ':' . $validator, time() + ($days * 24 * 60 * 60));
    } catch (Throwable $e) {
        // Не ломаем вход, если БД не дала создать/записать долгий токен.
    }
}

function asr_clear_remember_tokens(PDO $pdo, ?int $userId = null): void {
    asr_forget_remember_cookie();
    asr_ensure_remember_tokens_schema($pdo);
    try {
        if ($userId && $userId > 0) {
            $pdo->prepare('DELETE FROM `oca_user_remember_tokens` WHERE user_id = ?')->execute([$userId]);
        }
    } catch (Throwable $e) {
        // Молча игнорируем: logout всё равно удалит текущую сессию.
    }
}

function asr_try_remember_login(PDO $pdo): bool {
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['logged_in'])) {
        return false;
    }
    $raw = (string)($_COOKIE[asr_remember_cookie_name()] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) {
        return false;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        asr_forget_remember_cookie();
        return false;
    }
    asr_ensure_remember_tokens_schema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT t.*, u.username, u.role, u.full_name, u.is_active, u.remember_365_days
            FROM `oca_user_remember_tokens` t
            INNER JOIN `oca_users` u ON u.id = t.user_id
            WHERE t.selector = ? AND t.expires_at > NOW()
            LIMIT 1');
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
            asr_forget_remember_cookie();
            return false;
        }
        if ((int)($row['is_active'] ?? 1) !== 1 || (int)($row['remember_365_days'] ?? 0) !== 1) {
            asr_clear_remember_tokens($pdo, (int)$row['user_id']);
            return false;
        }

        session_regenerate_id(true);
        $role = trim((string)($row['role'] ?? 'operator'));
        if (in_array($role, ['user', 'viewer', 'observer'], true)) $role = 'operator';
        if (!in_array($role, ['admin', 'superadmin', 'manager', 'operator'], true)) $role = 'operator';

        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['user_role'] = $role;
        $_SESSION['full_name'] = (string)($row['full_name'] ?? '');
        $_SESSION['remember_365_days'] = 1;
        $_SESSION['remember_restored_at'] = time();
        $pdo->prepare('UPDATE `oca_user_remember_tokens` SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        asr_persist_current_session_for_days(365);
        asr_issue_remember_token($pdo, (int)$row['user_id'], 365);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function asr_send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function asr_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function asr_csrf_input(): string {
    $token = asr_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function asr_csrf_is_valid(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postToken = (string)($_POST['csrf_token'] ?? '');

    return $sessionToken !== '' && $postToken !== '' && hash_equals($sessionToken, $postToken);
}

function asr_require_csrf(): void {
    if (asr_csrf_is_valid()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Действие заблокировано</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:40px;color:#111827;background:#f9fafb;"><div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:24px;padding:28px;box-shadow:0 18px 40px rgba(15,23,42,.08)"><h1 style="margin-top:0;color:#FFA048;font-size:24px;">Действие заблокировано</h1><p>Защитный токен формы устарел или отсутствует. Вернитесь назад, обновите страницу и повторите действие.</p><p style="color:#6b7280;font-size:14px;">Это защита от случайной или внешней отправки форм.</p><p><a href="admin.php" style="color:#FFA048;font-weight:700;">Вернуться в админку</a></p></div></body></html>';
    exit;
}


function asr_inject_csrf_into_post_forms(string $html): string {
    if (session_status() !== PHP_SESSION_ACTIVE || stripos($html, '<form') === false) {
        return $html;
    }

    $input = asr_csrf_input();
    return preg_replace_callback('/<form\b(?=[^>]*\bmethod=["\']?post["\']?)[^>]*>/i', static function(array $m) use ($html, $input): string {
        $tag = $m[0];
        $pos = strpos($html, $tag);
        if ($pos !== false) {
            $tail = substr($html, $pos + strlen($tag), 0 + 500);
            if (stripos($tail, 'name="csrf_token"') !== false || stripos($tail, "name='csrf_token'") !== false) {
                return $tag;
            }
        }
        return $tag . "\n" . $input;
    }, $html) ?? $html;
}

function asr_start_csrf_output_filter(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    ob_start('asr_inject_csrf_into_post_forms');
}
