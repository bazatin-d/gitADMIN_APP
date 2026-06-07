<?php
defined('ASR_ADMIN') || exit;

/**
 * Lightweight diagnostics for Telegram sending queues.
 *
 * This file intentionally does not change queue state. It is the first small
 * layer for the future unified Telegram send queue, so cron/service files do
 * not collect more reporting logic inside large runtime functions.
 */

function asr_tg_queue_diag_status_keys(): array {
    return ['pending', 'processing', 'retry', 'sent', 'failed', 'skipped'];
}

function asr_tg_queue_diag_empty_counts(): array {
    $counts = [];
    foreach (asr_tg_queue_diag_status_keys() as $status) {
        $counts[$status] = 0;
    }
    return $counts;
}

function asr_tg_queue_diag_broadcast_snapshot(PDO $pdo, int $broadcastId = 0, int $staleMinutes = 10): array {
    $broadcastId = max(0, $broadcastId);
    $staleMinutes = max(1, min(120, $staleMinutes));

    $empty = [
        'available' => false,
        'broadcast_id' => $broadcastId,
        'total' => 0,
        'statuses' => asr_tg_queue_diag_empty_counts(),
        'due_pending' => 0,
        'future_pending' => 0,
        'due_retry' => 0,
        'future_retry' => 0,
        'stale_processing' => 0,
        'sent_today' => 0,
        'failed_total' => 0,
        'by_bot' => [],
    ];

    if (!function_exists('asr_tg_table_exists') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients')) {
        return $empty;
    }

    $now = function_exists('asr_tg_broadcast_now_sql') ? asr_tg_broadcast_now_sql() : date('Y-m-d H:i:s');
    $where = '1=1';
    $params = [];
    if ($broadcastId > 0) {
        $where .= ' AND r.broadcast_id = ?';
        $params[] = $broadcastId;
    }

    $snapshot = $empty;
    $snapshot['available'] = true;

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(r.status, ''), 'unknown') AS status, COUNT(*) AS cnt
            FROM oca_telegram_bot_broadcast_recipients r
            WHERE {$where}
            GROUP BY COALESCE(NULLIF(r.status, ''), 'unknown')");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)($row['status'] ?? 'unknown');
            $cnt = (int)($row['cnt'] ?? 0);
            if (!array_key_exists($status, $snapshot['statuses'])) {
                $snapshot['statuses'][$status] = 0;
            }
            $snapshot['statuses'][$status] += $cnt;
            $snapshot['total'] += $cnt;
        }

        $dueParams = array_merge([$now], $params);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r WHERE r.status = 'pending' AND r.scheduled_at <= ? AND {$where}");
        $stmt->execute($dueParams);
        $snapshot['due_pending'] = (int)$stmt->fetchColumn();

        $futureParams = array_merge([$now], $params);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r WHERE r.status = 'pending' AND r.scheduled_at > ? AND {$where}");
        $stmt->execute($futureParams);
        $snapshot['future_pending'] = (int)$stmt->fetchColumn();

        if (function_exists('asr_tg_column_exists') && asr_tg_column_exists($pdo, 'oca_telegram_bot_broadcast_recipients', 'next_attempt_at')) {
            $retryDueParams = array_merge([$now], $params);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r WHERE r.status = 'retry' AND COALESCE(r.next_attempt_at, r.scheduled_at) <= ? AND {$where}");
            $stmt->execute($retryDueParams);
            $snapshot['due_retry'] = (int)$stmt->fetchColumn();

            $retryFutureParams = array_merge([$now], $params);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r WHERE r.status = 'retry' AND COALESCE(r.next_attempt_at, r.scheduled_at) > ? AND {$where}");
            $stmt->execute($retryFutureParams);
            $snapshot['future_retry'] = (int)$stmt->fetchColumn();
        }

        $staleParams = $params;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r
            WHERE r.status = 'processing'
              AND (r.processing_at IS NULL OR r.processing_at < DATE_SUB(NOW(), INTERVAL {$staleMinutes} MINUTE))
              AND {$where}");
        $stmt->execute($staleParams);
        $snapshot['stale_processing'] = (int)$stmt->fetchColumn();

        $sentTodayParams = $params;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcast_recipients r
            WHERE r.status = 'sent' AND r.sent_at >= CURDATE() AND {$where}");
        $stmt->execute($sentTodayParams);
        $snapshot['sent_today'] = (int)$stmt->fetchColumn();
        $snapshot['failed_total'] = (int)($snapshot['statuses']['failed'] ?? 0);

        $stmt = $pdo->prepare("SELECT r.bot_id, COALESCE(NULLIF(b.title, ''), CONCAT('bot #', r.bot_id)) AS bot_title,
                   COALESCE(NULLIF(r.status, ''), 'unknown') AS status, COUNT(*) AS cnt
            FROM oca_telegram_bot_broadcast_recipients r
            LEFT JOIN oca_telegram_bots b ON b.id = r.bot_id
            WHERE {$where}
            GROUP BY r.bot_id, bot_title, COALESCE(NULLIF(r.status, ''), 'unknown')
            ORDER BY r.bot_id ASC");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $botId = (int)($row['bot_id'] ?? 0);
            if ($botId <= 0) continue;
            if (!isset($snapshot['by_bot'][$botId])) {
                $snapshot['by_bot'][$botId] = [
                    'bot_id' => $botId,
                    'title' => (string)($row['bot_title'] ?? ('bot #' . $botId)),
                    'total' => 0,
                    'statuses' => asr_tg_queue_diag_empty_counts(),
                ];
            }
            $status = (string)($row['status'] ?? 'unknown');
            $cnt = (int)($row['cnt'] ?? 0);
            if (!array_key_exists($status, $snapshot['by_bot'][$botId]['statuses'])) {
                $snapshot['by_bot'][$botId]['statuses'][$status] = 0;
            }
            $snapshot['by_bot'][$botId]['statuses'][$status] += $cnt;
            $snapshot['by_bot'][$botId]['total'] += $cnt;
        }
    } catch (Throwable $e) {
        $snapshot['error'] = $e->getMessage();
    }

    return $snapshot;
}

function asr_tg_queue_diag_format_compact(array $snapshot, string $prefix = 'queue'): string {
    if (empty($snapshot['available'])) {
        return $prefix . '_available=0';
    }

    $statuses = is_array($snapshot['statuses'] ?? null) ? $snapshot['statuses'] : [];
    $parts = [
        $prefix . '_available=1',
        $prefix . '_total=' . (int)($snapshot['total'] ?? 0),
        $prefix . '_pending=' . (int)($statuses['pending'] ?? 0),
        $prefix . '_processing=' . (int)($statuses['processing'] ?? 0),
        $prefix . '_retry=' . (int)($statuses['retry'] ?? 0),
        $prefix . '_sent=' . (int)($statuses['sent'] ?? 0),
        $prefix . '_sent_today=' . (int)($snapshot['sent_today'] ?? 0),
        $prefix . '_failed=' . (int)($statuses['failed'] ?? 0),
        $prefix . '_skipped=' . (int)($statuses['skipped'] ?? 0),
        $prefix . '_due_pending=' . (int)($snapshot['due_pending'] ?? 0),
        $prefix . '_future_pending=' . (int)($snapshot['future_pending'] ?? 0),
        $prefix . '_due_retry=' . (int)($snapshot['due_retry'] ?? 0),
        $prefix . '_future_retry=' . (int)($snapshot['future_retry'] ?? 0),
        $prefix . '_stale_processing=' . (int)($snapshot['stale_processing'] ?? 0),
    ];

    if (!empty($snapshot['error'])) {
        $safeError = preg_replace('/\s+/u', '_', mb_substr((string)$snapshot['error'], 0, 120, 'UTF-8'));
        $parts[] = $prefix . '_error=' . $safeError;
    }

    $botChunks = [];
    foreach ((array)($snapshot['by_bot'] ?? []) as $bot) {
        $botStatuses = is_array($bot['statuses'] ?? null) ? $bot['statuses'] : [];
        $botChunks[] = (int)($bot['bot_id'] ?? 0)
            . ':p' . (int)($botStatuses['pending'] ?? 0)
            . '/pr' . (int)($botStatuses['processing'] ?? 0)
            . '/r' . (int)($botStatuses['retry'] ?? 0)
            . '/s' . (int)($botStatuses['sent'] ?? 0)
            . '/f' . (int)($botStatuses['failed'] ?? 0)
            . '/sk' . (int)($botStatuses['skipped'] ?? 0);
    }
    if ($botChunks) {
        $parts[] = $prefix . '_by_bot=' . implode(',', $botChunks);
    }

    return implode(' ', $parts);
}
