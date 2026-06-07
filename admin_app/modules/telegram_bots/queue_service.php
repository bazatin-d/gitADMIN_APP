<?php
defined('ASR_ADMIN') || exit;

/**
 * Универсальная очередь отправки Telegram.
 *
 * Шаг 4: здесь только фундамент — схема, постановка задач, сброс зависших задач,
 * статистика и базовые операции обновления. Runtime сценариев пока не переведён
 * на эту очередь, чтобы не смешивать архитектурный слой с рабочей логикой.
 */

function asr_tg_send_queue_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_send_queue` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NULL DEFAULT NULL,
        `chat_id` BIGINT NULL DEFAULT NULL,
        `task_type` VARCHAR(60) NOT NULL,
        `source_type` VARCHAR(60) NULL DEFAULT NULL,
        `source_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `state_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `payload_json` MEDIUMTEXT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
        `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `next_attempt_at` DATETIME NULL DEFAULT NULL,
        `processing_at` DATETIME NULL DEFAULT NULL,
        `sent_at` DATETIME NULL DEFAULT NULL,
        `failed_at` DATETIME NULL DEFAULT NULL,
        `telegram_message_id` BIGINT NULL DEFAULT NULL,
        `last_error` TEXT NULL,
        `dedupe_key` VARCHAR(190) NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_send_queue_dedupe` (`dedupe_key`),
        KEY `idx_send_queue_status_scheduled` (`status`, `scheduled_at`),
        KEY `idx_send_queue_status_next_attempt` (`status`, `next_attempt_at`),
        KEY `idx_send_queue_bot_status` (`bot_id`, `status`),
        KEY `idx_send_queue_task_status` (`task_type`, `status`),
        KEY `idx_send_queue_source` (`source_type`, `source_id`),
        KEY `idx_send_queue_scenario_state` (`scenario_id`, `state_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'bot_id', 'INT UNSIGNED NOT NULL AFTER `id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'subscriber_id', 'INT UNSIGNED NULL DEFAULT NULL AFTER `bot_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'chat_id', 'BIGINT NULL DEFAULT NULL AFTER `subscriber_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'task_type', "VARCHAR(60) NOT NULL DEFAULT 'scenario_message' AFTER `chat_id`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'source_type', 'VARCHAR(60) NULL DEFAULT NULL AFTER `task_type`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'source_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `source_type`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'scenario_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `source_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'block_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `scenario_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'state_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `block_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'payload_json', 'MEDIUMTEXT NULL AFTER `state_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'status', "VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER `payload_json`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'attempts', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'max_attempts', 'SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `attempts`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'scheduled_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `max_attempts`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'next_attempt_at', 'DATETIME NULL DEFAULT NULL AFTER `scheduled_at`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'processing_at', 'DATETIME NULL DEFAULT NULL AFTER `next_attempt_at`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'sent_at', 'DATETIME NULL DEFAULT NULL AFTER `processing_at`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'failed_at', 'DATETIME NULL DEFAULT NULL AFTER `sent_at`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'telegram_message_id', 'BIGINT NULL DEFAULT NULL AFTER `failed_at`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'last_error', 'TEXT NULL AFTER `telegram_message_id`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_send_queue', 'dedupe_key', 'VARCHAR(190) NULL DEFAULT NULL AFTER `last_error`');

    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_status_scheduled', '(`status`, `scheduled_at`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_status_next_attempt', '(`status`, `next_attempt_at`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_bot_status', '(`bot_id`, `status`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_task_status', '(`task_type`, `status`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_source', '(`source_type`, `source_id`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_send_queue', 'idx_send_queue_scenario_state', '(`scenario_id`, `state_id`, `status`)');
}

function asr_tg_send_queue_table_ready(PDO $pdo): bool {
    return asr_tg_table_exists($pdo, 'oca_telegram_bot_send_queue');
}

function asr_tg_send_queue_statuses(): array {
    return ['pending', 'processing', 'sent', 'failed', 'retry', 'skipped'];
}

function asr_tg_send_queue_payload_to_json($payload): ?string {
    if ($payload === null || $payload === '') return null;
    if (is_string($payload)) {
        json_decode($payload, true);
        return json_last_error() === JSON_ERROR_NONE ? $payload : json_encode(['value' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_array($payload) || is_object($payload)) {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return json_encode(['value' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function asr_tg_send_queue_enqueue(PDO $pdo, array $task): int {
    asr_tg_send_queue_ensure_schema($pdo);

    $botId = (int)($task['bot_id'] ?? 0);
    $taskType = trim((string)($task['task_type'] ?? ''));
    if ($botId <= 0 || $taskType === '') {
        throw new InvalidArgumentException('Для постановки задачи в Telegram-очередь нужны bot_id и task_type.');
    }

    $status = (string)($task['status'] ?? 'pending');
    if (!in_array($status, asr_tg_send_queue_statuses(), true)) {
        $status = 'pending';
    }

    $dedupeKey = trim((string)($task['dedupe_key'] ?? ''));
    $dedupeKey = $dedupeKey !== '' ? mb_substr($dedupeKey, 0, 190, 'UTF-8') : null;
    $payloadJson = array_key_exists('payload_json', $task)
        ? asr_tg_send_queue_payload_to_json($task['payload_json'])
        : asr_tg_send_queue_payload_to_json($task['payload'] ?? null);

    $scheduledAt = trim((string)($task['scheduled_at'] ?? ''));
    if ($scheduledAt === '') $scheduledAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT IGNORE INTO oca_telegram_bot_send_queue
        (bot_id, subscriber_id, chat_id, task_type, source_type, source_id, scenario_id, block_id, state_id, payload_json, status, attempts, max_attempts, scheduled_at, next_attempt_at, last_error, dedupe_key, created_at, updated_at)
        VALUES
        (:bot_id, :subscriber_id, :chat_id, :task_type, :source_type, :source_id, :scenario_id, :block_id, :state_id, :payload_json, :status, :attempts, :max_attempts, :scheduled_at, :next_attempt_at, :last_error, :dedupe_key, NOW(), NOW())");
    $stmt->execute([
        ':bot_id' => $botId,
        ':subscriber_id' => isset($task['subscriber_id']) ? (int)$task['subscriber_id'] : null,
        ':chat_id' => isset($task['chat_id']) && (string)$task['chat_id'] !== '' ? (string)$task['chat_id'] : null,
        ':task_type' => mb_substr($taskType, 0, 60, 'UTF-8'),
        ':source_type' => isset($task['source_type']) && (string)$task['source_type'] !== '' ? mb_substr((string)$task['source_type'], 0, 60, 'UTF-8') : null,
        ':source_id' => isset($task['source_id']) ? (int)$task['source_id'] : null,
        ':scenario_id' => isset($task['scenario_id']) ? (int)$task['scenario_id'] : null,
        ':block_id' => isset($task['block_id']) ? (int)$task['block_id'] : null,
        ':state_id' => isset($task['state_id']) ? (int)$task['state_id'] : null,
        ':payload_json' => $payloadJson,
        ':status' => $status,
        ':attempts' => max(0, (int)($task['attempts'] ?? 0)),
        ':max_attempts' => max(1, min(20, (int)($task['max_attempts'] ?? 5))),
        ':scheduled_at' => $scheduledAt,
        ':next_attempt_at' => isset($task['next_attempt_at']) && (string)$task['next_attempt_at'] !== '' ? (string)$task['next_attempt_at'] : null,
        ':last_error' => isset($task['last_error']) && (string)$task['last_error'] !== '' ? (string)$task['last_error'] : null,
        ':dedupe_key' => $dedupeKey,
    ]);

    if ((int)$pdo->lastInsertId() > 0) {
        return (int)$pdo->lastInsertId();
    }

    if ($dedupeKey !== null) {
        $find = $pdo->prepare('SELECT id FROM oca_telegram_bot_send_queue WHERE dedupe_key = ? LIMIT 1');
        $find->execute([$dedupeKey]);
        return (int)($find->fetchColumn() ?: 0);
    }

    return 0;
}

function asr_tg_send_queue_next(PDO $pdo, int $limit = 30, ?string $taskType = null, int $botId = 0): array {
    asr_tg_send_queue_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    $now = date('Y-m-d H:i:s');
    $where = "((status = 'pending' AND scheduled_at <= ?) OR (status = 'retry' AND COALESCE(next_attempt_at, scheduled_at) <= ?))";
    $params = [$now, $now];
    if ($taskType !== null && trim($taskType) !== '') {
        $where .= ' AND task_type = ?';
        $params[] = mb_substr(trim($taskType), 0, 60, 'UTF-8');
    }
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_send_queue WHERE {$where} ORDER BY COALESCE(next_attempt_at, scheduled_at) ASC, id ASC LIMIT {$limit}");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_send_queue_update(PDO $pdo, int $queueId, array $data): void {
    if ($queueId <= 0 || !$data) return;
    asr_tg_send_queue_ensure_schema($pdo);
    $allowed = ['status','attempts','processing_at','sent_at','failed_at','next_attempt_at','telegram_message_id','last_error','payload_json'];
    $sets = [];
    $values = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $data)) continue;
        $sets[] = "`{$key}` = ?";
        $values[] = $key === 'payload_json' ? asr_tg_send_queue_payload_to_json($data[$key]) : $data[$key];
    }
    if (!$sets) return;
    $sets[] = '`updated_at` = NOW()';
    $values[] = $queueId;
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_send_queue SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}

function asr_tg_send_queue_reset_stale_processing(PDO $pdo, int $timeoutMinutes = 10, int $botId = 0): int {
    asr_tg_send_queue_ensure_schema($pdo);
    $timeoutMinutes = max(1, min(240, $timeoutMinutes));
    $where = "status = 'processing' AND processing_at IS NOT NULL AND processing_at < DATE_SUB(NOW(), INTERVAL {$timeoutMinutes} MINUTE)";
    $params = [];
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_send_queue
        SET status = 'retry', processing_at = NULL, next_attempt_at = NOW(), last_error = IF(last_error IS NULL OR last_error = '', 'stale_processing_reset', last_error), updated_at = NOW()
        WHERE {$where}");
    $stmt->execute($params);
    return $stmt->rowCount();
}



function asr_tg_send_queue_mark_processing(PDO $pdo, int $queueId, int $attempts): void {
    asr_tg_send_queue_update($pdo, $queueId, [
        'status' => 'processing',
        'attempts' => max(0, $attempts),
        'processing_at' => date('Y-m-d H:i:s'),
        'next_attempt_at' => null,
        'last_error' => null,
    ]);
}

function asr_tg_send_queue_mark_sent(PDO $pdo, int $queueId, ?int $telegramMessageId = null): void {
    asr_tg_send_queue_update($pdo, $queueId, [
        'status' => 'sent',
        'processing_at' => null,
        'sent_at' => date('Y-m-d H:i:s'),
        'telegram_message_id' => $telegramMessageId,
        'last_error' => null,
    ]);
}

function asr_tg_send_queue_mark_retry(PDO $pdo, int $queueId, string $error, int $delaySeconds = 60): void {
    $delaySeconds = max(10, min(86400, $delaySeconds));
    asr_tg_send_queue_update($pdo, $queueId, [
        'status' => 'retry',
        'processing_at' => null,
        'next_attempt_at' => date('Y-m-d H:i:s', time() + $delaySeconds),
        'last_error' => mb_substr($error, 0, 2000, 'UTF-8'),
    ]);
}

function asr_tg_send_queue_mark_failed(PDO $pdo, int $queueId, string $error): void {
    asr_tg_send_queue_update($pdo, $queueId, [
        'status' => 'failed',
        'processing_at' => null,
        'failed_at' => date('Y-m-d H:i:s'),
        'last_error' => mb_substr($error, 0, 2000, 'UTF-8'),
    ]);
}

function asr_tg_send_queue_mark_skipped(PDO $pdo, int $queueId, string $reason): void {
    asr_tg_send_queue_update($pdo, $queueId, [
        'status' => 'skipped',
        'processing_at' => null,
        'last_error' => mb_substr($reason, 0, 2000, 'UTF-8'),
    ]);
}

function asr_tg_send_queue_decode_payload(array $task): array {
    $raw = (string)($task['payload_json'] ?? '');
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}


function asr_tg_send_queue_process_scenario_delay_continue(PDO $pdo, array $task): array {
    $queueId = (int)($task['id'] ?? 0);
    $stateId = (int)($task['state_id'] ?? 0);
    $botId = (int)($task['bot_id'] ?? 0);
    $subscriberId = (int)($task['subscriber_id'] ?? 0);
    $scenarioId = (int)($task['scenario_id'] ?? 0);
    $blockId = (int)($task['block_id'] ?? 0);
    $chatId = $task['chat_id'] ?? 0;

    if ($queueId <= 0 || $stateId <= 0 || $botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || !$chatId) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_delay_continue_invalid_payload');
        return ['status' => 'failed', 'error' => 'scenario_delay_continue_invalid_payload'];
    }

    if (!function_exists('asr_tg_table_exists') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) {
        asr_tg_send_queue_mark_retry($pdo, $queueId, 'scenario_state_table_not_ready', 60);
        return ['status' => 'retry', 'error' => 'scenario_state_table_not_ready'];
    }

    $stmt = $pdo->prepare("SELECT ss.*, sub.chat_id, sub.status AS subscriber_status, b.status AS bot_status, s.status AS scenario_status
        FROM oca_telegram_bot_subscriber_scenarios ss
        JOIN oca_telegram_bot_subscribers sub ON sub.id = ss.subscriber_id AND sub.bot_id = ss.bot_id
        JOIN oca_telegram_bots b ON b.id = ss.bot_id
        JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id
        WHERE ss.id = ? AND ss.bot_id = ? AND ss.subscriber_id = ? AND ss.scenario_id = ?
        LIMIT 1");
    $stmt->execute([$stateId, $botId, $subscriberId, $scenarioId]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_state_not_found');
        return ['status' => 'failed', 'error' => 'scenario_state_not_found'];
    }

    $stateStatus = (string)($state['status'] ?? '');
    if (!in_array($stateStatus, ['queued', 'processing'], true)) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_state_not_queued:' . $stateStatus);
        return ['status' => 'skipped', 'error' => 'scenario_state_not_queued:' . $stateStatus];
    }

    if ((string)($state['bot_status'] ?? '') !== 'active' || (string)($state['scenario_status'] ?? '') !== 'active' || (string)($state['subscriber_status'] ?? '') === 'blocked') {
        try {
            $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")
                ->execute(['Канал, сценарий или подписчик недоступен для продолжения задержки.', $stateId]);
            if (function_exists('asr_tg_runtime_log_event')) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_queue_skipped', 'Задача продолжения задержки пропущена: канал, сценарий или подписчик недоступен.', ['state_id' => $stateId, 'queue_id' => $queueId]);
            }
        } catch (Throwable $ignored) {}
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_or_subscriber_unavailable');
        return ['status' => 'skipped', 'error' => 'scenario_or_subscriber_unavailable'];
    }

    $claim = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'processing', next_run_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ? AND status IN ('queued','processing')");
    $claim->execute([$stateId]);
    if ($claim->rowCount() <= 0) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_state_claim_failed');
        return ['status' => 'skipped', 'error' => 'scenario_state_claim_failed'];
    }

    $bot = function_exists('asr_tg_bot_find') ? asr_tg_bot_find($pdo, $botId) : null;
    if (!$bot) {
        $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute(['Канал задержанного сценария не найден.', $stateId]);
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_bot_not_found');
        return ['status' => 'failed', 'error' => 'scenario_bot_not_found'];
    }

    if (function_exists('asr_tg_runtime_log_event')) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_queue_started', 'Универсальная очередь запускает продолжение задержки.', ['state_id' => $stateId, 'queue_id' => $queueId]);
    }

    if (!function_exists('asr_tg_runtime_start_scenario')) {
        asr_tg_send_queue_mark_retry($pdo, $queueId, 'runtime_start_function_not_ready', 60);
        return ['status' => 'retry', 'error' => 'runtime_start_function_not_ready'];
    }

    $ok = asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, 'queued_delay_runner', ['state_id' => $stateId, 'queue_id' => $queueId]);
    if ($ok) {
        asr_tg_send_queue_mark_sent($pdo, $queueId, null);
        return ['status' => 'sent'];
    }

    asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_delay_continue_runtime_returned_false');
    return ['status' => 'failed', 'error' => 'scenario_delay_continue_runtime_returned_false'];
}

function asr_tg_send_queue_question_state(PDO $pdo, int $stateId, int $botId, int $subscriberId, int $scenarioId): ?array {
    if (!function_exists('asr_tg_table_exists') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return null;
    $stmt = $pdo->prepare("SELECT ss.*, sub.chat_id, sub.status AS subscriber_status, b.bot_token_encrypted, b.status AS bot_status, s.status AS scenario_status
        FROM oca_telegram_bot_subscriber_scenarios ss
        JOIN oca_telegram_bot_subscribers sub ON sub.id = ss.subscriber_id AND sub.bot_id = ss.bot_id
        JOIN oca_telegram_bots b ON b.id = ss.bot_id
        JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id
        WHERE ss.id = ? AND ss.bot_id = ? AND ss.subscriber_id = ? AND ss.scenario_id = ?
        LIMIT 1");
    $stmt->execute([$stateId, $botId, $subscriberId, $scenarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_tg_send_queue_question_is_available(array $state): bool {
    return (string)($state['bot_status'] ?? '') === 'active'
        && (string)($state['scenario_status'] ?? '') === 'active'
        && (string)($state['subscriber_status'] ?? '') === 'active';
}

function asr_tg_send_queue_process_scenario_question_reminder(PDO $pdo, array $task): array {
    $queueId = (int)($task['id'] ?? 0);
    $stateId = (int)($task['state_id'] ?? 0);
    $botId = (int)($task['bot_id'] ?? 0);
    $subscriberId = (int)($task['subscriber_id'] ?? 0);
    $scenarioId = (int)($task['scenario_id'] ?? 0);
    $blockId = (int)($task['block_id'] ?? 0);
    $chatId = $task['chat_id'] ?? 0;
    $payload = asr_tg_send_queue_decode_payload($task);
    $waitEventId = (int)($payload['wait_event_id'] ?? 0);
    $cardIndex = max(0, (int)($payload['card_index'] ?? 0));
    $remindText = trim((string)($payload['remind_text'] ?? '')) ?: 'Пожалуйста, ответьте на вопрос.';
    $nextRunAt = trim((string)($payload['next_run_at'] ?? ''));

    if ($queueId <= 0 || $stateId <= 0 || $botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || !$chatId) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_reminder_invalid_payload');
        return ['status' => 'failed', 'error' => 'scenario_question_reminder_invalid_payload'];
    }

    $state = asr_tg_send_queue_question_state($pdo, $stateId, $botId, $subscriberId, $scenarioId);
    if (!$state) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_state_not_found');
        return ['status' => 'failed', 'error' => 'scenario_question_state_not_found'];
    }
    if ((string)($state['status'] ?? '') !== 'waiting') {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_not_waiting:' . (string)($state['status'] ?? ''));
        return ['status' => 'skipped', 'error' => 'scenario_question_not_waiting'];
    }
    if (!asr_tg_send_queue_question_is_available($state)) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_or_subscriber_unavailable');
        return ['status' => 'skipped', 'error' => 'scenario_question_or_subscriber_unavailable'];
    }
    if ($waitEventId > 0 && function_exists('asr_tg_runtime_question_has_answer_after') && asr_tg_runtime_question_has_answer_after($pdo, $botId, $subscriberId, $scenarioId, $blockId, $waitEventId)) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_already_answered');
        return ['status' => 'skipped', 'error' => 'scenario_question_already_answered'];
    }

    $token = function_exists('asr_tg_decrypt_token') ? asr_tg_decrypt_token((string)($state['bot_token_encrypted'] ?? '')) : '';
    if ($token === '') {
        asr_tg_send_queue_mark_retry($pdo, $queueId, 'scenario_question_bot_token_empty', 60);
        return ['status' => 'retry', 'error' => 'scenario_question_bot_token_empty'];
    }

    asr_tg_api_send_broadcast_payload($token, $chatId, $remindText, '', '', '', true, '', [], false);
    $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET next_run_at = ?, last_error = NULL, updated_at = NOW() WHERE id = ? AND status = 'waiting'")->execute([$nextRunAt !== '' ? $nextRunAt : null, $stateId]);
    if (function_exists('asr_tg_runtime_log_event')) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_reminder_sent', 'Подписчику отправлено напоминание по вопросу через универсальную очередь.', ['card_index' => $cardIndex, 'next_run_at' => $nextRunAt, 'queue_id' => $queueId]);
    }
    asr_tg_send_queue_mark_sent($pdo, $queueId, null);
    return ['status' => 'sent'];
}

function asr_tg_send_queue_process_scenario_question_timeout(PDO $pdo, array $task): array {
    $queueId = (int)($task['id'] ?? 0);
    $stateId = (int)($task['state_id'] ?? 0);
    $botId = (int)($task['bot_id'] ?? 0);
    $subscriberId = (int)($task['subscriber_id'] ?? 0);
    $scenarioId = (int)($task['scenario_id'] ?? 0);
    $blockId = (int)($task['block_id'] ?? 0);
    $chatId = $task['chat_id'] ?? 0;
    $payload = asr_tg_send_queue_decode_payload($task);
    $waitEventId = (int)($payload['wait_event_id'] ?? 0);
    $cardIndex = max(0, (int)($payload['card_index'] ?? 0));
    $targetBlockId = max(0, (int)($payload['target_block_id'] ?? 0));

    if ($queueId <= 0 || $stateId <= 0 || $botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || $targetBlockId <= 0 || !$chatId) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_timeout_invalid_payload');
        return ['status' => 'failed', 'error' => 'scenario_question_timeout_invalid_payload'];
    }

    $state = asr_tg_send_queue_question_state($pdo, $stateId, $botId, $subscriberId, $scenarioId);
    if (!$state) {
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_state_not_found');
        return ['status' => 'failed', 'error' => 'scenario_question_state_not_found'];
    }
    $stateStatus = (string)($state['status'] ?? '');
    if (!in_array($stateStatus, ['queued', 'processing'], true)) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_timeout_not_queued:' . $stateStatus);
        return ['status' => 'skipped', 'error' => 'scenario_question_timeout_not_queued:' . $stateStatus];
    }
    if (!asr_tg_send_queue_question_is_available($state)) {
        $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute(['Канал, сценарий или подписчик недоступен для ветки «Подписчик не ответил».', $stateId]);
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_or_subscriber_unavailable');
        return ['status' => 'skipped', 'error' => 'scenario_question_or_subscriber_unavailable'];
    }
    if ($waitEventId > 0 && function_exists('asr_tg_runtime_question_has_answer_after') && asr_tg_runtime_question_has_answer_after($pdo, $botId, $subscriberId, $scenarioId, $blockId, $waitEventId)) {
        $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'active', next_run_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$stateId]);
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_already_answered');
        return ['status' => 'skipped', 'error' => 'scenario_question_already_answered'];
    }

    $claim = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'processing', next_run_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ? AND status IN ('queued','processing')");
    $claim->execute([$stateId]);
    if ($claim->rowCount() <= 0) {
        asr_tg_send_queue_mark_skipped($pdo, $queueId, 'scenario_question_timeout_claim_failed');
        return ['status' => 'skipped', 'error' => 'scenario_question_timeout_claim_failed'];
    }

    $bot = function_exists('asr_tg_bot_find') ? asr_tg_bot_find($pdo, $botId) : null;
    if (!$bot) {
        $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute(['Канал вопроса не найден.', $stateId]);
        asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_bot_not_found');
        return ['status' => 'failed', 'error' => 'scenario_question_bot_not_found'];
    }

    if (function_exists('asr_tg_runtime_log_event')) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_no_answer', 'Истекло ожидание ответа подписчика. Универсальная очередь запускает ветку «Подписчик не ответил».', ['card_index' => $cardIndex, 'target_block_id' => $targetBlockId, 'queue_id' => $queueId]);
    }

    if (!function_exists('asr_tg_runtime_start_scenario')) {
        asr_tg_send_queue_mark_retry($pdo, $queueId, 'runtime_start_function_not_ready', 60);
        return ['status' => 'retry', 'error' => 'runtime_start_function_not_ready'];
    }
    $ok = asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, 'question_no_answer_queued', ['from_block_id' => $blockId, 'card_index' => $cardIndex, 'state_id' => $stateId, 'queue_id' => $queueId]);
    if ($ok) {
        asr_tg_send_queue_mark_sent($pdo, $queueId, null);
        return ['status' => 'sent'];
    }

    asr_tg_send_queue_mark_failed($pdo, $queueId, 'scenario_question_timeout_runtime_returned_false');
    return ['status' => 'failed', 'error' => 'scenario_question_timeout_runtime_returned_false'];
}

function asr_tg_send_queue_process_one(PDO $pdo, array $task): array {
    $queueId = (int)($task['id'] ?? 0);
    $taskType = (string)($task['task_type'] ?? '');
    if ($queueId <= 0) {
        return ['status' => 'failed', 'error' => 'empty_queue_id'];
    }

    if (in_array($taskType, ['noop', 'diagnostic_noop'], true)) {
        asr_tg_send_queue_mark_sent($pdo, $queueId, null);
        return ['status' => 'sent'];
    }

    if ($taskType === 'scenario_delay_continue') {
        return asr_tg_send_queue_process_scenario_delay_continue($pdo, $task);
    }

    if ($taskType === 'scenario_question_reminder') {
        return asr_tg_send_queue_process_scenario_question_reminder($pdo, $task);
    }

    if ($taskType === 'scenario_question_timeout') {
        return asr_tg_send_queue_process_scenario_question_timeout($pdo, $task);
    }

    asr_tg_send_queue_mark_failed($pdo, $queueId, 'unsupported_send_queue_task_type:' . $taskType);
    return ['status' => 'failed', 'error' => 'unsupported_send_queue_task_type:' . $taskType];
}

function asr_tg_process_send_queue(PDO $pdo, int $limit = 30): array {
    asr_tg_send_queue_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));

    $result = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'retry' => 0,
        'skipped' => 0,
        'stale_processing_reset' => 0,
        'unsupported' => 0,
    ];

    $result['stale_processing_reset'] = asr_tg_send_queue_reset_stale_processing($pdo, 10);
    $items = asr_tg_send_queue_next($pdo, $limit);

    foreach ($items as $task) {
        $queueId = (int)($task['id'] ?? 0);
        if ($queueId <= 0) continue;
        $attempts = ((int)($task['attempts'] ?? 0)) + 1;
        $maxAttempts = max(1, (int)($task['max_attempts'] ?? 5));

        if ($attempts > $maxAttempts) {
            asr_tg_send_queue_mark_failed($pdo, $queueId, 'max_attempts_exceeded');
            $result['processed']++;
            $result['failed']++;
            continue;
        }

        asr_tg_send_queue_mark_processing($pdo, $queueId, $attempts);
        $result['processed']++;

        try {
            $one = asr_tg_send_queue_process_one($pdo, array_merge($task, ['attempts' => $attempts]));
            $status = (string)($one['status'] ?? 'failed');
            if ($status === 'sent') {
                $result['sent']++;
            } elseif ($status === 'retry') {
                $result['retry']++;
            } elseif ($status === 'skipped') {
                $result['skipped']++;
            } else {
                $result['failed']++;
                if (strpos((string)($one['error'] ?? ''), 'unsupported_send_queue_task_type') !== false) {
                    $result['unsupported']++;
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($attempts >= $maxAttempts) {
                asr_tg_send_queue_mark_failed($pdo, $queueId, $error);
                $result['failed']++;
            } else {
                asr_tg_send_queue_mark_retry($pdo, $queueId, $error, min(3600, 60 * $attempts));
                $result['retry']++;
            }
        }
    }

    return $result;
}

function asr_tg_send_queue_stats(PDO $pdo, bool $ensure = false): array {
    if ($ensure) {
        asr_tg_send_queue_ensure_schema($pdo);
    }
    if (!asr_tg_send_queue_table_ready($pdo)) {
        return ['available' => false];
    }

    $stats = [
        'available' => true,
        'total' => 0,
        'pending' => 0,
        'processing' => 0,
        'retry' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'due_pending' => 0,
        'future_pending' => 0,
        'due_retry' => 0,
        'future_retry' => 0,
        'stale_processing' => 0,
        'by_bot' => [],
    ];

    $rows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM oca_telegram_bot_send_queue GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $status = (string)$row['status'];
        $cnt = (int)$row['cnt'];
        $stats['total'] += $cnt;
        if (array_key_exists($status, $stats)) {
            $stats[$status] = $cnt;
        }
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN status = 'pending' AND scheduled_at <= ? THEN 1 ELSE 0 END) AS due_pending,
        SUM(CASE WHEN status = 'pending' AND scheduled_at > ? THEN 1 ELSE 0 END) AS future_pending,
        SUM(CASE WHEN status = 'retry' AND COALESCE(next_attempt_at, scheduled_at) <= ? THEN 1 ELSE 0 END) AS due_retry,
        SUM(CASE WHEN status = 'retry' AND COALESCE(next_attempt_at, scheduled_at) > ? THEN 1 ELSE 0 END) AS future_retry,
        SUM(CASE WHEN status = 'processing' AND processing_at IS NOT NULL AND processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) AS stale_processing
        FROM oca_telegram_bot_send_queue");
    $stmt->execute([$now, $now, $now, $now]);
    $extra = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['due_pending','future_pending','due_retry','future_retry','stale_processing'] as $key) {
        $stats[$key] = (int)($extra[$key] ?? 0);
    }

    $botRows = $pdo->query("SELECT bot_id, status, COUNT(*) AS cnt FROM oca_telegram_bot_send_queue GROUP BY bot_id, status ORDER BY bot_id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($botRows as $row) {
        $botId = (int)$row['bot_id'];
        if (!isset($stats['by_bot'][$botId])) {
            $stats['by_bot'][$botId] = ['pending' => 0, 'processing' => 0, 'retry' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $status = (string)$row['status'];
        if (array_key_exists($status, $stats['by_bot'][$botId])) {
            $stats['by_bot'][$botId][$status] = (int)$row['cnt'];
        }
    }

    return $stats;
}

function asr_tg_send_queue_format_stats(array $stats, string $prefix = 'send_queue'): string {
    if (empty($stats['available'])) {
        return $prefix . '_available=0';
    }
    $parts = [
        $prefix . '_available=1',
        $prefix . '_total=' . (int)($stats['total'] ?? 0),
        $prefix . '_pending=' . (int)($stats['pending'] ?? 0),
        $prefix . '_processing=' . (int)($stats['processing'] ?? 0),
        $prefix . '_retry=' . (int)($stats['retry'] ?? 0),
        $prefix . '_sent=' . (int)($stats['sent'] ?? 0),
        $prefix . '_failed=' . (int)($stats['failed'] ?? 0),
        $prefix . '_skipped=' . (int)($stats['skipped'] ?? 0),
        $prefix . '_due_pending=' . (int)($stats['due_pending'] ?? 0),
        $prefix . '_due_retry=' . (int)($stats['due_retry'] ?? 0),
        $prefix . '_future_pending=' . (int)($stats['future_pending'] ?? 0),
        $prefix . '_future_retry=' . (int)($stats['future_retry'] ?? 0),
        $prefix . '_stale_processing=' . (int)($stats['stale_processing'] ?? 0),
    ];
    if (!empty($stats['by_bot']) && is_array($stats['by_bot'])) {
        $botParts = [];
        foreach ($stats['by_bot'] as $botId => $row) {
            $botParts[] = (int)$botId
                . ':p' . (int)($row['pending'] ?? 0)
                . '/pr' . (int)($row['processing'] ?? 0)
                . '/r' . (int)($row['retry'] ?? 0)
                . '/s' . (int)($row['sent'] ?? 0)
                . '/f' . (int)($row['failed'] ?? 0)
                . '/sk' . (int)($row['skipped'] ?? 0);
        }
        $parts[] = $prefix . '_by_bot=' . implode(',', $botParts);
    }
    return implode(' ', $parts);
}
