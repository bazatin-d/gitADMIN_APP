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
    $now = function_exists('asr_tg_runtime_now_sql') ? asr_tg_runtime_now_sql() : (function_exists('asr_tg_broadcast_now_sql') ? asr_tg_broadcast_now_sql() : date('Y-m-d H:i:s'));
    $delayPendingBefore = function_exists('asr_tg_runtime_pending_delays_count') ? (int)asr_tg_runtime_pending_delays_count($pdo) : 0;
    $delayDueBefore = function_exists('asr_tg_runtime_due_delays_count') ? (int)asr_tg_runtime_due_delays_count($pdo) : 0;
    $questionPendingBefore = function_exists('asr_tg_runtime_pending_questions_count') ? (int)asr_tg_runtime_pending_questions_count($pdo) : 0;
    $questionDueBefore = function_exists('asr_tg_runtime_due_questions_count') ? (int)asr_tg_runtime_due_questions_count($pdo) : 0;
    $queueBefore = function_exists('asr_tg_queue_diag_broadcast_snapshot') ? asr_tg_queue_diag_broadcast_snapshot($pdo, $broadcastId, 10) : [];
    $queueBeforeLine = function_exists('asr_tg_queue_diag_format_compact') ? asr_tg_queue_diag_format_compact($queueBefore, 'queue_before') : 'queue_before_available=0';
    $activated = asr_tg_broadcast_activate_due_scheduled($pdo, 200);
    $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
    $delayResult = function_exists('asr_tg_runtime_process_due_delays') ? asr_tg_runtime_process_due_delays($pdo, $limit) : ['processed' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    $questionResult = function_exists('asr_tg_runtime_process_due_questions') ? asr_tg_runtime_process_due_questions($pdo, $limit) : ['processed' => 0, 'reminded' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    $queueAfter = function_exists('asr_tg_queue_diag_broadcast_snapshot') ? asr_tg_queue_diag_broadcast_snapshot($pdo, $broadcastId, 10) : [];
    $queueAfterLine = function_exists('asr_tg_queue_diag_format_compact') ? asr_tg_queue_diag_format_compact($queueAfter, 'queue_after') : 'queue_after_available=0';
    echo 'now=' . $now
        . ' due_before=' . (int)$dueBefore
        . ' delay_pending_before=' . (int)$delayPendingBefore
        . ' delay_due_before=' . (int)$delayDueBefore
        . ' question_pending_before=' . (int)$questionPendingBefore
        . ' question_due_before=' . (int)$questionDueBefore
        . ' activated=' . (int)$activated
        . ' processed=' . (int)$result['processed']
        . ' sent=' . (int)$result['sent']
        . ' failed=' . (int)$result['failed']
        . ' stale_processing_reset=' . (int)($result['stale_processing_reset'] ?? 0)
        . ' delay_processed=' . (int)($delayResult['processed'] ?? 0)
        . ' delay_started=' . (int)($delayResult['started'] ?? 0)
        . ' delay_failed=' . (int)($delayResult['failed'] ?? 0)
        . ' delay_skipped=' . (int)($delayResult['skipped'] ?? 0)
        . ' question_processed=' . (int)($questionResult['processed'] ?? 0)
        . ' question_reminded=' . (int)($questionResult['reminded'] ?? 0)
        . ' question_started=' . (int)($questionResult['started'] ?? 0)
        . ' question_failed=' . (int)($questionResult['failed'] ?? 0)
        . ' question_skipped=' . (int)($questionResult['skipped'] ?? 0)
        . ' ' . $queueBeforeLine
        . ' ' . $queueAfterLine
        . PHP_EOL;
} catch (Throwable $e) {
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
