<?php
/**
 * Minimal Web Push layer for Admin App PWA.
 * Sends server-side push wakeups without encrypted payload; the Service Worker
 * shows a generic notification and opens Dialogs on click.
 */
defined('ASR_ADMIN') || define('ASR_ADMIN', true);

function asr_pwa_push_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function asr_pwa_push_base64url_decode(string $data): string {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

function asr_pwa_push_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_pwa_push_column_exists(PDO $pdo, string $table, string $column): bool {
    if (function_exists('asr_table_column_exists')) return asr_table_column_exists($pdo, $table, $column);
    try {
        $safeTable = str_replace('`', '``', $table);
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function asr_pwa_push_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_pwa_push_config` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            `public_key` VARCHAR(120) NOT NULL,
            `private_pem` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_pwa_push_subscriptions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `endpoint_hash` CHAR(64) NOT NULL,
            `endpoint` TEXT NOT NULL,
            `keys_p256dh` VARCHAR(255) NULL DEFAULT NULL,
            `keys_auth` VARCHAR(255) NULL DEFAULT NULL,
            `user_agent` VARCHAR(255) NULL DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            `last_sent_at` DATETIME NULL DEFAULT NULL,
            `last_error` TEXT NULL DEFAULT NULL,
            UNIQUE KEY `uniq_endpoint_hash` (`endpoint_hash`),
            KEY `idx_user_active` (`user_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}

function asr_pwa_push_generate_vapid_keys(): array {
    if (!function_exists('openssl_pkey_new')) {
        throw new RuntimeException('OpenSSL недоступен: нельзя создать VAPID-ключи для Web Push.');
    }
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$res) throw new RuntimeException('Не удалось создать VAPID-ключи.');
    $privatePem = '';
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);
    $ec = is_array($details) ? ($details['ec'] ?? []) : [];
    $x = (string)($ec['x'] ?? '');
    $y = (string)($ec['y'] ?? '');
    if (strlen($x) !== 32 || strlen($y) !== 32 || $privatePem === '') {
        throw new RuntimeException('Не удалось получить публичный VAPID-ключ.');
    }
    return [
        'public_key' => asr_pwa_push_base64url_encode("\x04" . $x . $y),
        'private_pem' => $privatePem,
    ];
}

function asr_pwa_push_get_vapid_keys(PDO $pdo): array {
    asr_pwa_push_ensure_schema($pdo);
    try {
        $stmt = $pdo->query('SELECT public_key, private_pem FROM oca_pwa_push_config WHERE id = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($row && trim((string)$row['public_key']) !== '' && trim((string)$row['private_pem']) !== '') {
            return ['public_key' => (string)$row['public_key'], 'private_pem' => (string)$row['private_pem']];
        }
    } catch (Throwable $e) {}
    $keys = asr_pwa_push_generate_vapid_keys();
    try {
        $stmt = $pdo->prepare('INSERT INTO oca_pwa_push_config (id, public_key, private_pem, created_at, updated_at) VALUES (1, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), private_pem = VALUES(private_pem), updated_at = NOW()');
        $stmt->execute([$keys['public_key'], $keys['private_pem']]);
    } catch (Throwable $e) {}
    return $keys;
}

function asr_pwa_push_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function asr_pwa_push_der_to_jose(string $der): string {
    $offset = 0;
    if (!isset($der[$offset]) || ord($der[$offset++]) !== 0x30) return '';
    $len = ord($der[$offset++] ?? "\0");
    if ($len & 0x80) {
        $n = $len & 0x7f; $len = 0;
        for ($i = 0; $i < $n; $i++) $len = ($len << 8) + ord($der[$offset++] ?? "\0");
    }
    if (ord($der[$offset++] ?? "\0") !== 0x02) return '';
    $rLen = ord($der[$offset++] ?? "\0");
    $r = substr($der, $offset, $rLen); $offset += $rLen;
    if (ord($der[$offset++] ?? "\0") !== 0x02) return '';
    $sLen = ord($der[$offset++] ?? "\0");
    $s = substr($der, $offset, $sLen);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    if (strlen($r) !== 32 || strlen($s) !== 32) return '';
    return $r . $s;
}

function asr_pwa_push_endpoint_audience(string $endpoint): string {
    $parts = parse_url($endpoint);
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $host !== '' ? ($scheme . '://' . $host . $port) : '';
}

function asr_pwa_push_vapid_headers(PDO $pdo, string $endpoint): array {
    $keys = asr_pwa_push_get_vapid_keys($pdo);
    $aud = asr_pwa_push_endpoint_audience($endpoint);
    if ($aud === '') throw new RuntimeException('Некорректный endpoint push-подписки.');
    $domain = defined('APP_DOMAIN') ? APP_DOMAIN : 'localhost';
    $header = asr_pwa_push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $claims = asr_pwa_push_base64url_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 43200,
        'sub' => 'mailto:admin@' . $domain,
    ], JSON_UNESCAPED_SLASHES));
    $unsigned = $header . '.' . $claims;
    $signatureDer = '';
    if (!openssl_sign($unsigned, $signatureDer, (string)$keys['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Не удалось подписать VAPID JWT.');
    }
    $signature = asr_pwa_push_der_to_jose($signatureDer);
    if ($signature === '') throw new RuntimeException('Не удалось подготовить VAPID-подпись.');
    $jwt = $unsigned . '.' . asr_pwa_push_base64url_encode($signature);
    return [
        'TTL: 60',
        'Urgency: high',
        'Content-Length: 0',
        'Authorization: WebPush ' . $jwt,
        'Crypto-Key: p256ecdsa=' . (string)$keys['public_key'],
    ];
}

function asr_pwa_push_register_subscription(PDO $pdo, int $userId, array $subscription, string $userAgent = ''): void {
    if ($userId <= 0) throw new RuntimeException('Пользователь не найден.');
    asr_pwa_push_ensure_schema($pdo);
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    if ($endpoint === '' || !preg_match('#^https://#i', $endpoint)) {
        throw new RuntimeException('Браузер не передал корректную push-подписку.');
    }
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $hash = hash('sha256', $endpoint);
    $stmt = $pdo->prepare('INSERT INTO oca_pwa_push_subscriptions (user_id, endpoint_hash, endpoint, keys_p256dh, keys_auth, user_agent, is_active, created_at, updated_at, last_error) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NULL) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), keys_p256dh = VALUES(keys_p256dh), keys_auth = VALUES(keys_auth), user_agent = VALUES(user_agent), is_active = 1, updated_at = NOW(), last_error = NULL');
    $stmt->execute([$userId, $hash, $endpoint, (string)($keys['p256dh'] ?? ''), (string)($keys['auth'] ?? ''), mb_substr($userAgent, 0, 255, 'UTF-8')]);
}

function asr_pwa_push_unregister_subscription(PDO $pdo, int $userId, string $endpoint = ''): void {
    if ($userId <= 0) return;
    asr_pwa_push_ensure_schema($pdo);
    if ($endpoint !== '') {
        $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND endpoint_hash = ?');
        $stmt->execute([$userId, hash('sha256', $endpoint)]);
        return;
    }
    $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET is_active = 0, updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function asr_pwa_push_send_endpoint(PDO $pdo, string $endpoint): array {
    $headers = asr_pwa_push_vapid_headers($pdo, $endpoint);
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AdminAppPWA/1.0',
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $err ?: ''];
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => '',
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($endpoint, false, $context);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $code = (int)$m[1]; break; }
        }
    }
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => $result === false ? 'Push request failed' : ''];
}

function asr_pwa_push_active_subscriptions_for_dialogs(PDO $pdo): array {
    asr_pwa_push_ensure_schema($pdo);
    $where = ['s.is_active = 1'];
    $join = '';
    if (asr_pwa_push_table_exists($pdo, 'oca_users')) {
        $join = ' INNER JOIN oca_users u ON u.id = s.user_id ';
        if (asr_pwa_push_column_exists($pdo, 'oca_users', 'pwa_dialog_notify_enabled')) $where[] = 'COALESCE(u.pwa_dialog_notify_enabled, 0) = 1';
        if (asr_pwa_push_column_exists($pdo, 'oca_users', 'is_active')) $where[] = 'COALESCE(u.is_active, 1) = 1';
        if (asr_pwa_push_column_exists($pdo, 'oca_users', 'archived_at')) $where[] = 'u.archived_at IS NULL';
    }
    $sql = 'SELECT s.id, s.user_id, s.endpoint FROM oca_pwa_push_subscriptions s' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id ASC LIMIT 200';
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function asr_pwa_push_notify_dialog(PDO $pdo, array $bot, int $botId, int $subscriberId, ?string $text = null): void {
    $items = asr_pwa_push_active_subscriptions_for_dialogs($pdo);
    if (!$items) return;
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        $endpoint = trim((string)($item['endpoint'] ?? ''));
        if ($id <= 0 || $endpoint === '') continue;
        try {
            $result = asr_pwa_push_send_endpoint($pdo, $endpoint);
            if ($result['ok']) {
                $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET last_sent_at = NOW(), last_error = NULL WHERE id = ?');
                $stmt->execute([$id]);
            } else {
                $code = (int)($result['code'] ?? 0);
                $error = trim((string)($result['error'] ?? ''));
                if (in_array($code, [404, 410], true)) {
                    $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET is_active = 0, updated_at = NOW(), last_error = ? WHERE id = ?');
                    $stmt->execute(['Push endpoint expired: HTTP ' . $code, $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET last_error = ? WHERE id = ?');
                    $stmt->execute([mb_substr('HTTP ' . $code . ($error !== '' ? ': ' . $error : ''), 0, 1000, 'UTF-8'), $id]);
                }
            }
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare('UPDATE oca_pwa_push_subscriptions SET last_error = ? WHERE id = ?');
                $stmt->execute([mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $id]);
            } catch (Throwable $ignore) {}
        }
    }
}
