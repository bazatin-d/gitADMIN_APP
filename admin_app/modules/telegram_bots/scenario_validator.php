<?php
defined('ASR_ADMIN') || exit;

/**
 * Lightweight scenario integrity checker for the React Flow editor.
 * It intentionally does not mutate data. The checker is used from the gear menu
 * and on editor open to warn about obvious errors before a scenario is launched.
 */

function asr_tg_scenario_validator_json_decode($value): array {
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function asr_tg_scenario_validator_type_label(string $type): string {
    $map = [
        'start' => 'Старт сценария',
        'message' => 'Сообщение',
        'delay' => 'Задержка',
        'condition' => 'Условие',
        'actions' => 'Действия',
        'schedule' => 'Расписание',
        'random' => 'Случайный выбор',
        'formula' => 'Формула',
    ];
    return $map[$type] ?? $type;
}

function asr_tg_scenario_validator_block_title(array $block): string {
    $title = trim((string)($block['title'] ?? ''));
    $type = (string)($block['type'] ?? 'message');
    if ($title !== '') return $title;
    return asr_tg_scenario_validator_type_label($type);
}

function asr_tg_scenario_validator_link_handle(array $link): string {
    $type = (string)($link['link_type'] ?? 'next');
    if ($type === 'condition_yes') return 'condition_yes';
    if ($type === 'condition_no') return 'condition_no';
    if ($type === 'schedule_on_time') return 'schedule_on_time';
    if ($type === 'schedule_expired') return 'schedule_expired';
    if ($type === 'random_choice') {
        $condition = asr_tg_scenario_validator_json_decode($link['condition_json'] ?? '');
        $key = trim((string)($condition['random_key'] ?? ''));
        return $key !== '' ? ('random_' . $key) : 'random';
    }
    return 'out';
}

function asr_tg_scenario_validator_add_item(array &$items, string $severity, string $title, string $message, array $block = [], array $extra = []): void {
    $severity = in_array($severity, ['error','warning','info'], true) ? $severity : 'warning';
    $blockId = (int)($block['id'] ?? 0);
    $type = (string)($block['type'] ?? '');
    $items[] = [
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'block_id' => $blockId,
        'block_title' => $blockId > 0 ? asr_tg_scenario_validator_block_title($block) : '',
        'block_type' => $type,
        'block_type_label' => $type !== '' ? asr_tg_scenario_validator_type_label($type) : '',
    ] + $extra;
}

function asr_tg_scenario_validator_cards_are_empty(array $cards): bool {
    if (!$cards) return true;
    foreach ($cards as $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        $text = trim(strip_tags((string)($card['text'] ?? $card['html'] ?? $card['caption'] ?? '')));
        $file = trim((string)($card['file_url'] ?? $card['url'] ?? $card['path'] ?? ''));
        if ($type !== '' && ($text !== '' || $file !== '' || $type !== 'text')) return false;
    }
    return true;
}

function asr_tg_scenario_validator_action_stops_scenario(array $actions): bool {
    foreach ($actions as $action) {
        if (!is_array($action)) continue;
        $type = (string)($action['type'] ?? '');
        if (in_array($type, ['stop_scenario','unsubscribe_bot'], true)) return true;
    }
    return false;
}

function asr_tg_scenario_validator_formula_error(array $settings): string {
    $code = trim((string)($settings['formula_code'] ?? $settings['code'] ?? ''));
    if ($code === '') return 'Формула пустая. Добавьте хотя бы одну рабочую строку.';
    if (function_exists('asr_tg_scenario_formula_validate_code')) {
        try {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $code));
            asr_tg_scenario_formula_validate_code($code, $lines);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
    if (empty($settings['formula_valid'])) return 'Формула сохранена как некорректная. Откройте блок и сохраните его заново.';
    return '';
}

function asr_tg_scenario_validator_detect_cycles(array $graph, array $blocksById): array {
    $cycles = [];
    $visited = [];
    $stack = [];
    $stackIndex = [];

    $dfs = function(int $node) use (&$dfs, &$cycles, &$visited, &$stack, &$stackIndex, $graph, $blocksById): void {
        $visited[$node] = true;
        $stackIndex[$node] = count($stack);
        $stack[] = $node;
        foreach (($graph[$node] ?? []) as $to) {
            $to = (int)$to;
            if ($to <= 0 || !isset($blocksById[$to])) continue;
            if (empty($visited[$to])) {
                $dfs($to);
                continue;
            }
            if (isset($stackIndex[$to])) {
                $cycle = array_slice($stack, (int)$stackIndex[$to]);
                $cycle[] = $to;
                $keyParts = $cycle;
                sort($keyParts);
                $key = implode('-', array_unique($keyParts));
                if (!isset($cycles[$key])) $cycles[$key] = $cycle;
            }
        }
        array_pop($stack);
        unset($stackIndex[$node]);
    };

    foreach (array_keys($blocksById) as $id) {
        $id = (int)$id;
        if (empty($visited[$id])) $dfs($id);
    }
    return array_values($cycles);
}

function asr_tg_scenario_validate(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) {
        return ['ok' => false, 'ready' => false, 'items' => [[
            'severity' => 'error',
            'title' => 'Сценарий не найден',
            'message' => 'Не удалось определить сценарий для проверки.',
            'block_id' => 0,
            'block_title' => '',
            'block_type' => '',
            'block_type_label' => '',
        ]], 'counts' => ['error' => 1, 'warning' => 0, 'info' => 0], 'summary' => 'Найдена 1 ошибка.'];
    }

    if (function_exists('asr_tg_repository_ensure_scenario_schema')) asr_tg_repository_ensure_scenario_schema($pdo);
    $scenario = function_exists('asr_tg_scenario_find') ? asr_tg_scenario_find($pdo, $scenarioId) : null;
    if (!$scenario) {
        return ['ok' => false, 'ready' => false, 'items' => [[
            'severity' => 'error',
            'title' => 'Сценарий не найден',
            'message' => 'Сценарий удалён или недоступен.',
            'block_id' => 0,
            'block_title' => '',
            'block_type' => '',
            'block_type_label' => '',
        ]], 'counts' => ['error' => 1, 'warning' => 0, 'info' => 0], 'summary' => 'Найдена 1 ошибка.'];
    }

    $blocks = function_exists('asr_tg_scenario_blocks_all') ? asr_tg_scenario_blocks_all($pdo, $scenarioId) : [];
    if (!$blocks) {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$scenarioId]);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $links = function_exists('asr_tg_scenario_links_all') ? asr_tg_scenario_links_all($pdo, $scenarioId) : [];
    if (!$links) {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$scenarioId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $items = [];
    $blocksById = [];
    $startBlocks = [];
    foreach ($blocks as $block) {
        $id = (int)($block['id'] ?? 0);
        if ($id <= 0) continue;
        $blocksById[$id] = $block;
        if ((string)($block['type'] ?? '') === 'start') $startBlocks[] = $id;
    }

    if (!$blocksById) {
        asr_tg_scenario_validator_add_item($items, 'error', 'Нет блоков', 'В сценарии нет ни одного блока. Создайте стартовый блок и хотя бы одно сообщение.');
    }
    if (!$startBlocks) {
        asr_tg_scenario_validator_add_item($items, 'error', 'Нет старта', 'В сценарии не найден стартовый блок. Обновите редактор или пересоздайте сценарий.');
    }
    if (count($startBlocks) > 1) {
        asr_tg_scenario_validator_add_item($items, 'warning', 'Несколько стартовых блоков', 'Найдено несколько стартовых блоков. Сценарий может запускаться не с той точки.');
    }

    $outgoing = [];
    $graph = [];
    foreach ($links as $link) {
        $from = (int)($link['from_block_id'] ?? 0);
        $to = (int)($link['to_block_id'] ?? 0);
        if ($from <= 0 || $to <= 0) continue;
        if (!isset($blocksById[$from])) {
            asr_tg_scenario_validator_add_item($items, 'warning', 'Связь из удалённого блока', 'Есть связь, у которой исходный блок уже не существует. Удалите лишнюю связь, если она видна на холсте.');
            continue;
        }
        if (!isset($blocksById[$to])) {
            asr_tg_scenario_validator_add_item($items, 'warning', 'Связь в удалённый блок', 'Есть связь, которая ведёт в несуществующий блок.', $blocksById[$from]);
            continue;
        }
        $handle = asr_tg_scenario_validator_link_handle($link);
        $outgoing[$from][] = ['to' => $to, 'handle' => $handle, 'link' => $link];
        $graph[$from][] = $to;
    }

    foreach ($blocksById as $id => $block) {
        $type = (string)($block['type'] ?? 'message');
        $settings = asr_tg_scenario_validator_json_decode($block['settings_json'] ?? '');
        $outs = $outgoing[$id] ?? [];
        $hasOut = count($outs) > 0;
        $title = asr_tg_scenario_validator_block_title($block);

        if ($type === 'start' && !$hasOut) {
            asr_tg_scenario_validator_add_item($items, 'error', 'Старт никуда не ведёт', 'Соедините стартовый блок с первым рабочим блоком сценария.', $block);
        }

        if ($type === 'message') {
            $cards = isset($settings['cards']) && is_array($settings['cards']) ? $settings['cards'] : [];
            if (!$cards || asr_tg_scenario_validator_cards_are_empty($cards)) {
                asr_tg_scenario_validator_add_item($items, 'error', 'Пустой блок сообщения', 'В блоке нет содержимого для отправки. Добавьте текст, картинку, файл, вопрос или другой тип карточки.', $block);
            }
        }

        if ($type === 'condition') {
            if (empty($settings['condition_valid']) || empty($settings['conditions']) || !is_array($settings['conditions'])) {
                asr_tg_scenario_validator_add_item($items, 'error', 'Условие не настроено', 'Добавьте хотя бы одно корректное условие. Иначе сценарий не сможет решить, куда вести подписчика.', $block);
            }
            $handles = array_column($outs, 'handle');
            if (!in_array('condition_yes', $handles, true)) asr_tg_scenario_validator_add_item($items, 'error', 'Нет ветки «Да»', 'Подключите выход «Да» к следующему блоку.', $block);
            if (!in_array('condition_no', $handles, true)) asr_tg_scenario_validator_add_item($items, 'error', 'Нет ветки «Нет»', 'Подключите выход «Нет» к следующему блоку.', $block);
        }

        if ($type === 'schedule') {
            if (empty($settings['schedule_valid'])) {
                asr_tg_scenario_validator_add_item($items, 'error', 'Расписание не настроено', 'Укажите фиксированную дату или поле даты. Сейчас блок не знает, когда продолжать сценарий.', $block);
            }
            $handles = array_column($outs, 'handle');
            if (!in_array('schedule_on_time', $handles, true)) asr_tg_scenario_validator_add_item($items, 'error', 'Нет ветки «По расписанию»', 'Подключите выход «По расписанию» к следующему блоку.', $block);
        }

        if ($type === 'random') {
            $outputs = isset($settings['outputs']) && is_array($settings['outputs']) ? array_values($settings['outputs']) : [];
            $sum = 0;
            foreach ($outputs as $out) $sum += (int)round((float)($out['percent'] ?? 0));
            if (count($outputs) < 2 || $sum !== 100 || empty($settings['random_valid'])) {
                asr_tg_scenario_validator_add_item($items, 'error', 'Случайный выбор не настроен', 'Нужно минимум 2 выхода, а сумма вероятностей должна быть ровно 100%.', $block);
            }
            $handles = array_column($outs, 'handle');
            foreach ($outputs as $idx => $output) {
                if (!is_array($output)) continue;
                $key = trim((string)($output['key'] ?? 'r' . ((int)$idx + 1)));
                $percent = (int)round((float)($output['percent'] ?? 0));
                $name = trim((string)($output['title'] ?? 'Выход ' . ((int)$idx + 1)));
                if ($percent > 0 && !in_array('random_' . $key, $handles, true)) {
                    asr_tg_scenario_validator_add_item($items, 'error', 'Выход случайного выбора не подключён', 'У выхода «' . $name . '» стоит ' . $percent . '%, но он не соединён со следующим блоком.', $block);
                }
            }
        }

        if ($type === 'actions') {
            $actions = isset($settings['actions']) && is_array($settings['actions']) ? array_values($settings['actions']) : [];
            if (!$actions || empty($settings['actions_valid'])) {
                asr_tg_scenario_validator_add_item($items, 'error', 'Действия не настроены', 'Добавьте хотя бы одно корректное действие или удалите этот блок.', $block);
            }
        }

        if ($type === 'delay' && !$hasOut) {
            asr_tg_scenario_validator_add_item($items, 'error', 'Задержка без продолжения', 'После задержки нет следующего блока. Подписчик дождётся времени и остановится.', $block);
        }

        if ($type === 'formula') {
            $formulaError = asr_tg_scenario_validator_formula_error($settings);
            if ($formulaError !== '') {
                asr_tg_scenario_validator_add_item($items, 'error', 'Формула не настроена', $formulaError, $block);
            }
        }

    }

    $startId = (int)($startBlocks[0] ?? 0);
    if ($startId > 0) {
        $reachable = [];
        $queue = [$startId];
        while ($queue) {
            $cur = (int)array_shift($queue);
            if (isset($reachable[$cur])) continue;
            $reachable[$cur] = true;
            foreach (($graph[$cur] ?? []) as $to) if (!isset($reachable[(int)$to])) $queue[] = (int)$to;
        }
        foreach ($blocksById as $id => $block) {
            if (!isset($reachable[$id])) {
                asr_tg_scenario_validator_add_item($items, 'warning', 'Блок недоступен из старта', 'До этого блока нельзя дойти из стартового блока. Он не выполнится, пока вы не подключите его к цепочке.', $block);
            }
        }
    }

    foreach (asr_tg_scenario_validator_detect_cycles($graph, $blocksById) as $cycle) {
        $names = [];
        $cycleTypes = [];
        foreach ($cycle as $id) {
            $block = $blocksById[(int)$id] ?? [];
            if ($block) {
                $names[] = asr_tg_scenario_validator_block_title($block) . ' #' . (int)$id;
                $cycleTypes[] = (string)($block['type'] ?? '');
            }
        }
        if (!$names) continue;
        $hasPause = (bool)array_intersect($cycleTypes, ['delay','schedule','condition','random']);
        asr_tg_scenario_validator_add_item(
            $items,
            $hasPause ? 'warning' : 'error',
            'Возможная зацикленность',
            'Найдена цепочка, которая возвращается сама в себя: ' . implode(' → ', $names) . '. Проверьте, что этот цикл намеренный и у него есть понятный выход.',
            $blocksById[(int)($cycle[0] ?? 0)] ?? []
        );
    }

    $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
    foreach ($items as $item) {
        $sev = (string)($item['severity'] ?? 'warning');
        if (!isset($counts[$sev])) $counts[$sev] = 0;
        $counts[$sev]++;
    }
    $ready = $counts['error'] <= 0;
    if (!$items) {
        $summary = 'Ошибок не найдено. Сценарий готов к работе.';
    } elseif ($counts['error'] > 0) {
        $summary = 'Найдено ошибок: ' . $counts['error'] . '. Предупреждений: ' . $counts['warning'] . '.';
    } else {
        $summary = 'Критических ошибок нет. Предупреждений: ' . $counts['warning'] . '.';
    }

    return [
        'ok' => true,
        'ready' => $ready,
        'scenario_id' => $scenarioId,
        'summary' => $summary,
        'counts' => $counts,
        'items' => array_values($items),
    ];
}
