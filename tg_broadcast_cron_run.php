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

    $now = function_exists('asr_tg_broadcast_now_sql') ? asr_tg_broadcast_now_sql() : date('Y-m-d H:i:s');
    $dueBefore = function_exists('asr_tg_broadcast_due_scheduled_count') ? (int)asr_tg_broadcast_due_scheduled_count($pdo) : 0;

    if ($dry) {
        $out = 'dry_ok now=' . $now . ' due_before=' . $dueBefore . ' limit=' . $limit . ' broadcast_id=' . $broadcastId;
        asr_tg_cron_log_line($out);
        echo $out . "\n";
        exit;
    }

    $activated = function_exists('asr_tg_broadcast_activate_due_scheduled') ? (int)asr_tg_broadcast_activate_due_scheduled($pdo, 200) : 0;
    $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
    $delayResult = function_exists('asr_tg_runtime_process_due_delays') ? asr_tg_runtime_process_due_delays($pdo, $limit) : ['processed' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];

    $out = 'ok now=' . $now
        . ' due_before=' . $dueBefore
        . ' activated=' . $activated
        . ' processed=' . (int)($result['processed'] ?? 0)
        . ' sent=' . (int)($result['sent'] ?? 0)
        . ' failed=' . (int)($result['failed'] ?? 0)
        . ' delay_processed=' . (int)($delayResult['processed'] ?? 0)
        . ' delay_started=' . (int)($delayResult['started'] ?? 0)
        . ' delay_failed=' . (int)($delayResult['failed'] ?? 0)
        . ' delay_skipped=' . (int)($delayResult['skipped'] ?? 0)
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
