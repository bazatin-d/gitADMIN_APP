<?php
/**
 * Retention/cleanup layer for Telegram scenario journal.
 *
 * This file is intentionally isolated from runtime execution. It does not
 * change how scenarios run; it only counts and removes old journal events
 * when explicitly called from CLI cleanup.
 */

function asr_tg_scenario_retention_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_scenario_retention_policy(array $override = []): array {
    $policy = [
        // Вербозные технические события очередей и промежуточных этапов.
        'technical_days' => 90,
        // Обычная подробная история прохождения сценария.
        'detail_days' => 180,
        // Важные события: старт, остановка, ошибки, критические развилки.
        'important_days' => 365,
        // За один запуск чистим маленькими партиями, чтобы не держать блокировки.
        'batch_limit' => 5000,
    ];
    foreach ($override as $key => $value) {
        if (!array_key_exists($key, $policy)) continue;
        $policy[$key] = max(1, (int)$value);
    }
    $policy['batch_limit'] = max(100, min(20000, (int)$policy['batch_limit']));
    return $policy;
}

function asr_tg_scenario_retention_terminal_statuses(): array {
    return ['finished', 'completed', 'stopped', 'failed', 'error', 'archived'];
}

function asr_tg_scenario_retention_important_types(): array {
    return [
        'runtime_started',
        'runtime_failed',
        'runtime_guard_too_long',
        'runtime_guard_loop_detected',
        'runtime_scenario_missing',
        'runtime_scenario_not_active',
        'runtime_entry_missing',
        'runtime_block_missing',
        'runtime_block_unsupported',
        'runtime_bot_mismatch',
        'runtime_action_stop_scenario',
        'runtime_action_stop_scenario_failed',
        'runtime_question_no_answer',
        'runtime_question_no_answer_queued',
        'runtime_question_no_answer_no_target',
        'runtime_actions_invalid',
        'runtime_condition_invalid',
        'runtime_formula_invalid',
        'runtime_formula_line_failed',
    ];
}

function asr_tg_scenario_retention_technical_types(): array {
    return [
        'runtime_delay_queue_started',
        'runtime_delay_queue_skipped',
        'runtime_delay_queue_failed',
        'runtime_delay_queued',
        'runtime_question_reminder_queued',
        'runtime_question_reminder_sent',
        'runtime_question_due_failed',
        'runtime_actions_next_auto',
        'runtime_actions_next_missing',
        'runtime_next_auto',
        'runtime_card_skipped',
        'runtime_message_html_retry_plain',
        'runtime_message_sent_record_failed',
        'runtime_bot_link_repaired',
        'runtime_bot_link_repair_failed',
        'runtime_legacy_cards_read_failed',
        'runtime_question_card_lookup_failed',
    ];
}

function asr_tg_scenario_retention_in_placeholders(array $values): string {
    if (!$values) return "('')";
    return '(' . implode(',', array_fill(0, count($values), '?')) . ')';
}

function asr_tg_scenario_retention_active_protection_sql(array &$params): string {
    $terminal = asr_tg_scenario_retention_terminal_statuses();
    foreach ($terminal as $status) $params[] = $status;
    return "
        AND NOT EXISTS (
            SELECT 1
            FROM oca_telegram_bot_subscriber_scenarios ss
            WHERE ss.scenario_id = oca_telegram_bot_scenario_events.scenario_id
              AND ss.subscriber_id = oca_telegram_bot_scenario_events.subscriber_id
              AND ss.bot_id = oca_telegram_bot_scenario_events.bot_id
              AND ss.status NOT IN " . asr_tg_scenario_retention_in_placeholders($terminal) . "
            LIMIT 1
        )";
}

function asr_tg_scenario_retention_where(string $bucket, array $policy, array &$params): string {
    $params = [];
    $important = asr_tg_scenario_retention_important_types();
    $technical = asr_tg_scenario_retention_technical_types();

    if ($bucket === 'technical') {
        $params[] = (int)$policy['technical_days'];
        foreach ($technical as $type) $params[] = $type;
        $where = 'created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND event_type IN ' . asr_tg_scenario_retention_in_placeholders($technical);
    } elseif ($bucket === 'detail') {
        $params[] = (int)$policy['detail_days'];
        foreach ($important as $type) $params[] = $type;
        foreach ($technical as $type) $params[] = $type;
        $where = 'created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND event_type NOT IN ' . asr_tg_scenario_retention_in_placeholders($important)
            . ' AND event_type NOT IN ' . asr_tg_scenario_retention_in_placeholders($technical);
    } else {
        $params[] = (int)$policy['important_days'];
        foreach ($important as $type) $params[] = $type;
        $where = 'created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND event_type IN ' . asr_tg_scenario_retention_in_placeholders($important);
    }

    $where .= asr_tg_scenario_retention_active_protection_sql($params);
    return $where;
}

function asr_tg_scenario_retention_count_bucket(PDO $pdo, string $bucket, array $policy): int {
    if (!asr_tg_scenario_retention_table_exists($pdo, 'oca_telegram_bot_scenario_events')) return 0;
    $params = [];
    $where = asr_tg_scenario_retention_where($bucket, $policy, $params);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oca_telegram_bot_scenario_events WHERE ' . $where);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function asr_tg_scenario_retention_total_count(PDO $pdo): int {
    if (!asr_tg_scenario_retention_table_exists($pdo, 'oca_telegram_bot_scenario_events')) return 0;
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM oca_telegram_bot_scenario_events')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_tg_scenario_retention_delete_bucket(PDO $pdo, string $bucket, array $policy): int {
    if (!asr_tg_scenario_retention_table_exists($pdo, 'oca_telegram_bot_scenario_events')) return 0;
    $params = [];
    $where = asr_tg_scenario_retention_where($bucket, $policy, $params);
    $sql = 'DELETE FROM oca_telegram_bot_scenario_events WHERE ' . $where . ' LIMIT ' . (int)$policy['batch_limit'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

function asr_tg_scenario_retention_cleanup(PDO $pdo, array $override = [], bool $dryRun = true): array {
    $policy = asr_tg_scenario_retention_policy($override);
    $result = [
        'ok' => true,
        'mode' => $dryRun ? 'dry-run' : 'run',
        'policy' => $policy,
        'total_before' => asr_tg_scenario_retention_total_count($pdo),
        'technical_candidates' => 0,
        'detail_candidates' => 0,
        'important_candidates' => 0,
        'technical_deleted' => 0,
        'detail_deleted' => 0,
        'important_deleted' => 0,
        'total_after' => 0,
        'errors' => [],
    ];

    if (!asr_tg_scenario_retention_table_exists($pdo, 'oca_telegram_bot_scenario_events')) {
        $result['ok'] = false;
        $result['errors'][] = 'table_not_found: oca_telegram_bot_scenario_events';
        $result['total_after'] = 0;
        return $result;
    }

    try {
        foreach (['technical', 'detail', 'important'] as $bucket) {
            $result[$bucket . '_candidates'] = asr_tg_scenario_retention_count_bucket($pdo, $bucket, $policy);
            if (!$dryRun && $result[$bucket . '_candidates'] > 0) {
                $result[$bucket . '_deleted'] = asr_tg_scenario_retention_delete_bucket($pdo, $bucket, $policy);
            }
        }
        $result['total_after'] = asr_tg_scenario_retention_total_count($pdo);
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['errors'][] = $e->getMessage();
        $result['total_after'] = asr_tg_scenario_retention_total_count($pdo);
    }

    return $result;
}

function asr_tg_scenario_retention_format_result(array $result): string {
    $policy = is_array($result['policy'] ?? null) ? $result['policy'] : [];
    return 'mode=' . (string)($result['mode'] ?? '')
        . ' ok=' . (!empty($result['ok']) ? '1' : '0')
        . ' total_before=' . (int)($result['total_before'] ?? 0)
        . ' technical_days=' . (int)($policy['technical_days'] ?? 0)
        . ' detail_days=' . (int)($policy['detail_days'] ?? 0)
        . ' important_days=' . (int)($policy['important_days'] ?? 0)
        . ' batch_limit=' . (int)($policy['batch_limit'] ?? 0)
        . ' technical_candidates=' . (int)($result['technical_candidates'] ?? 0)
        . ' detail_candidates=' . (int)($result['detail_candidates'] ?? 0)
        . ' important_candidates=' . (int)($result['important_candidates'] ?? 0)
        . ' technical_deleted=' . (int)($result['technical_deleted'] ?? 0)
        . ' detail_deleted=' . (int)($result['detail_deleted'] ?? 0)
        . ' important_deleted=' . (int)($result['important_deleted'] ?? 0)
        . ' total_after=' . (int)($result['total_after'] ?? 0)
        . (!empty($result['errors']) ? ' errors=' . str_replace(["\n", "\r"], ' ', implode('; ', (array)$result['errors'])) : '');
}
