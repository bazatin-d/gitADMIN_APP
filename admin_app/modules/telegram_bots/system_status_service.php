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

if (!function_exists('asr_tg_system_platform')) {
    function asr_tg_system_platform(string $platform): string {
        $platform = strtolower(trim($platform));
        return $platform === 'vk' ? 'vk' : 'telegram';
    }
}

if (!function_exists('asr_tg_system_platform_label')) {
    function asr_tg_system_platform_label(string $platform): string {
        return asr_tg_system_platform($platform) === 'vk' ? 'VK' : 'Telegram';
    }
}

if (!function_exists('asr_tg_system_platform_where')) {
    function asr_tg_system_platform_where(string $alias, string $platform): array {
        $platform = asr_tg_system_platform($platform);
        $expr = "LOWER(COALESCE(NULLIF({$alias}.channel_type,''),'telegram'))";
        return [$expr . ' = ?', [$platform]];
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
    function asr_tg_system_collect(PDO $pdo, string $platform = 'telegram'): array {
        $now = date('Y-m-d H:i:s');
        $platform = asr_tg_system_platform($platform);
        [$botWhere, $botParams] = asr_tg_system_platform_where('b', $platform);

        $bots = ['total' => 0, 'active' => 0, 'inactive' => 0, 'status' => 'neutral'];
        if (asr_tg_system_table_exists($pdo, 'oca_telegram_bots')) {
            $bots['total'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bots b WHERE {$botWhere}", $botParams, 0);
            $bots['active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bots b WHERE {$botWhere} AND b.status = 'active'", $botParams, 0);
            $bots['inactive'] = max(0, $bots['total'] - $bots['active']);
            $bots['status'] = $bots['active'] > 0 ? 'ok' : ($bots['total'] > 0 ? 'warning' : 'neutral');
        }

        $broadcast = [
            'available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'),
            'counts' => [], 'due' => 0, 'retry_due' => 0, 'stale_processing' => 0, 'status' => 'neutral', 'recent_errors' => [],
        ];
        if ($broadcast['available']) {
            $broadcast['counts'] = asr_tg_system_recent_rows($pdo, "SELECT r.status AS k, COUNT(*) AS c FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bots b ON b.id = r.bot_id WHERE {$botWhere} GROUP BY r.status", $botParams);
            $broadcast['counts'] = array_column($broadcast['counts'], 'c', 'k');
            $broadcast['due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id JOIN oca_telegram_bots b ON b.id = r.bot_id WHERE {$botWhere} AND r.status = 'pending' AND r.scheduled_at <= ? AND br.status IN ('queued','processing')", array_merge($botParams, [$now]), 0);
            $broadcast['retry_due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id JOIN oca_telegram_bots b ON b.id = r.bot_id WHERE {$botWhere} AND r.status = 'retry' AND COALESCE(r.next_attempt_at, r.scheduled_at) <= ? AND br.status IN ('queued','processing')", array_merge($botParams, [$now]), 0);
            $broadcast['stale_processing'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r JOIN oca_telegram_bots b ON b.id = r.bot_id WHERE {$botWhere} AND r.status = 'processing' AND r.processing_at IS NOT NULL AND r.processing_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", $botParams, 0);
            $broadcast['status'] = asr_tg_system_status_by_counts($broadcast['stale_processing'], (int)($broadcast['counts']['failed'] ?? 0));
            $broadcast['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT r.id, r.broadcast_id, r.bot_id, r.subscriber_id, r.status, r.last_error, r.updated_at, br.title AS broadcast_title FROM oca_telegram_bot_broadcast_recipients r LEFT JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id JOIN oca_telegram_bots b ON b.id = r.bot_id WHERE {$botWhere} AND r.status IN ('failed','retry') AND COALESCE(r.last_error,'') <> '' ORDER BY r.updated_at DESC, r.id DESC LIMIT 10", $botParams);
        }

        $sendQueue = ['available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_send_queue') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'), 'stats' => [], 'status' => 'neutral', 'recent_errors' => []];
        if ($sendQueue['available']) {
            $countsRows = asr_tg_system_recent_rows($pdo, "SELECT q.status AS k, COUNT(*) AS c FROM oca_telegram_bot_send_queue q JOIN oca_telegram_bots b ON b.id = q.bot_id WHERE {$botWhere} GROUP BY q.status", $botParams);
            $counts = array_column($countsRows, 'c', 'k');
            $counts['due_pending'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_send_queue q JOIN oca_telegram_bots b ON b.id = q.bot_id WHERE {$botWhere} AND q.status = 'pending' AND (q.scheduled_at IS NULL OR q.scheduled_at <= ?)", array_merge($botParams, [$now]), 0);
            $counts['stale_processing'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_send_queue q JOIN oca_telegram_bots b ON b.id = q.bot_id WHERE {$botWhere} AND q.status = 'processing' AND q.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", $botParams, 0);
            $sendQueue['stats'] = $counts;
            $sendQueue['status'] = asr_tg_system_status_by_counts((int)($counts['stale_processing'] ?? 0), (int)($counts['failed'] ?? 0) + (int)($counts['retry'] ?? 0));
            $sendQueue['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT q.id, q.bot_id, q.subscriber_id, q.scenario_id, q.block_id, q.task_type, q.status, q.attempts, q.last_error, q.updated_at FROM oca_telegram_bot_send_queue q JOIN oca_telegram_bots b ON b.id = q.bot_id WHERE {$botWhere} AND q.status IN ('failed','retry') AND COALESCE(q.last_error,'') <> '' ORDER BY q.updated_at DESC, q.id DESC LIMIT 10", $botParams);
        }

        $scenarios = ['available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'), 'counts' => [], 'active' => 0, 'waiting' => 0, 'due' => 0, 'stale_active' => 0, 'errors' => 0, 'status' => 'neutral', 'recent_errors' => []];
        if ($scenarios['available']) {
            $rows = asr_tg_system_recent_rows($pdo, "SELECT ss.status AS k, COUNT(*) AS c FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} GROUP BY ss.status", $botParams);
            $scenarios['counts'] = array_column($rows, 'c', 'k');
            $scenarios['active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND ss.status IN ('active','running','waiting_answer','delayed','scheduled','queued','processing')", $botParams, 0);
            $scenarios['waiting'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND ss.status IN ('waiting_answer','delayed','scheduled')", $botParams, 0);
            $scenarios['due'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND ss.status IN ('delayed','scheduled','queued') AND ss.next_run_at IS NOT NULL AND ss.next_run_at <= ?", array_merge($botParams, [$now]), 0);
            $scenarios['stale_active'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND ss.status IN ('active','running','processing') AND ss.updated_at < DATE_SUB(NOW(), INTERVAL 6 HOUR) AND ss.next_run_at IS NULL", $botParams, 0);
            $scenarios['errors'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios ss JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND ss.status = 'error'", $botParams, 0);
            $scenarios['status'] = asr_tg_system_status_by_counts($scenarios['errors'] + $scenarios['stale_active'], $scenarios['due']);
            $scenarios['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT ss.id, ss.scenario_id, ss.bot_id, ss.subscriber_id, ss.current_block_id, ss.status, ss.last_error, ss.updated_at, s.title AS scenario_title FROM oca_telegram_bot_subscriber_scenarios ss LEFT JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id JOIN oca_telegram_bots b ON b.id = ss.bot_id WHERE {$botWhere} AND (ss.status = 'error' OR COALESCE(ss.last_error,'') <> '') ORDER BY ss.updated_at DESC, ss.id DESC LIMIT 10", $botParams);
        }

        $journal = ['available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_scenario_events') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'), 'total' => 0, 'older_90' => 0, 'older_180' => 0, 'older_365' => 0, 'last_event_at' => '', 'status' => 'neutral', 'recent_events' => []];
        if ($journal['available']) {
            $journal['total'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events ev JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere}", $botParams, 0);
            $journal['older_90'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events ev JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere} AND ev.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)", $botParams, 0);
            $journal['older_180'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events ev JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere} AND ev.created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)", $botParams, 0);
            $journal['older_365'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_scenario_events ev JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere} AND ev.created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)", $botParams, 0);
            $journal['last_event_at'] = (string)asr_tg_system_scalar($pdo, "SELECT MAX(ev.created_at) FROM oca_telegram_bot_scenario_events ev JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere}", $botParams, '');
            $journal['recent_events'] = asr_tg_system_recent_rows($pdo, "SELECT ev.id, ev.bot_id, ev.subscriber_id, ev.scenario_id, ev.block_id, ev.event_type, ev.event_text, ev.created_at, s.title AS scenario_title FROM oca_telegram_bot_scenario_events ev LEFT JOIN oca_telegram_bot_scenarios s ON s.id = ev.scenario_id JOIN oca_telegram_bots b ON b.id = ev.bot_id WHERE {$botWhere} AND ev.event_type IN ('runtime_start','runtime_callback_goto','runtime_question_answer_received','runtime_question_text_received','runtime_question_waiting','runtime_finished','runtime_error') ORDER BY ev.created_at DESC, ev.id DESC LIMIT 10", $botParams);
            $journal['status'] = $journal['older_365'] > 0 ? 'warning' : 'ok';
        }

        $metrika = ['available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_yandex_metrika_events') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'), 'counts' => [], 'stale_processing' => 0, 'status' => 'neutral', 'recent_errors' => []];
        if ($metrika['available']) {
            $rows = asr_tg_system_recent_rows($pdo, "SELECT y.status AS k, COUNT(*) AS c FROM oca_telegram_bot_yandex_metrika_events y JOIN oca_telegram_bots b ON b.id = y.bot_id WHERE {$botWhere} GROUP BY y.status", $botParams);
            $metrika['counts'] = array_column($rows, 'c', 'k');
            $metrika['stale_processing'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_yandex_metrika_events y JOIN oca_telegram_bots b ON b.id = y.bot_id WHERE {$botWhere} AND y.status = 'processing' AND y.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)", $botParams, 0);
            $metrika['status'] = asr_tg_system_status_by_counts($metrika['stale_processing'], (int)($metrika['counts']['failed'] ?? 0) + (int)($metrika['counts']['retry'] ?? 0));
            $metrika['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT y.id, y.bot_id, y.subscriber_id, y.scenario_id, y.block_id, y.status, y.attempts, y.last_error, y.updated_at FROM oca_telegram_bot_yandex_metrika_events y JOIN oca_telegram_bots b ON b.id = y.bot_id WHERE {$botWhere} AND y.status IN ('failed','retry') AND COALESCE(y.last_error,'') <> '' ORDER BY y.updated_at DESC, y.id DESC LIMIT 10", $botParams);
        }

        $logs = ['available' => asr_tg_system_table_exists($pdo, 'oca_telegram_bot_logs') && asr_tg_system_table_exists($pdo, 'oca_telegram_bots'), 'errors_24h' => 0, 'keyword_matches_24h' => 0, 'keyword_no_match_24h' => 0, 'keyword_no_active_triggers_24h' => 0, 'keyword_skipped_24h' => 0, 'keyword_start_failed_24h' => 0, 'payload_clicks_24h' => 0, 'recent_errors' => [], 'recent_scenario_logs' => [], 'recent_keyword_diagnostics' => [], 'status' => 'neutral'];
        if ($logs['available']) {
            $logs['errors_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.level IN ('error','critical') AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['keyword_matches_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type = 'scenario_keyword_trigger_matched' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['payload_clicks_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type = 'vk_scenario_button_payload_received' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['keyword_no_match_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type = 'scenario_keyword_no_match' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['keyword_no_active_triggers_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type = 'scenario_keyword_no_active_triggers' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['keyword_skipped_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type IN ('scenario_keyword_skipped_active_state','scenario_keyword_skipped_blocked_state') AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['keyword_start_failed_24h'] = (int)asr_tg_system_scalar($pdo, "SELECT COUNT(*) FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type = 'scenario_keyword_start_failed' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", $botParams, 0);
            $logs['recent_errors'] = asr_tg_system_recent_rows($pdo, "SELECT l.id, l.bot_id, l.level, l.event_type, l.message, l.created_at FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.level IN ('error','critical') ORDER BY l.created_at DESC, l.id DESC LIMIT 10", $botParams);
            $logs['recent_scenario_logs'] = asr_tg_system_recent_rows($pdo, "SELECT l.id, l.bot_id, l.level, l.event_type, l.message, l.context_json, l.created_at FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND (l.event_type LIKE '%scenario%' OR l.event_type LIKE '%runtime%' OR l.event_type LIKE 'vk_scenario%') ORDER BY l.created_at DESC, l.id DESC LIMIT 12", $botParams);
            $logs['recent_keyword_diagnostics'] = asr_tg_system_recent_rows($pdo, "SELECT l.id, l.bot_id, l.level, l.event_type, l.message, l.context_json, l.created_at FROM oca_telegram_bot_logs l JOIN oca_telegram_bots b ON b.id = l.bot_id WHERE {$botWhere} AND l.event_type IN ('scenario_keyword_trigger_matched','scenario_keyword_no_active_triggers','scenario_keyword_no_match','scenario_keyword_skipped_active_state','scenario_keyword_skipped_blocked_state','scenario_keyword_start_failed','vk_scenario_button_payload_received','vk_scenario_runtime_failed') ORDER BY l.created_at DESC, l.id DESC LIMIT 14", $botParams);
            $logs['status'] = $logs['errors_24h'] > 0 ? 'warning' : 'ok';
        }

        return [
            'generated_at' => $now,
            'platform' => $platform,
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

if (!function_exists('asr_tg_system_render_panel')) {
    function asr_tg_system_render_panel(array $sys, callable $h): string {
        $platform = asr_tg_system_platform((string)($sys['platform'] ?? 'telegram'));
        $label = asr_tg_system_platform_label($platform);
        $fmt = static function($value): string { return number_format((int)$value, 0, '.', ' '); };
        $shortDate = static function($value): string {
            $value = trim((string)$value);
            if ($value === '') return '—';
            $ts = strtotime($value);
            return $ts ? date('d.m.Y H:i', $ts) : $value;
        };
        $statusClass = static function(string $status): string {
            return [
                'ok' => 'tg-system-badge tg-system-badge--ok',
                'warning' => 'tg-system-badge tg-system-badge--warning',
                'danger' => 'tg-system-badge tg-system-badge--danger',
                'neutral' => 'tg-system-badge tg-system-badge--neutral',
            ][$status] ?? 'tg-system-badge tg-system-badge--neutral';
        };
        $renderStatus = static function(string $status) use ($h, $statusClass): string {
            return '<span class="' . $statusClass($status) . '">' . $h(asr_tg_system_status_label($status)) . '</span>';
        };
        $renderCounts = static function(array $counts) use ($h, $fmt): string {
            if (!$counts) return '<span class="tg-system-muted">нет данных</span>';
            $labels = [
                'pending' => 'ожидает', 'processing' => 'выполняется', 'retry' => 'повтор', 'sent' => 'отправлено', 'failed' => 'ошибка', 'skipped' => 'пропущено',
                'active' => 'активен', 'running' => 'выполняется', 'waiting_answer' => 'ждёт ответ', 'delayed' => 'задержка', 'scheduled' => 'расписание', 'finished' => 'завершён', 'stopped' => 'остановлен', 'error' => 'ошибка', 'queued' => 'очередь',
            ];
            $html = '<div class="tg-system-chips">';
            foreach ($counts as $k => $v) {
                $label = $labels[(string)$k] ?? (string)$k;
                $html .= '<span class="tg-system-chip"><b>' . $h($label) . '</b>' . $h($fmt($v)) . '</span>';
            }
            return $html . '</div>';
        };
        $contextSummary = static function(array $row) use ($h): string {
            $json = trim((string)($row['context_json'] ?? ''));
            if ($json === '') return '';
            $ctx = json_decode($json, true);
            if (!is_array($ctx)) return '';
            $parts = [];
            $labels = [
                'reason' => 'причина',
                'matched_phrase' => 'фраза',
                'scenario_id' => 'сценарий',
                'scenario_title' => 'сценарий',
                'subscriber_id' => 'подписчик',
                'callback_data' => 'payload',
                'text_preview' => 'текст',
                'active_keyword_scenarios' => 'активных сценариев',
            ];
            foreach ($labels as $key => $label) {
                if (!array_key_exists($key, $ctx)) continue;
                $value = $ctx[$key];
                if (is_array($value) || is_object($value)) continue;
                $value = trim((string)$value);
                if ($value === '') continue;
                if ($key === 'scenario_id') $value = '#' . ltrim($value, '#');
                $parts[] = $label . ': ' . mb_substr($value, 0, 120, 'UTF-8');
            }
            return $parts ? implode(' · ', $parts) : '';
        };
        $eventLabel = static function(string $event): string {
            return [
                'scenario_keyword_trigger_matched' => 'ключевое слово совпало',
                'scenario_keyword_no_active_triggers' => 'нет активных ключевых запусков',
                'scenario_keyword_no_match' => 'ключевое слово не совпало',
                'scenario_keyword_skipped_active_state' => 'подписчик уже в сценарии',
                'scenario_keyword_skipped_blocked_state' => 'подписчик ждёт сценарий',
                'scenario_keyword_start_failed' => 'сценарий не запустился',
                'vk_scenario_button_payload_received' => 'нажата VK-кнопка',
                'vk_scenario_runtime_failed' => 'ошибка VK runtime',
            ][$event] ?? $event;
        };
        $cardStatus = static function(array $part): string { return (string)($part['status'] ?? 'neutral'); };
        $errors = array_merge($sys['send_queue']['recent_errors'] ?? [], $sys['broadcast']['recent_errors'] ?? [], $sys['metrika']['recent_errors'] ?? [], $sys['logs']['recent_errors'] ?? []);
        $errors = array_slice($errors, 0, 12);
        ob_start();
        ?>
        <div class="tg-system-platform-head">
            <div>
                <h4><?php echo $h($label); ?>: диагностика</h4>
                <p><?php echo $platform === 'vk' ? 'Отдельный контроль VK-каналов, VK-сценариев, ключевых слов и payload-кнопок.' : 'Контроль Telegram-каналов, сценариев, очередей и Telegram/API-ошибок.'; ?></p>
            </div>
            <span class="tg-system-platform-pill"><?php echo $h($label); ?></span>
        </div>
        <div class="tg-system-grid">
            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Каналы</h4><p>Подключённые <?php echo $h($label); ?>-каналы и их активность.</p></div><?php echo $renderStatus($cardStatus($sys['bots'])); ?></div>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['total'] ?? 0)); ?></div><div class="tg-system-metric-label">всего</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['active'] ?? 0)); ?></div><div class="tg-system-metric-label">активные</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['inactive'] ?? 0)); ?></div><div class="tg-system-metric-label">неактивные</div></div></div>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Сценарии</h4><p>Прохождения сценариев только по платформе <?php echo $h($label); ?>.</p></div><?php echo $renderStatus($cardStatus($sys['scenarios'])); ?></div>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['scenarios']['active'] ?? 0)); ?></div><div class="tg-system-metric-label">активные</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['scenarios']['due'] ?? 0)); ?></div><div class="tg-system-metric-label">пора продолжить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt(($sys['scenarios']['errors'] ?? 0) + ($sys['scenarios']['stale_active'] ?? 0))); ?></div><div class="tg-system-metric-label">проблемы</div></div></div>
                <?php echo $renderCounts($sys['scenarios']['counts'] ?? []); ?>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Cron и очереди</h4><p>Очередь сценариев, задержек, вопросов и служебных задач.</p></div><?php echo $renderStatus($cardStatus($sys['send_queue'])); ?></div>
                <?php $sq = $sys['send_queue']['stats'] ?? []; ?>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['due_pending'] ?? $sq['pending'] ?? 0)); ?></div><div class="tg-system-metric-label">пора выполнить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['retry'] ?? 0)); ?></div><div class="tg-system-metric-label">повтор</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div></div>
                <?php echo $renderCounts(['pending'=>$sq['pending'] ?? 0,'processing'=>$sq['processing'] ?? 0,'retry'=>$sq['retry'] ?? 0,'failed'=>$sq['failed'] ?? 0,'skipped'=>$sq['skipped'] ?? 0]); ?>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Рассылки</h4><p>Получатели рассылок и проблемные отправки.</p></div><?php echo $renderStatus($cardStatus($sys['broadcast'])); ?></div>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['due'] ?? 0)); ?></div><div class="tg-system-metric-label">к отправке</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['retry_due'] ?? 0)); ?></div><div class="tg-system-metric-label">повторить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div></div>
                <?php echo $renderCounts($sys['broadcast']['counts'] ?? []); ?>
            </article>

            <?php if ($platform === 'vk'): ?>
            <article class="tg-system-card tg-system-card--wide">
                <div class="tg-system-card-head"><div><h4>VK-сценарии</h4><p>Ключевые слова, payload-кнопки и причины, если запуск не произошёл.</p></div><?php echo $renderStatus($cardStatus($sys['logs'])); ?></div>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['logs']['keyword_matches_24h'] ?? 0)); ?></div><div class="tg-system-metric-label">запусков по словам / 24ч</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['logs']['payload_clicks_24h'] ?? 0)); ?></div><div class="tg-system-metric-label">payload-кнопок / 24ч</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt(($sys['logs']['keyword_start_failed_24h'] ?? 0) + ($sys['logs']['errors_24h'] ?? 0))); ?></div><div class="tg-system-metric-label">ошибок запуска / 24ч</div></div></div>
                <div class="tg-system-chips"><span class="tg-system-chip"><b>не совпало</b><?php echo $h($fmt($sys['logs']['keyword_no_match_24h'] ?? 0)); ?></span><span class="tg-system-chip"><b>нет активных запусков</b><?php echo $h($fmt($sys['logs']['keyword_no_active_triggers_24h'] ?? 0)); ?></span><span class="tg-system-chip"><b>пропущено</b><?php echo $h($fmt($sys['logs']['keyword_skipped_24h'] ?? 0)); ?></span></div>
                <div class="tg-system-list"><?php foreach (($sys['logs']['recent_keyword_diagnostics'] ?? []) as $row): ?><?php $ctxText = $contextSummary($row); ?><div class="tg-system-row"><div class="tg-system-row-top"><span>#<?php echo (int)$row['id']; ?> · <?php echo $h($eventLabel((string)($row['event_type'] ?? ''))); ?></span><span><?php echo $h($shortDate($row['created_at'] ?? '')); ?></span></div><div class="tg-system-row-text"><?php echo $h($row['message'] ?? 'Без текста'); ?><?php if ($ctxText !== ''): ?><br><?php echo $h($ctxText); ?><?php endif; ?></div></div><?php endforeach; ?><?php if (empty($sys['logs']['recent_keyword_diagnostics'])): ?><div class="tg-system-muted">Диагностических событий VK-сценариев пока нет.</div><?php endif; ?></div>
            </article>
            <?php endif; ?>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Журнал сценариев</h4><p>История прохождения сценариев по платформе.</p></div><?php echo $renderStatus($cardStatus($sys['journal'])); ?></div>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['total'] ?? 0)); ?></div><div class="tg-system-metric-label">записей</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['older_180'] ?? 0)); ?></div><div class="tg-system-metric-label">старше 180 дней</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['older_365'] ?? 0)); ?></div><div class="tg-system-metric-label">старше 365 дней</div></div></div>
                <p>Последнее событие: <?php echo $h($shortDate($sys['journal']['last_event_at'] ?? '')); ?></p>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Яндекс.Метрика</h4><p>Очередь передачи событий офлайн-конверсий.</p></div><?php echo $renderStatus($cardStatus($sys['metrika'])); ?></div>
                <?php echo $renderCounts($sys['metrika']['counts'] ?? []); ?>
                <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['metrika']['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt(($sys['metrika']['counts']['failed'] ?? 0) + ($sys['metrika']['counts']['retry'] ?? 0))); ?></div><div class="tg-system-metric-label">ошибки/повтор</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['metrika']['counts']['sent'] ?? 0)); ?></div><div class="tg-system-metric-label">отправлено</div></div></div>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Последние ошибки сценариев</h4><p>Ошибки состояния подписчиков в сценариях.</p></div></div>
                <div class="tg-system-list"><?php foreach (($sys['scenarios']['recent_errors'] ?? []) as $row): ?><div class="tg-system-row"><div class="tg-system-row-top"><span>#<?php echo (int)$row['id']; ?> · <?php echo $h($row['scenario_title'] ?? ('сценарий #' . ($row['scenario_id'] ?? ''))); ?></span><span><?php echo $h($shortDate($row['updated_at'] ?? '')); ?></span></div><div class="tg-system-row-text"><?php echo $h($row['last_error'] ?? 'Без текста ошибки'); ?><?php if (!empty($row['current_block_id'])): ?> · блок #<?php echo (int)$row['current_block_id']; ?><?php endif; ?></div></div><?php endforeach; ?><?php if (empty($sys['scenarios']['recent_errors'])): ?><div class="tg-system-muted">Ошибок сценариев не найдено.</div><?php endif; ?></div>
            </article>

            <article class="tg-system-card">
                <div class="tg-system-card-head"><div><h4>Последние ошибки <?php echo $h($label); ?>/API</h4><p>Ошибки рассылок, очереди и служебных логов.</p></div><?php echo $renderStatus($cardStatus($sys['logs'])); ?></div>
                <div class="tg-system-list"><?php foreach ($errors as $row): ?><div class="tg-system-row"><div class="tg-system-row-top"><span>#<?php echo (int)($row['id'] ?? 0); ?> · <?php echo $h($row['status'] ?? $row['level'] ?? 'ошибка'); ?></span><span><?php echo $h($shortDate($row['updated_at'] ?? $row['created_at'] ?? '')); ?></span></div><div class="tg-system-row-text"><?php echo $h($row['last_error'] ?? $row['message'] ?? 'Без текста ошибки'); ?></div></div><?php endforeach; ?><?php if (!$errors): ?><div class="tg-system-muted">Свежих ошибок не найдено.</div><?php endif; ?></div>
            </article>
        </div>
        <?php
        return trim((string)ob_get_clean());
    }
}
