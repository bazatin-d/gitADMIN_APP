<?php
/**
 * Публичная точка входа для VK Callback API.
 *
 * Важное ограничение текущего шага:
 * файл только подтверждает сервер ВК и пишет входящие события в диагностику.
 * Создание диалогов, подписчиков и запуск сценариев подключим отдельным микропатчем.
 */
define('ASR_ADMIN', true);
require_once __DIR__ . '/config.php';

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
require_once __DIR__ . '/admin_app/modules/telegram_bots/platforms/vk/vk_webhook_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'method_not_allowed';
    exit;
}

try {
    $rawBody = file_get_contents('php://input');
    asr_tg_vk_webhook_handle($pdo, $rawBody === false ? '' : $rawBody);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && function_exists('asr_tg_log')) {
            asr_tg_log($pdo, null, 'error', 'vk_callback_fatal', $e->getMessage());
        }
    } catch (Throwable $ignored) {}

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'error';
}
