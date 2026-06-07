<?php
/**
 * Secure URL runner for Telegram broadcast queue.
 * Hosting cron example:
 * curl -sS "https://app.exec-booster.kz/tg_broadcast_cron_run.php?token=ebtgcron_20260602_9f7a1c4d2b&limit=30" >/dev/null 2>&1
 */
declare(strict_types=1);

const ASR_TG_CRON_TOKEN = 'ebtgcron_20260602_9f7a1c4d2b';

function asr_tg_cron_log_path(): string {
    $dir = __DIR__ . '/uploads/telegram_bots';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/cron_runner.log';
}

function asr_tg_cron_log_line(string $line): void {
    $path = asr_tg_cron_log_path();
    $prefix = date('Y-m-d H:i:s') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ua=' . str_replace(["\r", "\n"], ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '-')) . ' ';
    @file_put_contents($path, $prefix . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

header('Content-Type: text/plain; charset=utf-8');

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (!hash_equals(ASR_TG_CRON_TOKEN, $token)) {
    asr_tg_cron_log_line('forbidden token=' . substr($token, 0, 8));
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

@set_time_limit(120);

$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 30;
$broadcastId = isset($_GET['broadcast_id']) ? max(0, (int)$_GET['broadcast_id']) : 0;
$dry = !empty($_GET['dry']);

try {
    define('ASR_ADMIN', true);
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/admin_app/modules/telegram_bots/service.php';
    require_once __DIR__ . '/admin_app/modules/telegram_bots/yandex_metrika_service.php';

    $now = function_exists('asr_tg_runtime_now_sql') ? asr_tg_runtime_now_sql() : (function_exists('asr_tg_broadcast_now_sql') ? asr_tg_broadcast_now_sql() : date('Y-m-d H:i:s'));
    $dueBefore = function_exists('asr_tg_broadcast_due_scheduled_count') ? (int)asr_tg_broadcast_due_scheduled_count($pdo) : 0;
    $delayPendingBefore = function_exists('asr_tg_runtime_pending_delays_count') ? (int)asr_tg_runtime_pending_delays_count($pdo) : 0;
    $delayDueBefore = function_exists('asr_tg_runtime_due_delays_count') ? (int)asr_tg_runtime_due_delays_count($pdo) : 0;
    $questionPendingBefore = function_exists('asr_tg_runtime_pending_questions_count') ? (int)asr_tg_runtime_pending_questions_count($pdo) : 0;
    $questionDueBefore = function_exists('asr_tg_runtime_due_questions_count') ? (int)asr_tg_runtime_due_questions_count($pdo) : 0;
    $queueBefore = function_exists('asr_tg_queue_diag_broadcast_snapshot') ? asr_tg_queue_diag_broadcast_snapshot($pdo, $broadcastId, 10) : [];
    $queueBeforeLine = function_exists('asr_tg_queue_diag_format_compact') ? asr_tg_queue_diag_format_compact($queueBefore, 'queue_before') : 'queue_before_available=0';
    $sendQueueBefore = function_exists('asr_tg_send_queue_stats') ? asr_tg_send_queue_stats($pdo, false) : [];
    $sendQueueBeforeLine = function_exists('asr_tg_send_queue_format_stats') ? asr_tg_send_queue_format_stats($sendQueueBefore, 'send_queue_before') : 'send_queue_before_available=0';
    $ymQueueBefore = function_exists('asr_tg_yandex_metrika_stats') ? asr_tg_yandex_metrika_stats($pdo) : [];
    $ymQueueBeforeLine = function_exists('asr_tg_yandex_metrika_format_stats') ? asr_tg_yandex_metrika_format_stats($ymQueueBefore, 'ym_before') : 'ym_before_available=0';

    if ($dry) {
        $out = 'dry_ok now=' . $now . ' due_before=' . $dueBefore . ' delay_pending_before=' . $delayPendingBefore . ' delay_due_before=' . $delayDueBefore . ' question_pending_before=' . $questionPendingBefore . ' question_due_before=' . $questionDueBefore . ' ' . $queueBeforeLine . ' ' . $sendQueueBeforeLine . ' ' . $ymQueueBeforeLine . ' limit=' . $limit . ' broadcast_id=' . $broadcastId;
        asr_tg_cron_log_line($out);
        echo $out . "\n";
        exit;
    }

    $activated = function_exists('asr_tg_broadcast_activate_due_scheduled') ? (int)asr_tg_broadcast_activate_due_scheduled($pdo, 200) : 0;
    $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
    $delayResult = function_exists('asr_tg_runtime_process_due_delays') ? asr_tg_runtime_process_due_delays($pdo, $limit) : ['processed' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    $questionResult = function_exists('asr_tg_runtime_process_due_questions') ? asr_tg_runtime_process_due_questions($pdo, $limit) : ['processed' => 0, 'reminded' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    $sendQueueResult = function_exists('asr_tg_process_send_queue') ? asr_tg_process_send_queue($pdo, $limit) : ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'stale_processing_reset' => 0, 'unsupported' => 0];
    $ymQueueResult = function_exists('asr_tg_yandex_metrika_process_queue') ? asr_tg_yandex_metrika_process_queue($pdo, $limit) : ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'batches' => 0, 'stale_processing_reset' => 0];
    $queueAfter = function_exists('asr_tg_queue_diag_broadcast_snapshot') ? asr_tg_queue_diag_broadcast_snapshot($pdo, $broadcastId, 10) : [];
    $queueAfterLine = function_exists('asr_tg_queue_diag_format_compact') ? asr_tg_queue_diag_format_compact($queueAfter, 'queue_after') : 'queue_after_available=0';
    $sendQueueAfter = function_exists('asr_tg_send_queue_stats') ? asr_tg_send_queue_stats($pdo, false) : [];
    $sendQueueAfterLine = function_exists('asr_tg_send_queue_format_stats') ? asr_tg_send_queue_format_stats($sendQueueAfter, 'send_queue_after') : 'send_queue_after_available=0';
    $ymQueueAfter = function_exists('asr_tg_yandex_metrika_stats') ? asr_tg_yandex_metrika_stats($pdo) : [];
    $ymQueueAfterLine = function_exists('asr_tg_yandex_metrika_format_stats') ? asr_tg_yandex_metrika_format_stats($ymQueueAfter, 'ym_after') : 'ym_after_available=0';

    $out = 'ok now=' . $now
        . ' due_before=' . $dueBefore
        . ' delay_pending_before=' . $delayPendingBefore
        . ' delay_due_before=' . $delayDueBefore
        . ' question_pending_before=' . $questionPendingBefore
        . ' question_due_before=' . $questionDueBefore
        . ' activated=' . $activated
        . ' processed=' . (int)($result['processed'] ?? 0)
        . ' sent=' . (int)($result['sent'] ?? 0)
        . ' failed=' . (int)($result['failed'] ?? 0)
        . ' stale_processing_reset=' . (int)($result['stale_processing_reset'] ?? 0)
        . ' delay_processed=' . (int)($delayResult['processed'] ?? 0)
        . ' delay_started=' . (int)($delayResult['started'] ?? 0)
        . ' delay_queued=' . (int)($delayResult['queued'] ?? 0)
        . ' delay_failed=' . (int)($delayResult['failed'] ?? 0)
        . ' delay_skipped=' . (int)($delayResult['skipped'] ?? 0)
        . ' question_processed=' . (int)($questionResult['processed'] ?? 0)
        . ' question_queued=' . (int)($questionResult['queued'] ?? 0)
        . ' question_reminded=' . (int)($questionResult['reminded'] ?? 0)
        . ' question_started=' . (int)($questionResult['started'] ?? 0)
        . ' question_failed=' . (int)($questionResult['failed'] ?? 0)
        . ' question_skipped=' . (int)($questionResult['skipped'] ?? 0)
        . ' send_queue_processed=' . (int)($sendQueueResult['processed'] ?? 0)
        . ' send_queue_sent=' . (int)($sendQueueResult['sent'] ?? 0)
        . ' send_queue_failed=' . (int)($sendQueueResult['failed'] ?? 0)
        . ' send_queue_retry=' . (int)($sendQueueResult['retry'] ?? 0)
        . ' send_queue_skipped=' . (int)($sendQueueResult['skipped'] ?? 0)
        . ' send_queue_stale_processing_reset=' . (int)($sendQueueResult['stale_processing_reset'] ?? 0)
        . ' send_queue_unsupported=' . (int)($sendQueueResult['unsupported'] ?? 0)
        . ' ym_processed=' . (int)($ymQueueResult['processed'] ?? 0)
        . ' ym_sent=' . (int)($ymQueueResult['sent'] ?? 0)
        . ' ym_failed=' . (int)($ymQueueResult['failed'] ?? 0)
        . ' ym_retry=' . (int)($ymQueueResult['retry'] ?? 0)
        . ' ym_skipped=' . (int)($ymQueueResult['skipped'] ?? 0)
        . ' ym_batches=' . (int)($ymQueueResult['batches'] ?? 0)
        . ' ym_stale_processing_reset=' . (int)($ymQueueResult['stale_processing_reset'] ?? 0)
        . ' ' . $queueBeforeLine
        . ' ' . $queueAfterLine
        . ' ' . $sendQueueBeforeLine
        . ' ' . $sendQueueAfterLine
        . ' ' . $ymQueueBeforeLine
        . ' ' . $ymQueueAfterLine
        . ' limit=' . $limit
        . ' broadcast_id=' . $broadcastId;
    asr_tg_cron_log_line($out);
    echo $out . "\n";
} catch (Throwable $e) {
    $msg = 'error=' . $e->getMessage();
    asr_tg_cron_log_line($msg);
    http_response_code(500);
    echo $msg . "\n";
    exit(1);
}
