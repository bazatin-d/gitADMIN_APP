<?php
defined('ASR_ADMIN') || exit;

/**
 * Lightweight runtime trace for Telegram scenario cron diagnostics.
 * Enabled only when ASR_TG_SCENARIO_TRACE is defined and true.
 */

function asr_tg_scenario_trace_enabled(): bool {
    return defined('ASR_TG_SCENARIO_TRACE') && ASR_TG_SCENARIO_TRACE;
}

function asr_tg_scenario_trace_sanitize($value, int $depth = 0) {
    if ($depth > 2) return '...';
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        return mb_substr($value, 0, 240, 'UTF-8');
    }
    if (is_array($value)) {
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ >= 20) { $out['...'] = 'truncated'; break; }
            $out[(string)$k] = asr_tg_scenario_trace_sanitize($v, $depth + 1);
        }
        return $out;
    }
    if (is_object($value)) return get_class($value);
    return (string)$value;
}

function asr_tg_scenario_trace(string $event, array $context = []): void {
    if (!asr_tg_scenario_trace_enabled()) return;
    $event = preg_replace('/[^a-zA-Z0-9_:\-.]/', '_', $event) ?: 'trace';
    $context = asr_tg_scenario_trace_sanitize($context);
    $line = date('Y-m-d H:i:s') . ' ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!isset($GLOBALS['asr_tg_scenario_trace_lines']) || !is_array($GLOBALS['asr_tg_scenario_trace_lines'])) {
        $GLOBALS['asr_tg_scenario_trace_lines'] = [];
    }
    $GLOBALS['asr_tg_scenario_trace_lines'][] = $line;
    if (count($GLOBALS['asr_tg_scenario_trace_lines']) > 120) {
        $GLOBALS['asr_tg_scenario_trace_lines'] = array_slice($GLOBALS['asr_tg_scenario_trace_lines'], -120);
    }

    $base = dirname(__DIR__, 3);
    $dir = $base . '/uploads/telegram_bots';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/scenario_trace.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function asr_tg_scenario_trace_lines(): array {
    if (!asr_tg_scenario_trace_enabled()) return [];
    return isset($GLOBALS['asr_tg_scenario_trace_lines']) && is_array($GLOBALS['asr_tg_scenario_trace_lines'])
        ? $GLOBALS['asr_tg_scenario_trace_lines']
        : [];
}

function asr_tg_scenario_trace_append_to_output(string $out): string {
    if (!asr_tg_scenario_trace_enabled()) return $out;
    $lines = asr_tg_scenario_trace_lines();
    if (!$lines) return $out . "\ntrace_empty=1";
    return rtrim($out) . "\n" . implode("\n", array_map(static function ($line) {
        return 'TRACE ' . $line;
    }, $lines));
}

function asr_tg_scenario_trace_fetch_rows(PDO $pdo, string $sql, array $params = [], int $limit = 10): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_slice($rows, 0, $limit);
    } catch (Throwable $e) {
        return [['error' => $e->getMessage()]];
    }
}

function asr_tg_scenario_trace_cron_snapshot(PDO $pdo, string $label = 'snapshot'): void {
    if (!asr_tg_scenario_trace_enabled()) return;
    try {
        $now = function_exists('asr_tg_runtime_now_sql') ? asr_tg_runtime_now_sql() : date('Y-m-d H:i:s');
        $summary = ['now' => $now];
        if (function_exists('asr_tg_table_exists') && asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) {
            $summary['waiting_total'] = (int)$pdo->query("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'waiting'")->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'waiting' AND next_run_at IS NOT NULL AND next_run_at <= ?");
            $stmt->execute([$now]);
            $summary['waiting_due'] = (int)$stmt->fetchColumn();
            $summary['waiting_min_next_run_at'] = (string)($pdo->query("SELECT MIN(next_run_at) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'waiting' AND next_run_at IS NOT NULL")->fetchColumn() ?: '');
            $summary['waiting_recent'] = asr_tg_scenario_trace_fetch_rows($pdo, "SELECT id, bot_id, subscriber_id, scenario_id, current_block_id, status, next_run_at, updated_at, last_error FROM oca_telegram_bot_subscriber_scenarios WHERE status IN ('waiting','queued','processing','error') ORDER BY updated_at DESC, id DESC LIMIT 8", [], 8);
        } else {
            $summary['subscriber_scenarios_table'] = 'missing';
        }
        if (function_exists('asr_tg_table_exists') && asr_tg_table_exists($pdo, 'oca_telegram_bot_send_queue')) {
            $summary['send_queue_question_recent'] = asr_tg_scenario_trace_fetch_rows($pdo, "SELECT id, task_type, status, bot_id, subscriber_id, scenario_id, block_id, state_id, scheduled_at, next_attempt_at, processing_at, sent_at, failed_at, last_error FROM oca_telegram_bot_send_queue WHERE task_type IN ('scenario_question_reminder','scenario_question_timeout') ORDER BY id DESC LIMIT 10", [], 10);
        } else {
            $summary['send_queue_table'] = 'missing';
        }
        if (function_exists('asr_tg_table_exists') && asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_events')) {
            $summary['question_events_recent'] = asr_tg_scenario_trace_fetch_rows($pdo, "SELECT id, bot_id, subscriber_id, scenario_id, block_id, event_type, created_at, LEFT(message, 160) AS message FROM oca_telegram_bot_scenario_events WHERE event_type LIKE 'runtime_question%' ORDER BY id DESC LIMIT 10", [], 10);
        }
        asr_tg_scenario_trace($label, $summary);
    } catch (Throwable $e) {
        asr_tg_scenario_trace($label . '_failed', ['error' => $e->getMessage()]);
    }
}
