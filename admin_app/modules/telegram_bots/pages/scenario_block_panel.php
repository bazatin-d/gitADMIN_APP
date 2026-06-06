<?php
defined('ASR_ADMIN') || exit;

asr_tg_repository_ensure_scenario_schema($pdo);

$h = $h ?? static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$safeJson = static function($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? '[]' : $json;
};
$mediaUrl = static function(array $card): string {
    $url = trim((string)($card['media_file_path'] ?? '')) ?: trim((string)($card['media_url'] ?? ''));
    if ($url === '') return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    return '/' . ltrim($url, '/');
};

$scenarioId = (int)($_GET['scenario_id'] ?? 0);
$blockId = (int)($_GET['block_id'] ?? ($_GET['id'] ?? 0));
$scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
$block = null;
if ($blockId > 0) {
    $block = $scenario ? asr_tg_scenario_block_find($pdo, $blockId, $scenarioId) : asr_tg_scenario_block_find($pdo, $blockId);
    if (!$block) {
        // Safety fallback: if an old or cached URL lost the scenario_id, recover it by block id.
        $block = asr_tg_scenario_block_find($pdo, $blockId);
    }
    if ($block && (!$scenario || (int)($block['scenario_id'] ?? 0) !== $scenarioId)) {
        $scenarioId = (int)($block['scenario_id'] ?? 0);
        $scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
    }
}
if (!$scenario || !$block) {
    echo '<section id="tg-flow-panel" class="tg-flow-panel"><div class="tg-flow-panel-head"><div class="tg-flow-panel-title">Блок не найден</div><button type="button" class="tg-flow-drawer-close">×</button></div><div class="tg-flow-panel-body"><div class="tg-flow-panel-alert is-open">Не удалось найти блок сценария.</div></div></section>';
    return;
}

$type = (string)($block['type'] ?? 'message');
if ($type === 'start') {
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <div class="tg-flow-panel-head">
            <div><div class="tg-flow-panel-title">Старт сценария</div><div class="tg-flow-panel-subtitle">Пока запускаем сценарий по кнопке «Начать».</div></div>
            <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
        </div>
        <div class="tg-flow-panel-body">
            <div class="tg-flow-card">
                <div class="tg-flow-card-title">Запуск по кнопке «Начать»</div>
                <div class="tg-flow-card-sub" style="font-size:13px;line-height:1.6;margin-top:10px;color:#6b7280">Бот будет запускать сценарий при нажатии кнопки «Начать» или команде /start. Диплинки и ключевые слова добавим отдельным шагом.</div>
            </div>
        </div>
        <div class="tg-flow-panel-footer"><button type="button" class="tg-flow-panel-secondary tg-flow-drawer-close">Закрыть</button></div>
    </section>
    <?php
    return;
}

if ($type === 'delay') {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $delayMode = (string)($settings['delay_mode'] ?? $settings['mode'] ?? 'after');
    if (!in_array($delayMode, ['after', 'tomorrow', 'at'], true)) $delayMode = 'after';
    $delayValue = max(1, min(999, (int)($settings['delay_value'] ?? $settings['value'] ?? 1)));
    $delayUnit = (string)($settings['delay_unit'] ?? $settings['unit'] ?? 'days');
    if (!in_array($delayUnit, ['seconds', 'minutes', 'hours', 'days'], true)) $delayUnit = 'days';
    $sendTimeMode = (string)($settings['send_time_mode'] ?? 'any');
    if (!in_array($sendTimeMode, ['any', 'exact', 'interval'], true)) $sendTimeMode = 'any';
    $normalizeTime = static function($value, string $fallback = '00:00'): string {
        $time = trim((string)$value);
        if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) return $fallback;
        [$h, $m] = array_map('intval', explode(':', $time));
        return sprintf('%02d:%02d', $h, $m);
    };
    $sendTimeExact = $normalizeTime($settings['send_time_exact'] ?? '00:00');
    $sendTimeFrom = $normalizeTime($settings['send_time_from'] ?? '00:00');
    $sendTimeTo = $normalizeTime($settings['send_time_to'] ?? '00:00');
    $timezone = trim((string)($settings['timezone'] ?? 'Europe/Moscow')) ?: 'Europe/Moscow';
    $timezonePriorityLabels = [
        'Asia/Almaty' => 'Asia/Almaty — Алматы, Астана',
        'Europe/Moscow' => 'Europe/Moscow — Москва',
        'Asia/Yekaterinburg' => 'Asia/Yekaterinburg — Екатеринбург',
        'Asia/Tashkent' => 'Asia/Tashkent — Ташкент',
        'Asia/Dubai' => 'Asia/Dubai — Дубай',
        'Europe/Istanbul' => 'Europe/Istanbul — Стамбул',
        'UTC' => 'UTC — всемирное время',
    ];
    $timezoneIds = [];
    try {
        $timezoneIds = timezone_identifiers_list(DateTimeZone::ALL);
    } catch (Throwable $e) {
        $timezoneIds = timezone_identifiers_list();
    }
    if (!in_array('UTC', $timezoneIds, true)) $timezoneIds[] = 'UTC';
    if (!in_array($timezone, $timezoneIds, true)) $timezoneIds[] = $timezone;
    $timezoneChoices = [];
    foreach ($timezonePriorityLabels as $tzCode => $tzLabel) {
        if (in_array($tzCode, $timezoneIds, true)) $timezoneChoices[$tzCode] = ['label' => $tzLabel, 'popular' => true];
    }
    sort($timezoneIds, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($timezoneIds as $tzCode) {
        $tzCode = trim((string)$tzCode);
        if ($tzCode === '' || isset($timezoneChoices[$tzCode])) continue;
        $timezoneChoices[$tzCode] = ['label' => $tzCode, 'popular' => false];
    }
    $tzOffset = static function(string $tz): string {
        try {
            $zone = new DateTimeZone($tz);
            $offset = $zone->getOffset(new DateTimeImmutable('now', $zone));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            return 'GMT' . $sign . sprintf('%02d:%02d', intdiv($offset, 3600), intdiv($offset % 3600, 60));
        } catch (Throwable $e) {
            return 'GMT+00:00';
        }
    };
    $timezoneTitle = '(' . $tzOffset($timezone) . ') ' . $timezone;
    $lower = static function(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $allowedWeekdays = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $weekdays = $settings['weekdays'] ?? array_keys($allowedWeekdays);
    if (!is_array($weekdays) || !$weekdays) $weekdays = array_keys($allowedWeekdays);
    $weekdays = array_values(array_intersect(array_keys($allowedWeekdays), array_map('strval', $weekdays)));
    if (!$weekdays) $weekdays = array_keys($allowedWeekdays);
    $deeplink = null;
    try {
        if (function_exists('asr_tg_scenario_block_deeplink_find')) {
            $deeplink = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
            if ($deeplink && function_exists('asr_tg_scenario_deeplink_url')) {
                $deeplink['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $deeplink);
            }
        }
    } catch (Throwable $e) {
        $deeplink = null;
    }
    $deeplinkCode = is_array($deeplink) ? trim((string)($deeplink['code'] ?? $deeplink['token'] ?? '')) : '';
    $deeplinkUrl = is_array($deeplink) ? trim((string)($deeplink['url'] ?? '')) : '';
    $csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
    $blockMeta = [
        'scenarioId' => $scenarioId,
        'blockId' => $blockId,
        'hasDeeplink' => ($deeplinkCode !== '' || $deeplinkUrl !== ''),
    ];
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <form method="POST" id="scenario-message-form" class="tg-flow-panel-form">
            <div class="tg-flow-panel-head">
                <div>
                    <div class="tg-flow-panel-title">Редактировать блок «Задержка»</div>
                    <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
                </div>
                <div class="tg-flow-panel-actions">
                    <div class="tg-flow-panel-menu-wrap">
                        <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                        <div class="tg-flow-panel-dropdown" data-panel-menu>
                            <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                            <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                        </div>
                    </div>
                    <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
                </div>
            </div>
            <div class="tg-flow-panel-body">
                <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
                <input type="hidden" name="action" value="tg_scenario_block_save">
                <input type="hidden" name="return_page" value="scenario_flow">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
                <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
                <input type="hidden" name="block_type" value="delay">
                <input type="hidden" name="scenario_cards_json" id="scenario-cards-json" value="[]">
                <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

                <label class="tg-flow-panel-field">
                    <span class="tg-flow-panel-label">Название блока</span>
                    <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Задержка')); ?>" maxlength="190">
                </label>

                <div class="tg-flow-delay-box" data-delay-settings>
                    <h3>Задержка</h3>
                    <p>Через какое время отправить следующее сообщение?</p>
                    <label class="tg-flow-panel-field">
                        <span class="tg-flow-panel-label">Тип задержки</span>
                        <select class="tg-flow-panel-input" name="delay_mode" data-delay-mode>
                            <option value="after" <?php echo $delayMode === 'after' ? 'selected' : ''; ?>>Отправить через</option>
                            <option value="tomorrow" <?php echo $delayMode === 'tomorrow' ? 'selected' : ''; ?>>Отправить завтра</option>
                            <option value="at" <?php echo $delayMode === 'at' ? 'selected' : ''; ?>>Отправить в</option>
                        </select>
                    </label>

                    <div class="tg-flow-delay-row" data-delay-after>
                        <label class="tg-flow-panel-field tg-flow-delay-value">
                            <span class="tg-flow-panel-label">Значение</span>
                            <input class="tg-flow-panel-input" type="number" name="delay_value" min="1" max="999" step="1" value="<?php echo (int)$delayValue; ?>">
                        </label>
                        <label class="tg-flow-panel-field tg-flow-delay-unit">
                            <span class="tg-flow-panel-label">Единица времени</span>
                            <select class="tg-flow-panel-input" name="delay_unit">
                                <option value="seconds" <?php echo $delayUnit === 'seconds' ? 'selected' : ''; ?>>секунд</option>
                                <option value="minutes" <?php echo $delayUnit === 'minutes' ? 'selected' : ''; ?>>минут</option>
                                <option value="hours" <?php echo $delayUnit === 'hours' ? 'selected' : ''; ?>>часов</option>
                                <option value="days" <?php echo $delayUnit === 'days' ? 'selected' : ''; ?>>дней</option>
                            </select>
                        </label>
                    </div>

                    <div class="tg-flow-delay-section" data-delay-time-section>
                        <h3>Время отправки</h3>
                        <p>Выберите удобное время для получателя</p>
                        <label class="tg-flow-panel-field" data-delay-time-mode-wrap>
                            <span class="tg-flow-panel-label">Время отправки</span>
                            <select class="tg-flow-panel-input" name="send_time_mode" data-delay-time-mode>
                                <option value="any" <?php echo $sendTimeMode === 'any' ? 'selected' : ''; ?>>Любое</option>
                                <option value="exact" <?php echo $sendTimeMode === 'exact' ? 'selected' : ''; ?>>Точное время</option>
                                <option value="interval" <?php echo $sendTimeMode === 'interval' ? 'selected' : ''; ?>>Временной интервал</option>
                            </select>
                        </label>
                        <div class="tg-flow-delay-time-grid" data-delay-exact>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_exact" value="<?php echo $h($sendTimeExact); ?>">
                            <div class="tg-flow-delay-tz"><span data-tz-current><?php echo $h($timezoneTitle); ?></span><input type="hidden" name="timezone" data-tz-value value="<?php echo $h($timezone); ?>"><button type="button" data-tz-open>Изменить часовой пояс</button></div>
                        </div>
                        <div class="tg-flow-delay-time-grid is-interval" data-delay-interval>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_from" value="<?php echo $h($sendTimeFrom); ?>">
                            <span class="tg-flow-delay-dash">—</span>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_to" value="<?php echo $h($sendTimeTo); ?>">
                            <div class="tg-flow-delay-tz"><span data-tz-current><?php echo $h($timezoneTitle); ?></span><button type="button" data-tz-open>Изменить часовой пояс</button></div>
                        </div>
                        <div class="tg-flow-delay-warn" data-delay-interval-warn>Будет произведена задержка как минимум на выбранный срок от предыдущего шага и затем ожидание указанного интервала.</div>
                    </div>

                    <div class="tg-flow-delay-section">
                        <h3>Дни отправки</h3>
                        <div class="tg-flow-delay-weekdays">
                            <?php foreach ($allowedWeekdays as $code => $label): ?>
                                <label><input type="checkbox" name="weekdays[]" value="<?php echo $h($code); ?>" <?php echo in_array($code, $weekdays, true) ? 'checked' : ''; ?>> <span><?php echo $h($label); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="tg-flow-delay-help">Важно! Этот шаг будет отправлен только в выбранные дни недели.</div>
                    </div>
                    <div class="tg-flow-delay-runtime-note">Runtime-подсказка: блок требует следующий шаг. Пока это используется для подсветки на холсте, позже — для runner задержек.</div>
                </div>
                <div class="tg-delay-tz-modal" data-tz-modal aria-hidden="true">
                    <div class="tg-delay-tz-card" role="dialog" aria-modal="true" aria-label="Часовой пояс задержки">
                        <div class="tg-delay-tz-head"><h3>Часовой пояс</h3><button type="button" data-tz-close aria-label="Закрыть">×</button></div>
                        <p>Выберите, по какому времени считать точное время и интервалы отправки.</p>
                        <label class="tg-flow-panel-field tg-delay-tz-search-field">
                            <span class="tg-flow-panel-label">Поиск</span>
                            <input class="tg-flow-panel-input" type="search" data-tz-search placeholder="Например: Алматы, Moscow, GMT+05 или Asia/Almaty" autocomplete="off">
                        </label>
                        <div class="tg-delay-tz-list" data-tz-list>
                            <?php foreach ($timezoneChoices as $tzCode => $tzInfo): ?>
                                <?php
                                $tzLabel = is_array($tzInfo) ? (string)($tzInfo['label'] ?? $tzCode) : (string)$tzInfo;
                                $tzPopular = is_array($tzInfo) && !empty($tzInfo['popular']);
                                $tzTitle = '(' . $tzOffset($tzCode) . ') ' . $tzCode;
                                $tzSearch = $lower($tzCode . ' ' . $tzLabel . ' ' . $tzTitle);
                                ?>
                                <button type="button" class="tg-delay-tz-option<?php echo $tzCode === $timezone ? ' is-selected' : ''; ?><?php echo $tzPopular ? ' is-popular' : ''; ?>" data-tz-option value="<?php echo $h($tzCode); ?>" data-title="<?php echo $h($tzTitle); ?>" data-search="<?php echo $h($tzSearch); ?>">
                                    <span><?php echo $h($tzTitle); ?></span>
                                    <small><?php echo $h($tzLabel); ?><?php echo $tzPopular ? ' · часто используется' : ''; ?></small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tg-delay-tz-empty" data-tz-empty>Ничего не найдено. Попробуйте город, регион или GMT-смещение.</div>
                        <div class="tg-delay-tz-actions"><button type="button" class="tg-flow-panel-secondary" data-tz-close>Отмена</button><button type="button" class="tg-flow-panel-primary" data-tz-apply>Применить</button></div>
                    </div>
                </div>
            </div>
            <div class="tg-flow-panel-footer">
                <button type="submit" class="tg-flow-panel-primary">Сохранить блок</button>
            </div>
        </form>
    </section>
    <style data-flow-panel-style="scenario-delay-panel-v3.5.110">
    .tg-flow-delay-box{border:1px solid #e7ebf1;background:#fff;border-radius:18px;padding:20px;margin-top:12px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
    .tg-flow-delay-box h3,.tg-flow-delay-section h3{font-size:16px;font-weight:720;color:#1f2937;margin:0 0 10px;line-height:1.3}.tg-flow-delay-box p,.tg-flow-delay-section p{font-size:13px;color:#6b7280;margin:0 0 18px;line-height:1.5}.tg-flow-delay-box .tg-flow-panel-field{display:flex;flex-direction:column;gap:8px;margin:0 0 18px}.tg-flow-delay-box .tg-flow-panel-label{display:block;margin:0!important;line-height:1.25!important}.tg-flow-delay-box .tg-flow-panel-input{min-height:44px}.tg-flow-delay-row{display:grid;grid-template-columns:minmax(110px,176px) minmax(0,1fr);gap:16px;align-items:start;margin:2px 0 24px}.tg-flow-delay-row .tg-flow-panel-field{margin-bottom:0}.tg-flow-delay-section{margin-top:28px;padding-top:4px}.tg-flow-delay-section:first-of-type{margin-top:26px}.tg-flow-delay-time-grid{display:grid;grid-template-columns:112px minmax(0,1fr);gap:14px;align-items:center;margin-top:10px}.tg-flow-delay-time-grid.is-interval{grid-template-columns:112px 18px 112px minmax(0,1fr)}.tg-flow-delay-time{max-width:120px}.tg-flow-delay-dash{color:#9ca3af;text-align:center}.tg-flow-delay-tz{font-size:13px;color:#6b7280;line-height:1.45;min-width:0}.tg-flow-delay-tz button{display:block;border:0;background:transparent;color:#d97706;padding:3px 0 0;font-size:12px;font-weight:700;cursor:pointer;text-align:left}.tg-flow-delay-weekdays{display:flex;flex-wrap:wrap;gap:14px 16px;margin-top:10px}.tg-flow-delay-weekdays label{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:650;color:#374151}.tg-flow-delay-weekdays input{width:18px;height:18px;accent-color:#FFA048}.tg-flow-delay-help{margin-top:14px;font-size:12px;color:#6b7280;line-height:1.45}.tg-flow-delay-runtime-note{margin-top:18px;border-radius:14px;background:#f8fafc;color:#64748b;border:1px solid #e5e7eb;padding:10px 12px;font-size:12px;line-height:1.45}.tg-delay-tz-modal{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.36);padding:18px}.tg-delay-tz-modal.is-open{display:flex}.tg-delay-tz-card{width:min(520px,100%);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.24);padding:18px}.tg-delay-tz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.tg-delay-tz-head h3{margin:0;font-size:17px;font-weight:720;color:#1f2937}.tg-delay-tz-head button{border:0;background:#f3f4f6;color:#6b7280;border-radius:12px;width:34px;height:34px;font-size:22px;line-height:1;cursor:pointer}.tg-delay-tz-card p{margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.45}.tg-delay-tz-search-field{margin-bottom:10px}.tg-delay-tz-list{max-height:min(52vh,430px);overflow-y:auto;overflow-x:hidden;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc;padding:6px;scrollbar-gutter:stable}.tg-delay-tz-option{width:100%;max-width:100%;box-sizing:border-box;border:0;background:transparent;border-radius:12px;padding:10px 12px;text-align:left;cursor:pointer;display:block;color:#1f2937;white-space:normal;overflow:hidden}.tg-delay-tz-option:hover{background:#fff7ed}.tg-delay-tz-option.is-selected{background:#ffedd5;box-shadow:inset 0 0 0 1px rgba(249,115,22,.24)}.tg-delay-tz-option span{display:block;font-size:13px;font-weight:700;color:#1f2937;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-delay-tz-option small{display:block;margin-top:3px;font-size:12px;color:#6b7280;line-height:1.35;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-delay-tz-option.is-popular small{color:#9a5a09}.tg-delay-tz-empty{display:none;margin-top:10px;border-radius:14px;background:#f8fafc;border:1px dashed #d1d5db;padding:12px;color:#6b7280;font-size:13px;line-height:1.4}.tg-delay-tz-empty.is-visible{display:block}.tg-delay-tz-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}.tg-flow-delay-warn{display:none;margin-top:16px;border-radius:14px;background:#fff7ed;color:#5f4630;padding:12px 14px;font-size:13px;line-height:1.5}.tg-flow-delay-warn::before{content:'ⓘ';color:#d97706;font-weight:800;margin-right:8px}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-after],.tg-flow-delay-box[data-mode="at"] [data-delay-after]{display:none}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-time-mode-wrap],.tg-flow-delay-box[data-mode="at"] [data-delay-time-mode-wrap]{display:none}.tg-flow-delay-box[data-time-mode="any"] [data-delay-exact],.tg-flow-delay-box[data-time-mode="any"] [data-delay-interval],.tg-flow-delay-box[data-time-mode="exact"] [data-delay-interval],.tg-flow-delay-box[data-time-mode="interval"] [data-delay-exact]{display:none}.tg-flow-delay-box[data-time-mode="interval"] [data-delay-interval-warn]{display:block}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-exact],.tg-flow-delay-box[data-mode="at"] [data-delay-exact]{display:grid}
    @media(max-width:620px){.tg-flow-delay-box{padding:16px}.tg-flow-delay-row,.tg-flow-delay-time-grid,.tg-flow-delay-time-grid.is-interval{grid-template-columns:1fr}.tg-flow-delay-dash{text-align:left}.tg-flow-delay-time{max-width:none}.tg-delay-tz-actions{flex-direction:column-reverse}.tg-delay-tz-actions button{width:100%}}
    </style>
    <script data-flow-panel-script>
    (function(){
      var box = document.querySelector('[data-delay-settings]');
      if (!box || box.dataset.bound === '1') return;
      box.dataset.bound = '1';
      var mode = box.querySelector('[data-delay-mode]');
      var timeMode = box.querySelector('[data-delay-time-mode]');
      function sync(){
        var m = mode ? mode.value : 'after';
        var tm = timeMode ? timeMode.value : 'any';
        box.dataset.mode = m;
        box.dataset.timeMode = (m === 'after') ? tm : 'exact';
      }
      if (mode) mode.addEventListener('change', sync);
      if (timeMode) timeMode.addEventListener('change', sync);
      var tzModal = document.querySelector('[data-tz-modal]');
      var tzSearch = tzModal ? tzModal.querySelector('[data-tz-search]') : null;
      var tzList = tzModal ? tzModal.querySelector('[data-tz-list]') : null;
      var tzEmpty = tzModal ? tzModal.querySelector('[data-tz-empty]') : null;
      var tzInput = box.querySelector('[data-tz-value]');
      var selectedTz = tzInput && tzInput.value ? tzInput.value : 'Europe/Moscow';
      function tzOptions(){ return tzModal ? Array.prototype.slice.call(tzModal.querySelectorAll('[data-tz-option]')) : []; }
      function markSelected(value){
        selectedTz = value || selectedTz || 'Europe/Moscow';
        tzOptions().forEach(function(option){ option.classList.toggle('is-selected', option.getAttribute('value') === selectedTz); });
      }
      function filterTz(){
        var q = tzSearch ? (tzSearch.value || '').trim().toLowerCase() : '';
        var visible = 0;
        tzOptions().forEach(function(option){
          var haystack = (option.getAttribute('data-search') || option.textContent || '').toLowerCase();
          var show = !q || haystack.indexOf(q) !== -1;
          option.style.display = show ? '' : 'none';
          if (show) visible++;
        });
        if (tzEmpty) tzEmpty.classList.toggle('is-visible', visible === 0);
        if (tzList) tzList.scrollLeft = 0;
      }
      function openTz(){
        if (!tzModal || !tzList) return;
        if (tzInput && tzInput.value) selectedTz = tzInput.value;
        if (tzSearch) tzSearch.value = '';
        markSelected(selectedTz);
        filterTz();
        tzModal.classList.add('is-open');
        tzModal.setAttribute('aria-hidden', 'false');
        setTimeout(function(){
          try {
            if (tzSearch) tzSearch.focus();
            var selected = tzModal.querySelector('[data-tz-option].is-selected');
            if (selected) selected.scrollIntoView({ block: 'center', inline: 'nearest' });
            if (tzList) tzList.scrollLeft = 0;
          } catch(e){}
        }, 0);
      }
      function closeTz(){
        if (!tzModal) return;
        tzModal.classList.remove('is-open');
        tzModal.setAttribute('aria-hidden', 'true');
      }
      function applyTz(){
        if (!tzInput) { closeTz(); return; }
        var option = tzModal ? tzModal.querySelector('[data-tz-option].is-selected') : null;
        var value = option ? (option.getAttribute('value') || selectedTz) : selectedTz;
        var title = option ? (option.getAttribute('data-title') || option.textContent || value) : value;
        tzInput.value = value || 'Europe/Moscow';
        box.querySelectorAll('[data-tz-current]').forEach(function(el){ el.textContent = title; });
        closeTz();
      }
      box.querySelectorAll('[data-tz-open]').forEach(function(btn){ btn.addEventListener('click', openTz); });
      if (tzModal) {
        tzOptions().forEach(function(option){ option.addEventListener('click', function(){ markSelected(option.getAttribute('value') || 'Europe/Moscow'); }); });
        if (tzSearch) tzSearch.addEventListener('input', filterTz);
        tzModal.querySelectorAll('[data-tz-close]').forEach(function(btn){ btn.addEventListener('click', closeTz); });
        var applyBtn = tzModal.querySelector('[data-tz-apply]');
        if (applyBtn) applyBtn.addEventListener('click', applyTz);
        tzModal.addEventListener('click', function(event){ if (event.target === tzModal) closeTz(); });
      }
      document.addEventListener('keydown', function(event){ if (event.key === 'Escape') closeTz(); });

      var blockMeta = <?php echo $safeJson($blockMeta); ?>;
      var panel = document.getElementById('tg-flow-panel');
      var form = document.getElementById('scenario-message-form');
      var panelMenu = panel ? panel.querySelector('[data-panel-menu]') : null;
      var panelMenuToggle = panel ? panel.querySelector('[data-panel-menu-toggle]') : null;
      function closePanelMenu(){ if (panelMenu) panelMenu.classList.remove('is-open'); }
      function showPanelAlert(message){
        var alertBox = panel ? panel.querySelector('#scenario-message-alert') : null;
        if (alertBox) {
          alertBox.textContent = String(message || 'Ошибка');
          alertBox.classList.add('is-open');
          alertBox.scrollIntoView({block:'nearest', behavior:'smooth'});
        } else if (typeof window.tgScenarioFlowToast === 'function') {
          window.tgScenarioFlowToast(String(message || 'Ошибка'), 'error');
        }
      }
      async function postBlockAction(action, payload){
        payload = payload || {};
        if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
        var actionMap = {tg_scenario_duplicate_block: 'tg_scenario_block_duplicate'};
        var fd = new FormData();
        fd.set('action', actionMap[action] || action);
        fd.set('return_page', 'scenario_flow');
        fd.set('tg_ajax', '1');
        fd.set('scenario_id', String(blockMeta.scenarioId || ''));
        Object.keys(payload).forEach(function(key){ fd.set(key, String(payload[key] == null ? '' : payload[key])); });
        var csrf = form ? form.querySelector('input[name="csrf_token"]') : null;
        if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
        var response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        var text = await response.text();
        var json = null;
        try { json = text ? JSON.parse(text) : null; } catch(e) { json = null; }
        if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.');
        return json;
      }
      if (panelMenuToggle) {
        panelMenuToggle.addEventListener('click', function(event){ event.preventDefault(); event.stopPropagation(); if (panelMenu) panelMenu.classList.toggle('is-open'); });
      }
      if (panelMenu) panelMenu.addEventListener('click', function(event){ event.stopPropagation(); });
      document.addEventListener('click', closePanelMenu);
      var deeplinkBtn = panel ? panel.querySelector('[data-panel-deeplink]') : null;
      if (deeplinkBtn) deeplinkBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        if (blockMeta.hasDeeplink) return;
        try {
          await postBlockAction('tg_scenario_block_deeplink_create', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowToast === 'function') window.tgScenarioFlowToast('Диплинк создан. Ссылка появилась под блоком.');
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось создать диплинк.'); }
      });
      var duplicateBtn = panel ? panel.querySelector('[data-panel-duplicate]') : null;
      if (duplicateBtn) duplicateBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        try {
          await postBlockAction('tg_scenario_duplicate_block', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось дублировать блок.'); }
      });
      var deleteBtn = panel ? panel.querySelector('[data-panel-delete]') : null;
      if (deleteBtn) deleteBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        var ok = true;
        if (typeof window.tgScenarioFlowConfirm === 'function') {
          ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText:'Удалить', cancelText:'Отмена'});
        }
        if (!ok) return;
        try {
          await postBlockAction('tg_scenario_block_delete', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось удалить блок.'); }
      });
      sync();
    })();
    </script>
    <?php
    return;
}

$settings = json_decode((string)($block['settings_json'] ?? ''), true);
$cards = [];
if (is_array($settings) && isset($settings['cards']) && is_array($settings['cards'])) $cards = array_values($settings['cards']);
if (!$cards) $cards = [['type' => 'text', 'text' => (string)($block['message_text'] ?? ''), 'buttons' => []]];
$blocks = function_exists('asr_tg_scenario_blocks_all') ? asr_tg_scenario_blocks_all($pdo, $scenarioId) : [];
$questionFieldOptions = [];
$messageVariables = [
    ['group' => 'Системные', 'title' => 'Полное имя', 'token' => '{{full_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Имя', 'token' => '{{first_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Фамилия', 'token' => '{{last_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Username', 'token' => '{{username}}', 'icon' => '@'],
    ['group' => 'Системные', 'title' => 'Телефон', 'token' => '{{phone}}', 'icon' => '☎'],
    ['group' => 'Системные', 'title' => 'E-mail', 'token' => '{{email}}', 'icon' => '✉'],
    ['group' => 'Системные', 'title' => 'Канал', 'token' => '{{bot_title}}', 'icon' => '#'],
    ['group' => 'Системные', 'title' => 'Username бота', 'token' => '{{bot_username}}', 'icon' => '@'],
];
try {
    if (function_exists('asr_tg_custom_fields_all')) {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            $code = trim((string)($field['code'] ?? ''));
            if ($code === '') continue;
            $title = trim((string)($field['title'] ?? '')) ?: $code;
            $messageVariables[] = ['group' => 'Пользовательские', 'title' => $title, 'token' => '{{custom.' . $code . '}}', 'icon' => '★'];
            $fieldType = trim((string)($field['field_type'] ?? 'text'));
            if (!in_array($fieldType, ['text','number','date','datetime'], true)) $fieldType = 'text';
            if (in_array($fieldType, ['text','number'], true)) {
                $questionFieldOptions[] = ['code' => $code, 'title' => $title, 'field_type' => $fieldType];
            }
        }
    }
} catch (Throwable $e) {}

$transitionOptions = [];
foreach ($blocks as $b) {
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0 || $id === $blockId || (string)($b['type'] ?? '') === 'start') continue;
    $title = trim((string)($b['title'] ?? '')) ?: ('Блок #' . $id);
    $transitionOptions[] = ['id' => $id, 'title' => $title];
}

$deeplink = null;
try {
    if (function_exists('asr_tg_scenario_block_deeplink_find')) {
        $deeplink = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
        if ($deeplink && function_exists('asr_tg_scenario_deeplink_url')) {
            $deeplink['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $deeplink);
        }
    }
} catch (Throwable $e) {
    $deeplink = null;
}
$deeplinkCode = is_array($deeplink) ? trim((string)($deeplink['code'] ?? $deeplink['token'] ?? '')) : '';
$deeplinkUrl = is_array($deeplink) ? trim((string)($deeplink['url'] ?? '')) : '';
$blockMeta = [
    'scenarioId' => $scenarioId,
    'blockId' => $blockId,
    'hasDeeplink' => $deeplinkCode !== '' || $deeplinkUrl !== '',
    'deeplinkCode' => $deeplinkCode,
    'deeplinkUrl' => $deeplinkUrl,
];

$csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
?>
<section id="tg-flow-panel" class="tg-flow-panel">
    <form method="POST" enctype="multipart/form-data" id="scenario-message-form" class="tg-flow-panel-form">
        <div class="tg-flow-panel-head">
            <div>
                <div class="tg-flow-panel-title">Редактировать блок «Сообщение»</div>
                <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
            </div>
            <div class="tg-flow-panel-actions">
                <div class="tg-flow-panel-menu-wrap">
                    <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                    <div class="tg-flow-panel-dropdown" data-panel-menu>
                        <button type="button" data-panel-deeplink <?php echo ($deeplinkCode !== '' || $deeplinkUrl !== '') ? 'disabled' : ''; ?>>
                            <span class="tg-flow-panel-dropdown-ico">🔗</span><span><span class="tg-flow-panel-menu-main"><?php echo ($deeplinkCode !== '' || $deeplinkUrl !== '') ? 'Диплинк уже создан' : 'Добавить диплинк'; ?></span><?php if ($deeplinkCode !== '' || $deeplinkUrl !== ''): ?><span class="tg-flow-panel-menu-note">Ссылка уже есть под блоком</span><?php endif; ?></span>
                        </button>
                        <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                        <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                    </div>
                </div>
                <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
            </div>
        </div>
        <div class="tg-flow-panel-body">
            <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
            <input type="hidden" name="action" value="tg_scenario_block_save">
            <input type="hidden" name="return_page" value="scenario_flow">
            <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
            <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
            <input type="hidden" name="block_type" value="message">
            <input type="hidden" name="scenario_cards_json" id="scenario-cards-json" value="">
            <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

            <label class="tg-flow-panel-field">
                <span class="tg-flow-panel-label">Название блока</span>
                <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Сообщение')); ?>" maxlength="190">
            </label>

            <div class="tg-flow-card-toolbar" aria-label="Добавить карточку">
                <button type="button" class="tg-flow-card-add" data-add-card="text">T Текст</button>
                <button type="button" class="tg-flow-card-add" data-add-card="image">▣ Картинка</button>
                <button type="button" class="tg-flow-card-add" data-add-card="file">▤ Файл</button>
                <button type="button" class="tg-flow-card-add" data-add-card="audio">♫ Аудио</button>
                <button type="button" class="tg-flow-card-add" data-add-card="video">▶ Видео</button>
                <button type="button" class="tg-flow-card-add" data-add-card="video_note">◉ Видео-заметка</button>
                <button type="button" class="tg-flow-card-add" data-add-card="gallery">▣ Галерея</button>
                <button type="button" class="tg-flow-card-add" data-add-card="question">? Вопрос</button>
            </div>
            <div id="scenario-cards-box"></div>
        </div>
        <div class="tg-flow-panel-footer">
            <button type="submit" class="tg-flow-panel-primary">Сохранить блок</button>
        </div>
    </form>
    <div class="tg-date-modal-backdrop" id="tgScenarioDateModal" aria-hidden="true">
        <div class="tg-date-modal">
            <div class="tg-date-modal-head">
                <h4>Дата</h4>
                <button type="button" class="tg-date-modal-close" data-date-modal-close aria-label="Закрыть">×</button>
            </div>
            <div class="tg-date-modal-body">
                <p class="tg-date-modal-note">Выделенный текст станет ссылкой на календарное событие в Telegram.</p>
                <label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Дата</span><input type="date" class="tg-flow-panel-input" id="tgScenarioDateValue"></label>
                <label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Время</span><input type="time" class="tg-flow-panel-input" id="tgScenarioTimeValue" value="10:00"></label>
            </div>
            <div class="tg-date-modal-foot"><button type="button" class="tg-button-muted" data-date-modal-close>Отмена</button><button type="button" class="tg-button-save" id="tgScenarioDateApply">Готово</button></div>
        </div>
    </div>
</section>
<style data-flow-panel-style="scenario-block-panel-v3.5.89">
/* scenario block panel styles v3.5.89 */
.tg-video-note-status{display:none;margin:10px 0 0;padding:10px 12px;border-radius:12px;font-size:13px;line-height:1.45;font-weight:650}
.tg-video-note-status.is-open{display:block}
.tg-video-note-status.is-ok{background:#ecfdf3;color:#267044;border:1px solid #bbf7d0}
.tg-video-note-status.is-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.tg-video-note-status.is-error{background:#fff1f2;color:#b91c1c;border:1px solid #fecdd3}

.tg-message-card[data-type="question"]{padding:22px 24px;background:#f7f8fa;border-color:#edf0f5}
.tg-message-card[data-type="question"] .tg-flow-card-head{margin-bottom:16px}
.tg-question-editor-wrap{margin:0 0 22px 0;background:#fff;border:1px solid #dce2ea;border-radius:14px;overflow:hidden}
.tg-question-editor-wrap .tg-caption-editor{border:0;border-radius:0;background:#fff;margin:0}
.tg-question-editor-wrap .tg-card-toolbar{margin-top:0;border-top:0;border-left:0;border-right:0;border-bottom:1px solid #edf0f5;border-radius:0;background:#fff}
.tg-question-editor-wrap .tg-editor-wrap{border:0;border-radius:0;background:#fff}
.tg-question-editor-wrap .tg-card-editor{min-height:118px;padding:18px 20px;font-size:15px;line-height:1.55}
.tg-question-box{display:flex;flex-direction:column;gap:20px;margin-top:0;padding-top:0;border-top:0}
.tg-question-option-stack{display:flex;flex-direction:column;gap:12px}
.tg-question-option-line{display:flex;align-items:center;gap:12px;min-height:46px;padding:0;color:#4b5563;font-size:14px;font-weight:600;line-height:1.35;background:transparent;border:0;border-radius:0}
.tg-question-option-line input{width:18px;height:18px;accent-color:#FFA048;flex:0 0 auto;margin:0}
.tg-question-option-line .tg-help-dot,.tg-question-option-line .tg-protect-help{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#e9edf3;color:#8b95a4;font-size:12px;font-weight:800;margin-left:2px;position:relative;cursor:help}
.tg-question-option-line .tg-help-dot:hover::after,.tg-question-option-line .tg-protect-help:hover::after{content:attr(data-tip);position:absolute;left:50%;top:26px;transform:translateX(-50%);width:max-content;max-width:260px;background:#333;color:#fff;padding:9px 11px;border-radius:8px;font-size:12px;font-weight:600;line-height:1.35;z-index:1000;box-shadow:0 10px 24px rgba(15,23,42,.18)}
.tg-question-save-field{position:relative;display:block;margin:0;border:1px solid #dce2ea;border-radius:14px;background:#fff;padding:0 12px 12px}
.tg-question-save-field .tg-question-field-title{display:inline-flex;position:relative;top:-10px;margin:0 0 -2px;padding:0 8px;background:#fff;color:#8b95a4;font-size:12px;font-weight:750;letter-spacing:.01em}
.tg-question-save-field .tg-button-select{height:50px;background:#fff;border:0;border-radius:10px;font-size:15px;border-color:transparent;color:#1f2937;width:100%;padding:0 8px;box-shadow:none}
.tg-question-field-hint{margin:6px 8px 0;color:#8b95a4;font-size:12px;font-weight:600;line-height:1.35}
.tg-question-answers-panel{border:0;background:#f1f3f6;border-radius:16px;padding:16px 18px;margin:0}
.tg-question-answers-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.tg-question-answers-title{font-size:13px;font-weight:800;color:#1f2937;line-height:1.25;text-transform:none}
.tg-question-answers-hint{font-size:12px;color:#8b95a4;font-weight:600;line-height:1.35;margin-top:4px}
.tg-question-answers{display:flex;flex-wrap:wrap;gap:9px;margin:10px 0 2px;min-height:36px}
.tg-question-answer-chip{display:inline-flex;align-items:center;gap:8px;min-height:34px;border:0;background:#e1e4e8;border-radius:999px;padding:8px 12px;font-size:13px;font-weight:650;color:#374151;cursor:pointer;max-width:100%}
.tg-question-answer-chip:hover{background:#fff1e6;color:#c45b12}
.tg-question-answer-chip button{border:0;background:transparent;color:#6b7280;font-size:17px;line-height:1;cursor:pointer;padding:0;margin-left:2px}
.tg-question-answer-rows{display:flex;flex-direction:column;gap:10px}
.tg-question-answer-row{display:flex;align-items:center;gap:10px}
.tg-question-answer-row .tg-flow-panel-input{flex:1}
.tg-question-row-remove{width:36px;height:36px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:20px;line-height:1;cursor:pointer}
.tg-question-row-remove:hover{background:#fee2e2;color:#b91c1c}
.tg-question-answer-bulk .tg-question-settings-meta{margin-top:10px}
.tg-question-add-row{margin-top:4px}
.tg-question-empty{font-size:12px;color:#8b95a4;font-weight:600;line-height:1.45;width:100%;padding:8px 0;border:0;background:transparent;border-radius:0}
.tg-question-actions{display:flex;flex-wrap:wrap;gap:16px;align-items:center;border-top:1px solid #e5e8ee;padding-top:16px;margin-top:14px}
.tg-question-actions .tg-btn-ghost{margin:0;padding:0;border:0;background:transparent;color:#f28b36;font-size:13px;letter-spacing:.02em;font-weight:800;text-transform:none;box-shadow:none;min-height:32px}
.tg-question-actions .tg-btn-ghost:hover{color:#d76f1f;background:transparent}
.tg-question-wait{display:inline-flex;align-items:center;min-height:30px;color:#6b7280;font-size:13px;font-weight:650;margin-left:auto;white-space:nowrap;background:#fff;border:1px solid #e5e8ee;border-radius:999px;padding:5px 11px}
.tg-question-noanswer{border:0;background:#fff;border-radius:14px;padding:16px 18px;font-size:13px;color:#6b7280;line-height:1.55;display:flex;flex-direction:column;gap:6px;box-shadow:inset 0 0 0 1px #edf0f5}
.tg-question-noanswer strong{display:block;color:#1f2937;font-size:13px;font-weight:800;margin:0}
.tg-question-settings-modal .tg-button-modal{max-width:640px}
.tg-question-settings-modal .tg-button-modal-body{max-height:70vh;overflow:auto;padding-right:22px}
.tg-question-settings-modal .tg-question-settings-answer-box{border:1px solid #d9dee7;border-radius:12px;padding:10px 12px;background:#fff}
.tg-question-settings-modal .tg-question-settings-chips{display:flex;align-items:center;flex-wrap:wrap;gap:8px;min-height:38px}
.tg-question-settings-modal .tg-question-settings-chip{display:inline-flex;align-items:center;gap:8px;min-height:32px;border-radius:999px;background:#e5e7eb;color:#374151;padding:7px 10px;font-size:13px;font-weight:650}
.tg-question-settings-modal .tg-question-settings-chip button{border:0;background:transparent;color:#6b7280;font-size:16px;line-height:1;cursor:pointer;padding:0}
.tg-question-settings-modal .tg-question-settings-add{border:0;background:transparent;color:#8b95a4;font-size:13px;font-weight:750;cursor:pointer;padding:5px 2px}
.tg-question-settings-modal .tg-question-settings-meta{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.35}
.tg-question-settings-modal .tg-question-settings-row{display:grid;grid-template-columns:120px 150px;gap:14px;align-items:center;margin-top:10px}
.tg-question-settings-modal .tg-question-switches{display:flex;flex-direction:column;gap:16px;margin-top:20px}
.tg-question-settings-modal .tg-switch-line{display:flex;align-items:center;gap:12px;color:#374151;font-size:14px;font-weight:600}
.tg-question-settings-modal .tg-switch-line input{width:18px;height:18px;accent-color:#FFA048}
.tg-question-settings-modal .tg-reminder-box{display:none;margin-top:12px;border:1px solid #d9dee7;border-radius:12px;padding:12px;background:#fff}
.tg-question-settings-modal .tg-reminder-box.is-open{display:block}
.tg-question-settings-modal .tg-reminder-box textarea{min-height:110px;resize:vertical}
@media(max-width:720px){.tg-message-card[data-type="question"]{padding:18px 16px}.tg-question-actions .tg-btn-ghost{flex:0 0 auto}.tg-question-wait{width:100%;margin-left:0;justify-content:center}.tg-question-settings-modal .tg-question-settings-row{grid-template-columns:1fr}}

</style>
<script data-flow-panel-script>
(function(){
  const panel = document.getElementById('tg-flow-panel');
  const box = panel ? panel.querySelector('#scenario-cards-box') : document.getElementById('scenario-cards-box');
  const form = panel ? panel.querySelector('#scenario-message-form') : document.getElementById('scenario-message-form');
  const jsonInput = panel ? panel.querySelector('#scenario-cards-json') : document.getElementById('scenario-cards-json');
  if (!box || !form || !jsonInput || form.dataset.scenarioMessageEditorBound === '1') return;
  form.dataset.scenarioMessageEditorBound = '1';

  const initialCards = <?php echo $safeJson($cards); ?>;
  const transitionOptions = <?php echo $safeJson($transitionOptions); ?>;
  const macroCatalog = <?php echo $safeJson($messageVariables); ?>;
  const questionFieldOptions = <?php echo $safeJson($questionFieldOptions); ?>;
  const blockMeta = <?php echo $safeJson($blockMeta); ?>;
  const emojiGroups = {
    'Частые': ['😀','😁','🙂','😉','😍','🔥','👍','👏','🙏','✅','❗','💡','🚀','🎯','📌','❤️'],
    'Работа': ['📅','📍','📎','📞','✉️','💬','📣','📊','🧩','⚙️','🔔','⭐','🏁','⏱️','📝','💰']
  };
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
  function showPanelAlert(message){
    const alertBox = panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
    if (alertBox) {
      alertBox.textContent = String(message || 'Ошибка');
      alertBox.classList.add('is-open');
      alertBox.scrollIntoView({block:'nearest', behavior:'smooth'});
    } else if (typeof window.tgScenarioFlowToast === 'function') {
      window.tgScenarioFlowToast(String(message || 'Ошибка'), 'error');
    }
  }
  const postBlockAction = async (action, payload = {}) => {
    const actionMap = {tg_scenario_duplicate_block: 'tg_scenario_block_duplicate'};
    const realAction = actionMap[action] || action;
    if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
    const fd = new FormData();
    fd.set('action', realAction);
    fd.set('return_page', 'scenario_flow');
    fd.set('tg_ajax', '1');
    fd.set('scenario_id', String(blockMeta.scenarioId || ''));
    Object.entries(payload || {}).forEach(([key, value]) => fd.set(key, String(value ?? '')));
    const csrf = form.querySelector('input[name="csrf_token"]');
    if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
    const response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    const text = await response.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
    if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.');
    return json;
  };
  const panelMenu = panel ? panel.querySelector('[data-panel-menu]') : null;
  const panelMenuToggle = panel ? panel.querySelector('[data-panel-menu-toggle]') : null;
  const closePanelMenu = () => panelMenu && panelMenu.classList.remove('is-open');
  panelMenuToggle?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); panelMenu?.classList.toggle('is-open'); });
  panelMenu?.addEventListener('click', (event) => event.stopPropagation());
  document.addEventListener('click', closePanelMenu);
  panel?.querySelector('[data-panel-deeplink]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    if (blockMeta.hasDeeplink) return;
    try {
      await postBlockAction('tg_scenario_block_deeplink_create', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowToast === 'function') window.tgScenarioFlowToast('Диплинк создан. Ссылка появилась под блоком.');
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось создать диплинк.');
    }
  });
  panel?.querySelector('[data-panel-duplicate]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    try {
      await postBlockAction('tg_scenario_duplicate_block', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось дублировать блок.');
    }
  });
  panel?.querySelector('[data-panel-delete]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    let ok = true;
    if (typeof window.tgScenarioFlowConfirm === 'function') {
      ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText:'Удалить', cancelText:'Отмена'});
    }
    if (!ok) return;
    try {
      await postBlockAction('tg_scenario_block_delete', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось удалить блок.');
    }
  });
  const cardTitle = (type) => ({text:'Текст', image:'Картинка', file:'Файл', audio:'Аудио', video:'Видео', video_note:'Видео-заметка', gallery:'Галерея', question:'Вопрос'}[type] || 'Текст');
  const mediaAccept = (type) => type === 'image' ? 'image/*' : type === 'video_note' ? '.mp4,.m4v,video/mp4,video/x-m4v' : type === 'video' ? 'video/*' : type === 'audio' ? 'audio/*' : '*/*';
  const mediaUrl = (card) => { let url=String(card.media_file_path||card.media_url||'').trim(); if(!url)return''; if(/^https?:\/\//i.test(url))return url; return '/' + url.replace(/^\/+/, ''); };
  const VIDEO_NOTE_MAX_SIZE = 10 * 1024 * 1024;
  const VIDEO_NOTE_MAX_SECONDS = 60;
  function videoNoteStatus(card, message='', kind='info'){
    const el = card.querySelector('[data-video-note-status]');
    if(!el) return;
    el.className = 'tg-video-note-status' + (message ? ' is-open is-' + kind : '');
    el.textContent = message || '';
  }
  function videoNoteFileLooksValid(file){
    if(!file) return {ok:true};
    const name = String(file.name || '').toLowerCase();
    if(!(/\.(mp4|m4v)$/.test(name) || file.type === 'video/mp4' || file.type === 'video/x-m4v')) {
      return {ok:false, message:'Видео-заметка принимает только MP4 или M4V.'};
    }
    if(file.size > VIDEO_NOTE_MAX_SIZE) {
      return {ok:false, message:'Файл видео-заметки больше 10 МБ. Выберите файл поменьше.'};
    }
    return {ok:true};
  }
  function validateVideoNoteFile(card, file){
    if(!card || (card.dataset.type || '') !== 'video_note') return;
    const input = card.querySelector('[data-media-file]');
    const basic = videoNoteFileLooksValid(file);
    if(!basic.ok){
      if(input) input.value='';
      const label=card.querySelector('[data-upload-name]');
      if(label) label.textContent='Файл не выбран';
      videoNoteStatus(card, basic.message, 'error');
      collectCards();
      return;
    }
    if(!file){ videoNoteStatus(card,''); return; }
    const url = URL.createObjectURL(file);
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.onloadedmetadata = () => {
      URL.revokeObjectURL(url);
      const duration = Number(video.duration || 0);
      if(duration && duration > VIDEO_NOTE_MAX_SECONDS){
        if(input) input.value='';
        const label=card.querySelector('[data-upload-name]');
        if(label) label.textContent='Файл не выбран';
        videoNoteStatus(card, 'Длительность видео-заметки больше 60 секунд. Выберите короткое видео.', 'error');
        collectCards();
        return;
      }
      videoNoteStatus(card, 'Файл подходит для видео-заметки. После отправки Telegram покажет его кружком.', 'ok');
    };
    video.onerror = () => {
      URL.revokeObjectURL(url);
      videoNoteStatus(card, 'Формат похож на MP4/M4V. Длительность браузер не смог проверить, сервер проверит размер и расширение.', 'warn');
    };
    video.src = url;
  }
  function validateVideoNoteUrl(card){
    if(!card || (card.dataset.type || '') !== 'video_note') return true;
    const input = card.querySelector('[data-media-url]');
    const value = String(input?.value || '').trim();
    if(!value) { videoNoteStatus(card,''); return true; }
    if(!/^https?:\/\//i.test(value)) {
      videoNoteStatus(card, 'Ссылка на видео-заметку должна начинаться с http:// или https://.', 'error');
      return false;
    }
    if(!/\.(mp4|m4v)(\?|#|$)/i.test(value)) {
      videoNoteStatus(card, 'Для видео-заметки ссылка должна вести на MP4 или M4V. Размер и длительность ссылки проверяются при отправке.', 'warn');
      return true;
    }
    videoNoteStatus(card, 'Ссылка похожа на MP4/M4V. Размер до 10 МБ и длительность до 60 секунд проверим при отправке.', 'warn');
    return true;
  }
  function galleryImageUrl(item){ const raw=String((item&& (item.media_file_path||item.media_url||item.url))||'').trim(); if(!raw)return''; if(/^https?:\/\//i.test(raw)||raw.startsWith('/'))return raw; return '/' + raw.replace(/^\/+/, ''); }
  function galleryItems(source){
    const raw = source && Array.isArray(source.gallery_items) ? source.gallery_items : [];
    return raw.map(item=>({media_url:String((item&& (item.media_url||item.url))||'').trim(),media_file_path:String((item&&item.media_file_path)||'').trim(),media_file_name:String((item&&item.media_file_name)||'').trim()})).filter(item=>galleryImageUrl(item));
  }
  function setGalleryItems(card, items){ card.dataset.galleryItems = JSON.stringify(Array.isArray(items)?items:[]); }
  function getGalleryItems(card){ try { const parsed=JSON.parse(card.dataset.galleryItems||'[]'); return Array.isArray(parsed)?parsed:[]; } catch(e){ return []; } }
  function renderGalleryItems(card){
    const list = card.querySelector('[data-gallery-list]');
    if(!list) return;
    const items = getGalleryItems(card);
    const input = card.querySelector('[data-gallery-files]');
    const selected = input && input.files ? Array.from(input.files) : [];
    let html = '';
    items.forEach((item,index)=>{
      const src = galleryImageUrl(item);
      html += '<div class="tg-gallery-item"><div class="tg-gallery-thumb">'+(src?'<img src="'+esc(src)+'" alt="">':'')+'</div><div class="tg-gallery-name">'+esc(item.media_file_name||item.media_url||item.media_file_path||('Картинка '+(index+1)))+'</div><button type="button" data-gallery-remove="'+index+'" title="Удалить">'+adminIcon('delete')+'</button></div>';
    });
    selected.forEach((file)=>{ html += '<div class="tg-gallery-item is-new"><div class="tg-gallery-thumb">+</div><div class="tg-gallery-name">'+esc(file.name)+' <span>после сохранения</span></div><button type="button" disabled title="Будет загружено при сохранении">'+adminIcon('image')+'</button></div>'; });
    list.innerHTML = html || '<div class="tg-gallery-empty">Добавьте картинки файлом или ссылкой. Они сохранятся одной карточкой галереи.</div>';
    list.querySelectorAll('[data-gallery-remove]').forEach(btn=>btn.addEventListener('click',()=>{ const idx=parseInt(btn.dataset.galleryRemove||'-1',10); const next=getGalleryItems(card); if(idx>=0) next.splice(idx,1); setGalleryItems(card,next); renderGalleryItems(card); collectCards(); }));
  }
  function addGalleryUrl(card){
    const input=card.querySelector('[data-gallery-url]');
    const value=String(input?.value||'').trim();
    if(!value) { input?.focus(); return; }
    const items=getGalleryItems(card);
    items.push({media_url:value,media_file_path:'',media_file_name:value.split('/').pop()||'Картинка'});
    setGalleryItems(card,items);
    if(input) input.value='';
    renderGalleryItems(card); collectCards();
  }
  function adminIcon(name){
    const map={bold:'bold',italic:'italic',underline:'underline',strike:'strikethrough',mono:'mono',code:'code',spoiler:'eye-off',quote:'quote','event-date':'date',calendar:'date',link:'link',emoji:'emoji',variables:'variables',clear:'clear',delete:'delete','arrow-up':'arrow-up','arrow-down':'arrow-down',text:'text',image:'image',file:'file',audio:'audio',video:'video',video_note:'video',gallery:'image',question:'help'};
    const file=map[name]||name;
    return '<img class="tg-ui-icon tg-ui-icon--toolbar" src="/assets/admin/icons/tg2-'+file+'-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true">';
  }
  function emojiHtml(){return Object.entries(emojiGroups).map(([title,items])=>'<div class="tg-emoji-section"><div class="tg-emoji-title">'+esc(title)+'</div><div class="tg-emoji-grid">'+items.map(e=>'<button type="button" data-emoji-insert="'+esc(e)+'">'+esc(e)+'</button>').join('')+'</div></div>').join('');}
  function macroMenuHtml(){
    const grouped={}; (macroCatalog||[]).forEach(item=>{const g=item.group||'Переменные'; (grouped[g]=grouped[g]||[]).push(item);});
    let html='<input class="tg-macro-search" placeholder="Найти переменную" data-macro-search>';
    Object.keys(grouped).forEach(group=>{html+='<div class="tg-macro-group">'+esc(group)+'</div>'; grouped[group].forEach(item=>{const token=String(item.token||''); if(!token)return; html+='<button type="button" class="tg-macro-item" data-macro-insert="'+esc(token)+'" data-macro-search-text="'+esc((item.title||'')+' '+token+' '+group)+'"><span>'+esc(item.icon||'Т')+'</span><span>'+esc(item.title||token)+'</span><span class="tg-macro-token">'+esc(token)+'</span></button>';});});
    return html;
  }
  function normalizeRows(buttons){
    if(!Array.isArray(buttons)) return [];
    const rows=[]; buttons.forEach(row=>{ if(!Array.isArray(row)) row=[row]; const clean=row.filter(btn=>btn&&typeof btn==='object').map(btn=>({type:btn.type==='transition'?'transition':'url', text:String(btn.text||btn.title||''), url:String(btn.url||''), target_block_id:parseInt(btn.target_block_id||0,10)||0})); if(clean.length) rows.push(clean); });
    return rows;
  }
  function renderButtons(card){
    const btnBox=card.querySelector('[data-buttons-box]');
    if(!btnBox) return;
    const rows=getRows(card);
    btnBox.innerHTML='';
    rows.forEach((row,ri)=>{
      if(!Array.isArray(row) || !row.length) return;
      const line=document.createElement('div');
      line.className='tg-message-button-row';
      row.forEach((btn,bi)=>{
        const button=document.createElement('button');
        button.type='button';
        button.className='tg-message-button';
        button.textContent=btn.text || btn.title || 'Кнопка';
        button.addEventListener('click',()=>openButtonModal(card,ri,bi,false));
        line.appendChild(button);
        const plus=document.createElement('button');
        plus.type='button';
        plus.className='tg-message-button-add';
        plus.textContent='+';
        plus.title='Добавить рядом';
        plus.addEventListener('click',()=>openButtonModal(card,ri,bi+1,true));
        line.appendChild(plus);
      });
      btnBox.appendChild(line);
      const below=document.createElement('button');
      below.type='button';
      below.className='tg-btn-ghost self-start';
      below.textContent='+ Добавить ниже';
      below.addEventListener('click',()=>openButtonModal(card,ri+1,0,true));
      btnBox.appendChild(below);
    });
  }
  function getRows(card){
    try {
      const parsed=JSON.parse(card.dataset.buttons||'[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch(e) {
      return [];
    }
  }
  function setRows(card, rows){
    const clean=normalizeRows(rows||[]);
    card.dataset.buttons=JSON.stringify(clean);
    renderButtons(card);
    collectCards();
  }
  function openButtonModal(card,rowIndex,buttonIndex,isNew){
    const rows=getRows(card);
    if(rowIndex==null){rowIndex=rows.length; buttonIndex=0; isNew=true;}
    const current=!isNew&&rows[rowIndex]&&rows[rowIndex][buttonIndex]?rows[rowIndex][buttonIndex]:{type:'url',text:'',url:'',target_block_id:0};
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-message-button-modal is-open';
    const opts=['<option value="0">Без перехода</option>'].concat(transitionOptions.map(o=>'<option value="'+esc(o.id)+'" '+(parseInt(current.target_block_id||0,10)===parseInt(o.id,10)?'selected':'')+'>'+esc(o.title)+'</option>')).join('');
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Кнопка сообщения</h4><button type="button" class="tg-question-modal-close" data-btn-cancel>×</button></div><div class="tg-button-modal-body"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Текст кнопки</span><input class="tg-flow-panel-input" data-modal-btn-text value="'+esc(current.text||current.title||'')+'" maxlength="64" placeholder="Например: Перейти"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Действие</span><select class="tg-button-select" data-modal-btn-type><option value="url" '+(current.type==='transition'?'':'selected')+'>Открыть ссылку</option><option value="transition" '+(current.type==='transition'?'selected':'')+'>Переход к блоку</option></select></label><label class="tg-flow-panel-field" data-url-field><span class="tg-flow-panel-label">Ссылка</span><input class="tg-flow-panel-input" data-modal-btn-url value="'+esc(current.url||'')+'" placeholder="https://..."></label><label class="tg-flow-panel-field" data-target-field><span class="tg-flow-panel-label">Целевой блок</span><select class="tg-button-select" data-modal-btn-target>'+opts+'</select></label></div><div class="tg-button-modal-foot"><button type="button" class="tg-button-danger" data-btn-delete>Удалить</button><button type="button" class="tg-button-muted" data-btn-cancel>Отмена</button><button type="button" class="tg-button-save" data-btn-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const typeSel=modal.querySelector('[data-modal-btn-type]'), urlField=modal.querySelector('[data-url-field]'), targetField=modal.querySelector('[data-target-field]');
    function sync(){const t=typeSel.value==='transition'; urlField.style.display=t?'none':''; targetField.style.display=t?'':'none';}
    typeSel.addEventListener('change',sync); sync();
    const close=()=>modal.remove();
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-btn-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault();e.stopPropagation();close();}));
    const deleteBtn=modal.querySelector('[data-btn-delete]');
    if(deleteBtn){
      deleteBtn.style.display=isNew?'none':'';
      deleteBtn.addEventListener('click',(e)=>{e.preventDefault();e.stopPropagation(); if(rows[rowIndex]){rows[rowIndex].splice(buttonIndex,1); if(!rows[rowIndex].length)rows.splice(rowIndex,1);} setRows(card,rows); close(); });
    }
    const textInput=modal.querySelector('[data-modal-btn-text]');
    textInput?.focus();
    textInput?.addEventListener('keydown',(e)=>{ if(e.key==='Enter'){ e.preventDefault(); modal.querySelector('[data-btn-save]')?.click(); }});
    modal.querySelector('[data-btn-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const type=typeSel.value==='transition'?'transition':'url';
      const item={type,text:String(textInput?.value||'').trim(),url:type==='url'?String(modal.querySelector('[data-modal-btn-url]')?.value||'').trim():'',target_block_id:type==='transition'?(parseInt(modal.querySelector('[data-modal-btn-target]')?.value||'0',10)||0):0};
      if(!item.text){textInput?.focus();return;}
      if(!rows[rowIndex]) rows[rowIndex]=[];
      rows[rowIndex].splice(buttonIndex, isNew?0:1, item);
      setRows(card,rows.filter(r=>r&&r.length));
      close();
    });
  }
  function sanitizeEditorHtml(html){
    const wrap=document.createElement('div'); wrap.innerHTML=html||'';
    const allowed=new Set(['BR','B','STRONG','I','EM','U','S','STRIKE','CODE','PRE','BLOCKQUOTE','A','TG-SPOILER']);
    function clean(node){
      if(node.nodeType===Node.TEXT_NODE) return document.createTextNode(node.nodeValue.replace(/\u200b/g,''));
      if(node.nodeType!==Node.ELEMENT_NODE) return document.createTextNode('');
      if(!allowed.has(node.tagName)){const frag=document.createDocumentFragment(); Array.from(node.childNodes).forEach(ch=>frag.appendChild(clean(ch))); return frag;}
      let tag=node.tagName.toLowerCase(); if(tag==='strong')tag='b'; if(tag==='em')tag='i'; if(tag==='strike')tag='s';
      const el=document.createElement(tag); if(tag==='a'){const href=node.getAttribute('href')||''; if(/^https?:\/\//i.test(href)||/^tg:\/\//i.test(href)) el.setAttribute('href',href);}
      Array.from(node.childNodes).forEach(ch=>el.appendChild(clean(ch))); return el;
    }
    const out=document.createElement('div'); Array.from(wrap.childNodes).forEach(ch=>out.appendChild(clean(ch))); return out.innerHTML;
  }
  function insertHtmlAtSelection(editor, html){
    editor.focus(); const sel=window.getSelection(); if(!sel||!sel.rangeCount){editor.insertAdjacentHTML('beforeend',html); return;}
    const range=sel.getRangeAt(0); if(!editor.contains(range.commonAncestorContainer)){editor.insertAdjacentHTML('beforeend',html); return;}
    range.deleteContents(); const tmp=document.createElement('div'); tmp.innerHTML=html; const frag=document.createDocumentFragment(); let last=null; while(tmp.firstChild){last=frag.appendChild(tmp.firstChild);} range.insertNode(frag); if(last){range.setStartAfter(last); range.collapse(true); sel.removeAllRanges(); sel.addRange(range);}
  }
  function wrapSelection(editor,before,after){
    editor.focus(); const sel=window.getSelection(); const text=sel&&sel.rangeCount?String(sel):''; if(text){insertHtmlAtSelection(editor,before+esc(text)+after);} else insertHtmlAtSelection(editor,before+after);
  }
  function insertPlainBreak(editor){insertHtmlAtSelection(editor,'<br>');}
  function panelNotice(message, keep=false){
    const alert=panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
    if(alert){
      alert.textContent=String(message||'');
      alert.classList.add('is-open');
      alert.style.whiteSpace='pre-wrap';
      try { alert.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e) {}
      window.clearTimeout(panelNotice._timer);
      if(!keep) panelNotice._timer=window.setTimeout(()=>alert.classList.remove('is-open'),2600);
    }
  }
  function panelDiag(stage, data){
    const payload = data || {};
    window.__tgScenarioQuestionDiag = window.__tgScenarioQuestionDiag || [];
    const row = {time: new Date().toISOString(), stage, data: payload};
    window.__tgScenarioQuestionDiag.push(row);
    if (window.__tgScenarioQuestionDiag.length > 30) window.__tgScenarioQuestionDiag.shift();
    try { console.info('[ScenarioQuestionDiag]', row); } catch(e) {}
    return row;
  }
  function showPanelDiag(message, data){
    const suffix = data ? '\n\nДиагностика:\n' + JSON.stringify(data, null, 2).slice(0, 1600) : '';
    panelNotice(String(message || '') + suffix, true);
  }
  const editorSavedRanges=new WeakMap();
  function isRangeInsideEditor(editor,range){
    if(!editor||!range)return false;
    const node=range.commonAncestorContainer;
    return node===editor || editor.contains(node);
  }
  function rememberEditorSelection(editor){
    const sel=window.getSelection();
    if(!sel||!sel.rangeCount)return;
    const range=sel.getRangeAt(0);
    if(isRangeInsideEditor(editor,range) && String(sel).trim()!=='') editorSavedRanges.set(editor,range.cloneRange());
  }
  function selectedRangeForEditor(editor){
    const sel=window.getSelection();
    if(sel&&sel.rangeCount){
      const range=sel.getRangeAt(0);
      if(isRangeInsideEditor(editor,range) && String(sel).trim()!=='') return range.cloneRange();
    }
    const saved=editorSavedRanges.get(editor);
    if(saved && isRangeInsideEditor(editor,saved) && saved.toString().trim()!=='') return saved.cloneRange();
    return null;
  }
  let dateContext=null;
  function openDateModal(editor){
    const modal=panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal');
    const dateInput=panel ? panel.querySelector('#tgScenarioDateValue') : document.getElementById('tgScenarioDateValue');
    const timeInput=panel ? panel.querySelector('#tgScenarioTimeValue') : document.getElementById('tgScenarioTimeValue');
    if(!modal||!dateInput||!timeInput)return;
    const range=selectedRangeForEditor(editor);
    if(!range){
      panelNotice('Сначала выделите слово или фразу, к которой нужно привязать дату.');
      return;
    }
    const now=new Date();
    dateInput.value=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
    timeInput.value='10:00';
    dateContext={editor,range};
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
  }
  function closeDateModal(){
    const modal=panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal');
    if(modal){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');}
    dateContext=null;
  }
  function applyDateModal(){
    const dateInput=panel ? panel.querySelector('#tgScenarioDateValue') : document.getElementById('tgScenarioDateValue');
    const timeInput=panel ? panel.querySelector('#tgScenarioTimeValue') : document.getElementById('tgScenarioTimeValue');
    if(!dateContext||!dateInput)return;
    const d=dateInput.value;
    const t=(timeInput&&timeInput.value?timeInput.value:'00:00');
    if(!d){panelNotice('Выберите дату.');return;}
    const dt=new Date(d+'T'+t+':00');
    const ts=Math.floor(dt.getTime()/1000);
    if(!Number.isFinite(ts)){panelNotice('Дата указана некорректно.');return;}
    const range=dateContext.range.cloneRange();
    if(!isRangeInsideEditor(dateContext.editor,range) || range.toString().trim()===''){
      panelNotice('Не удалось применить дату: выделите текст ещё раз.');
      closeDateModal();
      return;
    }
    const anchor=document.createElement('a');
    anchor.className='tg-date-link';
    anchor.setAttribute('href','tg://msg?timestamp='+ts);
    anchor.textContent=range.toString();
    range.deleteContents();
    range.insertNode(anchor);
    const sel=window.getSelection();
    if(sel){
      const after=document.createRange();
      after.setStartAfter(anchor);
      after.collapse(true);
      sel.removeAllRanges();
      sel.addRange(after);
    }
    const card=dateContext.editor.closest('[data-card]');
    if(card){collectCards();updateCharCount(card);}
    editorSavedRanges.delete(dateContext.editor);
    closeDateModal();
  }
  function closeFloatingMenus(except=null){form.querySelectorAll('.tg-emoji-menu.is-open,.tg-macro-menu.is-open').forEach(m=>{if(m!==except)m.classList.remove('is-open');});}
  function editorTemplate(type, text, visible=true){
    const limit=(type==='text'||type==='question')?4096:1024; const placeholder=type==='question'?'Текст вопроса':(type==='text'?'Текст сообщения':'Подпись к медиа');
    const hidden = visible ? '' : ' style="display:none"';
    return '<div class="tg-caption-editor" data-caption-editor'+hidden+'><div class="tg-card-toolbar"><button type="button" title="Жирный" data-format="bold">'+adminIcon('bold')+'</button><button type="button" title="Курсив" data-format="italic">'+adminIcon('italic')+'</button><button type="button" title="Подчёркнутый" data-format="underline">'+adminIcon('underline')+'</button><button type="button" title="Зачёркнутый" data-format="strikeThrough">'+adminIcon('strike')+'</button><button type="button" title="Моноширинный текст" data-wrap="<code>|</code>">'+adminIcon('mono')+'</button><button type="button" title="Скрытый текст" data-wrap="<tg-spoiler>|</tg-spoiler>">'+adminIcon('spoiler')+'</button><button type="button" title="Код" data-wrap="<pre>|</pre>">'+adminIcon('code')+'</button><button type="button" title="Цитата" data-wrap="<blockquote>|</blockquote>">'+adminIcon('quote')+'</button><span class="tg-toolbar-sep"></span><button type="button" title="Дата / напоминание" data-date>'+adminIcon('calendar')+'</button><button type="button" title="Ссылка" data-link>'+adminIcon('link')+'</button></div><div class="tg-editor-wrap"><div class="tg-card-editor" contenteditable="true" data-editor data-placeholder="'+esc(placeholder)+'" spellcheck="true">'+(text||'')+'</div><div class="tg-card-bottom"><div class="tg-editor-tools"><button type="button" class="tg-mini-btn" data-emoji>'+adminIcon('emoji')+'<span>Эмодзи</span></button><button type="button" class="tg-mini-btn" data-macro>'+adminIcon('variables')+'<span>Переменные</span></button><button type="button" class="tg-mini-btn" data-clear-format>'+adminIcon('clear')+'<span>Очистить</span></button><div class="tg-emoji-menu">'+emojiHtml()+'</div><div class="tg-macro-menu">'+macroMenuHtml()+'</div></div><div class="tg-char-count"><span data-char-count>0</span> / '+limit+'</div></div></div></div>';
  }
  function protectOptionHtml(source){
    const checked = source && source.protect_content ? ' checked' : '';
    return '<label class="tg-protect-option"><input type="checkbox" data-protect-toggle'+checked+'> Защищать контент <span class="tg-protect-help" tabindex="0" aria-label="Защищает содержимое отправленного сообщения от пересылки и сохранения" data-tooltip="Защищает содержимое отправленного сообщения от пересылки и сохранения">?</span></label>';
  }
  function cardOptionsHtml(source, captionHtml=''){
    return '<div class="tg-card-options">'+captionHtml+protectOptionHtml(source || {})+'</div>';
  }
  function questionFieldOptionsHtml(selected){
    const current=String(selected||'');
    let html='<option value="">Не сохранять</option>';
    let count=0;
    (questionFieldOptions||[]).forEach(field=>{
      const code=String(field.code||''); if(!code)return;
      const type=String(field.field_type||'text');
      if(type!=='text' && type!=='number') return;
      const typeLabel=type==='number'?'число':'текст';
      html+='<option value="'+esc(code)+'" data-field-type="'+esc(type)+'" '+(code===current?'selected':'')+'>'+esc(field.title||code)+' · '+typeLabel+'</option>';
      count++;
    });
    if(!count) html+='<option value="" disabled>Создайте пользовательское поле типа текст или число</option>';
    return html;
  }
  function normalizeQuestionAnswers(answers){
    if(!Array.isArray(answers)) return [];
    return answers.map(item=>({text:String((item&& (item.text||item.title))||'').trim().slice(0,128),target_block_id:parseInt((item&&item.target_block_id)||0,10)||0})).filter(item=>item.text).slice(0,20);
  }
  function getQuestionAnswers(card){try{const parsed=JSON.parse(card.dataset.questionAnswers||'[]'); return normalizeQuestionAnswers(parsed);}catch(e){return[];}}
  function setQuestionAnswers(card,answers){
    card.dataset.questionAnswers=JSON.stringify(normalizeQuestionAnswers(answers));
    renderQuestionAnswers(card);
    try { collectCards(); } catch (e) { console.error('Question answers collect error', e); panelNotice('Не удалось обновить ответы. Проверьте карточку вопроса.'); }
  }
  function panelModalHost(){ return panel || document.body; }
  function closeQuestionModals(){
    (panel || document).querySelectorAll('.tg-question-answer-modal,.tg-question-settings-modal').forEach(modal=>modal.remove());
    document.querySelectorAll('body > .tg-question-answer-modal, body > .tg-question-settings-modal').forEach(modal=>modal.remove());
  }
  function questionWaitLabel(card){
    const value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
    const unit=card.dataset.questionWaitUnit||'hours';
    const unitLabel=unit==='minutes'?'мин.':(unit==='days'?'дн.':'часа');
    return 'Ожидание ответа: '+value+' '+unitLabel;
  }
  function renderQuestionAnswers(card){
    const list=card.querySelector('[data-question-answers]'); if(!list)return;
    const answers=getQuestionAnswers(card);
    if(!answers.length){list.innerHTML='<div class="tg-question-empty">Ответы не добавлены. Подписчик сможет написать обычное сообщение.</div>';}
    else {list.innerHTML=answers.map((answer,index)=>'<span class="tg-question-answer-chip" data-question-answer-edit="'+index+'">'+esc(answer.text)+'<button type="button" data-question-answer-remove="'+index+'" title="Удалить">×</button></span>').join('');}
    list.querySelectorAll('[data-question-answer-edit]').forEach(chip=>chip.addEventListener('click',e=>{if(e.target&&e.target.matches('button'))return; openQuestionAnswerModal(card,parseInt(chip.dataset.questionAnswerEdit||'0',10));}));
    list.querySelectorAll('[data-question-answer-remove]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation(); const idx=parseInt(btn.dataset.questionAnswerRemove||'-1',10); const next=getQuestionAnswers(card); if(idx>=0) next.splice(idx,1); setQuestionAnswers(card,next);}));
  }
  function openQuestionAnswerModal(card,index=null){
    if(!card || (card.dataset.type||'') !== 'question') return;
    closeQuestionModals();
    const answers=getQuestionAnswers(card);
    const openedFromAdd = index===null || index<0;
    const rowsData = answers.map((answer,idx)=>({text:String(answer.text||''), target_block_id:parseInt(answer.target_block_id||0,10)||0, original_index:idx}));
    if(openedFromAdd && rowsData.length < 20){
      rowsData.push({text:'', target_block_id:0, original_index:-1});
    }
    if(!rowsData.length){
      rowsData.push({text:'', target_block_id:0, original_index:-1});
    }
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-question-answer-modal is-open';
    const rowHtml=(row={})=>'<div class="tg-question-answer-row" data-question-answer-row data-original-index="'+String(parseInt(row.original_index||-1,10)||-1)+'" data-target-block-id="'+String(parseInt(row.target_block_id||0,10)||0)+'"><input class="tg-flow-panel-input" data-question-answer-text value="'+esc(row.text||'')+'" maxlength="128" placeholder="Например: Валик"><button type="button" class="tg-question-row-remove" data-question-row-remove title="Удалить строку">×</button></div>';
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Ответы</h4><button type="button" class="tg-question-modal-close" data-question-cancel>×</button></div><div class="tg-button-modal-body"><div class="tg-question-answer-bulk"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Варианты ответов</span><div class="tg-question-answer-rows" data-question-answer-rows>'+rowsData.map(rowHtml).join('')+'</div></label><button type="button" class="tg-btn-ghost tg-question-add-row" data-question-add-row>+ Ещё ответ</button><div class="tg-question-settings-meta">Можно добавить до 20 вариантов, максимум 128 символов каждый. Переходы настраиваются на холсте через точки рядом с ответами.</div></div></div><div class="tg-button-modal-foot"><button type="button" class="tg-button-muted" data-question-cancel>Отмена</button><button type="button" class="tg-button-save" data-question-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const close=()=>modal.remove();
    const focusLast=()=>{const inputs=modal.querySelectorAll('[data-question-answer-text]'); inputs[inputs.length-1]?.focus();};
    const syncRemoveButtons=()=>{const rows=modal.querySelectorAll('[data-question-answer-row]'); rows.forEach(row=>{const btn=row.querySelector('[data-question-row-remove]'); if(btn) btn.style.visibility=rows.length>1?'visible':'hidden';});};
    const currentCount=()=>modal.querySelectorAll('[data-question-answer-row]').length;
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-question-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close();}));
    modal.querySelector('[data-question-add-row]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const rowsBox=modal.querySelector('[data-question-answer-rows]');
      if(!rowsBox || currentCount()>=20){ panelNotice('В вопросе можно добавить максимум 20 ответов.'); return; }
      rowsBox.insertAdjacentHTML('beforeend', rowHtml({text:'',target_block_id:0,original_index:-1}));
      syncRemoveButtons();
      focusLast();
    });
    modal.addEventListener('click',(e)=>{
      const btn=e.target&&e.target.closest?e.target.closest('[data-question-row-remove]'):null;
      if(!btn) return;
      e.preventDefault(); e.stopPropagation();
      const row=btn.closest('[data-question-answer-row]');
      if(row && currentCount()>1) row.remove();
      syncRemoveButtons();
    });
    modal.addEventListener('keydown',(e)=>{
      if((e.ctrlKey||e.metaKey) && e.key==='Enter'){
        e.preventDefault(); modal.querySelector('[data-question-save]')?.click(); return;
      }
      if(e.key==='Enter' && !e.shiftKey){
        e.preventDefault();
        const inputs=Array.from(modal.querySelectorAll('[data-question-answer-text]'));
        const last=inputs[inputs.length-1];
        if(document.activeElement===last && String(last.value||'').trim() && currentCount()<20) modal.querySelector('[data-question-add-row]')?.click();
        else modal.querySelector('[data-question-save]')?.click();
      }
    });
    syncRemoveButtons();
    const inputs=Array.from(modal.querySelectorAll('[data-question-answer-text]'));
    if(index!==null && index>=0 && inputs[index]) inputs[index].focus(); else focusLast();
    modal.querySelector('[data-question-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const rows=Array.from(modal.querySelectorAll('[data-question-answer-row]'));
      const next=[];
      const seen=new Set();
      rows.forEach(row=>{
        if(next.length>=20) return;
        const input=row.querySelector('[data-question-answer-text]');
        const text=String(input?.value||'').trim().slice(0,128);
        if(!text || seen.has(text)) return;
        seen.add(text);
        const originalIndex=parseInt(row.dataset.originalIndex||'-1',10);
        const storedTarget=parseInt(row.dataset.targetBlockId||'0',10)||0;
        const originalAnswer=originalIndex>=0 ? (answers[originalIndex]||null) : null;
        const target=originalAnswer ? (parseInt(originalAnswer.target_block_id||0,10)||0) : storedTarget;
        next.push({text,target_block_id:target});
      });
      setQuestionAnswers(card,next);
      panelDiag('questionAnswers:saveAll',{count:next.length, answers:next.map(a=>a.text)});
      close();
    });
  }
  function openQuestionSettingsModal(card){
    if(!card || (card.dataset.type||'') !== 'question') return;
    closeQuestionModals();
    const value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
    const unit=card.dataset.questionWaitUnit||'hours';
    const check=card.dataset.questionCheck==='1';
    const remind=card.dataset.questionRemind==='1';
    const answers=getQuestionAnswers(card);
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-question-settings-modal is-open';
    const chips = answers.length
      ? answers.map((answer,index)=>'<span class="tg-question-settings-chip">'+esc(answer.text||'Ответ')+'<button type="button" data-settings-answer-remove="'+index+'">×</button></span>').join('')
      : '<span class="tg-question-empty">Ответы не добавлены.</span>';
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Настройки вопроса</h4><button type="button" class="tg-question-modal-close" data-question-settings-cancel>×</button></div>'
      + '<div class="tg-button-modal-body">'
      + '<label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Варианты ответов</span><div class="tg-question-settings-answer-box"><div class="tg-question-settings-chips">'+chips+'<button type="button" class="tg-question-settings-add" data-settings-add-answer>+ ответ</button></div><div class="tg-question-settings-meta">До 20 элементов. Максимум 128 символов каждый.</div></div></label>'
      + '<div class="tg-flow-panel-label" style="margin-top:18px;margin-bottom:8px">Ожидание ответа пользователя</div>'
      + '<div class="tg-question-settings-row"><input class="tg-flow-panel-input" type="number" min="1" max="999" data-question-wait-value value="'+esc(value)+'"><select class="tg-button-select" data-question-wait-unit><option value="minutes" '+(unit==='minutes'?'selected':'')+'>минут</option><option value="hours" '+(unit==='hours'?'selected':'')+'>часов</option><option value="days" '+(unit==='days'?'selected':'')+'>дней</option></select></div>'
      + '<div class="tg-question-switches"><label class="tg-switch-line"><input type="checkbox" data-question-check '+(check?'checked':'')+'> <span>Включить проверку</span></label><label class="tg-switch-line"><input type="checkbox" data-question-remind '+(remind?'checked':'')+'> <span>Напомнить, если нет ответа</span></label></div>'
      + '<div class="tg-reminder-box '+(remind?'is-open':'')+'" data-reminder-box><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Текст напоминания</span><textarea class="tg-flow-panel-input" data-question-remind-text maxlength="600" placeholder="{%first_name%}, пожалуйста, уточните ваш ответ…">'+esc(card.dataset.questionRemindText||'')+'</textarea></label><div class="tg-question-settings-row"><input class="tg-flow-panel-input" type="number" min="1" max="999" data-question-remind-value value="'+esc(card.dataset.questionRemindValue||'5')+'"><select class="tg-button-select" data-question-remind-unit><option value="minutes" '+((card.dataset.questionRemindUnit||'minutes')==='minutes'?'selected':'')+'>минут</option><option value="hours" '+((card.dataset.questionRemindUnit||'minutes')==='hours'?'selected':'')+'>часов</option><option value="days" '+((card.dataset.questionRemindUnit||'minutes')==='days'?'selected':'')+'>дней</option></select></div></div>'
      + '</div><div class="tg-button-modal-foot"><button type="button" class="tg-button-muted" data-question-settings-cancel>Отмена</button><button type="button" class="tg-button-save" data-question-settings-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const close=()=>modal.remove();
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-question-settings-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close();}));
    modal.querySelector('[data-settings-add-answer]')?.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close(); openQuestionAnswerModal(card,null);});
    modal.querySelectorAll('[data-settings-answer-remove]').forEach(btn=>btn.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); const idx=parseInt(btn.dataset.settingsAnswerRemove||'-1',10); const next=getQuestionAnswers(card); if(idx>=0){next.splice(idx,1); setQuestionAnswers(card,next);} close(); openQuestionSettingsModal(card);}));
    const remindToggle=modal.querySelector('[data-question-remind]');
    const remindBox=modal.querySelector('[data-reminder-box]');
    remindToggle?.addEventListener('change',()=>{if(remindBox) remindBox.classList.toggle('is-open', !!remindToggle.checked);});
    modal.querySelector('[data-question-settings-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      card.dataset.questionWaitValue=String(Math.max(1,parseInt(modal.querySelector('[data-question-wait-value]').value||'24',10)||24));
      card.dataset.questionWaitUnit=modal.querySelector('[data-question-wait-unit]').value||'hours';
      card.dataset.questionCheck=modal.querySelector('[data-question-check]').checked?'1':'0';
      card.dataset.questionRemind=modal.querySelector('[data-question-remind]').checked?'1':'0';
      card.dataset.questionRemindText=modal.querySelector('[data-question-remind-text]')?.value||'';
      card.dataset.questionRemindValue=String(Math.max(1,parseInt(modal.querySelector('[data-question-remind-value]')?.value||'5',10)||5));
      card.dataset.questionRemindUnit=modal.querySelector('[data-question-remind-unit]')?.value||'minutes';
      const wait=card.querySelector('[data-question-wait-label]'); if(wait) wait.textContent=questionWaitLabel(card);
      try { collectCards(); } catch(err) { console.error('Question settings collect error', err); panelNotice('Не удалось сохранить настройки вопроса.'); }
      close();
    });
  }
  
  function questionControlsHtml(source){
    const selected=source.save_field_code||'';
    const previewChecked=source.disable_web_page_preview?' checked':'';
    const protectChecked=source && source.protect_content ? ' checked' : '';
    return '<div class="tg-question-box">'
      + '<div class="tg-question-option-stack">'
      + '<label class="tg-question-option-line"><input type="checkbox" data-question-disable-preview'+previewChecked+'> <span>Отключить предпросмотр ссылок</span><span class="tg-help-dot" data-tip="Ссылки в тексте вопроса будут отправлены без автоматического предпросмотра">?</span></label>'
      + '<label class="tg-question-option-line"><input type="checkbox" data-protect-toggle'+protectChecked+'> <span>Защищать контент</span><span class="tg-protect-help" data-tip="Защищает содержимое отправленного сообщения от пересылки и сохранения">?</span></label>'
      + '</div>'
      + '<label class="tg-question-save-field"><span class="tg-question-field-title">Сохранить ответ в поле</span><select class="tg-button-select" data-question-save-field>'+questionFieldOptionsHtml(selected)+'</select><div class="tg-question-field-hint">Для вопросов доступны только пользовательские поля типа «текст» и «число». Поля «дата» и «дата со временем» здесь скрыты, чтобы подписчик не ломал формат ответа.</div></label>'
      + '<div class="tg-question-answers-panel"><div class="tg-question-answers-head"><div><div class="tg-question-answers-title">Ответы</div><div class="tg-question-answers-hint">Кнопки ответа можно добавить здесь или оставить пустым.</div></div></div><div class="tg-question-answers" data-question-answers></div><div class="tg-question-actions"><button type="button" class="tg-btn-ghost" data-question-add-answer>+ Добавить ответ</button><button type="button" class="tg-btn-ghost" data-question-settings>Настройки</button><span class="tg-question-wait" data-question-wait-label></span></div></div>'
      + '<div class="tg-question-noanswer"><strong>Подписчик не ответил</strong><span>Отдельный выход на холсте добавим следующим шагом.</span></div>'
      + '</div>';
  }
  function addCard(type='text', source={}){
    const card=document.createElement('div'); card.className='tg-message-card'; card.dataset.card='1'; card.dataset.type=type; card.dataset.buttons=JSON.stringify(normalizeRows(source.buttons));
    if(type==='question'){card.dataset.questionAnswers=JSON.stringify(normalizeQuestionAnswers(source.answers)); card.dataset.questionWaitValue=String(source.wait_value||24); card.dataset.questionWaitUnit=String(source.wait_unit||'hours'); card.dataset.questionCheck=source.enable_check?'1':'0'; card.dataset.questionRemind=source.remind_no_answer?'1':'0'; card.dataset.questionRemindText=String(source.remind_text||''); card.dataset.questionRemindValue=String(source.remind_value||5); card.dataset.questionRemindUnit=String(source.remind_unit||'minutes');}
    let media=''; const url=mediaUrl(source);
    if(type==='question'){
      media='';
    } else if(type==='gallery'){
      setGalleryItems(card, galleryItems(source));
      const captionOn = !!(source.caption_enabled || String(source.text||'').trim());
      const caption = captionOn ? ' checked' : '';
      media='<div class="tg-flow-gallery-box"><div class="tg-flow-media-grid"><label class="tg-media-field"><span class="tg-form-label">Картинки</span><div class="tg-flow-file-control"><input type="file" data-gallery-files multiple accept="image/*"><span class="tg-flow-file-button">Выберите файлы</span><span class="tg-flow-upload-name" data-upload-name>Можно выбрать сразу несколько</span></div></label><label class="tg-media-field"><span class="tg-form-label">Или ссылка на картинку</span><div class="tg-gallery-url-row"><input class="tg-form-field tg-media-url-field" data-gallery-url placeholder="https://..."><button type="button" data-gallery-url-add>+</button></div></label></div><div class="tg-gallery-list" data-gallery-list></div><div class="tg-gallery-options"><label><input type="checkbox" data-gallery-caption'+caption+'> Подпись</label>'+protectOptionHtml(source)+'</div><div class="tg-flow-card-note"><strong>Галерея</strong><span>Можно добавить несколько картинок одной карточкой. Отправку в Telegram через медиагруппу подключим следующим шагом.</span></div></div>';
    } else if(type!=='text'){
      const fileLabel = type === 'video_note' ? 'Файл MP4 / M4V' : 'Файл';
      const urlLabel = type === 'video_note' ? 'Или ссылка на видео' : 'Или ссылка на медиа';
      const captionOn = !!(source.caption_enabled || String(source.text||'').trim());
      media='<div class="tg-flow-media-grid"><label class="tg-media-field"><span class="tg-form-label">'+fileLabel+'</span><div class="tg-flow-file-control"><input type="file" data-media-file accept="'+mediaAccept(type)+'"><span class="tg-flow-file-button">Выберите файл</span><span class="tg-flow-upload-name" data-upload-name>'+esc(source.media_file_name||'Файл не выбран')+'</span></div></label><label class="tg-media-field"><span class="tg-form-label">'+urlLabel+'</span><input class="tg-form-field tg-media-url-field" data-media-url placeholder="https://..." value="'+esc(source.media_url||'')+'"></label></div>'+cardOptionsHtml(source, '<label><input type="checkbox" data-caption-toggle'+(captionOn?' checked':'')+'> Подпись</label>')+'<input type="hidden" data-existing-file-path value="'+esc(source.media_file_path||'')+'"><input type="hidden" data-existing-file-name value="'+esc(source.media_file_name||'')+'">';
      if(type==='video_note') media+='<div class="tg-flow-card-note"><strong>Видео-заметка Telegram</strong><span>MP4 или M4V, до 10 МБ и до 60 секунд. В Telegram будет отображаться как кружок.</span></div><div class="tg-video-note-status" data-video-note-status></div>';
      if(url&&type==='image') media+='<div class="tg-flow-media-preview"><img src="'+esc(url)+'" alt="Превью картинки"></div>'; else if(url) media+='<div class="tg-flow-media-preview"><strong>'+esc(source.media_file_name||cardTitle(type))+'</strong><div>'+esc(url)+'</div></div>';
    }
    const captionVisible = type==='text' || type==='question' || !!(source.caption_enabled || String(source.text||'').trim());
    const afterEditorOptions = type==='text' ? '<div class="tg-card-options tg-card-options-after">'+protectOptionHtml(source)+'</div>' : (type==='question' ? questionControlsHtml(source) : '');
    const buttonsHtml = type==='question' ? '' : '<div class="tg-message-buttons" data-buttons-box></div><div class="tg-flow-button-add-line"><button type="button" class="tg-btn-ghost" data-add-button>+ Добавить кнопку</button></div>';
    const editorHtml = type==='question' ? '<div class="tg-question-editor-wrap">'+editorTemplate(type, source.text||'', true)+'</div>' : editorTemplate(type, source.text||'', captionVisible);
    card.innerHTML='<div class="tg-flow-card-head"><h5>'+cardTitle(type)+'</h5><div class="tg-flow-card-actions"><button type="button" title="Выше" data-card-up>'+adminIcon('arrow-up')+'</button><button type="button" title="Ниже" data-card-down>'+adminIcon('arrow-down')+'</button><button type="button" title="Удалить" data-card-delete>'+adminIcon('delete')+'</button></div></div>'+media+editorHtml+afterEditorOptions+buttonsHtml;
    box.appendChild(card); bindCard(card); renderButtons(card); if(type==='gallery') renderGalleryItems(card); if(type==='question'){renderQuestionAnswers(card); const wait=card.querySelector('[data-question-wait-label]'); if(wait) wait.textContent=questionWaitLabel(card);} reindexFiles(); updateCharCount(card); return card;
  }
  function syncCaptionEditor(card, clearWhenHidden=false){
    const type=card.dataset.type||'text';
    const editorBox=card.querySelector('[data-caption-editor]');
    if(!editorBox || type==='text' || type==='question') return;
    const toggle=card.querySelector('[data-caption-toggle], [data-gallery-caption]');
    const enabled=!!(toggle && toggle.checked);
    editorBox.style.display=enabled ? '' : 'none';
    if(!enabled && clearWhenHidden){
      const editor=card.querySelector('[data-editor]');
      if(editor) editor.innerHTML='';
    }
    updateCharCount(card);
  }
  function bindCard(card){
    const editor=card.querySelector('[data-editor]');
    editor.addEventListener('paste',e=>{e.preventDefault(); const data=e.clipboardData||window.clipboardData; const html=data?.getData('text/html')||''; const text=data?.getData('text/plain')||''; insertHtmlAtSelection(editor, html?sanitizeEditorHtml(html):esc(text).replace(/\n/g,'<br>')); collectCards(); updateCharCount(card);});
    editor.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault(); insertPlainBreak(editor); collectCards(); updateCharCount(card);}});
    editor.addEventListener('keyup',()=>rememberEditorSelection(editor));
    editor.addEventListener('mouseup',()=>rememberEditorSelection(editor));
    editor.addEventListener('touchend',()=>setTimeout(()=>rememberEditorSelection(editor),0));
    card.addEventListener('input',()=>{rememberEditorSelection(editor); collectCards(); updateCharCount(card);}); card.addEventListener('change',()=>{collectCards(); updateCharCount(card);});
    card.querySelectorAll('.tg-card-toolbar button,.tg-mini-btn').forEach(btn=>btn.addEventListener('mousedown',e=>e.preventDefault()));
    card.querySelectorAll('[data-format]').forEach(btn=>btn.addEventListener('click',()=>{editor.focus(); document.execCommand(btn.dataset.format,false,null); rememberEditorSelection(editor); collectCards(); updateCharCount(card);}));
    card.querySelectorAll('[data-wrap]').forEach(btn=>btn.addEventListener('click',()=>{const [a,b]=(btn.dataset.wrap||'').split('|'); wrapSelection(editor,a,b); rememberEditorSelection(editor); collectCards(); updateCharCount(card);}));
    card.querySelector('[data-date]')?.addEventListener('click',e=>{e.preventDefault(); e.stopPropagation(); rememberEditorSelection(editor); openDateModal(editor);});
    card.querySelector('[data-link]')?.addEventListener('click',()=>{const url=prompt('Вставьте ссылку','https://'); if(url) {wrapSelection(editor,'<a href="'+esc(url)+'">','</a>'); collectCards();}});
    card.querySelector('[data-clear-format]')?.addEventListener('click',()=>{editor.focus(); document.execCommand('removeFormat',false,null); collectCards();});
    const emojiBtn=card.querySelector('[data-emoji]'), emojiMenu=card.querySelector('.tg-emoji-menu'), macroBtn=card.querySelector('[data-macro]'), macroMenu=card.querySelector('.tg-macro-menu');
    emojiBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!emojiMenu.classList.contains('is-open'); closeFloatingMenus(); emojiMenu.classList.toggle('is-open',open);});
    macroBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!macroMenu.classList.contains('is-open'); closeFloatingMenus(); macroMenu.classList.toggle('is-open',open);});
    emojiMenu?.addEventListener('click',e=>e.stopPropagation()); macroMenu?.addEventListener('click',e=>e.stopPropagation());
    card.querySelector('[data-macro-search]')?.addEventListener('input',e=>{const q=String(e.target.value||'').toLowerCase().trim(); card.querySelectorAll('[data-macro-search-text]').forEach(item=>{item.style.display=!q||String(item.dataset.macroSearchText||'').toLowerCase().includes(q)?'grid':'none';});});
    card.querySelectorAll('[data-emoji-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.emojiInsert||'');emojiMenu.classList.remove('is-open');collectCards();updateCharCount(card);}));
    card.querySelectorAll('[data-macro-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.macroInsert||'');macroMenu.classList.remove('is-open');collectCards();updateCharCount(card);}));
    card.querySelector('[data-add-button]')?.addEventListener('click',()=>openButtonModal(card,null,0,true));
    card.querySelector('[data-card-delete]')?.addEventListener('click',()=>{card.remove(); if(!box.querySelector('[data-card]')) addCard('text',{}); reindexFiles(); collectCards();});
    card.querySelector('[data-card-up]')?.addEventListener('click',()=>{if(card.previousElementSibling) box.insertBefore(card,card.previousElementSibling); reindexFiles(); collectCards();});
    card.querySelector('[data-card-down]')?.addEventListener('click',()=>{if(card.nextElementSibling) box.insertBefore(card.nextElementSibling,card); reindexFiles(); collectCards();});
    card.querySelector('[data-media-file]')?.addEventListener('change',e=>{const f=e.target.files&&e.target.files[0]; const label=card.querySelector('[data-upload-name]'); if(label) label.textContent=f?f.name:'Файл не выбран'; if((card.dataset.type||'')==='video_note'){validateVideoNoteFile(card,f);} collectCards();});
    card.querySelector('[data-media-url]')?.addEventListener('blur',()=>{validateVideoNoteUrl(card); collectCards();});
    card.querySelector('[data-gallery-files]')?.addEventListener('change',e=>{const files=e.target.files?Array.from(e.target.files):[]; const label=card.querySelector('[data-upload-name]'); if(label) label.textContent=files.length?('Выбрано: '+files.length):'Можно выбрать сразу несколько'; renderGalleryItems(card); collectCards();});
    card.querySelector('[data-gallery-url-add]')?.addEventListener('click',()=>addGalleryUrl(card));
    card.querySelector('[data-gallery-url]')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addGalleryUrl(card);}});
    card.querySelector('[data-caption-toggle]')?.addEventListener('change',e=>{syncCaptionEditor(card,true); collectCards();});
    card.querySelector('[data-gallery-caption]')?.addEventListener('change',e=>{syncCaptionEditor(card,true); collectCards();});
    card.querySelector('[data-protect-toggle]')?.addEventListener('change',collectCards);
    card.querySelector('[data-question-add-answer]')?.addEventListener('click',()=>openQuestionAnswerModal(card,null));
    card.querySelector('[data-question-settings]')?.addEventListener('click',()=>openQuestionSettingsModal(card));
    card.querySelector('[data-question-save-field]')?.addEventListener('change',collectCards);
    card.querySelector('[data-question-disable-preview]')?.addEventListener('change',collectCards);
    syncCaptionEditor(card,false);
  }
  function updateCharCount(card){const editor=card.querySelector('[data-editor]'); const counter=card.querySelector('[data-char-count]'); if(counter) counter.textContent=String((editor?.innerText||'').length);}
  function reindexFiles(){box.querySelectorAll('[data-card]').forEach((card,index)=>{const input=card.querySelector('[data-media-file]'); if(input) input.name='card_media_file_'+index; const galleryInput=card.querySelector('[data-gallery-files]'); if(galleryInput) galleryInput.name='gallery_media_file_'+index+'[]';});}
  function collectCards(){
    const cards=[];
    try {
      box.querySelectorAll('[data-card]').forEach((card,index)=>{
        const type=card.dataset.type||'text';
        const captionEnabled = (type==='text'||type==='question') ? true : !!card.querySelector('[data-caption-toggle], [data-gallery-caption]')?.checked;
        const rawText=(card.querySelector('[data-editor]')?.innerHTML||'').trim();
        const item={type,text:captionEnabled?rawText:'',buttons:type==='question'?[]:getRows(card),protect_content:!!card.querySelector('[data-protect-toggle]')?.checked};
        if(type==='question'){
          item.disable_web_page_preview=!!card.querySelector('[data-question-disable-preview]')?.checked;
          item.save_field_code=card.querySelector('[data-question-save-field]')?.value||'';
          item.answers=getQuestionAnswers(card);
          item.wait_value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
          item.wait_unit=card.dataset.questionWaitUnit||'hours';
          item.enable_check=card.dataset.questionCheck==='1';
          item.remind_no_answer=card.dataset.questionRemind==='1';
          item.remind_text=card.dataset.questionRemindText||'';
          item.remind_value=Math.max(1,parseInt(card.dataset.questionRemindValue||'5',10)||5);
          item.remind_unit=card.dataset.questionRemindUnit||'minutes';
        } else if(type==='gallery'){
          item.gallery_items=getGalleryItems(card); item.caption_enabled=captionEnabled; const gf=card.querySelector('[data-gallery-files]'); if(gf&&gf.files&&gf.files.length){item.has_gallery_upload=true; item.gallery_upload_slot=index;}
        } else if(type!=='text'){
          item.caption_enabled=captionEnabled; item.media_url=card.querySelector('[data-media-url]')?.value||''; item.media_file_path=card.querySelector('[data-existing-file-path]')?.value||''; item.media_file_name=card.querySelector('[data-existing-file-name]')?.value||''; const f=card.querySelector('[data-media-file]'); if(f&&f.files&&f.files.length){item.has_upload=true; item.upload_slot=index;}
        }
        cards.push(item);
      });
      jsonInput.value=JSON.stringify(cards);
      panelDiag('collectCards:ok',{count:cards.length, questionAnswers:cards.filter(c=>c.type==='question').map(c=>(c.answers||[]).length), jsonLength:jsonInput.value.length});
      return cards;
    } catch(e) {
      panelDiag('collectCards:error',{message:e && e.message ? e.message : String(e), stack:e && e.stack ? String(e.stack).slice(0,900) : ''});
      throw e;
    }
  }
  document.querySelectorAll('[data-add-card]').forEach(btn=>btn.addEventListener('click',()=>{
    const type = btn.dataset.addCard || 'text';
    addCard(type,{}); collectCards();
  }));
  (panel ? panel.querySelector('#tgScenarioDateApply') : document.getElementById('tgScenarioDateApply'))?.addEventListener('click',applyDateModal);
  (panel || document).querySelectorAll('[data-date-modal-close]').forEach(btn=>btn.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();closeDateModal();}));
  (panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal'))?.addEventListener('click',e=>{if(e.target&&e.target.id==='tgScenarioDateModal')closeDateModal();});
  document.addEventListener('click',()=>closeFloatingMenus());
  (Array.isArray(initialCards)&&initialCards.length?initialCards:[{type:'text',text:'',buttons:[]}]).forEach(card=>addCard(card.type||'text',card));
  collectCards();
  function prepareScenarioPanelSave(){
    let ok=true;
    try {
      closeQuestionModals();
      const alertBox = panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
      if(alertBox){ alertBox.textContent=''; alertBox.classList.remove('is-open'); }
      box.querySelectorAll('[data-card][data-type="question"]').forEach(card=>{
        // Принудительно нормализуем ответы прямо перед сохранением: это убирает рассинхрон
        // между чипсами в интерфейсе и hidden scenario_cards_json.
        const answers = getQuestionAnswers(card);
        card.dataset.questionAnswers = JSON.stringify(answers);
        renderQuestionAnswers(card);
        const wait = card.querySelector('[data-question-wait-label]');
        if(wait) wait.textContent = questionWaitLabel(card);
      });
      box.querySelectorAll('[data-card][data-type="video_note"]').forEach(card=>{
        if(!validateVideoNoteUrl(card)) ok=false;
        const input=card.querySelector('[data-media-file]');
        const file=input&&input.files&&input.files[0];
        const basic=videoNoteFileLooksValid(file);
        if(!basic.ok){ videoNoteStatus(card,basic.message,'error'); ok=false; }
      });
      const cards = collectCards();
      panelDiag('prepareSave:afterCollect',{count:Array.isArray(cards)?cards.length:0,jsonLength:(jsonInput&&jsonInput.value?jsonInput.value.length:0),questions:(Array.isArray(cards)?cards.filter(c=>c.type==='question').map(c=>({textLength:String(c.text||'').length,answers:(c.answers||[]).map(a=>a.text)})):[])});
      if(!Array.isArray(cards) || !cards.length){
        showPanelDiag('Добавьте хотя бы одну карточку сообщения.', {stage:'prepareSave:noCards'});
        ok=false;
      }
    } catch(e) {
      console.error('Scenario panel save prepare error', e);
      showPanelDiag('Не удалось подготовить карточки к сохранению. Проверьте карточку «Вопрос» и варианты ответов. Ошибка: '+(e && e.message ? e.message : e), {stage:'prepareSave:catch', stack:e && e.stack ? String(e.stack).slice(0,1200) : ''});
      ok=false;
    }
    return ok;
  }
  form.tgFlowPrepareSave = prepareScenarioPanelSave;
  const saveButton = form.querySelector('.tg-flow-panel-primary');
  if(saveButton){
    saveButton.addEventListener('click',()=>{
      try {
        const questionCards = Array.from(box.querySelectorAll('[data-card][data-type="question"]')).map((card,idx)=>({idx,answers:getQuestionAnswers(card).map(a=>a.text),textLength:(card.querySelector('[data-editor]')?.innerText||'').trim().length}));
        panelDiag('saveButton:click',{questions:questionCards,jsonLength:(jsonInput&&jsonInput.value?jsonInput.value.length:0)});
      } catch(e) { panelDiag('saveButton:click-error',{message:e && e.message ? e.message : String(e)}); }
    }, true);
  }
  form.addEventListener('submit',(event)=>{
    if(form.dataset.flowDrawerAjax === '1') return;
    if(!prepareScenarioPanelSave()){
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  });
})();
</script>
