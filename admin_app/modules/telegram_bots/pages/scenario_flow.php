<?php
defined('ASR_ADMIN') || exit;

asr_tg_repository_ensure_scenario_schema($pdo);

$h = $h ?? static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$safeJson = static function($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? '{}' : $json;
};
$plainPreview = static function(string $html): string {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    return mb_strlen($text, 'UTF-8') > 130 ? mb_substr($text, 0, 130, 'UTF-8') . '…' : $text;
};
$delayUnitTitle = static function(string $unit, int $value): string {
    $unit = in_array($unit, ['seconds', 'minutes', 'hours', 'days'], true) ? $unit : 'days';
    $map = [
        'seconds' => ['секунду', 'секунды', 'секунд'],
        'minutes' => ['минуту', 'минуты', 'минут'],
        'hours' => ['час', 'часа', 'часов'],
        'days' => ['день', 'дня', 'дней'],
    ];
    $forms = $map[$unit];
    $n = abs($value) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $forms[2];
    if ($n1 > 1 && $n1 < 5) return $forms[1];
    if ($n1 === 1) return $forms[0];
    return $forms[2];
};
$normalizeTime = static function($value, string $fallback = '00:00'): string {
    $time = trim((string)$value);
    if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) return $fallback;
    [$h, $m] = array_map('intval', explode(':', $time));
    return sprintf('%02d:%02d', $h, $m);
};
$normalizeWeekdays = static function($raw): array {
    $allowed = ['mon','tue','wed','thu','fri','sat','sun'];
    if (!is_array($raw) || !$raw) return $allowed;
    $out = [];
    foreach ($raw as $day) {
        $day = (string)$day;
        if (in_array($day, $allowed, true) && !in_array($day, $out, true)) $out[] = $day;
    }
    return $out ?: $allowed;
};
$weekdaysTitle = static function(array $days): string {
    $labels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $allowed = array_keys($labels);
    $days = array_values(array_intersect($allowed, $days));
    if (count($days) === 7) return 'Пн, Вт, Ср, Чт, Пт, Сб, Вс';
    return implode(', ', array_map(static fn($d) => $labels[$d] ?? $d, $days));
};
$getDelaySettings = static function(array $block) use ($delayUnitTitle, $normalizeTime, $normalizeWeekdays, $weekdaysTitle): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $mode = (string)($settings['delay_mode'] ?? $settings['mode'] ?? 'after');
    if (!in_array($mode, ['after', 'tomorrow', 'at'], true)) $mode = 'after';
    $value = max(1, min(999, (int)($settings['delay_value'] ?? $settings['value'] ?? 1)));
    $unit = (string)($settings['delay_unit'] ?? $settings['unit'] ?? 'days');
    if (!in_array($unit, ['seconds', 'minutes', 'hours', 'days'], true)) $unit = 'days';
    $timeMode = (string)($settings['send_time_mode'] ?? 'any');
    if (!in_array($timeMode, ['any', 'exact', 'interval'], true)) $timeMode = 'any';
    $exactTime = $normalizeTime($settings['send_time_exact'] ?? '00:00');
    $intervalFrom = $normalizeTime($settings['send_time_from'] ?? '00:00');
    $intervalTo = $normalizeTime($settings['send_time_to'] ?? '00:00');
    $timezone = trim((string)($settings['timezone'] ?? 'Europe/Moscow')) ?: 'Europe/Moscow';
    $weekdays = $normalizeWeekdays($settings['weekdays'] ?? []);

    $modeLabel = ['after' => 'Отправить через', 'tomorrow' => 'Отправить завтра', 'at' => 'Отправить в'][$mode];
    $unitTitle = $delayUnitTitle($unit, $value);
    if ($mode === 'tomorrow') {
        $sendLabel = 'Завтра в ' . $exactTime;
    } elseif ($mode === 'at') {
        $sendLabel = 'В ' . $exactTime;
    } else {
        $sendLabel = 'Через ' . $value . ' ' . $unitTitle;
    }
    $timeLabel = 'Любое';
    if ($mode !== 'after' || $timeMode === 'exact') $timeLabel = $exactTime;
    if ($mode === 'after' && $timeMode === 'interval') $timeLabel = $intervalFrom . '—' . $intervalTo;
    return [
        'mode' => $mode,
        'modeLabel' => $modeLabel,
        'value' => $value,
        'unit' => $unit,
        'unitTitle' => $unitTitle,
        'sendTimeMode' => $timeMode,
        'sendTimeExact' => $exactTime,
        'sendTimeFrom' => $intervalFrom,
        'sendTimeTo' => $intervalTo,
        'timezone' => $timezone,
        'weekdays' => $weekdays,
        'weekdaysTitle' => $weekdaysTitle($weekdays),
        'sendLabel' => $sendLabel,
        'timeLabel' => $timeLabel,
        'preview' => $sendLabel,
    ];
};
$getBlockCards = static function(array $block): array {
    if ((string)($block['type'] ?? '') === 'start') return [];
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (is_array($settings) && isset($settings['cards']) && is_array($settings['cards']) && $settings['cards']) {
        return array_values($settings['cards']);
    }
    return [[
        'type' => 'text',
        'text' => (string)($block['message_text'] ?? ''),
        'media_url' => '',
        'buttons' => [],
    ]];
};
$normalizeFlowCards = static function(array $cards) use ($plainPreview): array {
    $out = [];
    foreach ($cards as $cardIndex => $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if (!in_array($type, ['text', 'image', 'file', 'audio', 'video', 'video_note', 'gallery', 'question'], true)) $type = 'text';
        $buttonRows = [];
        $rawRows = $card['buttons'] ?? [];
        if (is_array($rawRows)) {
            foreach ($rawRows as $rowIndex => $rawRow) {
                $rowIsList = is_array($rawRow) && array_keys($rawRow) === range(0, count($rawRow) - 1);
                $row = $rowIsList ? $rawRow : [$rawRow];
                $cleanRow = [];
                foreach ($row as $buttonIndex => $btn) {
                    if (!is_array($btn)) continue;
                    $btnType = ((string)($btn['type'] ?? 'url')) === 'transition' ? 'transition' : 'url';
                    $text = trim((string)($btn['text'] ?? $btn['title'] ?? ''));
                    $url = trim((string)($btn['url'] ?? ''));
                    $target = max(0, (int)($btn['target_block_id'] ?? 0));
                    if ($text === '' && $url === '' && $target <= 0) continue;
                    $cleanRow[] = [
                        'handleId' => 'btn-c' . $cardIndex . '-r' . $rowIndex . '-b' . $buttonIndex,
                        'type' => $btnType,
                        'text' => $text !== '' ? $text : ($btnType === 'transition' ? 'Переход' : 'Кнопка'),
                        'url' => $url,
                        'target_block_id' => $target,
                    ];
                }
                if ($cleanRow) $buttonRows[] = $cleanRow;
            }
        }
        $questionAnswers = [];
        if ($type === 'question' && isset($card['answers']) && is_array($card['answers'])) {
            foreach ($card['answers'] as $answerIndex => $answer) {
                if (!is_array($answer)) continue;
                $answerText = trim((string)($answer['text'] ?? $answer['title'] ?? ''));
                if ($answerText === '') continue;
                $questionAnswers[] = [
                    'handleId' => 'q-a' . $cardIndex . '-' . $answerIndex,
                    'text' => $answerText,
                    'target_block_id' => max(0, (int)($answer['target_block_id'] ?? 0)),
                ];
                if (count($questionAnswers) >= 20) break;
            }
        }
        $media = trim((string)($card['media_file_path'] ?? '')) ?: trim((string)($card['media_url'] ?? ($card['url'] ?? '')));
        $out[] = [
            'type' => $type,
            'text' => (string)($card['text'] ?? ''),
            'textPreview' => $plainPreview((string)($card['text'] ?? '')),
            'media_url' => trim((string)($card['media_url'] ?? ($card['url'] ?? ''))),
            'media_file_path' => trim((string)($card['media_file_path'] ?? '')),
            'media_file_name' => trim((string)($card['media_file_name'] ?? '')),
            'media' => $media,
            'gallery_items' => isset($card['gallery_items']) && is_array($card['gallery_items']) ? array_values($card['gallery_items']) : [],
            'protect_content' => !empty($card['protect_content']),
            'caption_enabled' => !empty($card['caption_enabled']),
            'disable_web_page_preview' => !empty($card['disable_web_page_preview']),
            'save_field_code' => trim((string)($card['save_field_code'] ?? '')),
            'answers' => $questionAnswers,
            'wait_value' => max(1, (int)($card['wait_value'] ?? 24)),
            'wait_unit' => (string)($card['wait_unit'] ?? 'hours'),
            'no_answer_handle_id' => $type === 'question' ? ('q-noanswer-c' . $cardIndex) : '',
            'no_answer_target_block_id' => max(0, (int)($card['no_answer_target_block_id'] ?? 0)),
            'buttons' => $buttonRows,
        ];
    }
    return $out;
};
$findButtonHandleForLink = static function(array $cards, array $link): string {
    $linkType = (string)($link['link_type'] ?? '');
    if ($linkType === 'manual' || $linkType === 'start' || $linkType === 'next') return 'out';
    $to = (int)($link['to_block_id'] ?? 0);
    $buttonText = trim((string)($link['button_text'] ?? ''));
    if ($linkType === 'question_no_answer') {
        foreach ($cards as $card) {
            if (($card['type'] ?? '') !== 'question') continue;
            if ($to > 0 && (int)($card['no_answer_target_block_id'] ?? 0) === $to) return (string)($card['no_answer_handle_id'] ?? 'out');
        }
        foreach ($cards as $card) {
            if (($card['type'] ?? '') === 'question' && !empty($card['no_answer_handle_id'])) return (string)$card['no_answer_handle_id'];
        }
        return 'out';
    }
    if ($linkType === 'question_answer') {
        foreach ($cards as $card) {
            if (($card['type'] ?? '') !== 'question') continue;
            foreach (($card['answers'] ?? []) as $answer) {
                $target = (int)($answer['target_block_id'] ?? 0);
                $text = trim((string)($answer['text'] ?? ''));
                if ($target > 0 && $target === $to) return (string)($answer['handleId'] ?? 'out');
                if ($target <= 0 && $buttonText !== '' && $text === $buttonText) return (string)($answer['handleId'] ?? 'out');
            }
        }
        return 'out';
    }
    if ($linkType !== 'button') return 'out';
    foreach ($cards as $card) {
        foreach (($card['buttons'] ?? []) as $row) {
            foreach ($row as $button) {
                $target = (int)($button['target_block_id'] ?? 0);
                $text = trim((string)($button['text'] ?? ''));
                if ($target > 0 && $target === $to) return (string)$button['handleId'];
                if ($target <= 0 && $buttonText !== '' && $text === $buttonText) return (string)$button['handleId'];
            }
        }
    }
    return 'out';
};

$scenarioId = (int)($_GET['scenario_id'] ?? 0);
$scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
if ($scenario && function_exists('asr_tg_scenario_normalize_single_bot')) {
    try { asr_tg_scenario_normalize_single_bot($pdo, $scenarioId); } catch (Throwable $e) {}
}
if (!$scenario): ?>
    <section class="bg-white rounded-3xl border border-red-100 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-red-700">Сценарий не найден</h3>
        <a class="inline-flex mt-4 px-4 py-3 rounded-2xl bg-gray-100 text-gray-700 text-xs font-semibold" href="admin.php?tab=telegram_bots&page=scenarios">Вернуться к списку</a>
    </section>
<?php return; endif;

if (function_exists('asr_tg_scenario_ensure_start_block')) {
    try { asr_tg_scenario_ensure_start_block($pdo, $scenarioId); } catch (Throwable $e) {}
}
if (function_exists('asr_tg_scenario_blocks_all')) {
    $blocks = asr_tg_scenario_blocks_all($pdo, $scenarioId);
} else {
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$scenarioId]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (function_exists('asr_tg_scenario_links_all')) {
    $links = asr_tg_scenario_links_all($pdo, $scenarioId);
} else {
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$scenarioId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (!$blocks) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$scenarioId]);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}
if (!$links) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$scenarioId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

if (function_exists('asr_tg_scenario_block_deeplinks_by_blocks')) {
    try {
        $deeplinksByBlock = asr_tg_scenario_block_deeplinks_by_blocks($pdo, $scenarioId, array_map(static fn($block) => (int)($block['id'] ?? 0), $blocks));
    } catch (Throwable $e) {
        $deeplinksByBlock = [];
    }
} else {
    $deeplinksByBlock = [];
}

$nodes = [];
foreach ($blocks as $index => $block) {
    $blockId = (int)($block['id'] ?? 0);
    if ($blockId <= 0) continue;
    $type = (string)($block['type'] ?? 'message');
    $isStart = $type === 'start';
    $isDelay = $type === 'delay';
    $delaySettings = $isDelay ? $getDelaySettings($block) : [];
    $cards = $isDelay ? [] : $getBlockCards($block);
    $flowCards = $isDelay ? [] : $normalizeFlowCards($cards);
    $firstCard = $flowCards[0] ?? [];
    $x = (int)($block['position_x'] ?? 0);
    $y = (int)($block['position_y'] ?? 0);
    if ($x <= 0 || $y <= 0) {
        $x = $isStart ? 120 : 470 + (($index % 4) * 320);
        $y = 140 + ((int)floor($index / 4) * 230);
    }
    $preview = $isStart ? 'По кнопке «Начать»' : ($isDelay ? (string)($delaySettings['preview'] ?? 'Подождать 5 минут') : (string)($firstCard['textPreview'] ?? ''));
    $deeplink = !$isStart ? ($deeplinksByBlock[$blockId] ?? null) : null;
    $deeplinkCode = is_array($deeplink) ? trim((string)($deeplink['code'] ?? $deeplink['token'] ?? '')) : '';
    $deeplinkUrl = is_array($deeplink) ? trim((string)($deeplink['url'] ?? '')) : '';

    // v3.5.130: ссылка под блоком всегда должна быть полноценной Telegram deep link.
    // Раньше в некоторых строках сохранялся только base-url бота или только код,
    // из-за этого Telegram отправлял обычный /start без payload и сценарий не понимал,
    // с какого блока нужно запускаться.
    if ($deeplinkCode === '' && $deeplinkUrl !== '') {
        $parts = @parse_url($deeplinkUrl);
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string)$parts['query'], $q);
            $deeplinkCode = trim((string)($q['start'] ?? $q['startapp'] ?? ''));
        }
    }
    if ($deeplinkCode !== '') {
        $botUsernameForDeeplink = ltrim(trim((string)($currentScenarioBot['bot_username'] ?? '')), '@');
        if ($botUsernameForDeeplink !== '') {
            $deeplinkUrl = 'https://t.me/' . rawurlencode($botUsernameForDeeplink) . '?start=' . rawurlencode($deeplinkCode);
        }
    }
    $nodes[] = [
        'id' => (string)$blockId,
        'type' => $isStart ? 'startNode' : ($isDelay ? 'delayNode' : 'messageNode'),
        'position' => ['x' => $x, 'y' => $y],
        'data' => [
            'blockId' => $blockId,
            'blockType' => $type,
            'title' => trim((string)($block['title'] ?? '')) ?: ($isStart ? 'Старт' : ($isDelay ? 'Задержка' : 'Сообщение')),
            'preview' => $preview !== '' ? $preview : ($isDelay ? 'Подождать 5 минут' : 'Пустой текст'),
            'delayMode' => (string)($delaySettings['mode'] ?? ''),
            'delayModeLabel' => (string)($delaySettings['modeLabel'] ?? ''),
            'delayValue' => (int)($delaySettings['value'] ?? 0),
            'delayUnit' => (string)($delaySettings['unit'] ?? ''),
            'delayUnitTitle' => (string)($delaySettings['unitTitle'] ?? ''),
            'delaySendLabel' => (string)($delaySettings['sendLabel'] ?? ''),
            'delayTimeLabel' => (string)($delaySettings['timeLabel'] ?? ''),
            'delayWeekdaysTitle' => (string)($delaySettings['weekdaysTitle'] ?? ''),
            'missingNext' => false,
            'cards' => $flowCards,
            'cardsCount' => count($flowCards),
            'editUrl' => 'admin.php?tab=telegram_bots&page=scenario_block_panel&scenario_id=' . $scenarioId . '&block_id=' . $blockId . '&flow_panel_v=3.5.123',
            'deleteAllowed' => !$isStart,
            'deeplinkCode' => $deeplinkCode,
            'deeplinkUrl' => $deeplinkUrl,
            'hasDeeplink' => $deeplinkCode !== '' || $deeplinkUrl !== '',
        ],
    ];
}

$blockIds = array_fill_keys(array_map(static fn($n) => (string)$n['id'], $nodes), true);
$nodeCardsById = [];
foreach ($nodes as $node) {
    $nodeCardsById[(string)($node['id'] ?? '')] = $node['data']['cards'] ?? [];
}
$edges = [];
foreach ($links as $link) {
    $from = (string)((int)($link['from_block_id'] ?? 0));
    $to = (string)((int)($link['to_block_id'] ?? 0));
    if (!$from || !$to || !isset($blockIds[$from], $blockIds[$to])) continue;
    $edgeId = 'link-' . (int)($link['id'] ?? 0);
    $edges[] = [
        'id' => $edgeId,
        'source' => $from,
        'target' => $to,
        'sourceHandle' => $findButtonHandleForLink($nodeCardsById[$from] ?? [], $link),
        'targetHandle' => 'in',
        'type' => 'scenarioSmooth',
        'markerEnd' => ['type' => 'arrowclosed', 'width' => 10, 'height' => 10],
        'style' => ['strokeWidth' => 1.8, 'stroke' => '#737373'],
    ];
}

$csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
$botsLight = function_exists('asr_tg_bots_all_light') ? asr_tg_bots_all_light($pdo) : [];
$currentScenarioBotId = function_exists('asr_tg_scenario_bot_id') ? asr_tg_scenario_bot_id($pdo, $scenarioId) : 0;
$currentScenarioBot = $currentScenarioBotId > 0 && function_exists('asr_tg_bot_find_light') ? asr_tg_bot_find_light($pdo, $currentScenarioBotId) : null;
$currentChannelType = function_exists('asr_tg_channel_type_of') && is_array($currentScenarioBot) ? asr_tg_channel_type_of($currentScenarioBot) : (string)($currentScenarioBot['channel_type'] ?? 'telegram');
$currentChannelType = $currentChannelType === 'vk' ? 'vk' : 'telegram';
$currentScenarioBotUsername = is_array($currentScenarioBot) ? trim((string)($currentScenarioBot['bot_username'] ?? '')) : '';
$currentScenarioBotUsername = ltrim($currentScenarioBotUsername, '@');
$currentScenarioBotUrl = ($currentChannelType === 'telegram' && $currentScenarioBotUsername !== '') ? ('https://t.me/' . rawurlencode($currentScenarioBotUsername)) : '';
$scenarioCommandRows = [];
if ($currentScenarioBotId > 0 && function_exists('asr_tg_bot_commands_all')) {
    try { $scenarioCommandRows = asr_tg_bot_commands_all($pdo, $currentScenarioBotId); } catch (Throwable $e) { $scenarioCommandRows = []; }
}
// /start — системная команда Telegram. В меню её не показываем и не сохраняем:
// она используется только как транспорт для диплинков сценариев.
$scenarioCommandRows = array_values(array_filter($scenarioCommandRows, static function($cmd) {
    $name = strtolower(ltrim(trim((string)($cmd['command'] ?? '')), '/'));
    return $name !== 'start';
}));
if (!$scenarioCommandRows && $currentChannelType === 'telegram') {
    $scenarioCommandRows = [['command' => 'help', 'description' => 'помощь', 'scenario_id' => $scenarioId, 'step_id' => 0]];
}
$scenarioStepOptions = [];
if (function_exists('asr_tg_scenario_blocks_select')) {
    try { $scenarioStepOptions = asr_tg_scenario_blocks_select($pdo, $scenarioId); } catch (Throwable $e) { $scenarioStepOptions = []; }
} else {
    $scenarioStepOptions = $blocks;
}
$scenarioEditData = [
    'id' => $scenarioId,
    'title' => (string)($scenario['title'] ?? ''),
    'description' => (string)($scenario['description'] ?? ''),
    'status' => (string)($scenario['status'] ?? 'draft'),
    'bot_id' => $currentScenarioBotId,
];
$flowData = [
    'scenarioId' => $scenarioId,
    'scenarioTitle' => (string)($scenario['title'] ?? 'Сценарий'),
    'scenarioStatus' => (string)($scenario['status'] ?? 'draft'),
    'csrfToken' => $csrf,
    'nodes' => $nodes,
    'edges' => $edges,
    'returnUrl' => 'admin.php?tab=telegram_bots&page=scenario_flow&scenario_id=' . $scenarioId,
        'panelBaseUrl' => 'admin.php?tab=telegram_bots&page=scenario_block_panel&scenario_id=' . $scenarioId . '&flow_panel_v=3.5.123',
    'flowUrl' => 'admin.php?tab=telegram_bots&page=scenario_flow&scenario_id=' . $scenarioId,
    'blockLimit' => 550,
    'listUrl' => 'admin.php?tab=telegram_bots&page=scenarios',
];

$flowScriptPath = __DIR__ . '/../scenario_flow/dist/scenario-flow-cdn.js';
$flowScriptCode = is_file($flowScriptPath) ? (string)file_get_contents($flowScriptPath) : '';
?>

<link rel="stylesheet" href="https://unpkg.com/@xyflow/react@12/dist/style.css">
<style>

/* Минимальный локальный CSS React Flow на случай, если внешний CSS с unpkg не загрузился. */
.react-flow{width:100%;height:100%;position:relative;overflow:hidden;z-index:0}.react-flow__renderer{width:100%;height:100%;position:absolute;inset:0;overflow:hidden}.react-flow__pane{position:absolute;inset:0;z-index:1}.react-flow__viewport{position:absolute;top:0;left:0;transform-origin:0 0;z-index:2;pointer-events:none}.react-flow__nodes{position:absolute;top:0;left:0}.react-flow__node{position:absolute;user-select:none;pointer-events:all;transform-origin:0 0}.react-flow__edges{position:absolute;inset:0;overflow:visible;pointer-events:none}.react-flow__edge{pointer-events:visibleStroke}.react-flow__edge-path{fill:none}.react-flow__handle{position:absolute;pointer-events:all;min-width:5px;min-height:5px}.react-flow__handle-left{top:50%;transform:translateY(-50%)}.react-flow__handle-right{top:50%;transform:translateY(-50%)}.react-flow__background{position:absolute;inset:0;z-index:0}.react-flow__controls{position:absolute;left:15px;bottom:15px;z-index:5}.react-flow__controls button{display:block;width:28px;height:28px;border:0;border-bottom:1px solid #eee;background:#fff;cursor:pointer}.react-flow__container{position:absolute;inset:0}.react-flow__zoompane{position:absolute;inset:0}.react-flow__selectionpane{position:absolute;inset:0}.react-flow__edge-labels{position:absolute;inset:0;pointer-events:none}.react-flow__edge-label{position:absolute;pointer-events:all}.react-flow__nodesselection-rect,.react-flow__selection{position:absolute;pointer-events:none}
.tg-flow-app{position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;margin:0!important;z-index:90;background:#f1f3f5;display:flex;flex-direction:column;min-width:0;overflow:hidden}.tg-flow-topbar{height:58px;min-height:58px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 14px;box-shadow:0 8px 22px rgba(15,23,42,.06);z-index:10}.tg-flow-top-left,.tg-flow-top-right{display:flex;align-items:center;gap:8px;min-width:0}.tg-flow-menu-btn{width:40px;height:40px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;color:#4b5563;font-size:21px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.tg-flow-menu-btn:hover{background:#fff7ed;border-color:#fed7aa;color:#9a5a1f}.tg-flow-title{min-width:0;color:#1f2937;font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:42vw}.tg-flow-top-btn{height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0 13px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;color:#6b7280;text-decoration:none;font-size:12px;font-weight:650;cursor:pointer}.tg-flow-top-btn:hover{background:#f8fafc;color:#374151}.tg-flow-top-btn.is-primary{background:#FFA048;border-color:#FFA048;color:#fff}.tg-flow-top-btn.is-muted{background:#f3f4f6}.tg-flow-top-btn[disabled]{opacity:.5;cursor:not-allowed}.tg-flow-tech-link{height:38px;display:inline-flex;align-items:center;justify-content:center;padding:0 10px;border:1px solid #e5e7eb;background:#f9fafb;border-radius:14px;color:#9ca3af;text-decoration:none;font-size:11px;font-weight:650;cursor:pointer}.tg-flow-tech-link:hover{background:#f3f4f6;color:#6b7280}.tg-flow-canvas-wrap{position:relative;flex:1;min-height:0;overflow:hidden}.tg-flow-root{height:100%;width:100%}.tg-flow-node{width:270px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;box-shadow:0 12px 24px rgba(15,23,42,.06);overflow:visible}.tg-flow-node.is-start{border-color:#86efac}.tg-flow-node-head{height:46px;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 13px;border-radius:14px 14px 0 0;background:#fafafa;border-bottom:1px solid #f1f3f5}.tg-flow-node.is-start .tg-flow-node-head{background:#2fbf63;border-bottom-color:#2fbf63}.tg-flow-node-title{font-size:13px;font-weight:750;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tg-flow-node.is-start .tg-flow-node-title{color:#fff}.tg-flow-node-edit{border:0;background:transparent;color:#9ca3af;text-decoration:none;font-size:12px;font-weight:750;padding:7px 8px;border-radius:10px;cursor:pointer}.tg-flow-node-edit:hover{background:rgba(255,255,255,.18);color:#6b7280}.tg-flow-node-actions{display:flex;align-items:center;gap:3px;opacity:.72;transition:opacity .14s ease}.tg-flow-node:hover .tg-flow-node-actions{opacity:1}.tg-flow-node-action{width:24px;height:24px;border:0;background:transparent;border-radius:8px;color:#9ca3af;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;line-height:1;cursor:pointer}.tg-flow-node-action:hover{background:#f3f4f6;color:#374151}.tg-flow-node-action.is-danger:hover{background:#fef2f2;color:#dc2626}.tg-flow-node-action[disabled]{opacity:.35;cursor:not-allowed}.tg-flow-node.is-start .tg-flow-node-actions{opacity:.82}.tg-flow-node.is-start .tg-flow-node-action{color:rgba(255,255,255,.84)}.tg-flow-node.is-start .tg-flow-node-action:hover{background:rgba(255,255,255,.18);color:#fff}
.tg-flow-node.is-start .tg-flow-node-edit{color:rgba(255,255,255,.82)}.tg-flow-node-body{padding:13px}.tg-flow-node-card{background:#f7f7f8;border-radius:9px;padding:11px;font-size:12px;font-weight:650;color:#4b5563;line-height:1.45;min-height:48px;white-space:pre-wrap}.tg-flow-node-muted{margin-top:9px;font-size:11px;font-weight:650;color:#a3aab5;text-align:right}.tg-flow-node .react-flow__handle{width:17px;height:17px;border:2px solid #9ca3af;background:#fff;box-shadow:0 0 0 3px #fff}.tg-flow-node .react-flow__handle-left{left:-9px}.tg-flow-node .react-flow__handle-right{right:-9px}.tg-flow-add-menu{position:absolute;z-index:30;width:270px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.18);overflow:hidden;padding:8px}.tg-flow-add-title{padding:9px 12px;font-size:11px;font-weight:650;color:#9ca3af}.tg-flow-add-item{width:100%;display:flex;align-items:center;gap:10px;border:0;background:#fff;padding:12px;border-radius:12px;text-align:left;color:#374151;font-size:14px;font-weight:650;cursor:pointer}.tg-flow-add-item:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-add-item[disabled]{cursor:not-allowed;color:#c2c7d0;background:#fff}.tg-flow-error{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:25;width:min(520px,calc(100% - 40px));background:#fff;border:1px solid #fee2e2;border-radius:24px;padding:22px;box-shadow:0 18px 40px rgba(15,23,42,.08)}.tg-flow-error h4{margin:0;color:#991b1b;font-size:16px;font-weight:700}.tg-flow-error p{margin:9px 0 0;color:#6b7280;font-size:13px;font-weight:600;line-height:1.5}.react-flow__edge-path{stroke:#737373}.react-flow__edge.selected .react-flow__edge-path{stroke:#FFA048}.react-flow__controls{box-shadow:0 10px 28px rgba(15,23,42,.12);border-radius:14px;overflow:hidden}.react-flow__attribution{display:none!important}.tg-flow-root .react-flow__pane{cursor:grab}.tg-flow-root .react-flow__renderer,.tg-flow-root .react-flow__zoompane{cursor:grab}.tg-flow-root .react-flow__pane:active,.tg-flow-root.is-right-panning .react-flow__pane,.tg-flow-root.is-right-panning .react-flow__renderer,.tg-flow-root.is-right-panning .react-flow__zoompane{cursor:grabbing!important}.tg-flow-root .react-flow__node{cursor:grab}.tg-flow-root .react-flow__node:active{cursor:grabbing}.tg-flow-root .react-flow__handle{cursor:crosshair}.tg-flow-root .react-flow__edge-path{stroke-linecap:round;stroke-linejoin:round}.tg-flow-root .react-flow__edge:hover .react-flow__edge-path{stroke:#53565a!important;stroke-width:2.2!important}.tg-flow-block-counter{position:absolute;right:18px;bottom:14px;z-index:30;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.72);border:1px solid rgba(229,231,235,.75);color:#8b929d;font-size:12px;font-weight:650;pointer-events:none;box-shadow:0 8px 22px rgba(15,23,42,.06)}.tg-flow-toast{position:fixed;right:22px;top:76px;z-index:5200;max-width:min(520px,calc(100vw - 44px));padding:13px 16px;border-radius:18px;border:1px solid #fed7aa;background:#fff7ed;color:#9a5a1f;font-size:13px;font-weight:700;line-height:1.45;box-shadow:0 18px 42px rgba(15,23,42,.14);opacity:0;transform:translateY(-8px);pointer-events:none;transition:opacity .18s ease,transform .18s ease}.tg-flow-toast.is-open{opacity:1;transform:translateY(0)}.tg-flow-toast.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.tg-flow-confirm-backdrop{position:fixed;inset:0;z-index:5300;background:rgba(15,23,42,.28);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .16s ease}.tg-flow-confirm-backdrop.is-open{opacity:1;pointer-events:auto}.tg-flow-confirm-modal{width:min(430px,100%);background:#fff;border:1px solid #e5e7eb;border-radius:24px;box-shadow:0 28px 80px rgba(15,23,42,.22);padding:22px}.tg-flow-confirm-title{font-size:18px;font-weight:750;color:#1f2937;margin:0 0 8px}.tg-flow-confirm-text{font-size:13px;font-weight:600;line-height:1.55;color:#6b7280}.tg-flow-confirm-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.tg-flow-confirm-secondary,.tg-flow-confirm-danger{height:40px;border-radius:14px;padding:0 16px;font-size:13px;font-weight:700;cursor:pointer}.tg-flow-confirm-secondary{border:1px solid #e5e7eb;background:#fff;color:#6b7280}.tg-flow-confirm-secondary:hover{background:#f8fafc;color:#374151}.tg-flow-confirm-danger{border:1px solid #dc2626;background:#dc2626;color:#fff}.tg-flow-confirm-danger:hover{background:#b91c1c}
.tg-flow-block-drawer{position:fixed;inset:0;z-index:2500;pointer-events:none;opacity:0;transition:opacity .18s ease}.tg-flow-block-drawer.is-loading,.tg-flow-block-drawer.is-open{pointer-events:auto;opacity:1}.tg-flow-block-drawer:before{content:"";position:absolute;inset:0;background:rgba(15,23,42,.22);opacity:0;transition:opacity .18s ease}.tg-flow-block-drawer.is-open:before,.tg-flow-block-drawer.is-loading:before{opacity:1}.tg-flow-block-drawer .tg-flow-drawer-loader{position:absolute;right:24px;top:74px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:14px 16px;box-shadow:0 18px 40px rgba(15,23,42,.18);font-size:12px;font-weight:650;color:#6b7280}.tg-flow-block-drawer .tg-scenario-modal-backdrop{position:absolute!important;inset:0!important;background:transparent!important;display:flex!important;align-items:stretch!important;justify-content:flex-end!important;padding:0!important;pointer-events:none!important}.tg-flow-block-drawer .tg-scenario-modal{width:min(640px,100vw)!important;height:100vh!important;max-height:100vh!important;border-radius:24px 0 0 24px!important;border-top:0!important;border-right:0!important;border-bottom:0!important;box-shadow:-18px 0 55px rgba(15,23,42,.22)!important;transform:translateX(104%)!important;transition:transform .26s cubic-bezier(.2,.8,.2,1)!important;pointer-events:auto!important}.tg-flow-block-drawer.is-open .tg-scenario-modal{transform:translateX(0)!important}.tg-flow-block-drawer .tg-scenario-form{max-height:calc(100vh - 78px)!important;padding-bottom:92px!important}.tg-flow-block-drawer .tg-scenario-form-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #edf0f2;margin:18px -20px -18px!important;padding:14px 20px!important;z-index:5}.tg-flow-block-drawer .tg-scenario-message-grid{grid-template-columns:minmax(0,1fr)!important}.tg-flow-block-drawer .tg-scenario-preview-phone{display:none!important}.tg-flow-block-drawer .tg-scenario-modal-subtitle{display:none!important}.tg-flow-block-drawer.is-saving .tg-scenario-one-primary,.tg-flow-block-drawer.is-saving button[type="submit"]{opacity:.55;pointer-events:none}.tg-flow-block-drawer .tg-scenario-start-panel{padding:18px 20px;max-height:calc(100vh - 78px);overflow:auto}.tg-flow-block-drawer .tg-scenario-modal{display:flex!important;flex-direction:column!important;overflow:hidden!important}.tg-flow-block-drawer .tg-scenario-modal-head{flex:0 0 auto!important}.tg-flow-block-drawer .tg-scenario-form{height:calc(100vh - 73px)!important;max-height:none!important;display:flex!important;flex-direction:column!important;overflow:hidden!important;padding:18px 20px 0!important}.tg-flow-block-drawer .tg-scenario-form>.tg-scenario-field{flex:0 0 auto!important;margin-bottom:14px!important}.tg-flow-block-drawer .tg-scenario-message-grid{flex:1 1 auto!important;min-height:0!important;overflow:auto!important;padding-bottom:18px!important}.tg-flow-block-drawer .tg-scenario-form-actions{position:static!important;flex:0 0 auto!important;margin:0 -20px!important;padding:14px 20px!important;box-shadow:0 -10px 24px rgba(15,23,42,.06)!important}@media(max-width:760px){.tg-flow-block-drawer .tg-scenario-modal{width:100vw!important;border-radius:0!important}.tg-flow-block-drawer .tg-scenario-modal-head{padding:14px!important}.tg-flow-block-drawer .tg-scenario-form{padding:14px!important}.tg-flow-block-drawer .tg-scenario-form-actions{margin:14px -14px -14px!important;padding:12px 14px!important}}
.tg-flow-boot-status{display:none!important}.tg-flow-add-menu{width:320px;padding:10px;border-radius:20px}.tg-flow-add-title{padding:10px 12px 7px;color:#6b7280;font-size:13px;font-weight:750}.tg-flow-add-subtitle{padding:0 12px 10px;color:#9ca3af;font-size:11px;font-weight:600;line-height:1.35}.tg-flow-add-section{padding:8px 12px 5px;color:#9ca3af;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.tg-flow-add-item{min-height:48px}.tg-flow-add-icon{width:28px;height:28px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#fff7ed;color:#9a5a1f;font-size:15px;flex:0 0 auto}.tg-flow-add-text{display:flex;flex-direction:column;gap:2px;min-width:0}.tg-flow-add-name{font-size:13px;font-weight:750;color:#374151}.tg-flow-add-desc{font-size:11px;font-weight:600;color:#9ca3af}.tg-flow-add-item[disabled] .tg-flow-add-icon{background:#f3f4f6;color:#c2c7d0}.tg-flow-add-item[disabled] .tg-flow-add-name,.tg-flow-add-item[disabled] .tg-flow-add-desc{color:#c2c7d0}@media(max-width:760px){.tg-flow-title{max-width:38vw}.tg-flow-top-btn.hide-sm{display:none}.tg-flow-topbar{padding:0 8px}.tg-flow-top-btn{padding:0 10px}}

/* v3.5.101: polished block chooser */
.tg-flow-add-menu{position:absolute!important;z-index:60!important;width:min(280px,calc(100vw - 32px))!important;max-height:calc(100vh - 120px)!important;overflow:auto!important;padding:10px!important;border-radius:20px!important;background:#fff!important;border:1px solid #e5e7eb!important;box-shadow:0 24px 70px rgba(15,23,42,.18)!important;}
.tg-flow-add-title,.tg-flow-add-subtitle,.tg-flow-add-section,.tg-flow-add-desc{display:none!important;}
.tg-flow-add-item{min-height:46px!important;padding:10px 12px!important;border-radius:14px!important;align-items:center!important;gap:11px!important;}
.tg-flow-add-text{display:block!important;}
.tg-flow-add-name{font-size:13px!important;font-weight:760!important;color:#374151!important;}
.tg-flow-add-icon{width:30px!important;height:30px!important;border-radius:11px!important;}
.tg-flow-add-item[disabled]{opacity:.42!important;}

html,body{margin:0!important;padding:0!important}body.asr-flow-fullscreen{padding:0!important;margin:0!important;overflow:hidden!important;background-image:none!important;background:#f1f3f5!important}.admin-shell,.admin-main,.asr-flow-main{max-width:none!important;width:100%!important;margin:0!important;padding:0!important;transform:none!important}.admin-header{display:none!important}.drawer-edge-zone{display:none!important}#drawerBackdrop.drawer-backdrop{z-index:3000!important}#adminDrawer.drawer-panel{z-index:3010!important}.tg-flow-app{z-index:1000}.tg-flow-topbar{position:relative;z-index:1010}.tg-flow-canvas-wrap{z-index:1001}
body.drawer-open .tg-flow-app{pointer-events:none}body.drawer-open #adminDrawer,body.drawer-open #drawerBackdrop{pointer-events:auto}

/* v3.5.43: drawer на всю высоту экрана, без верхнего провала и без кнопок за нижним краем */
.tg-flow-block-drawer{position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;z-index:4200!important;}
.tg-flow-block-drawer .tg-scenario-modal-backdrop{position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;display:flex!important;align-items:stretch!important;justify-content:flex-end!important;padding:0!important;margin:0!important;background:rgba(15,23,42,.22)!important;}
.tg-flow-block-drawer .tg-scenario-modal{width:min(760px,100vw)!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;margin:0!important;border-radius:0!important;display:flex!important;flex-direction:column!important;overflow:hidden!important;transform:translateX(104%)!important;}
.tg-flow-block-drawer.is-open .tg-scenario-modal{transform:translateX(0)!important;}
.tg-flow-block-drawer .tg-scenario-modal-head{flex:0 0 auto!important;padding:18px 24px!important;background:#fff!important;}
.tg-flow-block-drawer .tg-scenario-form{flex:1 1 auto!important;min-height:0!important;max-height:none!important;overflow:auto!important;padding:22px 24px 0!important;display:flex!important;flex-direction:column!important;}
.tg-flow-block-drawer .tg-scenario-message-grid{flex:1 1 auto!important;min-height:0!important;display:block!important;margin-top:18px!important;}
.tg-flow-block-drawer .tg-scenario-form-actions{position:sticky!important;bottom:0!important;left:0!important;right:0!important;margin:auto -24px 0!important;padding:14px 24px!important;background:#fff!important;border-top:1px solid #edf0f2!important;z-index:20!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:12px!important;}
.tg-flow-block-drawer .tg-scenario-modal-subtitle{display:none!important;}
.tg-flow-block-drawer .tg-scenario-add-title{margin-top:4px!important;}
.tg-flow-block-drawer .tg-scenario-media-box{grid-template-columns:minmax(0,.65fr) minmax(0,1fr) minmax(0,1fr)!important;}

/* v3.5.45: drawer footer must stay visible; only the content area scrolls */
.tg-flow-block-drawer .tg-scenario-modal{top:0!important;bottom:0!important;}
.tg-flow-block-drawer .tg-scenario-form{overflow:hidden!important;display:flex!important;flex-direction:column!important;padding-bottom:0!important;}
.tg-flow-block-drawer .tg-scenario-message-grid{flex:1 1 auto!important;min-height:0!important;overflow:auto!important;padding-bottom:20px!important;}
.tg-flow-block-drawer .tg-scenario-form-actions{position:relative!important;flex:0 0 auto!important;bottom:auto!important;margin:0 -24px!important;padding:14px 24px!important;background:#fff!important;border-top:1px solid #edf0f2!important;box-shadow:0 -10px 24px rgba(15,23,42,.06)!important;z-index:30!important;}
.tg-flow-block-drawer .tg-scenario-media-box{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;align-items:end!important;}
@media(max-width:900px){.tg-flow-block-drawer .tg-scenario-media-box{grid-template-columns:1fr!important}.tg-flow-block-drawer .tg-scenario-modal{width:100vw!important}}

/* v3.5.43: rich previews and button-level handles */
.tg-flow-node{width:268px;overflow:visible!important}.tg-flow-node-body{padding:12px 12px 10px}.tg-flow-message-cards{display:flex;flex-direction:column;gap:9px}.tg-flow-preview-card{position:relative;background:#f7f7f8;border-radius:10px;padding:10px 10px 9px;border:1px solid transparent;min-height:42px}.tg-flow-preview-card-label{font-size:9px;font-weight:800;color:#a0a6b1;text-transform:none;margin-bottom:5px}.tg-flow-preview-text{font-size:12px;font-weight:650;color:#454b54;line-height:1.42;white-space:pre-wrap;word-break:break-word;display:-webkit-box;-webkit-line-clamp:7;-webkit-box-orient:vertical;overflow:hidden}.tg-flow-preview-image{display:block;width:100%;max-height:145px;object-fit:cover;border-radius:9px;margin:2px 0 6px;background:#e5e7eb}.tg-flow-preview-media{border:1px dashed #d8dde5;border-radius:9px;padding:9px;color:#6b7280;font-size:11px;font-weight:700;background:#fff}.tg-flow-preview-gallery{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin:4px 0 6px}.tg-flow-preview-gallery img,.tg-flow-preview-gallery span{width:100%;height:48px;border-radius:7px;object-fit:cover;background:#e5e7eb;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:10px;font-weight:800}.tg-flow-preview-gallery-more{background:#f1f5f9!important}.tg-flow-preview-buttons{display:flex;flex-direction:column;gap:6px;margin-top:8px}.tg-flow-preview-button{position:relative;min-height:28px;border:1px solid #e2e6ec;background:#fff;border-radius:9px;padding:6px 18px 6px 9px;text-align:center;color:#6b7280;font-size:11px;font-weight:750}.tg-flow-preview-button-text{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tg-flow-next-row{position:relative;display:flex;align-items:center;justify-content:flex-end;min-height:22px;margin-top:7px;padding-right:4px}.tg-flow-next-row .tg-flow-node-muted{margin-top:0}.tg-flow-node .tg-flow-main-out-handle{right:-21px!important;top:50%!important}.tg-flow-node .tg-flow-in-handle{left:-21px!important;top:50%!important}.tg-flow-node .tg-flow-button-handle,.tg-flow-node .tg-flow-question-handle{right:-22px!important;top:50%!important;width:13px!important;height:13px!important;border-width:2px!important;background:#fff!important;box-shadow:0 0 0 3px #fff!important}.tg-flow-node .tg-flow-button-handle:hover,.tg-flow-node .tg-flow-question-handle:hover,.tg-flow-node .tg-flow-main-out-handle:hover,.tg-flow-node .tg-flow-in-handle:hover{border-color:#737373!important}.tg-flow-node-card.is-empty{border:1px dashed #fecaca;background:#fff7f7;color:#ef4444;font-size:11px}.tg-flow-node-card.is-empty:after{content:'Для продолжения добавьте хотя бы одну карточку текста или файла.';display:block;margin-top:5px;color:#ef4444;font-size:10px;font-weight:700;line-height:1.3}.tg-flow-node.is-start .tg-flow-main-out-handle{right:-21px!important}.tg-flow-root .react-flow__edge-path{stroke-linecap:round;stroke-linejoin:round}.tg-flow-root .react-flow__edge .react-flow__edge-path{stroke:#6b6f76!important;stroke-width:1.9!important}.tg-flow-root .react-flow__edge:hover .react-flow__edge-path{stroke:#555a60!important;stroke-width:2.15!important}


/* v3.5.52: clean React Flow drawer. Do not let the classic scenario modal control footer/layout. */
.tg-flow-block-drawer{position:fixed!important;inset:0!important;z-index:5200!important;pointer-events:none!important;opacity:0!important;transition:opacity .18s ease!important;display:block!important;}
.tg-flow-block-drawer.is-open,.tg-flow-block-drawer.is-loading{pointer-events:auto!important;opacity:1!important;}
.tg-flow-block-drawer:before{content:""!important;position:absolute!important;inset:0!important;background:rgba(15,23,42,.28)!important;opacity:0!important;transition:opacity .18s ease!important;}
.tg-flow-block-drawer.is-open:before,.tg-flow-block-drawer.is-loading:before{opacity:1!important;}
.tg-flow-block-drawer .tg-flow-clean-drawer{position:absolute!important;top:0!important;right:0!important;bottom:0!important;width:min(760px,100vw)!important;height:100vh!important;height:100dvh!important;background:#fff!important;box-shadow:-18px 0 55px rgba(15,23,42,.24)!important;transform:translateX(104%)!important;transition:transform .26s cubic-bezier(.2,.8,.2,1)!important;overflow:hidden!important;z-index:2!important;}
.tg-flow-block-drawer.is-open .tg-flow-clean-drawer{transform:translateX(0)!important;}
.tg-flow-block-drawer .tg-flow-clean-form{height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;min-height:0!important;display:grid!important;grid-template-rows:auto minmax(0,1fr) auto!important;padding:0!important;margin:0!important;overflow:hidden!important;background:#fff!important;}
.tg-flow-block-drawer .tg-flow-clean-head{min-height:72px!important;display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:12px!important;padding:18px 24px!important;background:#fff!important;border-bottom:1px solid #edf0f2!important;box-sizing:border-box!important;}
.tg-flow-block-drawer .tg-flow-clean-title{font-size:18px!important;line-height:1.25!important;font-weight:750!important;color:#1f2937!important;margin:0!important;}
.tg-flow-block-drawer .tg-flow-clean-subtitle{font-size:12px!important;font-weight:600!important;color:#9ca3af!important;margin-top:4px!important;line-height:1.35!important;}
.tg-flow-block-drawer .tg-flow-drawer-close{width:42px!important;height:42px!important;border-radius:16px!important;border:1px solid #e5e7eb!important;background:#fff!important;color:#6b7280!important;font-size:24px!important;line-height:1!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;cursor:pointer!important;flex:0 0 auto!important;}
.tg-flow-block-drawer .tg-flow-drawer-close:hover{background:#f9fafb!important;color:#374151!important;}
.tg-flow-block-drawer .tg-flow-clean-body{min-height:0!important;overflow:auto!important;padding:22px 24px 28px!important;background:#fff!important;box-sizing:border-box!important;}
.tg-flow-block-drawer .tg-flow-clean-footer{min-height:72px!important;height:auto!important;flex:0 0 auto!important;padding:14px 24px!important;background:#fff!important;border-top:1px solid #edf0f2!important;box-shadow:0 -10px 24px rgba(15,23,42,.06)!important;box-sizing:border-box!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;}
.tg-flow-block-drawer .tg-flow-clean-footer .tg-scenario-form-actions{position:static!important;inset:auto!important;top:auto!important;right:auto!important;bottom:auto!important;left:auto!important;margin:0!important;padding:0!important;width:100%!important;height:auto!important;min-height:0!important;max-height:none!important;background:transparent!important;border:0!important;box-shadow:none!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:12px!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-message-grid{display:block!important;grid-template-columns:none!important;margin:18px 0 0!important;min-height:auto!important;overflow:visible!important;padding:0!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-builder{display:block!important;min-width:0!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-preview-phone{display:none!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-field{margin-bottom:14px!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-modal-head,.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-modal-subtitle{display:none!important;}
.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-media-box{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;align-items:end!important;}
.tg-flow-block-drawer .tg-flow-clean-footer .tg-scenario-one-primary,.tg-flow-block-drawer .tg-flow-clean-footer button[type="submit"]{min-height:46px!important;border-radius:16px!important;}
@media(max-width:900px){.tg-flow-block-drawer .tg-flow-clean-drawer{width:100vw!important}.tg-flow-block-drawer .tg-flow-clean-head{padding:14px 16px!important}.tg-flow-block-drawer .tg-flow-clean-body{padding:18px 16px 22px!important}.tg-flow-block-drawer .tg-flow-clean-footer{padding:12px 16px!important}.tg-flow-block-drawer .tg-flow-clean-body .tg-scenario-media-box{grid-template-columns:1fr!important}}


/* v3.5.53: isolated block panel, no classic scenario modal inside React Flow drawer */
.tg-flow-block-drawer .tg-flow-panel{position:absolute!important;top:0!important;right:0!important;bottom:0!important;width:min(760px,100vw)!important;background:#fff!important;box-shadow:-18px 0 55px rgba(15,23,42,.24)!important;transform:translateX(104%)!important;transition:transform .26s cubic-bezier(.2,.8,.2,1)!important;z-index:3!important;display:grid!important;grid-template-rows:auto minmax(0,1fr) auto!important;overflow:hidden!important;}
.tg-flow-block-drawer.is-open .tg-flow-panel{transform:translateX(0)!important;}
.tg-flow-panel-head{padding:18px 24px;border-bottom:1px solid #edf0f2;background:#fff;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;}
.tg-flow-panel-title{font-size:18px;font-weight:760;color:#172033;line-height:1.25;}
.tg-flow-panel-subtitle{font-size:12px;font-weight:650;color:#9ca3af;margin-top:4px;}
.tg-flow-panel-close{width:42px;height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:16px;color:#6b7280;font-size:24px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
.tg-flow-panel-form{display:contents!important;}
.tg-flow-panel-body{min-height:0;overflow:auto;padding:20px 24px 26px;background:#fff;}
.tg-flow-panel-footer{border-top:1px solid #edf0f2;background:#fff;box-shadow:0 -10px 24px rgba(15,23,42,.06);padding:14px 24px;display:flex;justify-content:flex-end;gap:12px;align-items:center;}
.tg-flow-panel-field{margin-bottom:16px;}
.tg-flow-panel-label{display:block;font-size:11px;font-weight:760;color:#9ca3af;margin-bottom:7px;}
.tg-flow-panel-input,.tg-flow-panel-select,.tg-flow-panel-url{width:100%;border:1px solid #d8dce3;border-radius:14px;background:#fff;padding:12px 14px;font-size:13px;font-weight:650;color:#374151;outline:none;box-sizing:border-box;}
.tg-flow-panel-input:focus,.tg-flow-panel-select:focus,.tg-flow-panel-url:focus{border-color:#FFA048;box-shadow:0 0 0 3px rgba(255,160,72,.14);}
.tg-flow-card-toolbar{display:flex;flex-wrap:wrap;gap:10px;margin:8px 0 18px;}
.tg-flow-card-add{display:inline-flex;align-items:center;gap:8px;border:1px solid #f1e1d2;background:#fff;color:#e6812e;border-radius:14px;padding:11px 14px;font-size:12px;font-weight:750;cursor:pointer;}
.tg-flow-card-add.is-soon{border-color:#e5e7eb;color:#64748b;background:#fff;}
.tg-flow-card{border:1px solid #eef0f4;border-radius:22px;background:#f7f7f8;padding:16px;margin-bottom:14px;}
.tg-flow-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;}
.tg-flow-card-title{font-size:16px;font-weight:760;color:#1f2937;}
.tg-flow-card-sub{font-size:11px;font-weight:650;color:#9ca3af;margin-top:3px;}
.tg-flow-card-remove{width:38px;height:38px;border-radius:14px;border:1px solid #fee2e2;background:#fff;color:#dc2626;font-size:18px;font-weight:800;cursor:pointer;}
.tg-flow-editor{border:1px solid #d8dce3;border-radius:16px;background:#fff;overflow:hidden;}
.tg-flow-editor-toolbar{display:flex;gap:6px;align-items:center;padding:10px 12px;border-bottom:1px solid #edf0f2;color:#4b5563;}
.tg-flow-editor-toolbar button{border:0;background:transparent;color:#4b5563;font-weight:800;font-size:15px;padding:6px 8px;border-radius:8px;cursor:pointer;}
.tg-flow-editor-toolbar button:hover{background:#f3f4f6;}
.tg-flow-editor-area{min-height:150px;padding:16px 18px;font-size:16px;line-height:1.5;color:#1f2937;outline:none;background:#fff;}
.tg-flow-card-media-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:end;}
.tg-flow-upload-box{border:1px solid #d8dce3;background:#fff;border-radius:16px;padding:12px;}
.tg-flow-upload-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #fed7aa;background:#fff7ed;color:#9a5a1f;border-radius:12px;padding:10px 12px;font-size:12px;font-weight:800;cursor:pointer;}
.tg-flow-upload-name{display:block;margin-top:8px;color:#9ca3af;font-size:11px;font-weight:650;word-break:break-word;}
.tg-flow-media-preview{margin-top:12px;border:1px solid #edf0f2;border-radius:16px;background:#fff;padding:10px;}
.tg-flow-media-preview img{display:block;max-width:100%;max-height:220px;border-radius:12px;object-fit:cover;}
.tg-flow-buttons{display:flex;flex-direction:column;gap:10px;margin-top:14px;}
.tg-flow-button-row{display:grid;grid-template-columns:160px minmax(0,1fr) minmax(0,1fr) 42px;gap:10px;align-items:center;}
.tg-flow-button-remove{height:42px;border:1px solid #fee2e2;background:#fff;color:#dc2626;border-radius:14px;font-size:17px;font-weight:800;cursor:pointer;}
.tg-flow-add-button{margin-top:12px;border:1px solid #e5e7eb;background:#fff;color:#374151;border-radius:16px;padding:11px 14px;font-size:12px;font-weight:760;cursor:pointer;}
.tg-flow-panel-alert{display:none;margin:0 0 14px;padding:12px 14px;border-radius:14px;background:#fef2f2;color:#b91c1c;font-size:12px;font-weight:700;}.tg-flow-panel-alert.is-open{display:block;}
.tg-flow-panel-secondary{border:1px solid #e5e7eb;background:#f3f4f6;color:#374151;border-radius:16px;padding:12px 16px;font-size:12px;font-weight:760;cursor:pointer;}
.tg-flow-panel-primary{border:0;background:#FFA048;color:#fff;border-radius:16px;padding:13px 18px;font-size:12px;font-weight:800;cursor:pointer;}.tg-flow-panel-primary:disabled{opacity:.55;cursor:wait;}
@media(max-width:900px){.tg-flow-block-drawer .tg-flow-panel{width:100vw!important}.tg-flow-panel-head{padding:14px 16px}.tg-flow-panel-body{padding:16px}.tg-flow-panel-footer{padding:12px 16px}.tg-flow-card-media-grid,.tg-flow-button-row{grid-template-columns:1fr}.tg-flow-button-remove{width:42px}}


/* v3.5.54: normalize React Flow drawer typography after clean panel split */
/* v3.5.55: React Flow is the single scenario editor; classic scenario page is disabled */
.tg-flow-block-drawer,
.tg-flow-block-drawer *{
  font-family:inherit!important;
  letter-spacing:normal!important;
}
.tg-flow-block-drawer .tg-flow-panel{
  width:min(760px,100vw)!important;
  font-size:13px!important;
  color:#374151!important;
}
.tg-flow-block-drawer .tg-flow-panel-head{
  padding:16px 24px!important;
}
.tg-flow-block-drawer .tg-flow-panel-title{
  font-size:16px!important;
  line-height:1.28!important;
  font-weight:650!important;
  color:#172033!important;
}
.tg-flow-block-drawer .tg-flow-panel-subtitle{
  font-size:11px!important;
  line-height:1.35!important;
  font-weight:500!important;
  color:#9ca3af!important;
}
.tg-flow-block-drawer .tg-flow-panel-body{
  padding:18px 24px 22px!important;
}
.tg-flow-block-drawer .tg-flow-panel-footer{
  min-height:68px!important;
  padding:12px 24px!important;
}
.tg-flow-block-drawer .tg-flow-panel-label{
  font-size:11px!important;
  line-height:1.3!important;
  font-weight:600!important;
  color:#9ca3af!important;
}
.tg-flow-block-drawer .tg-flow-panel-input,
.tg-flow-block-drawer .tg-flow-panel-select,
.tg-flow-block-drawer .tg-flow-panel-url{
  font-size:13px!important;
  line-height:1.35!important;
  font-weight:500!important;
  padding:11px 13px!important;
  border-radius:12px!important;
}
.tg-flow-block-drawer .tg-flow-card-toolbar{
  gap:8px!important;
  margin:8px 0 14px!important;
}
.tg-flow-block-drawer .tg-flow-card-add{
  font-size:12px!important;
  line-height:1.2!important;
  font-weight:600!important;
  padding:10px 12px!important;
  border-radius:12px!important;
}
.tg-flow-block-drawer .tg-flow-card{
  padding:14px!important;
  margin-bottom:12px!important;
  border-radius:18px!important;
}
.tg-flow-block-drawer .tg-flow-card-title{
  font-size:15px!important;
  line-height:1.3!important;
  font-weight:650!important;
}
.tg-flow-block-drawer .tg-flow-card-sub,
.tg-flow-block-drawer .tg-flow-upload-name{
  font-size:11px!important;
  line-height:1.35!important;
  font-weight:500!important;
}
.tg-flow-block-drawer .tg-flow-card-remove,
.tg-flow-block-drawer .tg-flow-button-remove{
  font-size:16px!important;
  font-weight:650!important;
}
.tg-flow-block-drawer .tg-flow-editor-toolbar button{
  font-size:13px!important;
  font-weight:650!important;
  padding:5px 7px!important;
}
.tg-flow-block-drawer .tg-flow-editor-area{
  min-height:130px!important;
  font-size:14px!important;
  line-height:1.48!important;
  font-weight:400!important;
  padding:14px 16px!important;
}
.tg-flow-block-drawer .tg-flow-upload-btn,
.tg-flow-block-drawer .tg-flow-add-button,
.tg-flow-block-drawer .tg-flow-panel-secondary,
.tg-flow-block-drawer .tg-flow-panel-primary{
  font-size:12px!important;
  line-height:1.2!important;
  font-weight:650!important;
}
.tg-flow-block-drawer .tg-flow-panel-primary,
.tg-flow-block-drawer .tg-flow-panel-secondary{
  min-height:42px!important;
  padding:11px 16px!important;
  border-radius:14px!important;
}
.tg-flow-block-drawer .tg-flow-button-row{
  grid-template-columns:135px minmax(0,1fr) minmax(0,1fr) 38px!important;
  gap:8px!important;
}
.tg-flow-block-drawer .tg-flow-media-preview{
  border-radius:14px!important;
}
.tg-flow-block-drawer .tg-flow-media-preview img{
  max-height:170px!important;
}
@media(max-width:900px){
  .tg-flow-block-drawer .tg-flow-panel-title{font-size:15px!important;}
  .tg-flow-block-drawer .tg-flow-panel-body{padding:16px!important;}
  .tg-flow-block-drawer .tg-flow-button-row{grid-template-columns:1fr!important;}
}


/* v3.5.60: CSRF token fix for local React Flow AJAX actions */
.tg-flow-add-menu{width:330px!important;text-align:left!important;}
.tg-flow-add-title,.tg-flow-add-subtitle,.tg-flow-add-section{text-align:left!important;}
.tg-flow-add-item{justify-content:flex-start!important;align-items:flex-start!important;text-align:left!important;}
.tg-flow-add-icon{margin-top:1px!important;}
.tg-flow-add-text{align-items:flex-start!important;text-align:left!important;flex:1 1 auto!important;}
.tg-flow-add-name,.tg-flow-add-desc{display:block!important;width:100%!important;text-align:left!important;}
.tg-flow-preview-button{position:relative;}


/* v3.5.65: block message editor aligned with broadcasts editor */
.tg-flow-block-drawer .tg-card-type-btn{display:inline-flex;align-items:center;gap:9px;border:1px solid #f1e1d2;color:#FFA048;background:#fff;border-radius:8px;padding:12px 16px;font-size:12px;font-weight:600;letter-spacing:.03em;cursor:pointer;}
.tg-flow-block-drawer .tg-card-type-btn:hover{background:#fff7ed;border-color:#fed7aa;}
.tg-flow-block-drawer .tg-message-card{background:#f7f7f8;border:1px solid #eef0f4;border-radius:18px;padding:20px;margin-top:16px;}
.tg-flow-block-drawer .tg-flow-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
.tg-flow-block-drawer .tg-flow-card-head h5{margin:0;font-size:15px;line-height:1.3;font-weight:650;color:#1f2937;}
.tg-flow-block-drawer .tg-flow-card-actions{display:flex;align-items:center;gap:8px;}
.tg-flow-block-drawer .tg-flow-card-actions button{width:30px;height:30px;border:0;background:transparent;color:#9ca3af;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
.tg-flow-block-drawer .tg-flow-card-actions button:hover{background:#eef0f4;color:#6b7280;}
.tg-flow-block-drawer .tg-card-toolbar{display:flex;gap:6px;flex-wrap:wrap;border:1px solid #d8dce3;border-bottom:0;border-radius:10px 10px 0 0;background:#fff;padding:8px;margin-top:12px;}
.tg-flow-block-drawer .tg-card-toolbar button{min-width:38px;height:34px;border:0;background:transparent;border-radius:9px;font-weight:600;color:#6b7280;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;}
.tg-flow-block-drawer .tg-card-toolbar button:hover{background:#f3f4f6;color:#374151;}
.tg-flow-block-drawer .tg-ui-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;}
.tg-flow-block-drawer .tg-card-toolbar button .tg-ui-icon,.tg-flow-block-drawer .tg-mini-btn .tg-ui-icon{width:22px!important;height:22px!important;}
.tg-flow-block-drawer .tg-toolbar-sep{width:1px;background:#e5e7eb;margin:4px 6px;}
.tg-flow-block-drawer .tg-editor-wrap{border:1px solid #d8dce3;border-radius:0 0 10px 10px;background:#fff;}
.tg-flow-block-drawer .tg-card-editor{min-height:150px;padding:16px;font-size:14px;font-weight:400;outline:none;white-space:pre-wrap;line-height:1.55;color:#1f2937;background:#fff;}
.tg-flow-block-drawer .tg-card-editor:empty:before{content:attr(data-placeholder);color:#9ca3af;}
.tg-flow-block-drawer .tg-card-bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border-top:1px solid #eef0f4;}
.tg-flow-block-drawer .relative{position:relative;}
.tg-flow-block-drawer .tg-mini-btn{border:0;background:transparent;color:#6b7280;font-weight:600;padding:5px 7px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
.tg-flow-block-drawer .tg-mini-btn:hover{background:#f3f4f6;}
.tg-flow-block-drawer .tg-char-count{font-size:12px;font-weight:600;color:#9ca3af;}
.tg-flow-block-drawer .tg-macro-menu,.tg-flow-block-drawer .tg-emoji-menu{position:absolute;left:0;bottom:36px;z-index:5300;width:310px;max-height:320px;overflow:auto;background:#fff;border:1px solid #eef0f4;border-radius:12px;box-shadow:0 18px 35px rgba(15,23,42,.15);padding:8px;display:none;}
.tg-flow-block-drawer .tg-macro-menu.is-open,.tg-flow-block-drawer .tg-emoji-menu.is-open{display:block;}
.tg-flow-block-drawer .tg-macro-search{width:100%;border:0;background:#f7f7f8;border-radius:10px;padding:11px 12px;font-weight:600;margin-bottom:8px;box-sizing:border-box;}
.tg-flow-block-drawer .tg-macro-item{display:grid!important;grid-template-columns:34px 1fr auto;align-items:center;gap:8px;width:100%;padding:10px;border:0;background:#fff;border-radius:10px;text-align:left;color:#374151;cursor:pointer;}
.tg-flow-block-drawer .tg-macro-item:hover{background:#f3f4f6;}
.tg-flow-block-drawer .tg-macro-token{color:#9ca3af;font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis;}
.tg-flow-block-drawer .tg-macro-group,.tg-flow-block-drawer .tg-emoji-title{padding:8px 9px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;}
.tg-flow-block-drawer .tg-emoji-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:4px;}
.tg-flow-block-drawer .tg-emoji-grid button{font-size:18px;border:0;background:#fff;border-radius:8px;padding:7px;cursor:pointer;}
.tg-flow-block-drawer .tg-emoji-grid button:hover{background:#f3f4f6;}
.tg-flow-block-drawer .tg-flow-media-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:end;margin:12px 0 10px;}
.tg-flow-block-drawer .tg-form-label{display:block;font-size:11px;font-weight:600;color:#9ca3af;margin-bottom:8px;letter-spacing:.04em;}
.tg-flow-block-drawer .tg-form-field{width:100%;border:1px solid #d8dce3;border-radius:8px;padding:13px 14px;font-size:13px;font-weight:500;background:#fff;color:#374151;box-sizing:border-box;}
.tg-flow-block-drawer .tg-form-field:focus{outline:none;border-color:#FFA048;box-shadow:0 0 0 3px rgba(255,160,72,.14);}
.tg-flow-block-drawer .tg-flow-upload-name{display:block;margin-top:8px;color:#9ca3af;font-size:11px;font-weight:600;word-break:break-word;}
.tg-flow-block-drawer .tg-flow-media-preview{margin:12px 0 2px;border:1px dashed #cbd5e1;border-radius:14px;background:#fff;padding:10px;font-size:12px;font-weight:600;color:#64748b;word-break:break-word;}
.tg-flow-block-drawer .tg-flow-media-preview img{display:block;max-width:100%;max-height:220px;border-radius:12px;object-fit:cover;}
.tg-flow-block-drawer .tg-flow-card-note{margin:10px 0 2px;padding:10px 12px;border:1px solid #eef0f4;background:#fff;border-radius:14px;color:#64748b;font-size:12px;line-height:1.45}.tg-flow-block-drawer .tg-flow-card-note strong{display:block;color:#374151;font-size:12px;margin-bottom:3px}.tg-flow-block-drawer .tg-flow-card-note span{display:block}
.tg-flow-block-drawer .tg-gallery-url-row{display:grid;grid-template-columns:minmax(0,1fr) 48px;gap:8px;align-items:center}.tg-flow-block-drawer .tg-gallery-url-row button{height:48px;border:1px solid #fed7aa;background:#fff7ed;color:#e6812e;border-radius:12px;font-size:22px;font-weight:600;cursor:pointer}.tg-flow-block-drawer .tg-gallery-list{display:flex;flex-direction:column;gap:8px;margin:12px 0}.tg-flow-block-drawer .tg-gallery-item{display:grid;grid-template-columns:48px minmax(0,1fr) 38px;align-items:center;gap:10px;border:1px solid #eef0f4;background:#fff;border-radius:12px;padding:8px}.tg-flow-block-drawer .tg-gallery-thumb{width:48px;height:38px;border-radius:9px;background:#f1f5f9;color:#94a3b8;display:flex;align-items:center;justify-content:center;font-weight:700;overflow:hidden}.tg-flow-block-drawer .tg-gallery-thumb img{width:100%;height:100%;object-fit:cover;display:block}.tg-flow-block-drawer .tg-gallery-name{min-width:0;color:#4b5563;font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-flow-block-drawer .tg-gallery-name span{color:#9ca3af;font-weight:500}.tg-flow-block-drawer .tg-gallery-item button{border:0;background:transparent;color:#9ca3af;display:flex;align-items:center;justify-content:center;cursor:pointer}.tg-flow-block-drawer .tg-gallery-empty{border:1px dashed #d8dde5;border-radius:12px;background:#fff;padding:12px;color:#9ca3af;font-size:12px;font-weight:600}.tg-flow-block-drawer .tg-gallery-options,.tg-flow-block-drawer .tg-card-options{display:flex;flex-wrap:wrap;gap:16px;margin:8px 0 4px}.tg-flow-block-drawer .tg-gallery-options label,.tg-flow-block-drawer .tg-card-options label{display:inline-flex;align-items:center;gap:8px;color:#4b5563;font-size:12px;font-weight:600}.tg-flow-block-drawer .tg-gallery-options input,.tg-flow-block-drawer .tg-card-options input{width:16px;height:16px;accent-color:#FFA048}
.tg-flow-block-drawer .tg-card-options-after{margin:10px 0 0}.tg-flow-block-drawer .tg-protect-option{position:relative}.tg-flow-block-drawer .tg-protect-help{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:#eef0f4;color:#8b95a5;font-size:11px;font-weight:800;line-height:1;cursor:help}.tg-flow-block-drawer .tg-protect-help:hover::after,.tg-flow-block-drawer .tg-protect-help:focus::after{content:attr(data-tooltip);position:absolute;left:calc(100% + 8px);top:50%;transform:translateY(-50%);width:250px;max-width:42vw;background:#333;color:#fff;border-radius:8px;padding:8px 10px;font-size:12px;line-height:1.35;font-weight:600;z-index:50;box-shadow:0 8px 22px rgba(15,23,42,.18)}
.tg-flow-block-drawer .tg-message-buttons{display:flex;flex-direction:column;gap:8px;margin-top:16px;}
.tg-flow-block-drawer .tg-message-button-row{display:flex;gap:8px;align-items:center;}
.tg-flow-block-drawer .tg-message-button{min-height:38px;flex:1;border:1px solid #dbeafe;border-radius:8px;background:#fff;color:#2563eb;font-size:13px;font-weight:600;position:relative;cursor:pointer;}
.tg-flow-block-drawer .tg-message-button:hover{background:#f8fbff;}
.tg-flow-block-drawer .tg-message-button-add{width:38px;height:38px;border:1px solid #dbeafe;border-radius:8px;background:#fff;color:#2563eb;font-size:20px;line-height:1;cursor:pointer;}
.tg-flow-block-drawer .tg-flow-button-add-line{margin-top:12px;display:flex;justify-content:flex-end;}
.tg-flow-block-drawer .tg-btn-ghost{color:#FFA048;border:0;background:transparent;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:600;letter-spacing:.03em;cursor:pointer;}
.tg-flow-block-drawer .tg-btn-ghost:hover{background:#fff7ed;}
.tg-button-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:8000;display:none;align-items:center;justify-content:center;padding:18px;}
.tg-button-modal-backdrop.is-open{display:flex;}
.tg-button-modal{width:560px;max-width:100%;background:#fff;border-radius:18px;box-shadow:0 25px 60px rgba(15,23,42,.35);overflow:hidden;}
.tg-button-modal-head{padding:22px 26px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.tg-button-modal-head h4{margin:0;font-size:16px;font-weight:650;color:#1f2937;}
.tg-button-modal-body{padding:0 26px 22px;}
.tg-button-modal-foot{padding:18px 26px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:12px;align-items:center;}
.tg-button-select{width:100%;border:1px solid #d8dce3;border-radius:8px;padding:13px 14px;background:#fff;font-size:13px;font-weight:500;color:#374151;box-sizing:border-box;}
.tg-button-danger{margin-right:auto;border:0;background:transparent;color:#ef4444;font-weight:600;padding:12px 0;cursor:pointer;}
.tg-button-save{border:0;background:#FFA048;color:#fff;border-radius:8px;padding:12px 24px;font-weight:600;cursor:pointer;}
.tg-button-muted{border:0;background:#f4f4f5;color:#52525b;border-radius:8px;padding:12px 18px;font-weight:600;cursor:pointer;}
.tg-flow-block-drawer .tg-card-editor code{background:#eef0f4;border-radius:5px;padding:1px 5px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.92em;}
.tg-flow-block-drawer .tg-card-editor pre{background:#eef0f4;border-radius:10px;padding:10px 12px;margin:8px 0;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}
.tg-flow-block-drawer .tg-card-editor blockquote{border-left:3px solid #FFA048;margin:8px 0;padding:7px 10px;background:#fff7ed;border-radius:8px;color:#4b5563;}
.tg-flow-block-drawer .tg-card-editor a{color:#2563eb;text-decoration:underline;}
.tg-flow-block-drawer .tg-card-editor tg-spoiler{background:#6b7280;color:transparent;border-radius:4px;padding:0 3px;text-shadow:0 0 7px rgba(255,255,255,.85);}
.tg-flow-block-drawer .tg-flow-panel-alert.is-open{display:block;}
.tg-flow-block-drawer .tg-message-card.is-invalid{border-color:#fecaca;background:#fff7f7;}
@media(max-width:900px){.tg-flow-block-drawer .tg-flow-media-grid{grid-template-columns:1fr}.tg-flow-block-drawer .tg-message-card{padding:16px}.tg-flow-block-drawer .tg-macro-menu,.tg-flow-block-drawer .tg-emoji-menu{width:min(310px,calc(100vw - 34px));}.tg-button-modal-foot{flex-wrap:wrap}.tg-button-danger{width:100%;text-align:left}}



/* v3.5.66: polish message block editor fields and tools */
.tg-flow-block-drawer .tg-flow-panel-footer{justify-content:flex-end!important;}
.tg-flow-block-drawer .tg-flow-card-toolbar{align-items:center!important;}
.tg-flow-block-drawer .tg-card-toolbar button span{font-size:12px;font-weight:600;color:inherit;line-height:1;}
.tg-flow-block-drawer .tg-editor-tools{position:relative;display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0;}
.tg-flow-block-drawer .tg-mini-btn{gap:6px;min-height:32px;padding:6px 9px!important;font-size:12px!important;font-weight:600!important;line-height:1.15!important;}
.tg-flow-block-drawer .tg-mini-btn span{display:inline-block;color:inherit;}
.tg-flow-block-drawer .tg-flow-media-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;align-items:start!important;gap:14px!important;margin:14px 0 12px!important;}
.tg-flow-block-drawer .tg-media-field{display:flex;flex-direction:column;min-width:0;}
.tg-flow-block-drawer .tg-form-label{height:18px;display:flex!important;align-items:center;margin-bottom:8px!important;}
.tg-flow-block-drawer .tg-flow-file-control{height:48px;border:1px solid #d8dce3;border-radius:12px;background:#fff;display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:10px;padding:6px 12px;box-sizing:border-box;position:relative;overflow:hidden;}
.tg-flow-block-drawer .tg-flow-file-control input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.tg-flow-block-drawer .tg-flow-file-button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #d8dce3;border-radius:9px;background:#f8fafc;color:#374151;font-size:12px;font-weight:600;padding:0 12px;white-space:nowrap;}
.tg-flow-block-drawer .tg-flow-file-control .tg-flow-upload-name{margin:0!important;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280!important;font-size:12px!important;font-weight:500!important;}
.tg-flow-block-drawer .tg-media-url-field{height:48px!important;border-radius:12px!important;}
.tg-flow-block-drawer .tg-card-bottom{align-items:center!important;min-height:48px;}
.tg-flow-block-drawer .tg-macro-menu,.tg-flow-block-drawer .tg-emoji-menu{bottom:42px!important;}
@media(max-width:900px){
  .tg-flow-block-drawer .tg-flow-media-grid{grid-template-columns:1fr!important;}
  .tg-flow-block-drawer .tg-mini-btn span{display:none;}
}

/* v3.5.67: scenario message editor date link modal */
.tg-date-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:8200;display:none;align-items:center;justify-content:center;padding:18px;}
.tg-date-modal-backdrop.is-open{display:flex;}
.tg-date-modal{width:460px;max-width:100%;background:#fff;border-radius:18px;box-shadow:0 25px 60px rgba(15,23,42,.35);overflow:hidden;}
.tg-date-modal-head{padding:22px 26px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.tg-date-modal-head h4{margin:0;font-size:16px;font-weight:650;color:#1f2937;}
.tg-date-modal-close{width:40px;height:40px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;color:#6b7280;font-size:22px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
.tg-date-modal-close:hover{background:#fff7ed;border-color:#fed7aa;color:#c96f2b;}
.tg-date-modal-body{padding:0 26px 22px;display:grid;gap:14px;}
.tg-date-modal-note{margin:0;color:#6b7280;font-size:13px;line-height:1.55;font-weight:600;}
.tg-date-modal-foot{padding:18px 26px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:12px;align-items:center;}
.tg-flow-block-drawer .tg-card-editor a[href^="tg://msg?timestamp="]{display:inline-block;border-bottom:1px dashed #FFA048;color:#9a5a17;text-decoration:none!important;}


/* v3.5.70: deeplink under block and compact block menus */
.tg-flow-node-deeplink{border-top:1px solid #e5e7eb;background:#fbfbfc;border-radius:0 0 14px 14px;padding:8px 12px;font-size:11px;font-weight:650;color:#9ca3af;line-height:1.25;cursor:pointer;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-flow-node-deeplink:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-node-deeplink span{display:block;color:#4f8dce;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-flow-node-menu-wrap{position:relative;display:inline-flex}.tg-flow-node-more{width:24px;height:24px;border:0;background:transparent;border-radius:8px;color:#9ca3af;display:inline-flex;align-items:center;justify-content:center;font-size:17px;font-weight:900;line-height:1;cursor:pointer}.tg-flow-node-more:hover{background:#f3f4f6;color:#374151}.tg-flow-node-menu{position:absolute;right:0;top:29px;z-index:50;width:222px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 18px 44px rgba(15,23,42,.16);padding:6px;display:none}.tg-flow-node-menu.is-open{display:block}.tg-flow-node-menu button{width:100%;border:0;background:#fff;border-radius:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;text-align:left;font-size:13px;font-weight:650;color:#4b5563;cursor:pointer}.tg-flow-node-menu button:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-node-menu button.is-danger:hover{background:#fef2f2;color:#dc2626}.tg-flow-node-menu button[disabled]{cursor:not-allowed;background:#fff!important;color:#c2c7d0!important}.tg-flow-node-menu .tg-flow-menu-ico{width:18px;text-align:center;color:inherit}.tg-flow-node.is-start .tg-flow-node-more{color:rgba(255,255,255,.84)}.tg-flow-node.is-start .tg-flow-node-more:hover{background:rgba(255,255,255,.18);color:#fff}
.tg-flow-panel-menu-wrap{position:relative;display:inline-flex;align-items:center}.tg-flow-panel-more{width:44px;height:44px;border:1px solid #edf0f2;background:#fff;border-radius:16px;color:#6b7280;font-size:22px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.tg-flow-panel-more:hover{background:#fff7ed;border-color:#fed7aa;color:#9a5a1f}.tg-flow-panel-dropdown{position:absolute;right:0;top:52px;z-index:80;width:250px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,.18);padding:7px;display:none}.tg-flow-panel-dropdown.is-open{display:block}.tg-flow-panel-dropdown button{width:100%;height:auto;border:0;background:#fff;border-radius:13px;padding:12px 13px;display:flex;align-items:center;gap:11px;text-align:left;font-size:14px;font-weight:650;color:#4b5563;cursor:pointer}.tg-flow-panel-dropdown button:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-panel-dropdown button[disabled]{cursor:not-allowed;background:#fff;color:#c2c7d0}.tg-flow-panel-dropdown button.is-danger:hover{background:#fef2f2;color:#dc2626}.tg-flow-panel-actions{display:flex;align-items:center;gap:10px}.tg-flow-panel-menu-note{display:block;font-size:11px;color:#a8afb9;font-weight:600;margin-top:2px}.tg-flow-panel-menu-main{display:block}.tg-flow-panel-dropdown-ico{width:20px;text-align:center;color:inherit}

.react-flow__edge.is-selected .react-flow__edge-path,.react-flow__edge.selected .react-flow__edge-path{stroke:#2f80ed!important;stroke-width:3!important}.tg-flow-edge-delete-hint{position:absolute;right:18px;bottom:54px;z-index:5;background:#fff;border:1px solid #dbeafe;color:#2563eb;border-radius:14px;padding:8px 12px;font-size:12px;font-weight:700;box-shadow:0 10px 24px rgba(15,23,42,.08)}

/* v3.5.101: polished block chooser */
.tg-flow-add-menu{position:absolute!important;z-index:60!important;width:min(360px,calc(100vw - 32px))!important;max-height:calc(100vh - 120px)!important;overflow:auto!important;padding:14px!important;border-radius:28px!important;background:rgba(255,255,255,.98)!important;border:1px solid #eceff3!important;box-shadow:0 26px 70px rgba(15,23,42,.18)!important;backdrop-filter:blur(6px)!important;}
.tg-flow-add-menu::-webkit-scrollbar{width:8px}.tg-flow-add-menu::-webkit-scrollbar-thumb{background:#d7dce3;border-radius:999px}.tg-flow-add-menu::-webkit-scrollbar-track{background:transparent}
.tg-flow-add-item{width:100%!important;min-height:64px!important;padding:12px 14px!important;border-radius:18px!important;display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:14px!important;border:1px solid transparent!important;background:transparent!important;box-shadow:none!important;transition:background .15s ease,border-color .15s ease,transform .15s ease!important;}
.tg-flow-add-item:hover{background:#faf6ef!important;border-color:#f6d5ac!important;transform:translateY(-1px)!important;color:#1f2937!important;}
.tg-flow-add-item[disabled]{opacity:1!important;background:transparent!important;color:#b9c0ca!important;cursor:not-allowed!important;}
.tg-flow-add-item[disabled]:hover{background:#fbfbfc!important;border-color:#f0f2f5!important;transform:none!important;}
.tg-flow-add-icon{width:44px!important;height:44px!important;border-radius:16px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:0 0 44px!important;font-size:22px!important;line-height:1!important;font-weight:700!important;box-shadow:inset 0 0 0 1px rgba(255,255,255,.34)!important;}
.tg-flow-add-glyph{display:block!important;line-height:1!important;transform:translateY(-.5px)!important}
.tg-flow-add-text{display:flex!important;align-items:center!important;min-width:0!important;flex:1 1 auto!important;}
.tg-flow-add-name{display:block!important;width:100%!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;font-size:17px!important;line-height:1.2!important;font-weight:700!important;letter-spacing:-.01em!important;color:#2b3440!important;}
.tg-flow-add-item[disabled] .tg-flow-add-name{color:#b6bec9!important;}
.tg-flow-add-icon--message{background:#fff3e3!important;color:#c6761d!important;}
.tg-flow-add-icon--question{background:#f3f4f6!important;color:#c6cbd3!important;}
.tg-flow-add-icon--condition{background:#fff1f1!important;color:#ef5b5b!important;}
.tg-flow-add-icon--delay{background:#eef5ff!important;color:#4f86ff!important;}
.tg-flow-add-icon--tag{background:#ebfdf0!important;color:#27b861!important;}
.tg-flow-add-icon--webhook{background:#fff6e5!important;color:#efab2c!important;}
.tg-flow-add-item[disabled] .tg-flow-add-icon{filter:saturate(.25)!important;opacity:.85!important;}
@media(max-width:760px){.tg-flow-add-menu{width:min(340px,calc(100vw - 22px))!important;padding:12px!important;border-radius:24px!important}.tg-flow-add-item{min-height:58px!important;padding:11px 12px!important}.tg-flow-add-icon{width:40px!important;height:40px!important;flex-basis:40px!important;border-radius:14px!important;font-size:20px!important}.tg-flow-add-name{font-size:16px!important;}}


/* v3.5.102: block chooser order and new block types */
.tg-flow-add-icon--actions{background:#fff6e5!important;color:#e69a14!important;}
.tg-flow-add-icon--schedule{background:#f3f4f6!important;color:#6b7280!important;}
.tg-flow-add-icon--random{background:#f5f0ff!important;color:#8b5cf6!important;}
.tg-flow-add-icon--formula{background:#eefdf6!important;color:#16a06a!important;font-family:Georgia,serif!important;font-style:italic!important;}
.tg-flow-add-item--random .tg-flow-add-name,
.tg-flow-add-item--schedule .tg-flow-add-name,
.tg-flow-add-item--formula .tg-flow-add-name,
.tg-flow-add-item--actions .tg-flow-add-name{white-space:nowrap!important;}


/* v3.5.103: keep add-block menu fully visible */
.tg-flow-add-menu{max-height:min(520px,calc(100vh - 132px))!important;overflow:auto!important;}
.tg-flow-add-item{min-height:56px!important;padding:10px 14px!important;}
.tg-flow-add-icon{width:40px!important;height:40px!important;flex-basis:40px!important;border-radius:15px!important;font-size:20px!important;}
.tg-flow-add-name{font-size:16px!important;}
.tg-flow-node.is-delay{border-color:#dbeafe;min-width:300px}
.tg-flow-node.is-delay .tg-flow-node-head{background:#eaf4ff;border-bottom-color:#dbeafe}
.tg-flow-node.is-delay .tg-flow-node-title{color:#2b74c7}
.tg-flow-node.is-delay.is-missing-next{border-color:#ff6b73;box-shadow:0 0 0 1px #ff6b73,0 14px 28px rgba(255,107,115,.14)}
.tg-flow-node.is-delay.is-missing-next .tg-flow-main-out-handle{border-color:#ff6b73!important;box-shadow:0 0 0 3px #fff,0 0 0 5px rgba(255,107,115,.24)!important}
.tg-flow-node-card.tg-flow-delay-preview{background:#fff;color:#374151;border:0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;min-height:74px;padding:10px 10px 2px}
.tg-flow-delay-preview-col{min-width:0}.tg-flow-delay-preview-col.is-right{text-align:right}.tg-flow-delay-preview-label{display:block;color:#a3aab5;font-size:10px;font-weight:800;line-height:1.2;margin-bottom:5px}.tg-flow-delay-preview-main{display:block;font-size:12px;font-weight:760;color:#374151;line-height:1.25;white-space:normal}.tg-flow-delay-preview-days{grid-column:1 / -1;text-align:right;font-size:12px;font-weight:650;color:#4b5563;line-height:1.25;margin-top:2px}

/* v3.5.115: scenario gear menu and command/settings modals */
.tg-flow-gear-wrap{position:relative;display:inline-flex;align-items:center}.tg-flow-gear-btn{width:38px;height:38px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;color:#6b7280;font-size:18px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.tg-flow-gear-btn:hover{background:#fff7ed;border-color:#fed7aa;color:#9a5a1f}.tg-flow-gear-menu{position:absolute;right:0;top:46px;z-index:1030;display:none;width:270px;padding:8px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.18)}.tg-flow-gear-menu.is-open{display:block}.tg-flow-gear-menu button{width:100%;min-height:44px;border:0;background:#fff;border-radius:13px;padding:10px 12px;display:flex;align-items:center;gap:11px;color:#374151;font-size:14px;font-weight:650;text-align:left;cursor:pointer}.tg-flow-gear-menu button:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-gear-menu button[disabled]{color:#b8c0cc;cursor:not-allowed;background:#fff}.tg-flow-gear-ico{width:24px;color:#8a929e;text-align:center;font-weight:800}.tg-flow-modal-backdrop{position:fixed;inset:0;z-index:6100;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.38);backdrop-filter:blur(2px);padding:18px}.tg-flow-modal-backdrop.is-open{display:flex}.tg-flow-modal{width:min(940px,100%);max-height:min(760px,calc(100vh - 36px));overflow:hidden;background:#fff;border:1px solid #edf0f2;border-radius:24px;box-shadow:0 28px 80px rgba(15,23,42,.24);display:flex;flex-direction:column}.tg-flow-modal-head{padding:22px 28px 18px;border-bottom:1px solid #edf0f2;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.tg-flow-modal-title{font-size:20px;font-weight:750;color:#1f2937;line-height:1.25}.tg-flow-modal-note{margin-top:6px;color:#6b7280;font-size:13px;font-weight:600;line-height:1.45}.tg-flow-modal-close{width:40px;height:40px;border:0;border-radius:14px;background:#f3f4f6;color:#6b7280;font-size:22px;cursor:pointer}.tg-flow-modal-body{padding:22px 28px;overflow:auto;display:grid;gap:16px}.tg-flow-modal-actions{padding:16px 28px;border-top:1px solid #edf0f2;display:flex;justify-content:flex-end;gap:10px;background:#fff}.tg-flow-field span{display:block;margin-bottom:7px;font-size:12px;font-weight:650;color:#8b929e}.tg-flow-field input,.tg-flow-field textarea,.tg-flow-field select{width:100%;border:1px solid #e5e7eb;background:#fff;border-radius:16px;padding:12px 14px;color:#374151;font-size:14px;font-weight:650;outline:none}.tg-flow-field textarea{min-height:92px;resize:vertical;line-height:1.45}.tg-flow-command-list{display:grid;gap:10px}.tg-flow-command-row{display:grid;grid-template-columns:minmax(130px,.7fr) minmax(190px,1fr) minmax(190px,1fr) 44px;gap:10px;align-items:end;border:1px solid #edf0f2;border-radius:18px;background:#fbfbfc;padding:12px}.tg-flow-command-prefix{position:relative}.tg-flow-command-prefix input{padding-left:28px}.tg-flow-command-prefix:before{content:'/';position:absolute;left:13px;bottom:13px;color:#9ca3af;font-weight:800}.tg-flow-command-delete{width:42px;height:42px;border:1px solid #fee2e2;background:#fff;border-radius:14px;color:#ef4444;font-size:20px;font-weight:750;cursor:pointer}.tg-flow-command-add{width:100%;min-height:42px;border:1px dashed #fed7aa;background:#fffaf4;border-radius:16px;color:#c96f2b;font-size:13px;font-weight:750;cursor:pointer}.tg-flow-btn-main{border:0;background:#FFA048;color:#fff;border-radius:15px;padding:0 18px;min-height:42px;font-size:13px;font-weight:750;cursor:pointer}.tg-flow-btn-ghost{border:0;background:#f3f4f6;color:#6b7280;border-radius:15px;padding:0 18px;min-height:42px;font-size:13px;font-weight:750;cursor:pointer}@media(max-width:760px){.tg-flow-top-right{gap:5px}.tg-flow-top-btn{padding:0 9px}.tg-flow-gear-menu{right:auto;left:0;width:250px}.tg-flow-command-row{grid-template-columns:1fr}.tg-flow-command-delete{width:100%}.tg-flow-modal{max-height:calc(100vh - 20px);border-radius:20px}.tg-flow-modal-backdrop{padding:10px}.tg-flow-modal-head,.tg-flow-modal-body,.tg-flow-modal-actions{padding-left:18px;padding-right:18px}}



/* v3.5.122: dropdown items must be left-aligned and normal-weight */
.tg-flow-gear-menu,.tg-flow-gear-menu *{text-align:left!important}.tg-flow-gear-menu button{justify-content:flex-start!important;font-weight:500!important}
/* v3.5.122: runtime v0.1 command start */
.tg-flow-gear-menu a.tg-flow-gear-link{width:100%;min-height:44px;border:0;background:#fff;border-radius:13px;padding:10px 12px;display:flex;align-items:center;justify-content:flex-start!important;gap:11px;color:#374151;font-size:14px;font-weight:500!important;text-align:left!important;text-decoration:none;box-sizing:border-box}.tg-flow-gear-menu a.tg-flow-gear-link:hover{background:#fff7ed;color:#9a5a1f}.tg-flow-gear-menu .tg-flow-gear-disabled{width:100%;min-height:44px;border:0;background:#fff;border-radius:13px;padding:10px 12px;display:flex;align-items:center;justify-content:flex-start!important;gap:11px;color:#b8c0cc;font-size:14px;font-weight:500!important;text-align:left!important;box-sizing:border-box}
.tg-step-native{display:none!important}.tg-step-picker{position:relative;width:100%;text-align:left!important}.tg-step-picker *{box-sizing:border-box}.tg-step-picker-btn{width:100%;min-height:46px;border:1px solid #e5e7eb;background:#fff;border-radius:16px;padding:11px 42px 11px 14px;color:#374151;font-size:14px;font-weight:500!important;text-align:left!important;cursor:pointer;position:relative;justify-content:flex-start!important}.tg-step-picker-btn:after{content:'⌄';position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:18px}.tg-step-picker.is-open .tg-step-picker-btn{border-color:#FFA048;box-shadow:0 0 0 3px rgba(255,160,72,.14)}.tg-step-picker-panel{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:6200;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 22px 55px rgba(15,23,42,.18);padding:10px;text-align:left!important}.tg-step-picker.is-open .tg-step-picker-panel{display:block}.tg-step-picker-search{width:100%;border:1px solid #e5e7eb;border-radius:14px;padding:10px 12px;margin-bottom:8px;color:#374151;font-size:14px;font-weight:400!important;outline:none;text-align:left!important}.tg-step-picker-search:focus{border-color:#FFA048;box-shadow:0 0 0 3px rgba(255,160,72,.12)}.tg-step-picker-list{max-height:260px;overflow:auto;display:grid;gap:4px;text-align:left!important}.tg-step-picker-option{width:100%;border:0;background:#fff;border-radius:12px;padding:10px 12px!important;text-align:left!important;color:#374151;font-size:14px;font-weight:400!important;line-height:1.35;cursor:pointer;display:block!important;white-space:normal}.tg-step-picker-option:hover,.tg-step-picker-option.is-selected{background:#fff7ed;color:#9a5a1f}.tg-step-picker-empty{padding:12px;color:#9ca3af;font-size:13px;font-weight:400!important;text-align:left!important}


/* v3.5.127: restore visible deeplink under message blocks */
.tg-flow-node-deeplink{display:block!important;text-align:left!important;user-select:text}.tg-flow-node.is-delay .tg-flow-node-deeplink,.tg-flow-node.is-start .tg-flow-node-deeplink{display:none!important}

</style>

<div class="tg-flow-app">
    <div class="tg-flow-topbar">
        <div class="tg-flow-top-left">
            <button type="button" class="tg-flow-menu-btn" id="tg-flow-menu-btn" aria-label="Открыть меню">☰</button>
            <div class="tg-flow-title"><?php echo $h($scenario['title'] ?? 'Сценарий'); ?> <span style="font-size:11px;color:#9ca3af;font-weight:650">Flow v3.5.123</span> <span id="tg-flow-boot-status" class="tg-flow-boot-status">PHP: <?php echo count($nodes); ?> блоков / <?php echo count($edges); ?> связей · React: запуск…</span></div>
        </div>
        <div class="tg-flow-top-right">
            <div class="tg-flow-gear-wrap">
                <button type="button" class="tg-flow-gear-btn" id="tg-flow-gear-btn" aria-label="Настройки сценария">⚙</button>
                <div class="tg-flow-gear-menu" id="tg-flow-gear-menu">
                    <button type="button" data-flow-open-settings><span class="tg-flow-gear-ico">☷</span><span>Настройки</span></button>
                    <?php if ($currentScenarioBotId > 0 && $currentChannelType === 'telegram'): ?>
                        <button type="button" data-flow-open-commands><span class="tg-flow-gear-ico">///</span><span>Telegram меню для канала</span></button>
                    <?php else: ?>
                        <button type="button" disabled><span class="tg-flow-gear-ico">///</span><span>Telegram меню для канала</span></button>
                    <?php endif; ?>
                    <?php if ($currentScenarioBotUrl !== ''): ?>
                        <a class="tg-flow-gear-link" href="<?php echo $h($currentScenarioBotUrl); ?>" target="_blank" rel="noopener"><span class="tg-flow-gear-ico">↗</span><span>Открыть бот в Telegram</span></a>
                    <?php else: ?>
                        <div class="tg-flow-gear-disabled"><span class="tg-flow-gear-ico">↗</span><span>Ссылка на бот недоступна</span></div>
                    <?php endif; ?>
                    <?php if ($currentScenarioBotId > 0): ?>
                        <a class="tg-flow-gear-link" href="admin.php?tab=telegram_bots&tg_ajax=scenario_runtime_diag&scenario_id=<?php echo $scenarioId; ?>&bot_id=<?php echo $currentScenarioBotId; ?>&command=help" target="_blank" rel="noopener"><span class="tg-flow-gear-ico">ⓘ</span><span>Диагностика runtime</span></a>
                    <?php endif; ?>
                    <button type="button" disabled><span class="tg-flow-gear-ico">↻</span><span>Сбросить статистику</span></button>
                    <button type="button" disabled><span class="tg-flow-gear-ico">↔</span><span>Конвертировать бота</span></button>
                </div>
            </div>
            <button type="button" class="tg-flow-top-btn is-primary" id="tg-flow-add-block-btn">+ Блок</button>
            <button type="button" class="tg-flow-top-btn" id="tg-flow-save-btn">Сохранить</button>
            <?php $scenarioIsActive = (string)($scenario['status'] ?? 'draft') === 'active'; ?>
            <button type="button" class="tg-flow-top-btn is-muted" id="tg-flow-stop-btn" data-flow-status-action="<?php echo $scenarioIsActive ? 'pause' : 'resume'; ?>"><?php echo $scenarioIsActive ? 'Остановить' : 'Запустить'; ?></button>
        </div>
    </div>
    <div class="tg-flow-canvas-wrap">
        <div id="tg-scenario-flow-root" class="tg-flow-root" data-nodes-count="<?php echo count($nodes); ?>" data-edges-count="<?php echo count($edges); ?>"></div>
        <div id="tg-scenario-flow-error" class="tg-flow-error" style="display:none">
            <h4>React Flow не загрузился</h4>
            <p>Проверьте доступность скриптов редактора или откройте классический редактор из верхней панели.</p>
        </div>
    </div>
</div>
<div id="tg-flow-block-drawer" class="tg-flow-block-drawer" aria-live="polite"></div>
<div class="tg-flow-modal-backdrop" id="tg-flow-settings-modal" aria-hidden="true">
    <div class="tg-flow-modal" role="dialog" aria-modal="true">
        <div class="tg-flow-modal-head">
            <div>
                <div class="tg-flow-modal-title">Настройки сценария</div>
                <div class="tg-flow-modal-note">Название, описание, статус и канал сценария.</div>
            </div>
            <button type="button" class="tg-flow-modal-close" data-flow-close-modal>×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="tg_scenario_save">
            <input type="hidden" name="return_page" value="scenario_flow">
            <input type="hidden" name="scenario_id" value="<?php echo $scenarioId; ?>">
            <div class="tg-flow-modal-body">
                <label class="tg-flow-field"><span>Название</span><input type="text" name="title" value="<?php echo $h($scenario['title'] ?? ''); ?>" required></label>
                <label class="tg-flow-field"><span>Описание</span><textarea name="description"><?php echo $h($scenario['description'] ?? ''); ?></textarea></label>
                <label class="tg-flow-field"><span>Статус</span><select name="status">
                    <?php foreach (asr_tg_scenario_status_labels() as $statusKey => $statusLabel): if ($statusKey === 'archived') continue; ?>
                        <option value="<?php echo $h($statusKey); ?>" <?php echo (string)($scenario['status'] ?? 'draft') === $statusKey ? 'selected' : ''; ?>><?php echo $h($statusLabel); ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="tg-flow-field"><span>Канал сценария</span><select name="scenario_bot_id">
                    <option value="0">Без канала</option>
                    <?php foreach ($botsLight as $bot): $botId = (int)($bot['id'] ?? 0); $channelType = function_exists('asr_tg_channel_label') ? asr_tg_channel_label((string)($bot['channel_type'] ?? 'telegram')) : 'Telegram'; $label = (string)($bot['title'] ?? ('Канал #' . $botId)); $meta = $channelType . (!empty($bot['bot_username']) ? ' · @' . (string)$bot['bot_username'] : ''); ?>
                        <option value="<?php echo $botId; ?>" <?php echo $currentScenarioBotId === $botId ? 'selected' : ''; ?>><?php echo $h($label . ' — ' . $meta); ?></option>
                    <?php endforeach; ?>
                </select></label>
            </div>
            <div class="tg-flow-modal-actions"><button type="button" class="tg-flow-btn-ghost" data-flow-close-modal>Отмена</button><button type="submit" class="tg-flow-btn-main">Сохранить</button></div>
        </form>
    </div>
</div>

<div class="tg-flow-modal-backdrop" id="tg-flow-commands-modal" aria-hidden="true">
    <div class="tg-flow-modal" role="dialog" aria-modal="true">
        <div class="tg-flow-modal-head">
            <div>
                <div class="tg-flow-modal-title">Telegram меню для канала<?php echo $currentScenarioBot ? ': ' . $h($currentScenarioBot['title'] ?? '') : ''; ?></div>
                <div class="tg-flow-modal-note">Команда сохраняется в меню Telegram выбранного канала. Шаги привязаны к текущему сценарию.</div>
            </div>
            <button type="button" class="tg-flow-modal-close" data-flow-close-modal>×</button>
        </div>
        <form method="POST" class="tg-flow-command-form">
            <input type="hidden" name="action" value="tg_bot_commands_save">
            <input type="hidden" name="return_page" value="scenario_flow">
            <input type="hidden" name="return_scenario_id" value="<?php echo $scenarioId; ?>">
            <input type="hidden" name="bot_id" value="<?php echo $currentScenarioBotId; ?>">
            <div class="tg-flow-modal-body">
                <?php if ($currentScenarioBotId <= 0 || $currentChannelType !== 'telegram'): ?>
                    <div class="tg-flow-modal-note">Для меню команд нужно выбрать Telegram-канал в настройках сценария.</div>
                <?php else: ?>
                    <div class="tg-flow-command-list" data-flow-command-list>
                        <?php foreach ($scenarioCommandRows as $cmd): $cmdScenarioId = (int)($cmd['scenario_id'] ?? 0); $cmdStepId = (int)($cmd['step_id'] ?? 0); if ($cmdScenarioId > 0 && $cmdScenarioId !== $scenarioId) { $cmdStepId = 0; } ?>
                            <div class="tg-flow-command-row" data-flow-command-row>
                                <input type="hidden" name="scenario_id[]" value="<?php echo $scenarioId; ?>">
                                <label class="tg-flow-field tg-flow-command-prefix"><span>Команда</span><input name="command[]" value="<?php echo $h($cmd['command'] ?? ''); ?>" placeholder="help" maxlength="32"></label>
                                <label class="tg-flow-field"><span>Описание</span><input name="description[]" value="<?php echo $h($cmd['description'] ?? ''); ?>" placeholder="помощь" maxlength="256"></label>
                                <label class="tg-flow-field"><span>Запустить с шага</span><select name="step_id[]" class="tg-step-native js-flow-step-select" data-step-picker-select><option value="0">Сначала сценария</option><?php foreach ($scenarioStepOptions as $step): $sid = (int)($step['id'] ?? 0); if ($sid <= 0) continue; $st = trim((string)($step['title'] ?? '')) ?: 'Без названия'; ?><option value="<?php echo $sid; ?>" <?php echo $cmdStepId === $sid ? 'selected' : ''; ?>><?php echo $h($st . ' - Блок #' . $sid); ?></option><?php endforeach; ?></select></label>
                                <button type="button" class="tg-flow-command-delete" data-flow-command-delete>×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="tg-flow-command-add" data-flow-command-add>+ Добавить команду</button>
                    <template data-flow-command-template>
                        <div class="tg-flow-command-row" data-flow-command-row>
                            <input type="hidden" name="scenario_id[]" value="<?php echo $scenarioId; ?>">
                            <label class="tg-flow-field tg-flow-command-prefix"><span>Команда</span><input name="command[]" placeholder="help" maxlength="32"></label>
                            <label class="tg-flow-field"><span>Описание</span><input name="description[]" placeholder="описание команды" maxlength="256"></label>
                            <label class="tg-flow-field"><span>Запустить с шага</span><select name="step_id[]" class="tg-step-native js-flow-step-select" data-step-picker-select><option value="0">Сначала сценария</option><?php foreach ($scenarioStepOptions as $step): $sid = (int)($step['id'] ?? 0); if ($sid <= 0) continue; $st = trim((string)($step['title'] ?? '')) ?: 'Без названия'; ?><option value="<?php echo $sid; ?>"><?php echo $h($st . ' - Блок #' . $sid); ?></option><?php endforeach; ?></select></label>
                            <button type="button" class="tg-flow-command-delete" data-flow-command-delete>×</button>
                        </div>
                    </template>
                <?php endif; ?>
            </div>
            <?php if ($currentScenarioBotId > 0 && $currentChannelType === 'telegram'): ?><div class="tg-flow-modal-actions"><button type="button" class="tg-flow-btn-ghost" data-flow-close-modal>Отмена</button><button type="submit" class="tg-flow-btn-main">Сохранить меню</button></div><?php endif; ?>
        </form>
    </div>
</div>
<script>
(function(){
  const gearBtn = document.getElementById('tg-flow-gear-btn');
  const gearMenu = document.getElementById('tg-flow-gear-menu');
  const settingsModal = document.getElementById('tg-flow-settings-modal');
  const commandsModal = document.getElementById('tg-flow-commands-modal');
  function closeGear(){ if (gearMenu) gearMenu.classList.remove('is-open'); }
  function openModal(modal){ if (!modal) return; closeGear(); modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function closeModals(){ document.querySelectorAll('.tg-flow-modal-backdrop.is-open').forEach(m => { m.classList.remove('is-open'); m.setAttribute('aria-hidden','true'); }); document.body.style.overflow='hidden'; }
  if (gearBtn && gearMenu) {
    gearBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); gearMenu.classList.toggle('is-open'); });
    document.addEventListener('click', function(e){ if (!e.target.closest('.tg-flow-gear-wrap')) closeGear(); });
  }
  document.querySelectorAll('[data-flow-open-settings]').forEach(btn => btn.addEventListener('click', () => openModal(settingsModal)));
  document.querySelectorAll('[data-flow-open-commands]').forEach(btn => btn.addEventListener('click', () => openModal(commandsModal)));
  document.querySelectorAll('[data-flow-close-modal]').forEach(btn => btn.addEventListener('click', closeModals));
  document.querySelectorAll('.tg-flow-modal-backdrop').forEach(backdrop => backdrop.addEventListener('click', function(e){ if (e.target === this) closeModals(); }));
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { closeGear(); closeModals(); } });
  function initStepPickers(scope){
    (scope || document).querySelectorAll('select[data-step-picker-select]').forEach(function(select){
      if (select.dataset.stepPickerReady === '1') return;
      select.dataset.stepPickerReady = '1';
      const wrap = document.createElement('div');
      wrap.className = 'tg-step-picker';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tg-step-picker-btn';
      const panel = document.createElement('div');
      panel.className = 'tg-step-picker-panel';
      const search = document.createElement('input');
      search.type = 'text';
      search.className = 'tg-step-picker-search';
      search.placeholder = 'Поиск по названию или номеру блока';
      const list = document.createElement('div');
      list.className = 'tg-step-picker-list';
      panel.appendChild(search); panel.appendChild(list); wrap.appendChild(btn); wrap.appendChild(panel);
      select.insertAdjacentElement('afterend', wrap);
      function selectedText(){ const opt = select.options[select.selectedIndex]; return opt ? opt.textContent : 'Сначала сценария'; }
      function render(){
        const q = (search.value || '').trim().toLowerCase();
        list.innerHTML = '';
        let count = 0;
        Array.from(select.options).forEach(function(opt){
          if (opt.disabled || opt.hidden) return;
          const text = opt.textContent || '';
          if (q && text.toLowerCase().indexOf(q) === -1) return;
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'tg-step-picker-option' + (opt.selected ? ' is-selected' : '');
          item.textContent = text;
          item.addEventListener('click', function(){
            select.value = opt.value;
            select.dispatchEvent(new Event('change', { bubbles:true }));
            btn.textContent = selectedText();
            wrap.classList.remove('is-open');
          });
          list.appendChild(item); count++;
        });
        if (!count) { const empty = document.createElement('div'); empty.className = 'tg-step-picker-empty'; empty.textContent = 'Ничего не найдено'; list.appendChild(empty); }
        btn.textContent = selectedText();
      }
      btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); document.querySelectorAll('.tg-step-picker.is-open').forEach(x => { if (x !== wrap) x.classList.remove('is-open'); }); wrap.classList.toggle('is-open'); if (wrap.classList.contains('is-open')) { render(); setTimeout(() => search.focus(), 20); } });
      search.addEventListener('input', render);
      select.addEventListener('change', render);
      document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) wrap.classList.remove('is-open'); });
      render();
    });
  }
  initStepPickers(document);
  document.addEventListener('click', function(e){
    const add = e.target.closest('[data-flow-command-add]');
    if (add) {
      e.preventDefault();
      const form = add.closest('.tg-flow-command-form');
      const list = form ? form.querySelector('[data-flow-command-list]') : null;
      const tpl = form ? form.querySelector('template[data-flow-command-template]') : null;
      if (list && tpl && tpl.content) { const frag = tpl.content.cloneNode(true); list.appendChild(frag); initStepPickers(list); }
      return;
    }
    const del = e.target.closest('[data-flow-command-delete]');
    if (del) {
      e.preventDefault();
      const row = del.closest('[data-flow-command-row]');
      const list = row ? row.closest('[data-flow-command-list]') : null;
      if (row && list && list.querySelectorAll('[data-flow-command-row]').length > 1) row.remove();
      else if (row) row.querySelectorAll('input').forEach(input => { if (input.type !== 'hidden') input.value = ''; });
    }
  });
})();
</script>

<script type="application/json" id="scenario-flow-data"><?php echo $safeJson($flowData); ?></script>
<script>
(function(){
  document.body.classList.add('asr-flow-fullscreen');
  var btn = document.getElementById('tg-flow-menu-btn');
  if (!btn) return;
  btn.addEventListener('click', function(event){
    event.preventDefault();
    event.stopPropagation();
    if (typeof window.openDrawer === 'function') {
      window.openDrawer();
      return;
    }
    document.body.classList.add('drawer-open');
  });
})();
</script>
<script>
(function(){
  window.__tgScenarioFlowBoot = {
    version: '3.5.129',
    started: false,
    nodes: <?php echo (int)count($nodes); ?>,
    edges: <?php echo (int)count($edges); ?>
  };
  window.addEventListener('error', function(event){
    if (window.__tgScenarioFlowBoot && window.__tgScenarioFlowBoot.started) return;
    var errorEl = document.getElementById('tg-scenario-flow-error');
    if (!errorEl) return;
    errorEl.style.display = 'block';
    var p = errorEl.querySelector('p');
    var msg = event && event.message ? event.message : 'неизвестная ошибка JS';
    if (p) p.textContent = 'Ошибка запуска React Flow: ' + msg + '. Блоков PHP передал: ' + window.__tgScenarioFlowBoot.nodes + ', связей: ' + window.__tgScenarioFlowBoot.edges + '. Версия: 3.5.129.';
  }, true);
  window.addEventListener('unhandledrejection', function(event){
    if (window.__tgScenarioFlowBoot && window.__tgScenarioFlowBoot.started) return;
    var errorEl = document.getElementById('tg-scenario-flow-error');
    if (!errorEl) return;
    errorEl.style.display = 'block';
    var reason = event.reason && (event.reason.message || String(event.reason));
    var p = errorEl.querySelector('p');
    if (p) p.textContent = 'Не загрузился JS-модуль редактора: ' + (reason || 'неизвестная ошибка') + '. Блоков PHP передал: ' + window.__tgScenarioFlowBoot.nodes + ', связей: ' + window.__tgScenarioFlowBoot.edges + '. Версия: 3.5.129.';
  });
  setTimeout(function(){
    if (window.__tgScenarioFlowBoot && window.__tgScenarioFlowBoot.started) return;
    var errorEl = document.getElementById('tg-scenario-flow-error');
    if (!errorEl) return;
    errorEl.style.display = 'block';
    var p = errorEl.querySelector('p');
    if (p) p.textContent = 'React Flow-скрипт не стартовал. Блоков PHP передал: ' + window.__tgScenarioFlowBoot.nodes + ', связей: ' + window.__tgScenarioFlowBoot.edges + '. Версия патча: 3.5.129.';
  }, 2200);
})();
</script>
<script type="module">
<?php if ($flowScriptCode !== ''): ?>
<?php echo $flowScriptCode; ?>
<?php else: ?>
const errorEl = document.getElementById('tg-scenario-flow-error');
if (errorEl) {
  errorEl.style.display = 'block';
  const p = errorEl.querySelector('p');
  if (p) p.textContent = 'Файл scenario-flow-cdn.js не найден на сервере. Проверьте путь admin_app/modules/telegram_bots/scenario_flow/dist/scenario-flow-cdn.js. Версия патча: 3.5.129.';
}
console.error('Scenario Flow script file not found');
<?php endif; ?>
</script>
<script nomodule>document.getElementById('tg-scenario-flow-error').style.display='block';</script>
