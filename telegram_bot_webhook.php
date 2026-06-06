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

try {
    $botId = (int)($_GET['bot_id'] ?? 0);
    $secret = (string)($_GET['secret'] ?? '');
    $rawBody = file_get_contents('php://input');
    $headerSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    asr_tg_handle_webhook($pdo, $botId, $secret, $rawBody === false ? '' : $rawBody, $headerSecret);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        try { asr_tg_log($pdo, isset($botId) ? (int)$botId : null, 'error', 'webhook_fatal', $e->getMessage()); } catch (Throwable $ignored) {}
    }
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
