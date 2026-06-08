<?php
/**
 * CLI-чистка старого журнала прохождения сценариев.
 *
 * Безопасная проверка без удаления:
 * php admin_app/modules/telegram_bots/cron_cleanup_scenario_journal.php --dry-run
 *
 * Реальная чистка:
 * php admin_app/modules/telegram_bots/cron_cleanup_scenario_journal.php --run
 *
 * Дополнительные параметры:
 * --technical-days=90
 * --detail-days=180
 * --important-days=365
 * --limit=5000
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ASR_ADMIN', true);
require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/scenario_retention_service.php';

function asr_tg_cleanup_scenario_journal_arg(string $name, ?int $default = null): ?int {
    foreach (($GLOBALS['argv'] ?? []) as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return (int)substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('PDO-подключение не найдено в config.php.');
    }

    $args = $argv ?? [];
    $isRun = in_array('--run', $args, true);
    $dryRun = !$isRun || in_array('--dry-run', $args, true);

    $override = [];
    $technicalDays = asr_tg_cleanup_scenario_journal_arg('technical-days');
    $detailDays = asr_tg_cleanup_scenario_journal_arg('detail-days');
    $importantDays = asr_tg_cleanup_scenario_journal_arg('important-days');
    $limit = asr_tg_cleanup_scenario_journal_arg('limit');

    if ($technicalDays !== null) $override['technical_days'] = $technicalDays;
    if ($detailDays !== null) $override['detail_days'] = $detailDays;
    if ($importantDays !== null) $override['important_days'] = $importantDays;
    if ($limit !== null) $override['batch_limit'] = $limit;

    $result = asr_tg_scenario_retention_cleanup($pdo, $override, $dryRun);
    echo asr_tg_scenario_retention_format_result($result) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    echo 'mode=error ok=0 error=' . str_replace(["\n", "\r"], ' ', $e->getMessage()) . PHP_EOL;
    exit(1);
}
