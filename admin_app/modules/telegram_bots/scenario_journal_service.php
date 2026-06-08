<?php
/**
 * Human-readable scenario journal for subscriber card.
 *
 * Separate read/render layer. It uses existing scenario_events rows and does not
 * modify runtime, webhook, queues, or scenario execution logic.
 */

defined('ASR_ADMIN') || exit;

function asr_tg_scenario_journal_event_labels(): array {
    return [
        'runtime_started' => 'Сценарий запущен',
        'runtime_message_sent' => 'Отправлено сообщение',
        'runtime_question_waiting' => 'Ожидаем ответ подписчика',
        'runtime_question_text_received' => 'Получен текстовый ответ',
        'runtime_question_answer_received' => 'Получен ответ по кнопке',
        'runtime_question_text_invalid' => 'Ответ не прошёл проверку',
        'runtime_question_answer_invalid' => 'Ответ не прошёл проверку',
        'runtime_question_next_auto' => 'Переход к следующему шагу',
        'runtime_callback_goto' => 'Переход по кнопке',
        'runtime_next_auto' => 'Переход к следующему шагу',
        'runtime_actions_next_auto' => 'Переход к следующему шагу',
        'runtime_delay_scheduled' => 'Поставлена задержка',
        'runtime_delay_queued' => 'Задержка завершена',
        'runtime_delay_skipped' => 'Задержка пропущена',
        'runtime_schedule_scheduled' => 'Поставлено расписание',
        'runtime_schedule_expired' => 'Дата расписания прошла',
        'runtime_condition_checked' => 'Проверено условие',
        'runtime_condition_branch_missing' => 'У ветки условия нет следующего шага',
        'runtime_random_selected' => 'Выбран случайный выход',
        'runtime_random_missing_next' => 'У случайного выхода нет следующего шага',
        'runtime_formula_executed' => 'Формула выполнена',
        'runtime_formula_line_failed' => 'Ошибка формулы',
        'runtime_actions_executed' => 'Действия выполнены',
        'runtime_action_tag_add' => 'Добавлен тег',
        'runtime_action_tag_remove' => 'Удалён тег',
        'runtime_action_field_set' => 'Изменено поле',
        'runtime_action_field_failed' => 'Поле не изменено',
        'runtime_action_staff_notify' => 'Отправлено уведомление сотруднику',
        'runtime_action_webhook_sent' => 'Отправлен Webhook',
        'runtime_action_external_request_sent' => 'Выполнен внешний запрос',
        'runtime_action_yandex_metrika_queued' => 'Событие отправлено в очередь Яндекс.Метрики',
        'runtime_action_stop_scenario' => 'Сценарий остановлен действием',
        'runtime_manual_stopped' => 'Сценарий остановлен вручную',
        'runtime_failed' => 'Ошибка выполнения сценария',
        'runtime_block_missing' => 'Блок не найден',
        'runtime_block_unsupported' => 'Тип блока не поддержан',
        'runtime_guard_loop_detected' => 'Обнаружено зацикливание',
        'runtime_guard_too_long' => 'Сценарий остановлен защитой',
        'runtime_scenario_missing' => 'Сценарий не найден',
        'runtime_scenario_not_active' => 'Сценарий не активен',
        'runtime_bot_mismatch' => 'Сценарий привязан к другому каналу',
        'runtime_entry_missing' => 'Стартовый блок не найден',
    ];
}

function asr_tg_scenario_journal_is_noise(string $eventType): bool {
    $noise = [
        'runtime_legacy_cards_read_failed',
        'runtime_question_card_lookup_failed',
        'runtime_bot_link_repaired',
        'runtime_bot_link_repair_failed',
        'scenario_command_autofallback_created',
        'scenario_command_autofallback_skipped',
        'scenario_command_autofallback_persist_failed',
    ];
    return in_array($eventType, $noise, true);
}

function asr_tg_scenario_journal_dt(?string $value): string {
    if (function_exists('asr_tg_scenario_state_human_dt')) return asr_tg_scenario_state_human_dt($value);
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return '';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : $value;
}

function asr_tg_scenario_journal_payload(array $event): array {
    $raw = (string)($event['payload_json'] ?? '');
    if ($raw === '') return [];
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function asr_tg_scenario_journal_block_label(array $event): string {
    $blockId = (int)($event['block_id'] ?? 0);
    $title = trim((string)($event['block_title'] ?? ''));
    $type = trim((string)($event['block_type'] ?? ''));
    $typeLabel = function_exists('asr_tg_scenario_state_block_type_label') ? asr_tg_scenario_state_block_type_label($type) : ($type !== '' ? $type : 'Блок');
    $base = $title !== '' ? '«' . $title . '»' : $typeLabel;
    return $blockId > 0 ? $base . ' #' . $blockId : $base;
}

function asr_tg_scenario_journal_event_tone(string $eventType): string {
    if (str_contains($eventType, 'failed') || str_contains($eventType, 'invalid') || str_contains($eventType, 'missing') || str_contains($eventType, 'error') || str_contains($eventType, 'loop') || str_contains($eventType, 'too_long')) return 'is-error';
    if (str_contains($eventType, 'waiting') || str_contains($eventType, 'scheduled')) return 'is-waiting';
    if (str_contains($eventType, 'stopped') || str_contains($eventType, 'stop_scenario')) return 'is-stopped';
    return 'is-normal';
}

function asr_tg_scenario_journal_short_value($value, int $limit = 120): string {
    if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $value = trim((string)$value);
    if ($value === '') return '';
    return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit - 1, 'UTF-8') . '…' : $value;
}

function asr_tg_scenario_journal_human_text(array $event): string {
    $eventType = (string)($event['event_type'] ?? '');
    $payload = asr_tg_scenario_journal_payload($event);
    $block = asr_tg_scenario_journal_block_label($event);
    $nextBlockId = (int)($payload['next_block_id'] ?? $payload['target_block_id'] ?? 0);
    $answer = asr_tg_scenario_journal_short_value($payload['answer'] ?? $payload['button_text'] ?? '');
    $rawText = trim((string)($event['event_text'] ?? ''));

    switch ($eventType) {
        case 'runtime_started':
            return 'Запущен сценарий. Стартовый блок: ' . $block . '.';
        case 'runtime_message_sent':
            return 'Отправлено сообщение из блока ' . $block . '.';
        case 'runtime_question_waiting':
            return 'Вопрос отправлен, ждём ответ подписчика на блоке ' . $block . '.';
        case 'runtime_question_text_received':
            return 'Получен текстовый ответ на блоке ' . $block . '.';
        case 'runtime_question_answer_received':
            return $answer !== ''
                ? 'Подписчик выбрал ответ «' . $answer . '» на блоке ' . $block . '.'
                : 'Получен ответ по кнопке на блоке ' . $block . '.';
        case 'runtime_question_text_invalid':
        case 'runtime_question_answer_invalid':
            $error = asr_tg_scenario_journal_short_value($payload['error'] ?? '');
            return $error !== '' ? 'Ответ не прошёл проверку: ' . $error . '.' : 'Ответ на блоке ' . $block . ' не прошёл проверку.';
        case 'runtime_question_next_auto':
        case 'runtime_next_auto':
        case 'runtime_actions_next_auto':
            return $nextBlockId > 0 ? 'Автоматический переход к блоку #' . $nextBlockId . '.' : 'Автоматический переход к следующему шагу.';
        case 'runtime_callback_goto':
            return $nextBlockId > 0 ? 'Переход по кнопке к блоку #' . $nextBlockId . '.' : 'Переход по кнопке сценария.';
        case 'runtime_delay_scheduled':
            $nextRunAt = asr_tg_scenario_journal_dt($payload['next_run_at'] ?? '');
            $target = $nextBlockId > 0 ? ' Следующий блок: #' . $nextBlockId . '.' : '';
            return $nextRunAt !== '' ? 'Поставлена задержка до ' . $nextRunAt . '.' . $target : 'Поставлена задержка.' . $target;
        case 'runtime_delay_queued':
            return 'Задержка завершена, продолжение поставлено в очередь.';
        case 'runtime_schedule_scheduled':
            $nextRunAt = asr_tg_scenario_journal_dt($payload['next_run_at'] ?? $payload['target_at'] ?? '');
            $target = $nextBlockId > 0 ? ' Следующий блок: #' . $nextBlockId . '.' : '';
            return $nextRunAt !== '' ? 'Продолжение запланировано на ' . $nextRunAt . '.' . $target : 'Продолжение поставлено в расписание.' . $target;
        case 'runtime_schedule_expired':
            return 'Дата расписания уже прошла.';
        case 'runtime_condition_checked':
            $branch = asr_tg_scenario_journal_short_value($payload['branch_label'] ?? $payload['branch'] ?? '');
            $matched = isset($payload['matched_count'], $payload['total_count']) ? ((int)$payload['matched_count'] . ' из ' . (int)$payload['total_count']) : '';
            $parts = [];
            if ($branch !== '') $parts[] = 'ветка «' . $branch . '»';
            if ($matched !== '') $parts[] = 'совпало ' . $matched;
            return $parts ? 'Условие проверено: ' . implode(', ', $parts) . '.' : 'Условие на блоке ' . $block . ' проверено.';
        case 'runtime_random_selected':
            $label = asr_tg_scenario_journal_short_value($payload['label'] ?? $payload['exit_label'] ?? $payload['selected_label'] ?? '');
            $percent = asr_tg_scenario_journal_short_value($payload['percent'] ?? $payload['weight'] ?? '');
            $tail = $label !== '' ? 'Выбран выход «' . $label . '»' : 'Выбран случайный выход';
            if ($percent !== '') $tail .= ' (' . $percent . '%)';
            return $tail . '.';
        case 'runtime_formula_executed':
            $lines = (int)($payload['executed_lines'] ?? 0);
            return $lines > 0 ? 'Формула выполнена: строк — ' . $lines . '.' : 'Формула выполнена.';
        case 'runtime_actions_executed':
            $executed = (int)($payload['executed'] ?? 0);
            $skipped = (int)($payload['skipped'] ?? 0);
            $tail = [];
            if ($executed > 0) $tail[] = 'выполнено — ' . $executed;
            if ($skipped > 0) $tail[] = 'ожидают будущих этапов — ' . $skipped;
            return $tail ? 'Блок «Действия» выполнен: ' . implode(', ', $tail) . '.' : 'Блок «Действия» выполнен.';
        case 'runtime_action_field_failed':
        case 'runtime_formula_line_failed':
        case 'runtime_failed':
            return $rawText !== '' ? $rawText : 'Ошибка выполнения на блоке ' . $block . '.';
        case 'runtime_manual_stopped':
            return 'Сценарий остановлен вручную из карточки подписчика.';
    }

    $labels = asr_tg_scenario_journal_event_labels();
    if (isset($labels[$eventType])) return $rawText !== '' ? $rawText : $labels[$eventType] . '.';
    return $rawText !== '' ? $rawText : 'Событие сценария: ' . $eventType . '.';
}

function asr_tg_subscriber_scenario_journal_events(PDO $pdo, int $subscriberId, int $botId = 0, int $limit = 40): array {
    $subscriberId = max(0, $subscriberId);
    $limit = max(5, min(200, $limit));
    if ($subscriberId <= 0) return [];

    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_events')) return [];
        $subscriberIds = function_exists('asr_tg_subscriber_scenario_related_subscriber_ids')
            ? asr_tg_subscriber_scenario_related_subscriber_ids($pdo, $subscriberId)
            : [$subscriberId];
        $subscriberIds = array_values(array_unique(array_filter(array_map('intval', $subscriberIds), static fn($id) => $id > 0)));
        if (!$subscriberIds) return [];

        $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));
        $params = $subscriberIds;
        $botSql = '';
        if ($botId > 0) {
            // Do not restrict by bot by default: subscriber card intentionally shows all channels of the same Telegram user.
            // Left here only for possible future narrow widgets.
        }

        $sql = "SELECT ev.*, sc.title AS scenario_title, bl.title AS block_title, bl.type AS block_type, b.title AS bot_title, b.bot_username AS bot_username
                FROM oca_telegram_bot_scenario_events ev
                LEFT JOIN oca_telegram_bot_scenarios sc ON sc.id = ev.scenario_id
                LEFT JOIN oca_telegram_bot_scenario_blocks bl ON bl.id = ev.block_id AND bl.scenario_id = ev.scenario_id
                LEFT JOIN oca_telegram_bots b ON b.id = ev.bot_id
                WHERE ev.subscriber_id IN ({$placeholders}) {$botSql}
                ORDER BY ev.created_at DESC, ev.id DESC
                LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter($rows, static fn($row) => !asr_tg_scenario_journal_is_noise((string)($row['event_type'] ?? ''))));
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_journal_read_failed', $e->getMessage(), ['subscriber_id' => $subscriberId]); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_render_subscriber_scenario_journal(PDO $pdo, int $subscriberId, int $botId, callable $h, int $limit = 40): void {
    $limit = max(80, min(200, $limit));
    $events = asr_tg_subscriber_scenario_journal_events($pdo, $subscriberId, $botId, $limit);
    $labels = asr_tg_scenario_journal_event_labels();
    $modalId = 'tg-scenario-journal-modal-' . max(0, $subscriberId) . '-' . substr(md5((string)$subscriberId . ':' . (string)$botId), 0, 8);
    static $scenarioJournalAssetsPrinted = false;
    if (!$scenarioJournalAssetsPrinted):
        $scenarioJournalAssetsPrinted = true;
        ?>
        <style>
            .tg-scenario-journal-box{padding:0 14px 22px}.tg-scenario-journal-card{border:1px solid #edf0f2;background:#fff;border-radius:18px;padding:13px 14px;box-shadow:0 8px 20px rgba(15,23,42,.035)}.tg-scenario-journal-card-top{display:flex;align-items:center;justify-content:space-between;gap:12px}.tg-scenario-journal-card-text{font-size:12px;font-weight:500;color:#8b929e;line-height:1.45}.tg-scenario-journal-open{border:1px solid #fed7aa;background:#fff7ed;color:#c2410c;border-radius:12px;padding:9px 13px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap}.tg-scenario-journal-open:hover{background:#ffedd5}.tg-scenario-journal-empty{border:1px dashed #e5e7eb;background:#fafafa;border-radius:16px;padding:14px;color:#8b929e;font-size:13px;font-weight:500;line-height:1.45}.tg-scenario-journal-modal-backdrop{position:fixed;inset:0;z-index:220;background:rgba(17,24,39,.48);display:none;align-items:center;justify-content:center;padding:18px}.tg-scenario-journal-modal-backdrop.is-open{display:flex}.tg-scenario-journal-modal{width:min(860px,100%);max-height:calc(100vh - 36px);overflow:hidden;background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.28);display:flex;flex-direction:column}.tg-scenario-journal-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:22px 24px 14px;border-bottom:1px solid #eef0f4}.tg-scenario-journal-modal-title{font-size:19px;font-weight:600;color:#20272d;line-height:1.2}.tg-scenario-journal-modal-sub{margin-top:5px;font-size:12px;font-weight:500;color:#8b929e;line-height:1.4}.tg-scenario-journal-close{width:38px;height:38px;border:0;background:#f4f4f5;border-radius:14px;color:#6b7280;font-size:24px;font-weight:500;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.tg-scenario-journal-close:hover{background:#fff7ed;color:#9a5a17}.tg-scenario-journal-modal-body{padding:18px 24px 24px;overflow:auto}.tg-scenario-journal-list{position:relative;padding-left:4px}.tg-scenario-journal-item{position:relative;display:grid;grid-template-columns:18px minmax(0,1fr);gap:8px;margin:0 0 12px}.tg-scenario-journal-item:before{content:"";position:absolute;left:6px;top:17px;bottom:-13px;width:1px;background:#edf0f2}.tg-scenario-journal-item:last-child:before{display:none}.tg-scenario-journal-dot{width:13px;height:13px;border-radius:999px;background:#d1d5db;border:3px solid #fff;box-shadow:0 0 0 1px #e5e7eb;margin-top:4px}.tg-scenario-journal-item.is-normal .tg-scenario-journal-dot{background:#FFA048}.tg-scenario-journal-item.is-waiting .tg-scenario-journal-dot{background:#f59e0b}.tg-scenario-journal-item.is-error .tg-scenario-journal-dot{background:#ef4444}.tg-scenario-journal-item.is-stopped .tg-scenario-journal-dot{background:#9ca3af}.tg-scenario-journal-content{background:#f8fafc;border:1px solid #edf0f2;border-radius:15px;padding:10px 11px}.tg-scenario-journal-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px}.tg-scenario-journal-title{font-size:12px;font-weight:600;color:#20272d;line-height:1.25}.tg-scenario-journal-time{font-size:10px;font-weight:500;color:#a1a1aa;white-space:nowrap}.tg-scenario-journal-text{font-size:12px;font-weight:500;color:#374151;line-height:1.45}.tg-scenario-journal-meta{margin-top:6px;font-size:10px;font-weight:500;color:#9ca3af;line-height:1.35}.tg-scenario-journal-item.is-error .tg-scenario-journal-content{background:#fff7f7;border-color:#fee2e2}.tg-scenario-journal-item.is-error .tg-scenario-journal-title{color:#b91c1c}.tg-scenario-journal-note{margin-top:14px;border:1px solid #edf0f2;background:#fafafa;border-radius:14px;padding:11px 12px;font-size:12px;font-weight:500;color:#8b929e;line-height:1.45}@media(max-width:720px){.tg-scenario-journal-modal-backdrop{padding:10px}.tg-scenario-journal-modal{max-height:calc(100vh - 20px);border-radius:18px}.tg-scenario-journal-modal-head{padding:18px 18px 12px}.tg-scenario-journal-modal-body{padding:16px 18px 20px}.tg-scenario-journal-card-top{align-items:flex-start;flex-direction:column}.tg-scenario-journal-open{width:100%}}
        </style>
        <script>
            (function(){
                if (window.__tgScenarioJournalModalReady) return;
                window.__tgScenarioJournalModalReady = true;
                document.addEventListener('click', function(event){
                    var openBtn = event.target.closest('[data-scenario-journal-open]');
                    if (openBtn) {
                        event.preventDefault();
                        var modal = document.getElementById(openBtn.getAttribute('data-scenario-journal-open'));
                        if (modal) {
                            modal.classList.add('is-open');
                            modal.setAttribute('aria-hidden', 'false');
                        }
                        return;
                    }
                    if (event.target.closest('[data-scenario-journal-close]')) {
                        event.preventDefault();
                        var closeModal = event.target.closest('.tg-scenario-journal-modal-backdrop');
                        if (closeModal) {
                            closeModal.classList.remove('is-open');
                            closeModal.setAttribute('aria-hidden', 'true');
                        }
                        return;
                    }
                    if (event.target.classList && event.target.classList.contains('tg-scenario-journal-modal-backdrop')) {
                        event.target.classList.remove('is-open');
                        event.target.setAttribute('aria-hidden', 'true');
                    }
                });
                document.addEventListener('keydown', function(event){
                    if (event.key !== 'Escape') return;
                    document.querySelectorAll('.tg-scenario-journal-modal-backdrop.is-open').forEach(function(modal){
                        modal.classList.remove('is-open');
                        modal.setAttribute('aria-hidden', 'true');
                    });
                });
            })();
        </script>
        <?php
    endif;
    ?>
    <div class="tg-info-section tg-scenario-journal-section">Журнал сценариев</div>
    <div class="tg-scenario-journal-box">
        <?php if (!$events): ?>
            <div class="tg-scenario-journal-empty">Истории прохождения сценариев пока нет.</div>
        <?php else: ?>
            <div class="tg-scenario-journal-card">
                <div class="tg-scenario-journal-card-top">
                    <div class="tg-scenario-journal-card-text">Есть события прохождения сценариев: <?php echo (int)count($events); ?>. Откройте журнал, чтобы посмотреть историю без перегруза карточки.</div>
                    <button type="button" class="tg-scenario-journal-open" data-scenario-journal-open="<?php echo $h($modalId); ?>">Журнал</button>
                </div>
            </div>
            <div class="tg-scenario-journal-modal-backdrop" id="<?php echo $h($modalId); ?>" aria-hidden="true">
                <div class="tg-scenario-journal-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo $h($modalId); ?>-title">
                    <div class="tg-scenario-journal-modal-head">
                        <div>
                            <div class="tg-scenario-journal-modal-title" id="<?php echo $h($modalId); ?>-title">Журнал сценариев</div>
                            <div class="tg-scenario-journal-modal-sub">Последние <?php echo (int)count($events); ?> событий по этому подписчику во всех его каналах.</div>
                        </div>
                        <button type="button" class="tg-scenario-journal-close" data-scenario-journal-close aria-label="Закрыть">×</button>
                    </div>
                    <div class="tg-scenario-journal-modal-body">
                        <div class="tg-scenario-journal-list">
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $eventType = (string)($event['event_type'] ?? '');
                                    $title = $labels[$eventType] ?? 'Событие сценария';
                                    $text = asr_tg_scenario_journal_human_text($event);
                                    $time = asr_tg_scenario_journal_dt($event['created_at'] ?? '');
                                    $scenarioTitle = trim((string)($event['scenario_title'] ?? ''));
                                    if ($scenarioTitle === '') $scenarioTitle = ((int)($event['scenario_id'] ?? 0) > 0 ? 'Сценарий #' . (int)$event['scenario_id'] : 'Сценарий');
                                    $botTitle = trim((string)($event['bot_title'] ?? ''));
                                    $botUsername = trim((string)($event['bot_username'] ?? ''));
                                    $tone = asr_tg_scenario_journal_event_tone($eventType);
                                ?>
                                <div class="tg-scenario-journal-item <?php echo $h($tone); ?>">
                                    <div class="tg-scenario-journal-dot"></div>
                                    <div class="tg-scenario-journal-content">
                                        <div class="tg-scenario-journal-top">
                                            <span class="tg-scenario-journal-title"><?php echo $h($title); ?></span>
                                            <?php if ($time !== ''): ?><span class="tg-scenario-journal-time"><?php echo $h($time); ?></span><?php endif; ?>
                                        </div>
                                        <div class="tg-scenario-journal-text"><?php echo $h($text); ?></div>
                                        <div class="tg-scenario-journal-meta"><?php echo $h($scenarioTitle); ?><?php if ($botTitle !== '' || $botUsername !== ''): ?> · <?php echo $h($botTitle !== '' ? $botTitle : 'Канал'); ?><?php echo $botUsername !== '' ? ' · @' . $h(ltrim($botUsername, '@')) : ''; ?><?php endif; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($events) >= $limit): ?>
                            <div class="tg-scenario-journal-note">Показаны последние <?php echo (int)$limit; ?> событий. Старые записи лучше хранить ограниченное время или переносить в архив, чтобы журнал не превращался в склад вечной памяти.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
