<?php
defined('ASR_ADMIN') || exit;

/**
 * Изолированный слой массового запуска сценариев.
 * Не меняет runtime, webhook, cron и структуру таблиц.
 */

function asr_tg_bulk_start_type_label(string $type): string {
    $labels = function_exists('asr_tg_scenario_block_type_labels') ? asr_tg_scenario_block_type_labels() : [];
    return (string)($labels[$type] ?? $type);
}

function asr_tg_bulk_start_catalog(PDO $pdo): array {
    if (!function_exists('asr_tg_repository_ensure_scenario_schema')) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);

    $sql = "SELECT s.id, s.title, s.status,
                   GROUP_CONCAT(DISTINCT sb.bot_id ORDER BY sb.bot_id ASC SEPARATOR ',') AS bot_ids
            FROM oca_telegram_bot_scenarios s
            JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id AND COALESCE(sb.is_enabled, 1) = 1
            WHERE s.status = 'active' AND s.archived_at IS NULL
            GROUP BY s.id
            ORDER BY s.title ASC, s.id DESC
            LIMIT 300";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    $out = [];
    $blockStmt = $pdo->prepare("SELECT id, type, title, sort_order
        FROM oca_telegram_bot_scenario_blocks
        WHERE scenario_id = ? AND type <> 'start'
        ORDER BY sort_order ASC, id ASC
        LIMIT 300");
    foreach ($rows as $row) {
        $scenarioId = (int)($row['id'] ?? 0);
        if ($scenarioId <= 0) continue;
        $blockStmt->execute([$scenarioId]);
        $blocks = [];
        foreach (($blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $block) {
            $blockId = (int)($block['id'] ?? 0);
            if ($blockId <= 0) continue;
            $type = (string)($block['type'] ?? '');
            $title = trim((string)($block['title'] ?? ''));
            $blocks[] = [
                'id' => $blockId,
                'title' => ($title !== '' ? $title : asr_tg_bulk_start_type_label($type)) . ' #' . $blockId,
                'type' => $type,
                'type_label' => asr_tg_bulk_start_type_label($type),
            ];
        }
        if (!$blocks) continue;
        $out[] = [
            'id' => $scenarioId,
            'title' => trim((string)($row['title'] ?? '')) ?: ('Сценарий #' . $scenarioId),
            'bot_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($row['bot_ids'] ?? ''))))),
            'blocks' => $blocks,
        ];
    }
    return $out;
}

function asr_tg_bulk_start_subscriber_ids_from_post(array $post): array {
    $raw = $post['subscriber_ids'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_slice(array_values($ids), 0, 200);
}


function asr_tg_bulk_start_active_statuses(): array {
    return ['active','waiting','delayed','queued','processing','running','pending'];
}

function asr_tg_bulk_start_has_active_state(PDO $pdo, int $botId, int $subscriberId, int $scenarioId): bool {
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0) return false;
    if (function_exists('asr_tg_table_exists') && !asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return false;
    $statuses = asr_tg_bulk_start_active_statuses();
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id
            FROM oca_telegram_bot_subscriber_scenarios
            WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND status IN ({$ph})
            ORDER BY updated_at DESC, id DESC
            LIMIT 1");
        $stmt->execute(array_merge([$botId, $subscriberId, $scenarioId], $statuses));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Если таблица/поле временно недоступны, не ломаем массовый запуск целиком.
        // Ошибку конкретного запуска поймает runtime ниже.
        return false;
    }
}

function asr_tg_bulk_start_skip_result(array &$result, string $reason): void {
    $result['skipped'] = (int)($result['skipped'] ?? 0) + 1;
    if (!isset($result['skip_reasons']) || !is_array($result['skip_reasons'])) $result['skip_reasons'] = [];
    if (!isset($result['skip_reasons'][$reason])) $result['skip_reasons'][$reason] = 0;
    $result['skip_reasons'][$reason]++;
}

function asr_tg_bulk_start_scenario_from_post(PDO $pdo, array $post): array {
    if (!function_exists('asr_tg_can') || (!asr_tg_can('flows') && !asr_tg_can('broadcast'))) {
        throw new RuntimeException('Недостаточно прав для запуска сценариев.');
    }
    if (!function_exists('asr_tg_runtime_start_scenario')) {
        throw new RuntimeException('Runtime сценариев недоступен.');
    }
    asr_tg_repository_ensure_schema($pdo);
    asr_tg_repository_ensure_scenario_schema($pdo);

    $scenarioId = (int)($post['scenario_id'] ?? 0);
    $blockId = (int)($post['block_id'] ?? 0);
    $subscriberIds = asr_tg_bulk_start_subscriber_ids_from_post($post);

    if ($scenarioId <= 0) throw new InvalidArgumentException('Выберите сценарий.');
    if ($blockId <= 0) throw new InvalidArgumentException('Выберите стартовый блок.');
    if (!$subscriberIds) throw new InvalidArgumentException('Выберите хотя бы одного подписчика.');

    $scenario = asr_tg_scenario_find($pdo, $scenarioId);
    if (!$scenario) throw new RuntimeException('Сценарий не найден.');
    if ((string)($scenario['status'] ?? '') !== 'active') throw new RuntimeException('Массово запускать можно только активный сценарий.');

    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block || (string)($block['type'] ?? '') === 'start') throw new RuntimeException('Стартовый блок не найден или выбран технический старт.');

    $allowedBotIds = function_exists('asr_tg_scenario_bot_ids') ? asr_tg_scenario_bot_ids($pdo, $scenarioId, true) : [];
    $allowedBotIds = array_values(array_filter(array_map('intval', $allowedBotIds), static fn($id) => $id > 0));
    if (!$allowedBotIds) throw new RuntimeException('У сценария нет активного канала.');

    $ph = implode(',', array_fill(0, count($subscriberIds), '?'));
    $stmt = $pdo->prepare("SELECT s.*, b.status AS bot_status
        FROM oca_telegram_bot_subscribers s
        JOIN oca_telegram_bots b ON b.id = s.bot_id
        WHERE s.id IN ({$ph})
        ORDER BY s.id ASC");
    $stmt->execute($subscriberIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = ['selected' => count($subscriberIds), 'started' => 0, 'skipped' => 0, 'skip_reasons' => ['not_subscribed' => 0, 'already_active' => 0, 'not_allowed_channel' => 0, 'not_found' => 0, 'error' => 0], 'errors' => []];
    $botsCache = [];

    foreach ($rows as $row) {
        $subscriberId = (int)($row['id'] ?? 0);
        $botId = (int)($row['bot_id'] ?? 0);
        $chatId = $row['chat_id'] ?? '';
        if ($subscriberId <= 0 || $botId <= 0) { asr_tg_bulk_start_skip_result($result, 'not_found'); continue; }
        if (!in_array($botId, $allowedBotIds, true)) { asr_tg_bulk_start_skip_result($result, 'not_allowed_channel'); continue; }
        if ((string)($row['status'] ?? '') !== 'active' || (string)($row['bot_status'] ?? '') !== 'active' || trim((string)$chatId) === '') { asr_tg_bulk_start_skip_result($result, 'not_subscribed'); continue; }
        if (asr_tg_bulk_start_has_active_state($pdo, $botId, $subscriberId, $scenarioId)) { asr_tg_bulk_start_skip_result($result, 'already_active'); continue; }

        if (!isset($botsCache[$botId])) {
            $botsCache[$botId] = function_exists('asr_tg_bot_find') ? asr_tg_bot_find($pdo, $botId) : null;
        }
        $bot = $botsCache[$botId];
        if (!$bot) { asr_tg_bulk_start_skip_result($result, 'not_allowed_channel'); continue; }

        try {
            $ok = asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, 'bulk_manual_start', [
                'bulk' => true,
                'started_by' => function_exists('asr_current_user_id') ? (int)asr_current_user_id() : 0,
            ]);
            if ($ok) $result['started']++; else asr_tg_bulk_start_skip_result($result, 'error');
        } catch (Throwable $e) {
            asr_tg_bulk_start_skip_result($result, 'error');
            if (count($result['errors']) < 5) $result['errors'][] = 'Подписчик #' . $subscriberId . ': ' . $e->getMessage();
        }
    }

    $knownRows = count($rows);
    if ($knownRows < count($subscriberIds)) {
        for ($i = 0; $i < count($subscriberIds) - $knownRows; $i++) asr_tg_bulk_start_skip_result($result, 'not_found');
    }
    return $result;
}
