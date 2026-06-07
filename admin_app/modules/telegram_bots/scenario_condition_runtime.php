<?php
defined('ASR_ADMIN') || exit;

function asr_tg_runtime_condition_normalize_string($value): string {
    if (is_array($value) || is_object($value)) return '';
    return trim((string)$value);
}

function asr_tg_runtime_condition_subscriber_custom_values(PDO $pdo, int $subscriberId): array {
    if ($subscriberId <= 0 || !function_exists('asr_tg_subscriber_custom_values_get')) return [];
    try {
        $fields = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, true) : [];
        $values = asr_tg_subscriber_custom_values_get($pdo, $subscriberId);
        $out = [];
        foreach ($fields as $field) {
            $code = trim((string)($field['code'] ?? ''));
            $fieldId = (int)($field['id'] ?? 0);
            if ($code === '' || $fieldId <= 0) continue;
            $row = $values[$fieldId] ?? null;
            if (!is_array($row)) { $out[$code] = ''; continue; }
            $type = (string)($field['field_type'] ?? 'text');
            if ($type === 'number') {
                $out[$code] = ($row['value_number'] ?? null) === null ? '' : (string)$row['value_number'];
            } elseif ($type === 'date') {
                $out[$code] = (string)($row['value_date'] ?? '');
            } elseif ($type === 'datetime') {
                $out[$code] = (string)($row['value_datetime'] ?? '');
            } else {
                $out[$code] = (string)($row['value_text'] ?? '');
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_runtime_condition_subscriber_field_value(PDO $pdo, array $subscriber, int $subscriberId, string $fieldCode): string {
    $fieldCode = trim($fieldCode);
    if ($fieldCode === '') return '';
    $system = ['first_name','last_name','username','phone','email','status','language_code','ref','utm_source','utm_medium','utm_campaign','utm_content','utm_term'];
    if (in_array($fieldCode, $system, true)) return asr_tg_runtime_condition_normalize_string($subscriber[$fieldCode] ?? '');
    $custom = asr_tg_runtime_condition_subscriber_custom_values($pdo, $subscriberId);
    return asr_tg_runtime_condition_normalize_string($custom[$fieldCode] ?? '');
}

function asr_tg_runtime_condition_tag_name(PDO $pdo, int $tagId): string {
    if ($tagId <= 0) return '';
    try {
        $stmt = $pdo->prepare('SELECT name FROM oca_telegram_bot_tags WHERE id = ? LIMIT 1');
        $stmt->execute([$tagId]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function asr_tg_runtime_condition_subscriber_has_tag(PDO $pdo, int $subscriberId, int $tagId, string $tagName = ''): bool {
    if ($subscriberId <= 0 || $tagId <= 0) return false;
    try {
        $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, 0) : null;
        $relatedIds = function_exists('asr_tg_subscriber_related_ids') ? asr_tg_subscriber_related_ids($pdo, $subscriberId, is_array($subscriber) ? $subscriber : null) : [$subscriberId];
        $relatedIds = array_values(array_filter(array_map('intval', $relatedIds), static fn($id) => $id > 0));
        if (!$relatedIds) $relatedIds = [$subscriberId];

        $tagName = trim($tagName);
        if ($tagName === '') $tagName = asr_tg_runtime_condition_tag_name($pdo, $tagId);

        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));

        // Теги в проекте уже считаются глобальными, но в БД могли остаться старые записи
        // с разными id и одинаковым названием по разным каналам. Поэтому runtime проверяет
        // не только точный tag_id, но и совпадение названия тега у всех связанных карточек
        // одного telegram_user_id. Это защищает ветку «Да/Нет» от ложного ухода в «Нет».
        if ($tagName !== '') {
            $params = $relatedIds;
            $params[] = $tagId;
            $params[] = $tagName;
            $stmt = $pdo->prepare("SELECT 1
                FROM oca_telegram_bot_subscriber_tags st
                JOIN oca_telegram_bot_tags t ON t.id = st.tag_id
                WHERE st.subscriber_id IN ({$placeholders})
                  AND (st.tag_id = ? OR TRIM(t.name) = ?)
                LIMIT 1");
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        }

        $params = $relatedIds;
        $params[] = $tagId;
        $stmt = $pdo->prepare("SELECT 1 FROM oca_telegram_bot_subscriber_tags WHERE subscriber_id IN ({$placeholders}) AND tag_id = ? LIMIT 1");
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_runtime_condition_subscriber_has_channel(PDO $pdo, int $subscriberId, int $botId): bool {
    if ($subscriberId <= 0 || $botId <= 0) return false;
    try {
        $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, 0) : null;
        $relatedIds = function_exists('asr_tg_subscriber_related_ids') ? asr_tg_subscriber_related_ids($pdo, $subscriberId, is_array($subscriber) ? $subscriber : null) : [$subscriberId];
        $relatedIds = array_values(array_filter(array_map('intval', $relatedIds), static fn($id) => $id > 0));
        if (!$relatedIds) $relatedIds = [$subscriberId];
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $params = $relatedIds;
        array_unshift($params, $botId);
        $stmt = $pdo->prepare("SELECT 1 FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND id IN ({$placeholders}) LIMIT 1");
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


function asr_tg_runtime_condition_text_compare(string $actual, string $operator, string $expected): bool {
    $actualTrim = trim($actual);
    $expectedTrim = trim($expected);
    $actualLower = mb_strtolower($actualTrim, 'UTF-8');
    $expectedLower = mb_strtolower($expectedTrim, 'UTF-8');
    if ($operator === 'is_empty') return $actualTrim === '';
    if ($operator === 'is_filled') return $actualTrim !== '';
    if ($operator === 'not_equals') return $actualLower !== $expectedLower;
    if ($operator === 'contains') return $expectedLower !== '' && mb_strpos($actualLower, $expectedLower, 0, 'UTF-8') !== false;
    if ($operator === 'not_contains') return $expectedLower === '' || mb_strpos($actualLower, $expectedLower, 0, 'UTF-8') === false;
    return $actualLower === $expectedLower;
}

function asr_tg_runtime_condition_number_compare(string $actual, string $operator, string $expected): bool {
    $actualTrim = str_replace([' ', ','], ['', '.'], trim($actual));
    $expectedTrim = str_replace([' ', ','], ['', '.'], trim($expected));
    if ($operator === 'is_empty') return $actualTrim === '';
    if ($operator === 'is_filled') return $actualTrim !== '' && is_numeric($actualTrim);
    if (!is_numeric($actualTrim) || !is_numeric($expectedTrim)) return false;
    $a = (float)$actualTrim;
    $b = (float)$expectedTrim;
    if ($operator === 'ne') return abs($a - $b) > 0.000001;
    if ($operator === 'gt') return $a > $b;
    if ($operator === 'gte') return $a >= $b;
    if ($operator === 'lt') return $a < $b;
    if ($operator === 'lte') return $a <= $b;
    return abs($a - $b) <= 0.000001;
}

function asr_tg_runtime_condition_date_compare(string $actual, string $operator, string $expected, bool $withTime = false): bool {
    $actualTrim = trim($actual);
    $expectedTrim = trim($expected);
    if ($operator === 'is_empty') return $actualTrim === '';
    if ($operator === 'is_filled') return $actualTrim !== '';
    if ($actualTrim === '' || $expectedTrim === '') return false;
    $actualNorm = str_replace('T', ' ', $actualTrim);
    $expectedNorm = str_replace('T', ' ', $expectedTrim);
    try {
        $a = new DateTimeImmutable($actualNorm);
        $b = new DateTimeImmutable($expectedNorm);
    } catch (Throwable $e) {
        return false;
    }
    $av = $withTime ? $a->format('Y-m-d H:i') : $a->format('Y-m-d');
    $bv = $withTime ? $b->format('Y-m-d H:i') : $b->format('Y-m-d');
    if ($operator === 'ne') return $av !== $bv;
    if ($operator === 'gt') return $av > $bv;
    if ($operator === 'gte') return $av >= $bv;
    if ($operator === 'lt') return $av < $bv;
    if ($operator === 'lte') return $av <= $bv;
    return $av === $bv;
}


function asr_tg_runtime_condition_match_mode_label(string $mode): string {
    return $mode === 'any' ? 'любое условие' : 'каждое условие';
}

function asr_tg_runtime_condition_branch_label(bool $matched): string {
    return $matched ? 'Да' : 'Нет';
}

function asr_tg_runtime_condition_rule_debug_label(array $rule): string {
    $summary = trim((string)($rule['summary'] ?? ''));
    if ($summary !== '') return $summary;

    $label = trim((string)($rule['field_label'] ?? ''));
    if ($label === '') $label = trim((string)($rule['label'] ?? ''));
    if ($label === '') $label = trim((string)($rule['field_code'] ?? ''));
    if ($label === '') $label = trim((string)($rule['type'] ?? 'Условие'));

    $operator = trim((string)($rule['operator_label'] ?? ''));
    if ($operator === '') $operator = trim((string)($rule['operator'] ?? ''));

    $value = trim((string)($rule['value_label'] ?? ''));
    if ($value === '') $value = trim((string)($rule['value'] ?? ''));

    return trim($label . ($operator !== '' ? ' — ' . $operator : '') . ($value !== '' ? ' — ' . $value : ''));
}

function asr_tg_runtime_condition_rule_matches(PDO $pdo, array $subscriber, int $subscriberId, array $rule, DateTimeImmutable $now): bool {
    $type = (string)($rule['type'] ?? '');
    $operator = (string)($rule['operator'] ?? '');

    if ($type === 'tag') {
        $hasTag = asr_tg_runtime_condition_subscriber_has_tag($pdo, $subscriberId, (int)($rule['tag_id'] ?? $rule['value'] ?? 0), (string)($rule['tag_name'] ?? $rule['value_label'] ?? ''));
        return $operator === 'not_has_tag' ? !$hasTag : $hasTag;
    }

    if ($type === 'channel') {
        $hasChannel = asr_tg_runtime_condition_subscriber_has_channel($pdo, $subscriberId, (int)($rule['bot_id'] ?? $rule['value'] ?? 0));
        return $operator === 'not_has_channel' ? !$hasChannel : $hasChannel;
    }

    if ($type === 'current_date') {
        $date = trim((string)($rule['value'] ?? $rule['date'] ?? ''));
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return false;
        $today = $now->format('Y-m-d');
        if ($operator === 'lte') return $today <= $date;
        if ($operator === 'gte') return $today >= $date;
        return $today === $date;
    }

    if ($type === 'current_time') {
        $time = trim((string)($rule['value'] ?? $rule['time'] ?? ''));
        if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) return false;
        [$hh, $mm] = array_map('intval', explode(':', $time));
        $time = sprintf('%02d:%02d', $hh, $mm);
        $current = $now->format('H:i');
        if ($operator === 'lte') return $current <= $time;
        return $current >= $time;
    }

    if ($type === 'weekday') {
        $weekday = (int)($rule['value'] ?? $rule['weekday'] ?? 0);
        if ($weekday < 1 || $weekday > 7) return false;
        $current = (int)$now->format('N');
        return $operator === 'ne' ? $current !== $weekday : $current === $weekday;
    }

    $fieldCode = trim((string)($rule['field_code'] ?? ''));
    $actual = asr_tg_runtime_condition_subscriber_field_value($pdo, $subscriber, $subscriberId, $fieldCode);
    $expected = (string)($rule['value'] ?? '');

    if ($type === 'field_number') return asr_tg_runtime_condition_number_compare($actual, $operator, $expected);
    if ($type === 'field_date') return asr_tg_runtime_condition_date_compare($actual, $operator, $expected, false);
    if ($type === 'field_datetime') return asr_tg_runtime_condition_date_compare($actual, $operator, $expected, true);
    return asr_tg_runtime_condition_text_compare($actual, $operator, $expected);
}

function asr_tg_runtime_condition_timezone(PDO $pdo, int $scenarioId): DateTimeZone {
    $tzName = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
    try {
        $scenario = function_exists('asr_tg_scenario_find') ? asr_tg_scenario_find($pdo, $scenarioId) : null;
        if (is_array($scenario)) {
            $candidate = trim((string)($scenario['timezone'] ?? ''));
            if ($candidate !== '') $tzName = $candidate;
        }
    } catch (Throwable $ignored) {}
    try { return new DateTimeZone($tzName); } catch (Throwable $e) { return new DateTimeZone('Asia/Almaty'); }
}

function asr_tg_runtime_condition_evaluate(PDO $pdo, int $subscriberId, int $scenarioId, array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $rules = isset($settings['conditions']) && is_array($settings['conditions']) ? array_values($settings['conditions']) : [];
    $rules = array_values(array_filter($rules, static fn($rule) => is_array($rule)));
    if (!$rules || empty($settings['condition_valid'])) {
        return ['ok' => false, 'matched' => false, 'error' => 'Блок «Условие» не настроен или содержит некорректные правила.', 'details' => []];
    }

    $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, 0) : null;
    if (!$subscriber) {
        return ['ok' => false, 'matched' => false, 'error' => 'Подписчик не найден для проверки условия.', 'details' => []];
    }

    $matchMode = (string)($settings['condition_match_mode'] ?? 'all');
    if (!in_array($matchMode, ['all','any'], true)) $matchMode = 'all';
    $now = new DateTimeImmutable('now', asr_tg_runtime_condition_timezone($pdo, $scenarioId));
    $details = [];
    $matchedCount = 0;

    foreach ($rules as $index => $rule) {
        $matched = asr_tg_runtime_condition_rule_matches($pdo, $subscriber, $subscriberId, $rule, $now);
        if ($matched) $matchedCount++;
        $details[] = [
            'index' => (int)$index + 1,
            'type' => (string)($rule['type'] ?? ''),
            'field_code' => (string)($rule['field_code'] ?? ''),
            'field_label' => (string)($rule['field_label'] ?? ''),
            'operator' => (string)($rule['operator'] ?? ''),
            'operator_label' => (string)($rule['operator_label'] ?? ''),
            'value' => (string)($rule['value'] ?? ''),
            'value_label' => (string)($rule['value_label'] ?? ''),
            'summary' => asr_tg_runtime_condition_rule_debug_label($rule),
            'matched' => $matched,
        ];
    }

    $result = $matchMode === 'any' ? $matchedCount > 0 : $matchedCount === count($rules);
    return [
        'ok' => true,
        'matched' => $result,
        'error' => '',
        'match_mode' => $matchMode,
        'matched_count' => $matchedCount,
        'total_count' => count($rules),
        'details' => $details,
        'now' => $now->format('Y-m-d H:i:s'),
        'timezone' => $now->getTimezone()->getName(),
    ];
}

function asr_tg_runtime_condition_target_block_id(PDO $pdo, int $scenarioId, int $blockId, bool $matched): int {
    if ($scenarioId <= 0 || $blockId <= 0) return 0;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $linkType = $matched ? 'condition_yes' : 'condition_no';
        $stmt = $pdo->prepare("SELECT to_block_id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = ? AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$scenarioId, $blockId, $linkType]);
        return max(0, (int)($stmt->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_tg_runtime_execute_condition_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    $result = asr_tg_runtime_condition_evaluate($pdo, $subscriberId, $scenarioId, $block);
    if (empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Блок «Условие» не выполнен.');
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_condition_invalid', $error, ['source' => $source] + $sourcePayload);
        return true;
    }

    $matched = !empty($result['matched']);
    $targetBlockId = asr_tg_runtime_condition_target_block_id($pdo, $scenarioId, $blockId, $matched);
    $branch = $matched ? 'yes' : 'no';
    $branchLabel = asr_tg_runtime_condition_branch_label($matched);
    $matchModeLabel = asr_tg_runtime_condition_match_mode_label((string)($result['match_mode'] ?? 'all'));
    $matchedCount = (int)($result['matched_count'] ?? 0);
    $totalCount = (int)($result['total_count'] ?? 0);
    $diagnosticMessage = $matched
        ? 'Условие выполнено: совпало ' . $matchedCount . ' из ' . $totalCount . ', режим — ' . $matchModeLabel . ', переход — «' . $branchLabel . '».'
        : 'Условие не выполнено: совпало ' . $matchedCount . ' из ' . $totalCount . ', режим — ' . $matchModeLabel . ', переход — «' . $branchLabel . '».';

    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_condition_checked', $diagnosticMessage, [
        'branch' => $branch,
        'branch_label' => $branchLabel,
        'target_block_id' => $targetBlockId,
        'matched' => $matched,
        'matched_count' => $matchedCount,
        'total_count' => $totalCount,
        'match_mode' => (string)($result['match_mode'] ?? 'all'),
        'match_mode_label' => $matchModeLabel,
        'condition_details' => $result['details'] ?? [],
        'checked_at' => (string)($result['now'] ?? ''),
        'timezone' => (string)($result['timezone'] ?? ''),
        'source' => $source,
        'result' => $result,
    ] + $sourcePayload);

    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');

    if ($targetBlockId > 0 && $targetBlockId !== $blockId) {
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, 'condition_' . $branch, ['from_block_id' => $blockId, 'source' => $source, 'condition_result' => $branch]);
    }

    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_condition_branch_missing', 'Для ветки «' . $branchLabel . '» не задан следующий блок. Совпало ' . $matchedCount . ' из ' . $totalCount . ', режим — ' . $matchModeLabel . '.', [
        'branch' => $branch,
        'branch_label' => $branchLabel,
        'matched' => $matched,
        'matched_count' => $matchedCount,
        'total_count' => $totalCount,
        'match_mode' => (string)($result['match_mode'] ?? 'all'),
        'match_mode_label' => $matchModeLabel,
        'source' => $source,
    ] + $sourcePayload);
    return true;
}
