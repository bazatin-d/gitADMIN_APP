<?php
/**
 * Публичная точка входа для Telegram webhook.
 * Вся бизнес-логика лежит внутри admin_app/modules/telegram_bots/.
 */
define('ASR_ADMIN', true);
require_once __DIR__ . '/config.php';

// Webhook клиентских Telegram-ботов работает без полной админской загрузки,
// поэтому вручную подключаем только безопасные общие функции, которые нужны
// для уведомлений в технический бот: чтение настроек, базовый URL и проверка
// колонок. Без этого уведомления о новых диалогах не видят токен тех. бота
// и список подключённых сотрудников.
$settingsLib = __DIR__ . '/admin_app/lib/settings.php';
if (is_file($settingsLib)) {
    require_once $settingsLib;
}

if (!function_exists('asr_table_column_exists')) {
    function asr_table_column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $safeTable = str_replace('`', '``', $table);
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
            $stmt->execute([$column]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) return true;
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = ?\n                  AND COLUMN_NAME = ?\n            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('asr_table_column_exists_fresh')) {
    function asr_table_column_exists_fresh(PDO $pdo, string $table, string $column): bool {
        return asr_table_column_exists($pdo, $table, $column);
    }
}

require_once __DIR__ . '/admin_app/modules/telegram_bots/service.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Runtime v0.1.6: очень узкая страховка для старта сценария по Telegram-команде.
 * Основной runtime остаётся в service.php. Этот слой нужен только чтобы не терять
 * команду, если штатный обработчик получил webhook, но не дошёл до команды/диалога.
 */
function asr_tg_webhook_runtime_command_from_update(array $update): array {
    $message = null;
    $source = '';
    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
        if (is_array($update[$key] ?? null)) {
            $message = $update[$key];
            $source = $key;
            break;
        }
    }
    if (!$message && is_array($update['callback_query']['message'] ?? null)) {
        $message = $update['callback_query']['message'];
        $source = 'callback_query.message';
    }

    $text = '';
    if (is_array($update['callback_query'] ?? null) && isset($update['callback_query']['data'])) {
        $text = trim((string)$update['callback_query']['data']);
        if ($source === '') $source = 'callback_query.data';
    }
    if ($text === '' && $message) {
        $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));
    }

    $command = '';
    if ($text !== '' && preg_match('/^\/([a-zA-Z0-9_]{1,32})(?:@[^\s]+)?(?:\s|$)/u', $text, $m)) {
        $command = strtolower((string)$m[1]);
    }

    return [$command, $text, is_array($message) ? $message : [], $source];
}

function asr_tg_webhook_runtime_one_active_scenario(PDO $pdo, int $botId): int {
    if ($botId <= 0) return 0;
    try {
        $sql = "SELECT s.id
                FROM oca_telegram_bot_scenarios s
                JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id
                WHERE sb.bot_id = ?
                  AND COALESCE(sb.is_enabled, 1) = 1
                  AND s.status = 'active'";
        if (function_exists('asr_tg_column_exists') && asr_tg_column_exists($pdo, 'oca_telegram_bot_scenarios', 'archived_at')) {
            $sql .= " AND s.archived_at IS NULL";
        }
        $sql .= " ORDER BY COALESCE(sb.is_default, 0) DESC, s.id ASC LIMIT 2";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$botId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return count($rows) === 1 ? (int)$rows[0]['id'] : 0;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'webhook_wrapper_active_scenario_lookup_failed', $e->getMessage()); } catch (Throwable $ignored) {}
        return 0;
    }
}

function asr_tg_webhook_runtime_command_row(PDO $pdo, int $botId, string $command): ?array {
    if ($botId <= 0 || $command === '') return null;
    try {
        if (!function_exists('asr_tg_table_exists') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) return null;
        $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_commands WHERE bot_id = ? AND (command = ? OR command = ?) AND (is_active = 1 OR is_active IS NULL) ORDER BY CASE WHEN scenario_id IS NOT NULL AND scenario_id > 0 THEN 0 ELSE 1 END, sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$botId, $command, '/' . $command]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'webhook_wrapper_command_lookup_failed', $e->getMessage(), ['command' => $command]); } catch (Throwable $ignored) {}
        return null;
    }
}

function asr_tg_webhook_runtime_persist_command(PDO $pdo, int $botId, string $command, int $scenarioId): void {
    if ($botId <= 0 || $command === '' || $scenarioId <= 0) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_commands (bot_id, command, description, scenario_id, step_id, sort_order, is_active, created_at, updated_at)
            VALUES (?, ?, 'Автопривязка runtime', ?, NULL, 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE scenario_id = VALUES(scenario_id), is_active = 1, updated_at = NOW()");
        $stmt->execute([$botId, $command, $scenarioId]);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'webhook_wrapper_command_persist_failed', $e->getMessage(), ['command' => $command, 'scenario_id' => $scenarioId]); } catch (Throwable $ignored) {}
    }
}

function asr_tg_webhook_runtime_fallback(PDO $pdo, int $botId, string $rawBody): void {
    if ($botId <= 0 || $rawBody === '') return;
    try {
        $update = json_decode($rawBody, true);
        if (!is_array($update)) return;
        [$command, $text, $message, $source] = asr_tg_webhook_runtime_command_from_update($update);
        try {
            asr_tg_log($pdo, $botId, 'info', 'webhook_wrapper_seen', 'Webhook wrapper проверил входящее событие.', [
                'update_id' => $update['update_id'] ?? null,
                'source' => $source,
                'text_preview' => mb_substr($text, 0, 120, 'UTF-8'),
                'command' => $command,
                'update_keys' => array_keys($update),
            ]);
        } catch (Throwable $ignored) {}
        if ($command === '' || !$message) return;

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        if (!$from && is_array($update['callback_query']['from'] ?? null)) $from = $update['callback_query']['from'];
        $chatId = $chat['id'] ?? ($from['id'] ?? 0);
        if (!$chatId) return;

        $subscriberId = 0;
        try {
            $subscriberId = (int)asr_tg_subscriber_upsert($pdo, $botId, $from, $chatId);
            if ($subscriberId > 0) {
                // Для команды запуска не держим тестового подписчика в старом статусе blocked/spam.
                $pdo->prepare("UPDATE oca_telegram_bot_subscribers SET status = 'active', last_seen_at = NOW(), updated_at = NOW() WHERE id = ? AND bot_id = ? AND status IN ('blocked','spam','inactive')")->execute([$subscriberId, $botId]);
            }
        } catch (Throwable $e) {
            try { asr_tg_log($pdo, $botId, 'warning', 'webhook_wrapper_subscriber_failed', $e->getMessage(), ['chat_id' => $chatId]); } catch (Throwable $ignored) {}
            return;
        }
        if ($subscriberId <= 0) return;

        $row = asr_tg_webhook_runtime_command_row($pdo, $botId, $command);
        $scenarioId = (int)($row['scenario_id'] ?? 0);
        $stepId = (int)($row['step_id'] ?? 0);
        if ($scenarioId <= 0 && $stepId > 0 && function_exists('asr_tg_runtime_scenario_from_step')) {
            $scenarioId = (int)asr_tg_runtime_scenario_from_step($pdo, $stepId);
        }
        if ($scenarioId <= 0) {
            $scenarioId = asr_tg_webhook_runtime_one_active_scenario($pdo, $botId);
            if ($scenarioId > 0) {
                asr_tg_webhook_runtime_persist_command($pdo, $botId, $command, $scenarioId);
            }
        }
        if ($scenarioId <= 0) {
            try { asr_tg_log($pdo, $botId, 'warning', 'webhook_wrapper_no_scenario', 'Не найден единственный активный сценарий для команды.', ['command' => $command]); } catch (Throwable $ignored) {}
            return;
        }

        try {
            asr_tg_log($pdo, $botId, 'info', 'webhook_wrapper_runtime_start', 'Wrapper запускает сценарий по команде.', ['command' => $command, 'scenario_id' => $scenarioId, 'step_id' => $stepId, 'subscriber_id' => $subscriberId]);
        } catch (Throwable $ignored) {}
        if (function_exists('asr_tg_runtime_start_scenario')) {
            $bot = asr_tg_bot_find($pdo, $botId);
            if ($bot) {
                asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $stepId, 'telegram_webhook_wrapper', ['command' => $command]);
            }
        }
    } catch (Throwable $e) {
        try {
            asr_tg_bot_update($pdo, $botId, ['last_error' => $e->getMessage()]);
            asr_tg_log($pdo, $botId, 'error', 'webhook_wrapper_failed', $e->getMessage());
        } catch (Throwable $ignored) {}
    }
}

try {
    $botId = (int)($_GET['bot_id'] ?? 0);
    $secret = (string)($_GET['secret'] ?? '');
    $rawBody = file_get_contents('php://input');
    $rawBody = $rawBody === false ? '' : $rawBody;
    $headerSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    asr_tg_handle_webhook($pdo, $botId, $secret, $rawBody, $headerSecret);
    asr_tg_webhook_runtime_fallback($pdo, $botId, $rawBody);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        try { asr_tg_log($pdo, isset($botId) ? (int)$botId : null, 'error', 'webhook_fatal', $e->getMessage()); } catch (Throwable $ignored) {}
    }
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
