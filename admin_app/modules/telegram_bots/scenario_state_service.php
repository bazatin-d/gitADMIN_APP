<?php
defined('ASR_ADMIN') || exit;

/**
 * Scenario state layer.
 *
 * This file is intentionally small and isolated from the runtime executor.
 * It reads and manages subscriber scenario positions without touching block
 * execution logic, queues, webhook handlers or React Flow editor code.
 */

function asr_tg_scenario_state_status_labels(): array {
    return [
        'active' => 'выполняется',
        'waiting' => 'ждёт ответа',
        'delayed' => 'ожидает продолжения',
        'queued' => 'в очереди',
        'processing' => 'обрабатывается',
        'stopped' => 'остановлен',
        'finished' => 'завершён',
        'error' => 'ошибка',
    ];
}

function asr_tg_scenario_state_status_class(string $status): string {
    $map = [
        'active' => 'is-active',
        'waiting' => 'is-waiting',
        'delayed' => 'is-delayed',
        'queued' => 'is-queued',
        'processing' => 'is-queued',
        'stopped' => 'is-muted',
        'finished' => 'is-finished',
        'error' => 'is-error',
    ];
    return $map[$status] ?? 'is-muted';
}

function asr_tg_scenario_state_block_type_label(string $type): string {
    $map = [
        'start' => 'Старт',
        'message' => 'Сообщение',
        'actions' => 'Действия',
        'delay' => 'Задержка',
        'condition' => 'Условие',
        'schedule' => 'Расписание',
        'random' => 'Случайный выбор',
        'formula' => 'Формула',
    ];
    return $map[$type] ?? ($type !== '' ? $type : 'Блок');
}

function asr_tg_scenario_state_human_dt(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return '';
    try {
        $dt = new DateTime($value);
        return $dt->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function asr_tg_scenario_state_priority(string $status): int {
    $map = [
        'processing' => 10,
        'queued' => 20,
        'active' => 30,
        'waiting' => 40,
        'delayed' => 50,
        'error' => 60,
        'stopped' => 90,
        'finished' => 100,
    ];
    return $map[$status] ?? 80;
}

function asr_tg_scenario_state_ts(?string $value): int {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return 0;
    $ts = strtotime($value);
    return $ts !== false ? (int)$ts : 0;
}

function asr_tg_scenario_state_resolve_block(PDO $pdo, array $state): array {
    $blockId = (int)($state['current_block_id'] ?? 0);
    if ($blockId <= 0) $blockId = (int)($state['block_id'] ?? 0);
    if ($blockId <= 0) $blockId = (int)($state['resolved_block_id'] ?? 0);

    if ($blockId > 0) {
        $state['_display_block_id'] = $blockId;
        if (trim((string)($state['block_title'] ?? '')) !== '' && trim((string)($state['block_type'] ?? '')) !== '') return $state;
        try {
            $stmt = $pdo->prepare('SELECT title, type FROM oca_telegram_bot_scenario_blocks WHERE id = ? AND scenario_id = ? LIMIT 1');
            $stmt->execute([$blockId, (int)($state['scenario_id'] ?? 0)]);
            $block = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($block) {
                if (trim((string)($state['block_title'] ?? '')) === '') $state['block_title'] = (string)($block['title'] ?? '');
                if (trim((string)($state['block_type'] ?? '')) === '') $state['block_type'] = (string)($block['type'] ?? '');
            }
        } catch (Throwable $e) {}
        return $state;
    }

    try {
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_events')) return $state;
        $stmt = $pdo->prepare("SELECT ev.block_id, bl.title AS block_title, bl.type AS block_type
            FROM oca_telegram_bot_scenario_events ev
            LEFT JOIN oca_telegram_bot_scenario_blocks bl ON bl.id = ev.block_id AND bl.scenario_id = ev.scenario_id
            WHERE ev.bot_id = ? AND ev.subscriber_id = ? AND ev.scenario_id = ? AND ev.block_id IS NOT NULL AND ev.block_id > 0
            ORDER BY ev.created_at DESC, ev.id DESC
            LIMIT 1");
        $stmt->execute([
            (int)($state['bot_id'] ?? 0),
            (int)($state['subscriber_id'] ?? 0),
            (int)($state['scenario_id'] ?? 0),
        ]);
        $eventBlock = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $eventBlockId = (int)($eventBlock['block_id'] ?? 0);
        if ($eventBlockId > 0) {
            $state['_display_block_id'] = $eventBlockId;
            if (trim((string)($state['block_title'] ?? '')) === '') $state['block_title'] = (string)($eventBlock['block_title'] ?? '');
            if (trim((string)($state['block_type'] ?? '')) === '') $state['block_type'] = (string)($eventBlock['block_type'] ?? '');
        }
    } catch (Throwable $e) {}

    return $state;
}

function asr_tg_subscriber_scenario_related_subscriber_ids(PDO $pdo, int $subscriberId): array {
    $subscriberId = max(0, $subscriberId);
    if ($subscriberId <= 0) return [];

    try {
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscribers')) return [$subscriberId];
        $stmt = $pdo->prepare('SELECT telegram_user_id FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1');
        $stmt->execute([$subscriberId]);
        $telegramUserId = (string)($stmt->fetchColumn() ?: '');
        if ($telegramUserId === '' || $telegramUserId === '0') return [$subscriberId];

        $stmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_subscribers WHERE telegram_user_id = ? ORDER BY id ASC');
        $stmt->execute([$telegramUserId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$ids) return [$subscriberId];
        if (!in_array($subscriberId, $ids, true)) array_unshift($ids, $subscriberId);
        return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
    } catch (Throwable $e) {
        return [$subscriberId];
    }
}

function asr_tg_subscriber_scenario_states(PDO $pdo, int $subscriberId, int $botId = 0, int $limit = 8): array {
    $subscriberId = max(0, $subscriberId);
    $limit = max(1, min(20, $limit));
    if ($subscriberId <= 0) return [];

    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return [];

        $subscriberIds = asr_tg_subscriber_scenario_related_subscriber_ids($pdo, $subscriberId);
        if (!$subscriberIds) return [];
        $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));

        $sql = "SELECT ss.*,
                       sc.title AS scenario_title,
                       sc.status AS scenario_status,
                       bl.id AS resolved_block_id,
                       bl.title AS block_title,
                       bl.type AS block_type,
                       b.title AS bot_title,
                       b.bot_username AS bot_username
                FROM oca_telegram_bot_subscriber_scenarios ss
                LEFT JOIN oca_telegram_bot_scenarios sc ON sc.id = ss.scenario_id
                LEFT JOIN oca_telegram_bot_scenario_blocks bl ON bl.id = ss.current_block_id AND bl.scenario_id = ss.scenario_id
                LEFT JOIN oca_telegram_bots b ON b.id = ss.bot_id
                WHERE ss.subscriber_id IN ({$placeholders})
                ORDER BY FIELD(ss.status, 'processing','queued','active','waiting','delayed','error','stopped','finished'), ss.updated_at DESC, ss.id DESC
                LIMIT 80";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($subscriberIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $idx => $row) {
            $rows[$idx] = asr_tg_scenario_state_resolve_block($pdo, $row);
        }

        $currentByScenarioChannel = [];
        foreach ($rows as $row) {
            $key = (int)($row['bot_id'] ?? 0) . ':' . (int)($row['scenario_id'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $rank = asr_tg_scenario_state_priority($status);
            $ts = asr_tg_scenario_state_ts($row['updated_at'] ?? '') ?: asr_tg_scenario_state_ts($row['started_at'] ?? '');
            $row['_state_rank'] = $rank;
            $row['_state_ts'] = $ts;

            if (!isset($currentByScenarioChannel[$key])) {
                $currentByScenarioChannel[$key] = $row;
                continue;
            }

            $prev = $currentByScenarioChannel[$key];
            $prevRank = (int)($prev['_state_rank'] ?? 999);
            $prevTs = (int)($prev['_state_ts'] ?? 0);
            if ($rank < $prevRank || ($rank === $prevRank && $ts > $prevTs)) {
                $currentByScenarioChannel[$key] = $row;
            }
        }

        $states = array_values($currentByScenarioChannel);
        usort($states, static function(array $a, array $b): int {
            $rankA = (int)($a['_state_rank'] ?? 999);
            $rankB = (int)($b['_state_rank'] ?? 999);
            if ($rankA !== $rankB) return $rankA <=> $rankB;
            $botCmp = strnatcasecmp((string)($a['bot_title'] ?? ''), (string)($b['bot_title'] ?? ''));
            if ($botCmp !== 0) return $botCmp;
            return ((int)($b['_state_ts'] ?? 0)) <=> ((int)($a['_state_ts'] ?? 0));
        });

        return array_slice($states, 0, $limit);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_state_read_failed', $e->getMessage(), ['subscriber_id' => $subscriberId]); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_subscriber_scenario_blocks_for_select(PDO $pdo, int $scenarioId): array {
    $scenarioId = max(0, $scenarioId);
    if ($scenarioId <= 0) return [];
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $stmt = $pdo->prepare("SELECT id, title, type FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY FIELD(type, 'start') DESC, sort_order ASC, id ASC");
        $stmt->execute([$scenarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_subscriber_scenario_stop_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    asr_tg_repository_ensure_scenario_schema($pdo);

    $stateId = max(0, (int)($post['state_id'] ?? 0));
    if ($stateId <= 0) throw new InvalidArgumentException('Не выбрано состояние сценария.');

    // В карточке может быть открыт один дубль подписчика, а состояние сценария может
    // относиться к другой подписке того же telegram_user_id / другому каналу.
    // Поэтому доверяем state_id как основному ключу и не режем поиск bot_id/subscriber_id из формы.
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_subscriber_scenarios WHERE id = ? LIMIT 1');
    $stmt->execute([$stateId]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) throw new RuntimeException('Состояние сценария не найдено.');

    $botId = (int)($state['bot_id'] ?? 0);
    $subscriberId = (int)($state['subscriber_id'] ?? 0);
    $scenarioId = (int)($state['scenario_id'] ?? 0);
    $telegramUserId = (string)($state['telegram_user_id'] ?? '');
    $currentBlockId = (int)($state['current_block_id'] ?? 0);
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0) throw new RuntimeException('Состояние сценария повреждено: не хватает bot_id/subscriber_id/scenario_id.');

    $status = (string)($state['status'] ?? '');
    if (in_array($status, ['stopped','finished'], true)) return $stateId;

    $activeStatuses = ['active','waiting','delayed','queued','processing','error'];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

    // Останавливаем не только конкретную строку, но и возможные активные дубли этого же
    // сценария в этом же канале для того же Telegram-пользователя. Это защищает от ситуации,
    // когда после остановки на экране всплывает старая активная строка того же сценария.
    $params = [$scenarioId, $botId];
    $scope = ['id = ?', 'subscriber_id = ?'];
    $params[] = $stateId;
    $params[] = $subscriberId;
    if ($telegramUserId !== '' && $telegramUserId !== '0') {
        $scope[] = 'telegram_user_id = ?';
        $params[] = $telegramUserId;
    }
    $params = array_merge($params, $activeStatuses);

    $sql = "UPDATE oca_telegram_bot_subscriber_scenarios
            SET status = 'stopped', next_run_at = NULL, last_error = NULL, stopped_at = NOW(), updated_at = NOW()
            WHERE scenario_id = ? AND bot_id = ? AND (" . implode(' OR ', $scope) . ") AND status IN ({$placeholders})";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    // Если по сценарию уже стояли отложенные/повторные задачи в универсальной очереди,
    // помечаем их как skipped, чтобы cron не оживил остановленный сценарий позже.
    try {
        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_send_queue')) {
            $queueParams = [$scenarioId, $botId, $stateId, $subscriberId];
            $queueScope = ['state_id = ?', 'subscriber_id = ?'];
            if ($telegramUserId !== '' && $telegramUserId !== '0') {
                $queueScope[] = 'telegram_user_id = ?';
                $queueParams[] = $telegramUserId;
            }
            $queueSql = "UPDATE oca_telegram_bot_send_queue
                         SET status = 'skipped', last_error = 'Сценарий остановлен вручную из карточки подписчика.', updated_at = NOW()
                         WHERE scenario_id = ? AND bot_id = ? AND (" . implode(' OR ', $queueScope) . ") AND status IN ('pending','retry')";
            $queueUpd = $pdo->prepare($queueSql);
            $queueUpd->execute($queueParams);
        }
    } catch (Throwable $ignored) {}

    if (function_exists('asr_tg_runtime_log_event')) {
        asr_tg_runtime_log_event(
            $pdo,
            $botId,
            $subscriberId,
            $scenarioId,
            $currentBlockId,
            'runtime_manual_stopped',
            'Сценарий остановлен вручную из карточки подписчика.',
            ['state_id' => $stateId, 'source' => 'subscriber_card']
        );
    }
    return $stateId;
}

function asr_tg_scenario_state_current_text(array $state): string {
    $status = (string)($state['status'] ?? '');
    $blockTitle = trim((string)($state['block_title'] ?? ''));
    $blockType = (string)($state['block_type'] ?? '');
    $blockLabel = asr_tg_scenario_state_block_type_label($blockType);
    $blockId = (int)($state['_display_block_id'] ?? 0);
    if ($blockId <= 0) $blockId = (int)($state['current_block_id'] ?? 0);
    if ($blockId <= 0) $blockId = (int)($state['resolved_block_id'] ?? 0);
    $blockIdText = $blockId > 0 ? ' #' . $blockId : '';
    $blockText = $blockTitle !== '' ? '«' . $blockTitle . '»' . $blockIdText : ($blockLabel . $blockIdText);
    $nextRunAt = asr_tg_scenario_state_human_dt($state['next_run_at'] ?? '');
    $finishedAt = asr_tg_scenario_state_human_dt($state['finished_at'] ?? '');
    $stoppedAt = asr_tg_scenario_state_human_dt($state['stopped_at'] ?? '');

    if ($status === 'delayed') {
        return $nextRunAt !== ''
            ? 'Задержка на блоке ' . $blockText . '. Продолжение: ' . $nextRunAt . '.'
            : 'Задержка на блоке ' . $blockText . '. Ждём времени продолжения.';
    }
    if ($status === 'waiting') {
        return $nextRunAt !== ''
            ? 'Ждём ответ подписчика на блоке ' . $blockText . '. Контроль ответа: ' . $nextRunAt . '.'
            : 'Ждём ответ подписчика на блоке ' . $blockText . '.';
    }
    if ($status === 'queued') return 'Продолжение поставлено в очередь. Следующий блок: ' . $blockText . '.';
    if ($status === 'processing') return 'Сейчас обрабатывается блок ' . $blockText . '.';
    if ($status === 'active') {
        if ($blockType === 'message') return 'Сейчас отправляется сообщение из блока ' . $blockText . '.';
        if ($blockType === 'actions') return 'Сейчас выполняются действия из блока ' . $blockText . '.';
        if ($blockType === 'condition') return 'Сейчас проверяется условие на блоке ' . $blockText . '.';
        if ($blockType === 'schedule') return 'Сейчас проверяется расписание на блоке ' . $blockText . '.';
        if ($blockType === 'random') return 'Сейчас выбирается выход на блоке ' . $blockText . '.';
        if ($blockType === 'formula') return 'Сейчас рассчитывается формула на блоке ' . $blockText . '.';
        return 'Сейчас выполняется блок ' . $blockText . '.';
    }
    if ($status === 'error') return 'Сценарий остановился с ошибкой на блоке ' . $blockText . '.';
    if ($status === 'stopped') return $stoppedAt !== '' ? 'Сценарий остановлен ' . $stoppedAt . '.' : 'Сценарий остановлен.';
    if ($status === 'finished') return $finishedAt !== '' ? 'Сценарий завершён ' . $finishedAt . '.' : 'Сценарий завершён.';

    return 'Текущее состояние: ' . ($status !== '' ? $status : 'неизвестно') . '. Блок: ' . $blockText . '.';
}

function asr_tg_render_subscriber_scenario_state_card(PDO $pdo, int $subscriberId, int $botId, callable $h, bool $canControl = false): void {
    $states = asr_tg_subscriber_scenario_states($pdo, $subscriberId, $botId, 12);
    $labels = asr_tg_scenario_state_status_labels();
    ?>
    <div class="tg-info-section tg-scenario-state-section">Состояние в сценариях</div>
    <div class="tg-scenario-state-box">
        <?php if (!$states): ?>
            <div class="tg-scenario-state-empty">По этому подписчику пока нет текущих состояний сценариев.</div>
        <?php else: ?>
            <?php foreach ($states as $state): ?>
                <?php
                    $status = (string)($state['status'] ?? '');
                    $statusLabel = $labels[$status] ?? ($status !== '' ? $status : 'неизвестно');
                    $scenarioTitle = trim((string)($state['scenario_title'] ?? ''));
                    if ($scenarioTitle === '') $scenarioTitle = 'Сценарий #' . (int)($state['scenario_id'] ?? 0);
                    $botTitle = trim((string)($state['bot_title'] ?? ''));
                    if ($botTitle === '') $botTitle = 'Канал #' . (int)($state['bot_id'] ?? 0);
                    $botUsername = trim((string)($state['bot_username'] ?? ''));
                    $summary = asr_tg_scenario_state_current_text($state);
                    $updatedAt = asr_tg_scenario_state_human_dt($state['updated_at'] ?? '');
                    $lastError = trim((string)($state['last_error'] ?? ''));
                    $isActive = in_array($status, ['active','waiting','delayed','queued','processing','error'], true);
                ?>
                <div class="tg-scenario-state-item <?php echo $h(asr_tg_scenario_state_status_class($status)); ?>">
                    <div class="tg-scenario-state-top">
                        <div class="tg-scenario-state-title"><?php echo $h($scenarioTitle); ?></div>
                        <span class="tg-scenario-state-badge"><?php echo $h($statusLabel); ?></span>
                    </div>
                    <div class="tg-scenario-state-channel"><?php echo $h($botTitle); ?><?php echo $botUsername !== '' ? ' · @' . $h(ltrim($botUsername, '@')) : ''; ?></div>
                    <div class="tg-scenario-state-summary"><?php echo $h($summary); ?></div>
                    <?php if ($updatedAt !== ''): ?><div class="tg-scenario-state-updated">Обновлено: <?php echo $h($updatedAt); ?></div><?php endif; ?>
                    <?php if ($lastError !== ''): ?><div class="tg-scenario-state-error"><?php echo $h($lastError); ?></div><?php endif; ?>
                    <?php if ($canControl && $isActive): ?>
                        <form method="POST" class="tg-scenario-state-actions" onsubmit="return confirm('Остановить сценарий для этого подписчика?');">
                            <input type="hidden" name="action" value="tg_subscriber_scenario_stop">
                            <input type="hidden" name="bot_id" value="<?php echo (int)($state['bot_id'] ?? $botId); ?>">
                            <input type="hidden" name="subscriber_id" value="<?php echo (int)($state['subscriber_id'] ?? $subscriberId); ?>">
                            <input type="hidden" name="return_bot_id" value="<?php echo (int)$botId; ?>">
                            <input type="hidden" name="return_subscriber_id" value="<?php echo (int)$subscriberId; ?>">
                            <input type="hidden" name="state_id" value="<?php echo (int)($state['id'] ?? 0); ?>">
                            <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                            <button type="submit" class="tg-scenario-state-stop">Остановить сценарий</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
