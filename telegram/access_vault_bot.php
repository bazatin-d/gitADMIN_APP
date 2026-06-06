<?php
// Telegram webhook для модуля «Доступы».
// URL вида: /telegram/access_vault_bot.php?secret=...

define('ASR_ADMIN', true);
require_once __DIR__ . '/../admin_app/bootstrap.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/repository.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/crypto.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/service.php';


function av_tg_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function av_tg_force_user_telegram_schema(PDO $pdo): void {
    $columns = [
        'telegram_chat_id' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_chat_id` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_username' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_username` VARCHAR(128) NULL DEFAULT NULL",
        'telegram_bind_token' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_bound_at' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bound_at` DATETIME NULL DEFAULT NULL",
    ];
    foreach ($columns as $column => $sql) {
        if (av_tg_column_exists($pdo, 'oca_users', $column)) continue;
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Если колонка уже появилась параллельно — это нормально. Остальные ошибки обработаем ниже проверкой.
            if (stripos($e->getMessage(), 'Duplicate column') === false) {
                // Не роняем webhook: Telegram будет повторять запросы, а пользователю дадим понятный ответ.
            }
        }
    }
}

function av_tg_send_plain(PDO $pdo, string $chatId, string $text): void {
    try {
        asr_av_send_telegram_message($chatId, $text);
    } catch (Throwable $e) {
        // Webhook не должен падать: Telegram будет повторять запросы.
    }
}

header('Content-Type: application/json; charset=UTF-8');

try {
    $cfg = asr_av_telegram_config();
    $expectedSecret = trim((string)($cfg['webhook_secret'] ?? ''));
    $givenSecret = trim((string)($_GET['secret'] ?? ''));

    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $givenSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    asr_av_ensure_user_telegram_schema($pdo);
    av_tg_force_user_telegram_schema($pdo);

    $raw = file_get_contents('php://input') ?: '';
    $update = json_decode($raw, true);
    if (!is_array($update)) {
        echo json_encode(['ok' => true, 'ignored' => 'empty'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $message = $update['message'] ?? $update['edited_message'] ?? null;
    if (!is_array($message)) {
        echo json_encode(['ok' => true, 'ignored' => 'no_message'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];
    $chatId = (string)($chat['id'] ?? '');
    $text = trim((string)($message['text'] ?? ''));
    $username = trim((string)($from['username'] ?? ''));

    if ($chatId === '') {
        echo json_encode(['ok' => true, 'ignored' => 'no_chat'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!preg_match('~^/start\s+(.+)$~u', $text, $m)) {
        av_tg_send_plain($pdo, $chatId, "Здравствуйте. Для привязки Telegram откройте персональную ссылку из админки.");
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $code = trim($m[1]);
    $userId = 0;
    $token = $code;
    if (preg_match('~^u(\d+)_([a-f0-9]{16,64})$~i', $code, $mm)) {
        $userId = (int)$mm[1];
        $token = $mm[2];
    }

    if (!av_tg_column_exists($pdo, 'oca_users', 'telegram_bind_token')) {
        av_tg_send_plain($pdo, $chatId, "Не удалось привязать Telegram: в базе нет поля telegram_bind_token. Выполните миграцию 2026_05_29_022_users_tg_bind_token_webhook_fix.sql или проверьте, что webhook бота смотрит на app.exec-booster.kz.");
        echo json_encode(['ok' => true, 'error' => 'missing_column'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE id = ? AND telegram_bind_token = ? LIMIT 1');
        $stmt->execute([$userId, $token]);
    } else {
        $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE telegram_bind_token = ? LIMIT 1');
        $stmt->execute([$token]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        av_tg_send_plain($pdo, $chatId, "Не нашёл пользователя по этому коду. Попросите администратора выдать новую ссылку привязки.");
        echo json_encode(['ok' => true, 'bound' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    av_tg_force_user_telegram_schema($pdo);
    $upd = $pdo->prepare('UPDATE oca_users SET telegram_chat_id = ?, telegram_username = ?, telegram_bound_at = NOW() WHERE id = ?');
    $upd->execute([$chatId, $username, (int)$user['id']]);

    $name = trim((string)($user['full_name'] ?? '')) ?: (string)($user['username'] ?? '');
    av_tg_send_plain($pdo, $chatId, "✅ Telegram подключён к пользователю: " . $name);

    echo json_encode(['ok' => true, 'bound' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
}
