<?php
// Telegram webhook для технического бота проверки рассылок.
// URL вида: /telegram/broadcast_test_bot.php?secret=...

define('ASR_ADMIN', true);
require_once __DIR__ . '/../admin_app/bootstrap.php';
$tgRepo = __DIR__ . '/../admin_app/modules/telegram_bots/repository.php';
if (is_file($tgRepo)) { require_once $tgRepo; }

function btest_tg_column_exists(PDO $pdo, string $table, string $column): bool {
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

function btest_tg_ensure_schema(PDO $pdo): void {
    $columns = [
        'telegram_bind_token' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_broadcast_test_chat_id' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_chat_id` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_broadcast_test_username' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_username` VARCHAR(128) NULL DEFAULT NULL",
        'telegram_broadcast_test_bound_at' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_bound_at` DATETIME NULL DEFAULT NULL",
        'telegram_broadcast_test_receive_broadcasts' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_receive_broadcasts` TINYINT(1) NOT NULL DEFAULT 1",
        'telegram_broadcast_test_notify_dialogs' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_notify_dialogs` TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($columns as $column => $sql) {
        if (btest_tg_column_exists($pdo, 'oca_users', $column)) continue;
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}

function btest_tg_config(): array {
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    return [
        'bot_token' => trim((string)($settings['telegram_broadcast_test_bot_token'] ?? (function_exists('asr_get_setting') ? asr_get_setting('telegram_broadcast_test_bot_token', '') : ''))),
        'webhook_secret' => trim((string)($settings['telegram_broadcast_test_webhook_secret'] ?? (function_exists('asr_get_setting') ? asr_get_setting('telegram_broadcast_test_webhook_secret', '') : ''))),
    ];
}

function btest_tg_send_plain(string $token, string $chatId, string $text): void {
    if ($token === '' || $chatId === '') return;
    try {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $text],
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {}
}

function btest_tg_api_request(string $token, string $method, array $payload = []): void {
    if ($token === '' || $method === '') return;
    try {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {}
}

function btest_tg_answer_callback(string $token, string $callbackId, string $text): void {
    if ($callbackId === '') return;
    btest_tg_api_request($token, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => '0',
    ]);
}


function btest_tg_update_dialog_status(PDO $pdo, int $botId, int $subscriberId, string $status): array {
    if ($subscriberId <= 0 || !in_array($status, ['closed','spam'], true)) {
        return ['ok' => false, 'error' => 'bad_args', 'affected' => 0];
    }

    // Основной путь через штатную функцию, если она доступна.
    if (function_exists('asr_tg_dialog_set_status')) {
        try {
            asr_tg_dialog_set_status($pdo, $subscriberId, $status, null, $botId);
        } catch (Throwable $e) {
            // Ниже всё равно пробуем прямой безопасный fallback.
        }
    }

    $closedSql = $status === 'closed' ? 'NOW()' : 'NULL';
    $spamSql = $status === 'spam' ? 'NOW()' : 'NULL';
    $label = $status === 'spam' ? 'dialog_spam' : 'dialog_closed';
    $eventText = $status === 'spam' ? 'Технический бот перенёс(ла) диалог в спам' : 'Технический бот закрыл(а) диалог';

    $affected = 0;
    try {
        $where = 'subscriber_id = ?';
        $params = [$status, $subscriberId];
        if ($botId > 0) {
            $where .= ' AND bot_id = ?';
            $params[] = $botId;
        }
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = ?, assigned_user_id = NULL, unread_count = 0, read_at = NOW(), closed_at = {$closedSql}, spam_at = {$spamSql}, updated_at = NOW() WHERE {$where}");
        $stmt->execute($params);
        $affected = (int)$stmt->rowCount();

        // Если точная связка bot_id + subscriber_id не дала результата, пробуем по subscriber_id.
        // Это спасает старые/нестандартные записи диалогов, где bot_id мог быть не заполнен.
        if ($affected <= 0 && $botId > 0) {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = ?, assigned_user_id = NULL, unread_count = 0, read_at = NOW(), closed_at = {$closedSql}, spam_at = {$spamSql}, updated_at = NOW() WHERE subscriber_id = ?");
            $stmt->execute([$status, $subscriberId]);
            $affected = (int)$stmt->rowCount();
        }

        if (function_exists('asr_tg_dialog_system_event_add')) {
            try { asr_tg_dialog_system_event_add($pdo, $botId, $subscriberId, $eventText, $label, ['dialog_status' => $status, 'source' => 'broadcast_test_bot']); } catch (Throwable $e) {}
        }

        if ($status === 'spam' && function_exists('asr_tg_subscriber_mark_status')) {
            try { asr_tg_subscriber_mark_status($pdo, $subscriberId, 'blocked'); } catch (Throwable $e) {}
        }

        return ['ok' => true, 'affected' => $affected];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'affected' => $affected];
    }
}

function btest_tg_edit_callback_message(string $token, array $callback, string $appendText): void {
    $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = (string)($chat['id'] ?? '');
    $messageId = (int)($message['message_id'] ?? 0);
    if ($token === '' || $chatId === '' || $messageId <= 0) return;
    $oldText = (string)($message['text'] ?? '');
    $newText = trim($oldText . "\n\n" . $appendText);
    btest_tg_api_request($token, 'editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $newText,
        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
    ]);
}
header('Content-Type: application/json; charset=UTF-8');

try {
    $cfg = btest_tg_config();
    $expectedSecret = trim((string)($cfg['webhook_secret'] ?? ''));
    $givenSecret = trim((string)($_GET['secret'] ?? ''));

    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $givenSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    btest_tg_ensure_schema($pdo);

    $raw = file_get_contents('php://input') ?: '';
    $update = json_decode($raw, true);
    if (!is_array($update)) {
        echo json_encode(['ok' => true, 'ignored' => 'empty'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $callback = $update['callback_query'] ?? null;
    if (is_array($callback)) {
        $callbackId = (string)($callback['id'] ?? '');
        $data = trim((string)($callback['data'] ?? ''));
        $from = is_array($callback['from'] ?? null) ? $callback['from'] : [];
        $chatId = (string)($from['id'] ?? '');
        if ($data !== '' && preg_match('~^btd_(close|spam)_(\d+)_(\d+)$~', $data, $m)) {
            $action = (string)$m[1];
            $botId = (int)$m[2];
            $subscriberId = (int)$m[3];
            $status = $action === 'spam' ? 'spam' : 'closed';
            $label = $status === 'spam' ? 'Диалог отправлен в спам.' : 'Диалог закрыт.';
            $result = btest_tg_update_dialog_status($pdo, $botId, $subscriberId, $status);

            if (!empty($result['ok'])) {
                btest_tg_answer_callback((string)$cfg['bot_token'], $callbackId, $label);
                btest_tg_edit_callback_message((string)$cfg['bot_token'], $callback, '✅ ' . $label);
                echo json_encode(['ok' => true, 'callback' => $status, 'affected' => (int)($result['affected'] ?? 0)], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $err = trim((string)($result['error'] ?? 'unknown'));
            btest_tg_answer_callback((string)$cfg['bot_token'], $callbackId, 'Не удалось выполнить действие.');
            if ($chatId !== '') btest_tg_send_plain((string)$cfg['bot_token'], $chatId, 'Не удалось выполнить действие: ' . $err);
            echo json_encode(['ok' => true, 'callback' => 'failed', 'error' => $err], JSON_UNESCAPED_UNICODE);
            exit;
        }
        btest_tg_answer_callback((string)$cfg['bot_token'], $callbackId, 'Действие не распознано.');
        echo json_encode(['ok' => true, 'ignored' => 'unknown_callback'], JSON_UNESCAPED_UNICODE);
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

    $code = '';
    if (preg_match('~^/start(?:\s+(.+))?$~u', $text, $m)) {
        $code = trim((string)($m[1] ?? ''));
    }

    $userId = 0;
    $token = $code;
    if ($code !== '' && preg_match('~^btest_u(\d+)_([a-f0-9]{16,64})$~i', $code, $mm)) {
        $userId = (int)$mm[1];
        $token = $mm[2];
    } elseif ($code !== '' && preg_match('~^u(\d+)_([a-f0-9]{16,64})$~i', $code, $mm)) {
        $userId = (int)$mm[1];
        $token = $mm[2];
    }

    $user = null;
    if ($code !== '') {
        if ($userId > 0) {
            $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE id = ? AND telegram_bind_token = ? LIMIT 1');
            $stmt->execute([$userId, $token]);
        } else {
            $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE telegram_bind_token = ? LIMIT 1');
            $stmt->execute([$token]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Резервная привязка: если сотрудник уже подключал основной бот «Доступов»,
    // Telegram private chat_id совпадает для разных ботов. Это спасает случай,
    // когда человек нажал Start в техническом боте без персонального параметра.
    if (!$user && btest_tg_column_exists($pdo, 'oca_users', 'telegram_chat_id')) {
        $stmt = $pdo->prepare("SELECT id, full_name, username FROM oca_users WHERE telegram_chat_id = ? AND COALESCE(archived_at, '') = '' LIMIT 1");
        try {
            $stmt->execute([$chatId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE telegram_chat_id = ? LIMIT 1');
            $stmt->execute([$chatId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if (!$user) {
        btest_tg_send_plain((string)$cfg['bot_token'], $chatId, "Не нашёл сотрудника. Откройте персональную ссылку тестового бота из карточки сотрудника в разделе «Наша команда» и нажмите Start.");
        echo json_encode(['ok' => true, 'bound' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    btest_tg_ensure_schema($pdo);
    if (!btest_tg_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_chat_id')) {
        btest_tg_send_plain((string)$cfg['bot_token'], $chatId, "Не удалось сохранить привязку: в базе нет поля telegram_broadcast_test_chat_id. Выполните SQL-миграцию 2026_06_03_001_broadcast_test_bot_users.sql.");
        echo json_encode(['ok' => true, 'bound' => false, 'error' => 'missing_column'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $upd = $pdo->prepare('UPDATE oca_users SET telegram_broadcast_test_chat_id = ?, telegram_broadcast_test_username = ?, telegram_broadcast_test_bound_at = NOW() WHERE id = ?');
    $upd->execute([$chatId, $username, (int)$user['id']]);

    $name = trim((string)($user['full_name'] ?? '')) ?: (string)($user['username'] ?? '');
    btest_tg_send_plain((string)$cfg['bot_token'], $chatId, "✅ Тестовый бот рассылок подключён к сотруднику: " . $name);

    echo json_encode(['ok' => true, 'bound' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
}
