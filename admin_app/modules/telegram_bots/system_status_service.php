<?php
defined('ASR_ADMIN') || exit;

if (!function_exists('asr_tg_system_table_exists')) {
    function asr_tg_system_table_exists(PDO $pdo, string $table): bool {
        if (function_exists('asr_tg_table_exists')) {
            try { return (bool)asr_tg_table_exists($pdo, $table); } catch (Throwable $e) {}
        }
        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('asr_tg_system_scalar')) {
    function asr_tg_system_scalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : $value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('asr_tg_system_status_by_counts')) {
    function asr_tg_system_status_by_counts(int $bad, int $warn = 0): string {
        if ($bad > 0) return 'danger';
        if ($warn > 0) return 'warning';
        return 'ok';
    }
}

if (!function_exists('asr_tg_system_status_label')) {
    function asr_tg_system_status_label(string $status): string {
        return [
            'ok' => 'нормально',
            'warning' => 'внимание',
            'danger' => 'проблема',
            'neutral' => 'нет данных',
        ][$status] ?? 'нет данных';
    }
}

if (!function_exists('asr_tg_system_group_counts')) {
    function asr_tg_system_group_counts(PDO $pdo, string $table, string $column, string $where = '1=1', array $params = []): array {
        if (!asr_tg_system_table_exists($pdo, $table)) return [];
        try {
            $sql = "SELECT {$column} AS k, COUNT(*) AS c FROM {$table} WHERE {$where} GROUP BY {$column}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $out = [];
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $out[(string)($row['k'] ?? '')] = (int)($row['c'] ?? 0);
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('asr_tg_system_recent_rows')) {
    function asr_tg_system_recent_rows(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('asr_tg_system_collect')) {
    function asr_tg_system_collect(PDO $pdo): array {
        $now = date('Y-m-d H:i:s');

        $bots = ['total' => 0, 'active' => 0, 'inactive' => 0, 'status' => 'neutral'];
        if (asr_tg_system_table_exists($pdo, 'oca_telegram_bots')) {
            $bots['total'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bots", [], 0);
            $bots['active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bots WHERE status = 'active'", [], 0);
            $bots['inactive'] = max(0, $bots['total'] - $bots['active']);
            $bots['status'] = $bots['active'] > 0 ? 'ok' : ($bots['total'] > 0 ? 'warning' : 'neutral');
        }

        $broadcast = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients'),
            'counts' => [],
            'due' => 0,
            'retry_due' => 0,
            'stale_processing' => 0,
            'status' => 'neutral',
            'recent_errors' => [],
        ];
        if ($broadcast['available']) {
            $broadcast['counts'] = asr_tg_system_group_counts($pdo, 'oca_telegram_bot_broadcast_recipients', 'status');
            $broadcast['due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bot_broadcasts b ON b.id = r.broadcast_id WHERE r.status = 'pending' AND r.scheduled_at <= ? AND b.status IN ('queued','processing')", [$now], 0);
            $broadcast['retry_due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bot_broadcasts b ON b.id = r.broadcast_id WHERE r.status = 'retry' AND COALESCE(r.next_attempt_at, r.scheduled_at) <= ? AND b.status IN ('queued','processing')", [$now], 0);
            $broadcast['stale_processing'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients WHERE status = 'processing' AND processing_at IS NOT NULL AND processing_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [], 0);
            $broadcast['status'] = asr_tg_system_status_by_counts($broadcast['stale_processing'], ($broadcast['counts']['failed'] ?? 0));
            $broadcast['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT r.id, r.broadcast_id, r.bot_id, r.subscriber_id, r.status, r.last_error, r.updated_at, b.title AS broadcast_title FROM oca_telegram_bot_broadcast_recipients r LEFT JOIN oca_telegram_bot_broadcasts b ON b.id = r.broadcast_id WHERE r.status IN ('failed','retry') AND COALESCE(r.last_error,'') <> '' ORDER BY r.updated_at DESC, r.id DESC LIMIT 10");
        }

        $sendQueue = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_send_queue'),
            'stats' => [],
            'status' => 'neutral',
            'recent_errors' => [],
        ];
        if ($sendQueue['available']) {
            if (function_exists('asr_tg_send_queue_stats')) {
                try { $sendQueue['stats'] = asr_tg_send_queue_stats($pdo, false); } catch (Throwable $e) { $sendQueue['stats'] = []; }
            }
            if (!$sendQueue['stats']) {
                $sendQueue['stats'] = ['available' => true, 'total' => 0] + asr_tg_system_group_counts($pdo, 'oca_telegram_bot_send_queue', 'status');
            }
            $sendQueue['status'] = asr_tg_system_status_by_counts((int)($sendQueue['stats']['stale_processing'] ?? 0), (int)($sendQueue['stats']['failed'] ?? 0) + (int)($sendQueue['stats']['retry'] ?? 0));
            $sendQueue['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT id, bot_id, subscriber_id, scenario_id, block_id, task_type, status, attempts, last_error, updated_at FROM oca_telegram_bot_send_queue WHERE status IN ('failed','retry') AND COALESCE(last_error,'') <> '' ORDER BY updated_at DESC, id DESC LIMIT 10");
        }

        $scenarios = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios'),
            'counts' => [],
            'active' => 0,
            'waiting' => 0,
            'due' => 0,
            'stale_active' => 0,
            'errors' => 0,
            'status' => 'neutral',
            'recent_errors' => [],
        ];
        if ($scenarios['available']) {
            $scenarios['counts'] = asr_tg_system_group_counts($pdo, 'oca_telegram_bot_subscriber_scenarios', 'status');
            $scenarios['active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status IN ('active','running','waiting_answer','delayed','scheduled','queued','processing')", [], 0);
            $scenarios['waiting'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status IN ('waiting_answer','delayed','scheduled')", [], 0);
            $scenarios['due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status IN ('delayed','scheduled','queued') AND next_run_at IS NOT NULL AND next_run_at <= ?", [$now], 0);
            $scenarios['stale_active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status IN ('active','running','processing') AND updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR) AND next_run_at IS NULL", [], 0);
            $scenarios['errors'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'error'", [], 0);
            $scenarios['status'] = asr_tg_system_status_by_counts($scenarios['errors'] + $scenarios['stale_active'], $scenarios['due']);
            $scenarios['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT ss.id, ss.scenario_id, ss.bot_id, ss.subscriber_id, ss.current_block_id, ss.status, ss.last_error, ss.updated_at, s.title AS scenario_title FROM oca_telegram_bot_subscriber_scenarios ss LEFT JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id WHERE ss.status = 'error' OR COALESCE(ss.last_error,'') <> '' ORDER BY ss.updated_at DESC, ss.id DESC LIMIT 10");
        }

        $journal = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_scenario_events'),
            'total' => 0,
            'older_90' => 0,
            'older_180' => 0,
            'older_365' => 0,
            'last_event_at' => '',
            'status' => 'neutral',
        ];
        if ($journal['available']) {
            $journal['total'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events", [], 0);
            $journal['older_90'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)", [], 0);
            $journal['older_180'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)", [], 0);
            $journal['older_365'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)", [], 0);
            $journal['last_event_at'] = (string)asr_tg_system_scalar($pdo, "SELECT MAX(created_at) FROM oca_telegram_bot_scenario_events", [], '');
            $journal['status'] = $journal['older_365'] > 0 ? 'warning' : 'ok';
        }

        $metrika = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_yandex_metrika_events'),
            'counts' => [],
            'stale_processing' => 0,
            'status' => 'neutral',
            'recent_errors' => [],
        ];
        if ($metrika['available']) {
            $metrika['counts'] = asr_tg_system_group_counts($pdo, 'oca_telegram_bot_yandex_metrika_events', 'status');
            $metrika['stale_processing'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_yandex_metrika_events WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [], 0);
            $metrika['status'] = asr_tg_system_status_by_counts($metrika['stale_processing'], (int)($metrika['counts']['failed'] ?? 0) + (int)($metrika['counts']['retry'] ?? 0));
            $metrika['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT id, bot_id, subscriber_id, scenario_id, block_id, status, attempts, last_error, updated_at FROM oca_telegram_bot_yandex_metrika_events WHERE status IN ('failed','retry') AND COALESCE(last_error,'') <> '' ORDER BY updated_at DESC, id DESC LIMIT 10");
        }

        $logs = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_logs'),
            'errors_24h' => 0,
            'recent_errors' => [],
            'status' => 'neutral',
        ];
        if ($logs['available']) {
            $logs['errors_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs WHERE level IN ('error','critical') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", [], 0);
            $logs['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT id, bot_id, level, event_type, message, created_at FROM oca_telegram_bot_logs WHERE level IN ('error','critical') ORDER BY created_at DESC, id DESC LIMIT 10");
            $logs['status'] = $logs['errors_24h'] > 0 ? 'warning' : 'ok';
        }

        return [
            'generated_at' => $now,
            'bots' => $bots,
            'broadcast' => $broadcast,
            'send_queue' => $sendQueue,
            'scenarios' => $scenarios,
            'journal' => $journal,
            'metrika' => $metrika,
            'logs' => $logs,
        ];
    }
}
