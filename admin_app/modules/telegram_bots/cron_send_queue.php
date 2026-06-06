<?php
/**
 * CLI-обработчик очереди Telegram-рассылок.
 * Запуск из cron, пример:
 * * * * * /usr/bin/php /path/to/project/admin_app/modules/telegram_bots/cron_send_queue.php 30 >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ASR_ADMIN', true);
require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/service.php';

$limit = isset($argv[1]) ? (int)$argv[1] : 30;
$broadcastId = isset($argv[2]) ? (int)$argv[2] : 0;

try {
    $dueBefore = function_exists('asr_tg_broadcast_due_scheduled_count') ? asr_tg_broadcast_due_scheduled_count($pdo) : 0;
    $now = function_exists('asr_tg_broadcast_now_sql') ? asr_tg_broadcast_now_sql() : date('Y-m-d H:i:s');
    $activated = asr_tg_broadcast_activate_due_scheduled($pdo, 200);
    $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
    echo 'now=' . $now . ' due_before=' . (int)$dueBefore . ' activated=' . (int)$activated . ' processed=' . (int)$result['processed'] . ' sent=' . (int)$result['sent'] . ' failed=' . (int)$result['failed'] . PHP_EOL;
} catch (Throwable $e) {
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
