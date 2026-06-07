<?php
defined('ASR_ADMIN') || exit;

function asr_tg_runtime_actions_settings(array $block): array {
    $raw = (string)($block['settings_json'] ?? '');
    $settings = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $settings = $decoded;
    }
    $actions = $settings['actions'] ?? [];
    if (!is_array($actions)) $actions = [];
    $settings['actions'] = array_values(array_filter($actions, static fn($item) => is_array($item)));
    return $settings;
}

function asr_tg_runtime_actions_tag_name(PDO $pdo, int $tagId): string {
    if ($tagId <= 0) return '';
    try {
        $stmt = $pdo->prepare('SELECT name FROM oca_telegram_bot_tags WHERE id = ? LIMIT 1');
        $stmt->execute([$tagId]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function asr_tg_runtime_actions_related_subscriber_ids(PDO $pdo, int $subscriberId): array {
    if ($subscriberId <= 0) return [];
    try {
        $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, 0) : null;
        $ids = function_exists('asr_tg_subscriber_related_ids') ? asr_tg_subscriber_related_ids($pdo, $subscriberId, is_array($subscriber) ? $subscriber : null) : [$subscriberId];
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn($id) => $id > 0)));
        return $ids ?: [$subscriberId];
    } catch (Throwable $e) {
        return [$subscriberId];
    }
}

function asr_tg_runtime_actions_apply_tag(PDO $pdo, int $subscriberId, int $tagId, string $mode): array {
    if ($subscriberId <= 0 || $tagId <= 0) {
        return ['ok' => false, 'affected' => 0, 'subscriber_ids' => [], 'tag_id' => $tagId, 'tag_name' => '', 'error' => 'Не выбран тег или подписчик.'];
    }

    $tagName = asr_tg_runtime_actions_tag_name($pdo, $tagId);
    if ($tagName === '') {
        return ['ok' => false, 'affected' => 0, 'subscriber_ids' => [], 'tag_id' => $tagId, 'tag_name' => '', 'error' => 'Тег не найден.'];
    }

    $subscriberIds = asr_tg_runtime_actions_related_subscriber_ids($pdo, $subscriberId);
    $affected = 0;

    if ($mode === 'add') {
        foreach ($subscriberIds as $id) {
            try {
                if (function_exists('asr_tg_subscriber_tag_add')) {
                    asr_tg_subscriber_tag_add($pdo, $id, $tagId);
                } else {
                    $stmt = $pdo->prepare('INSERT IGNORE INTO oca_telegram_bot_subscriber_tags (subscriber_id, tag_id, created_at) VALUES (?, ?, NOW())');
                    $stmt->execute([$id, $tagId]);
                }
                $affected++;
            } catch (Throwable $e) {
                // Не валим весь сценарий из-за одной связанной карточки, но отдаём ошибку в общий результат ниже.
            }
        }
        return ['ok' => true, 'affected' => $affected, 'subscriber_ids' => $subscriberIds, 'tag_id' => $tagId, 'tag_name' => $tagName, 'error' => ''];
    }

    // При удалении учитываем старые дубли тегов с одинаковым названием. Это согласовано с runtime блока «Условие»:
    // теги глобальные по смыслу, но в базе могли остаться разные id с одним названием.
    try {
        $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));
        $params = $subscriberIds;
        $params[] = $tagId;
        $params[] = $tagName;
        $stmt = $pdo->prepare("DELETE st
            FROM oca_telegram_bot_subscriber_tags st
            JOIN oca_telegram_bot_tags t ON t.id = st.tag_id
            WHERE st.subscriber_id IN ({$placeholders})
              AND (st.tag_id = ? OR TRIM(t.name) = ?)");
        $stmt->execute($params);
        $affected = (int)$stmt->rowCount();
    } catch (Throwable $e) {
        foreach ($subscriberIds as $id) {
            try {
                if (function_exists('asr_tg_subscriber_tag_remove')) {
                    asr_tg_subscriber_tag_remove($pdo, $id, $tagId);
                } else {
                    $stmt = $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_tags WHERE subscriber_id = ? AND tag_id = ?');
                    $stmt->execute([$id, $tagId]);
                }
                $affected++;
            } catch (Throwable $ignored) {}
        }
    }

    return ['ok' => true, 'affected' => $affected, 'subscriber_ids' => $subscriberIds, 'tag_id' => $tagId, 'tag_name' => $tagName, 'error' => ''];
}


function asr_tg_runtime_actions_field_catalog(PDO $pdo): array {
    $catalog = function_exists('asr_tg_scenario_condition_parameter_catalog') ? asr_tg_scenario_condition_parameter_catalog($pdo) : [];
    $out = [];
    $allowedSystem = ['first_name' => true, 'last_name' => true, 'phone' => true, 'email' => true];
    foreach ($catalog as $item) {
        if (!is_array($item)) continue;
        $key = trim((string)($item['key'] ?? ''));
        $paramType = (string)($item['param_type'] ?? 'text');
        $source = (string)($item['source'] ?? '');
        $fieldCode = trim((string)($item['field_code'] ?? ''));
        if ($key === '' || !in_array($paramType, ['text','number','date','datetime'], true)) continue;
        if (strpos($key, 'field:') === 0 && !isset($allowedSystem[$fieldCode])) continue;
        if (strpos($key, 'field:') !== 0 && strpos($key, 'custom:') !== 0) continue;
        $out[$key] = $item;
    }
    return $out;
}

function asr_tg_runtime_actions_normalize_number($value): ?string {
    if (is_array($value) || is_object($value)) return null;
    $raw = trim(str_replace([',', ' '], ['.', ''], (string)$value));
    if ($raw === '' || !preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $raw)) return null;
    return $raw;
}

function asr_tg_runtime_actions_prepare_field_value(string $type, string $operation, string $value, string $fieldTitle = 'поле'): array {
    $value = trim((string)$value);
    if ($operation === 'clear') return ['ok' => true, 'value' => '', 'error' => ''];
    if ($type === 'number') {
        $number = asr_tg_runtime_actions_normalize_number($value);
        if ($number === null) return ['ok' => false, 'value' => '', 'error' => 'Поле «' . $fieldTitle . '» должно быть числом.'];
        return ['ok' => true, 'value' => $number, 'error' => ''];
    }
    if ($type === 'date') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return ['ok' => false, 'value' => '', 'error' => 'Поле «' . $fieldTitle . '» должно быть датой.'];
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) return ['ok' => false, 'value' => '', 'error' => 'Поле «' . $fieldTitle . '» содержит некорректную дату.'];
        return ['ok' => true, 'value' => $value, 'error' => ''];
    }
    if ($type === 'datetime') {
        $normalized = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) $normalized .= ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $normalized);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $normalized) return ['ok' => false, 'value' => '', 'error' => 'Поле «' . $fieldTitle . '» должно быть датой и временем.'];
        return ['ok' => true, 'value' => $normalized, 'error' => ''];
    }
    if ($value === '') return ['ok' => false, 'value' => '', 'error' => 'Поле «' . $fieldTitle . '» не заполнено.'];
    return ['ok' => true, 'value' => mb_substr($value, 0, 5000, 'UTF-8'), 'error' => ''];
}

function asr_tg_runtime_actions_related_subscriber(PDO $pdo, int $subscriberId): ?array {
    if ($subscriberId <= 0 || !function_exists('asr_tg_subscriber_find')) return null;
    try {
        $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, 0);
        return is_array($subscriber) ? $subscriber : null;
    } catch (Throwable $e) {
        return null;
    }
}

function asr_tg_runtime_actions_apply_system_field(PDO $pdo, int $subscriberId, string $fieldCode, string $operation, string $value): array {
    $allowed = ['first_name' => 190, 'last_name' => 190, 'phone' => 80, 'email' => 190];
    if ($subscriberId <= 0 || !isset($allowed[$fieldCode])) return ['ok' => false, 'error' => 'Системное поле не найдено или его нельзя менять из сценария.', 'affected' => 0];
    $subscriber = asr_tg_runtime_actions_related_subscriber($pdo, $subscriberId);
    if (!$subscriber) return ['ok' => false, 'error' => 'Подписчик не найден.', 'affected' => 0];
    if (!in_array($operation, ['set','clear'], true)) return ['ok' => false, 'error' => 'Для системных текстовых полей доступны только операции «установить» и «очистить».', 'affected' => 0];

    $newValue = $operation === 'clear' ? null : mb_substr(trim($value), 0, $allowed[$fieldCode], 'UTF-8');
    if ($newValue === '') $newValue = null;
    if ($fieldCode === 'email' && $newValue !== null && !filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email указан некорректно.', 'affected' => 0];
    }

    try {
        $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
        $safeField = str_replace('`', '``', $fieldCode);
        if ($telegramUserId > 0) {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscribers SET `{$safeField}` = ?, updated_at = NOW() WHERE telegram_user_id = ?");
            $stmt->execute([$newValue, $telegramUserId]);
        } else {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscribers SET `{$safeField}` = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newValue, $subscriberId]);
        }
        return ['ok' => true, 'error' => '', 'affected' => (int)$stmt->rowCount(), 'field_code' => $fieldCode, 'value' => $newValue ?? ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'affected' => 0];
    }
}

function asr_tg_runtime_actions_custom_field_by_code(PDO $pdo, string $code): ?array {
    $code = trim($code);
    if ($code === '' || !function_exists('asr_tg_custom_fields_all')) return null;
    try {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            if ((string)($field['code'] ?? '') === $code) return $field;
        }
    } catch (Throwable $e) {}
    return null;
}

function asr_tg_runtime_actions_current_custom_value(PDO $pdo, int $subscriberId, int $fieldId, string $fieldType): string {
    if ($subscriberId <= 0 || $fieldId <= 0 || !function_exists('asr_tg_subscriber_custom_values_get')) return '';
    try {
        $values = asr_tg_subscriber_custom_values_get($pdo, $subscriberId);
        $row = $values[$fieldId] ?? null;
        if (!is_array($row)) return '';
        if ($fieldType === 'number') return ($row['value_number'] ?? null) === null ? '' : (string)$row['value_number'];
        if ($fieldType === 'date') return (string)($row['value_date'] ?? '');
        if ($fieldType === 'datetime') return (string)($row['value_datetime'] ?? '');
        return (string)($row['value_text'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function asr_tg_runtime_actions_apply_custom_field(PDO $pdo, int $botId, int $subscriberId, string $fieldCode, string $operation, string $value): array {
    if ($subscriberId <= 0 || $botId <= 0 || $fieldCode === '') return ['ok' => false, 'error' => 'Пользовательское поле или подписчик не найден.', 'affected' => 0];
    $field = asr_tg_runtime_actions_custom_field_by_code($pdo, $fieldCode);
    if (!$field) return ['ok' => false, 'error' => 'Пользовательское поле не найдено.', 'affected' => 0];
    $fieldId = (int)($field['id'] ?? 0);
    $fieldType = (string)($field['field_type'] ?? 'text');
    if (!in_array($fieldType, ['text','number','date','datetime'], true)) $fieldType = 'text';
    $fieldTitle = trim((string)($field['title'] ?? $fieldCode)) ?: $fieldCode;

    if (!in_array($operation, ['set','clear','inc','dec'], true)) $operation = 'set';
    if ($fieldType !== 'number' && in_array($operation, ['inc','dec'], true)) {
        return ['ok' => false, 'error' => 'Прибавить или вычесть можно только для числового поля.', 'affected' => 0];
    }

    if (in_array($operation, ['inc','dec'], true)) {
        $delta = asr_tg_runtime_actions_normalize_number($value);
        if ($delta === null) return ['ok' => false, 'error' => 'Для числовой операции нужно указать число.', 'affected' => 0];
        $current = asr_tg_runtime_actions_current_custom_value($pdo, $subscriberId, $fieldId, 'number');
        $currentNumber = asr_tg_runtime_actions_normalize_number($current);
        $base = $currentNumber === null ? 0.0 : (float)$currentNumber;
        $next = $operation === 'inc' ? ($base + (float)$delta) : ($base - (float)$delta);
        $preparedValue = rtrim(rtrim(sprintf('%.10F', $next), '0'), '.');
    } else {
        $prepared = asr_tg_runtime_actions_prepare_field_value($fieldType, $operation, $value, $fieldTitle);
        if (empty($prepared['ok'])) return ['ok' => false, 'error' => (string)($prepared['error'] ?? 'Поле заполнено некорректно.'), 'affected' => 0];
        $preparedValue = (string)($prepared['value'] ?? '');
    }

    try {
        $subscriberIds = asr_tg_runtime_actions_related_subscriber_ids($pdo, $subscriberId);
        if (!$subscriberIds) $subscriberIds = [$subscriberId];

        if ($preparedValue === '') {
            $delete = $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_custom_values WHERE subscriber_id = ? AND field_id = ?');
            $affected = 0;
            foreach ($subscriberIds as $relatedId) {
                $delete->execute([(int)$relatedId, $fieldId]);
                $affected += (int)$delete->rowCount();
            }
            return ['ok' => true, 'error' => '', 'affected' => $affected, 'field_id' => $fieldId, 'field_code' => $fieldCode, 'field_type' => $fieldType, 'value' => ''];
        }

        $valueText = null;
        $valueNumber = null;
        $valueDate = null;
        $valueDatetime = null;
        if ($fieldType === 'number') {
            $valueNumber = $preparedValue;
            $valueText = $preparedValue;
        } elseif ($fieldType === 'date') {
            $valueDate = $preparedValue;
            $valueText = $preparedValue;
        } elseif ($fieldType === 'datetime') {
            $valueDatetime = $preparedValue;
            $valueText = $preparedValue;
        } else {
            $valueText = $preparedValue;
        }

        $upsert = $pdo->prepare('INSERT INTO oca_telegram_bot_subscriber_custom_values
            (subscriber_id, field_id, value_text, value_number, value_date, value_datetime)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                value_text = VALUES(value_text),
                value_number = VALUES(value_number),
                value_date = VALUES(value_date),
                value_datetime = VALUES(value_datetime),
                updated_at = NOW()');
        $affected = 0;
        foreach ($subscriberIds as $relatedId) {
            $upsert->execute([(int)$relatedId, $fieldId, $valueText, $valueNumber, $valueDate, $valueDatetime]);
            $affected += max(1, (int)$upsert->rowCount());
        }
        return ['ok' => true, 'error' => '', 'affected' => $affected, 'field_id' => $fieldId, 'field_code' => $fieldCode, 'field_type' => $fieldType, 'value' => $preparedValue];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'affected' => 0];
    }
}

function asr_tg_runtime_actions_apply_field(PDO $pdo, int $botId, int $subscriberId, array $action): array {
    $paramKey = trim((string)($action['param_key'] ?? ''));
    $operation = trim((string)($action['operation'] ?? 'set'));
    $value = (string)($action['value'] ?? '');
    if (!in_array($operation, ['set','clear','inc','dec'], true)) $operation = 'set';
    if (strpos($paramKey, 'field:') === 0) {
        $fieldCode = substr($paramKey, 6);
        return asr_tg_runtime_actions_apply_system_field($pdo, $subscriberId, $fieldCode, $operation, $value);
    }
    if (strpos($paramKey, 'custom:') === 0) {
        $fieldCode = substr($paramKey, 7);
        return asr_tg_runtime_actions_apply_custom_field($pdo, $botId, $subscriberId, $fieldCode, $operation, $value);
    }
    return ['ok' => false, 'error' => 'Поле / переменная не выбраны.', 'affected' => 0];
}


function asr_tg_runtime_actions_stop_scenario(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId, string $reason = ''): array {
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0) {
        return ['ok' => false, 'error' => 'Не хватает данных для остановки сценария.'];
    }
    $reason = trim($reason);
    if ($reason === '') $reason = 'Сценарий остановлен действием блока «Действия».';

    try {
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'stopped', null, $reason);

        // Дополнительная страховка: если у подписчика остались ожидающие/отложенные состояния этого сценария,
        // гасим их тоже. Это не трогает другие сценарии и не вмешивается в очередь рассылок.
        try {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios
                SET status = 'stopped', next_run_at = NULL, last_error = ?, stopped_at = COALESCE(stopped_at, NOW()), updated_at = NOW()
                WHERE scenario_id = ? AND bot_id = ? AND subscriber_id = ?
                  AND status IN ('active','waiting','delayed','queued','processing','error')");
            $stmt->execute([$reason, $scenarioId, $botId, $subscriberId]);
        } catch (Throwable $ignored) {}

        return ['ok' => true, 'error' => '', 'reason' => $reason];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'reason' => $reason];
    }
}


function asr_tg_runtime_actions_unsubscribe_bot(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId): array {
    if ($botId <= 0 || $subscriberId <= 0) {
        return ['ok' => false, 'error' => 'Не хватает данных для отписки от бота.', 'affected' => 0];
    }

    $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
    if (!$subscriber) return ['ok' => false, 'error' => 'Подписчик не найден.', 'affected' => 0];

    $reason = 'Подписчик отписан от бота действием блока «Действия».';
    $affected = 0;

    try {
        $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
        if ($telegramUserId > 0) {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscribers
                SET status = 'unsubscribed', updated_at = NOW()
                WHERE bot_id = ? AND telegram_user_id = ? AND status <> 'blocked'");
            $stmt->execute([$botId, $telegramUserId]);
        } else {
            $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscribers
                SET status = 'unsubscribed', updated_at = NOW()
                WHERE id = ? AND bot_id = ? AND status <> 'blocked'");
            $stmt->execute([$subscriberId, $botId]);
        }
        $affected = (int)$stmt->rowCount();

        // Отписка от бота означает, что текущий сценарий больше не продолжаем.
        try {
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'stopped', null, $reason);
        } catch (Throwable $ignored) {}

        try {
            $stop = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios
                SET status = 'stopped', next_run_at = NULL, last_error = ?, stopped_at = COALESCE(stopped_at, NOW()), updated_at = NOW()
                WHERE scenario_id = ? AND bot_id = ? AND subscriber_id = ?
                  AND status IN ('active','waiting','delayed','queued','processing','error')");
            $stop->execute([$reason, $scenarioId, $botId, $subscriberId]);
        } catch (Throwable $ignored) {}

        return ['ok' => true, 'error' => '', 'affected' => $affected, 'reason' => $reason, 'status' => 'unsubscribed'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'affected' => $affected];
    }
}


function asr_tg_runtime_actions_staff_recipient(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    try {
        if (function_exists('asr_tg_broadcast_test_ensure_user_schema')) asr_tg_broadcast_test_ensure_user_schema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM oca_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        $chatId = trim((string)($user['telegram_broadcast_test_chat_id'] ?? ''));
        if ($chatId === '') return null;
        if (array_key_exists('telegram_broadcast_test_notify_dialogs', $user) && (int)$user['telegram_broadcast_test_notify_dialogs'] !== 1) return null;
        if (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1) return null;
        if (array_key_exists('archived_at', $user) && trim((string)$user['archived_at']) !== '') return null;
        $label = trim((string)($user['full_name'] ?? ''));
        if ($label === '') $label = trim((string)($user['name'] ?? ''));
        if ($label === '') $label = trim((string)($user['telegram_broadcast_test_username'] ?? ''));
        if ($label === '') $label = trim((string)($user['username'] ?? ''));
        if ($label === '') $label = trim((string)($user['email'] ?? ''));
        if ($label === '') $label = 'Сотрудник #' . $userId;
        $user['_notify_label'] = $label;
        $user['_notify_chat_id'] = $chatId;
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

function asr_tg_runtime_actions_subscriber_context(PDO $pdo, int $botId, int $subscriberId): array {
    if ($subscriberId <= 0) return [];
    try {
        $stmt = $pdo->prepare('SELECT s.*, b.title AS bot_title, b.bot_username FROM oca_telegram_bot_subscribers s LEFT JOIN oca_telegram_bots b ON b.id = s.bot_id WHERE s.id = ? LIMIT 1');
        $stmt->execute([$subscriberId]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$subscriber && function_exists('asr_tg_subscriber_find')) {
            $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId) ?: [];
        }
        return is_array($subscriber) ? $subscriber : [];
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_runtime_actions_dialog_url(int $botId, int $subscriberId): string {
    if ($botId <= 0 || $subscriberId <= 0) return '';
    $base = function_exists('asr_current_base_url') ? rtrim((string)asr_current_base_url(), '/') : '';
    if ($base === '') return '';
    return $base . '/admin.php?tab=telegram_bots&page=messages&dialog_view=new&bot_id=' . $botId . '&subscriber_id=' . $subscriberId;
}

function asr_tg_runtime_actions_send_staff_notification(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, array $action): array {
    $staffUserId = (int)($action['staff_user_id'] ?? 0);
    $message = trim((string)($action['message'] ?? ''));
    if ($staffUserId <= 0) return ['ok' => false, 'error' => 'Не выбран сотрудник.', 'staff_user_id' => 0];
    if ($message === '') return ['ok' => false, 'error' => 'Не заполнен текст уведомления.', 'staff_user_id' => $staffUserId];

    $recipient = asr_tg_runtime_actions_staff_recipient($pdo, $staffUserId);
    if (!$recipient) {
        return ['ok' => false, 'error' => 'Сотрудник не найден или не подключён к уведомлениям диалогов.', 'staff_user_id' => $staffUserId];
    }

    $cfg = function_exists('asr_tg_broadcast_test_bot_config') ? asr_tg_broadcast_test_bot_config() : [];
    $token = trim((string)($cfg['bot_token'] ?? ''));
    if ($token === '') return ['ok' => false, 'error' => 'Не настроен технический Telegram-бот для сотрудников.', 'staff_user_id' => $staffUserId];

    $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
    $rendered = function_exists('asr_tg_render_subscriber_macros') ? asr_tg_render_subscriber_macros($pdo, $message, $subscriber) : $message;
    $rendered = trim($rendered);
    if ($rendered === '') return ['ok' => false, 'error' => 'После подстановки переменных текст уведомления пустой.', 'staff_user_id' => $staffUserId];
    $rendered = mb_substr($rendered, 0, 3900, 'UTF-8');

    $subscriberName = function_exists('asr_tg_subscriber_macro_full_name') ? asr_tg_subscriber_macro_full_name($subscriber) : ('Подписчик #' . $subscriberId);
    $channelTitle = trim((string)($subscriber['bot_title'] ?? ($bot['title'] ?? '')));
    $body = "🔔 Уведомление из сценария\n\n";
    if ($channelTitle !== '') $body .= "Канал: " . $channelTitle . "\n";
    if ($subscriberName !== '') $body .= "Подписчик: " . $subscriberName . "\n";
    $body .= "\n" . $rendered;
    $body = mb_substr($body, 0, 4096, 'UTF-8');

    $replyMarkup = null;
    if (!empty($action['add_dialog_link'])) {
        $dialogUrl = asr_tg_runtime_actions_dialog_url($botId, $subscriberId);
        if ($dialogUrl !== '') {
            $replyMarkup = json_encode(['inline_keyboard' => [[['text' => 'Открыть диалог', 'url' => $dialogUrl]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    try {
        $options = ['parse_mode' => ''];
        if ($replyMarkup !== null) $options['reply_markup'] = $replyMarkup;
        if (!function_exists('asr_tg_api_send_message')) return ['ok' => false, 'error' => 'Функция отправки Telegram-сообщений недоступна.', 'staff_user_id' => $staffUserId];
        asr_tg_api_send_message($token, (string)$recipient['_notify_chat_id'], $body, $options);
        return ['ok' => true, 'error' => '', 'staff_user_id' => $staffUserId, 'staff_label' => (string)($recipient['_notify_label'] ?? ''), 'add_dialog_link' => !empty($action['add_dialog_link'])];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'staff_user_id' => $staffUserId, 'staff_label' => (string)($recipient['_notify_label'] ?? '')];
    }
}


function asr_tg_runtime_actions_public_http_url(string $url): array {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'url' => '', 'error' => 'URL webhook не указан.'];
    if (mb_strlen($url, 'UTF-8') > 500) return ['ok' => false, 'url' => '', 'error' => 'URL webhook слишком длинный.'];
    $parts = parse_url($url);
    if (!is_array($parts)) return ['ok' => false, 'url' => '', 'error' => 'URL webhook указан некорректно.'];
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http','https'], true)) return ['ok' => false, 'url' => '', 'error' => 'Webhook поддерживает только http/https URL.'];
    $host = trim((string)($parts['host'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => '', 'error' => 'В URL webhook не указан домен.'];
    $hostLower = strtolower($host);
    if (in_array($hostLower, ['localhost'], true) || preg_match('/(^|\.)local$/i', $hostLower)) {
        return ['ok' => false, 'url' => '', 'error' => 'Webhook на локальные адреса запрещён.'];
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return ['ok' => false, 'url' => '', 'error' => 'Webhook на приватные или служебные IP-адреса запрещён.'];
        }
    }
    return ['ok' => true, 'url' => $url, 'error' => ''];
}

function asr_tg_runtime_actions_custom_payload(PDO $pdo, int $subscriberId): array {
    $out = [];
    if ($subscriberId <= 0 || !function_exists('asr_tg_custom_fields_all') || !function_exists('asr_tg_subscriber_custom_values_get')) return $out;
    try {
        $fields = asr_tg_custom_fields_all($pdo, 0, true);
        $values = asr_tg_subscriber_custom_values_get($pdo, $subscriberId);
        foreach ($fields as $field) {
            $code = trim((string)($field['code'] ?? ''));
            if ($code === '') continue;
            $fieldId = (int)($field['id'] ?? 0);
            $type = (string)($field['field_type'] ?? 'text');
            $row = $values[$fieldId] ?? [];
            $value = null;
            if ($row) {
                if ($type === 'number') {
                    $raw = $row['value_number'] ?? null;
                    $value = ($raw === null || $raw === '') ? null : (0 + $raw);
                } elseif ($type === 'date') {
                    $raw = trim((string)($row['value_date'] ?? ''));
                    $value = $raw === '' ? null : $raw;
                } elseif ($type === 'datetime') {
                    $raw = trim((string)($row['value_datetime'] ?? ''));
                    $value = $raw === '' ? null : $raw;
                } else {
                    $raw = (string)($row['value_text'] ?? '');
                    $value = $raw === '' ? null : $raw;
                }
            }
            $out[$code] = [
                'id' => $fieldId,
                'title' => trim((string)($field['title'] ?? $code)),
                'type' => in_array($type, ['text','number','date','datetime'], true) ? $type : 'text',
                'value' => $value,
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

function asr_tg_runtime_actions_tags_payload(PDO $pdo, int $subscriberId): array {
    $out = [];
    if ($subscriberId <= 0) return $out;
    try {
        $subscriberIds = asr_tg_runtime_actions_related_subscriber_ids($pdo, $subscriberId);
        if (!$subscriberIds) return [];
        $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT t.id, t.name FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_tags t ON t.id = st.tag_id WHERE st.subscriber_id IN ({$placeholders}) ORDER BY t.name ASC, t.id ASC");
        $stmt->execute($subscriberIds);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;
            $out[] = ['id' => (int)($row['id'] ?? 0), 'name' => $name];
        }
    } catch (Throwable $e) {}
    return $out;
}

function asr_tg_runtime_actions_webhook_payload(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, array $action, string $source = 'manual'): array {
    $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
    $scenarioTitle = '';
    try {
        if (function_exists('asr_tg_scenario_find')) {
            $scenario = asr_tg_scenario_find($pdo, $scenarioId);
            if (is_array($scenario)) $scenarioTitle = trim((string)($scenario['title'] ?? $scenario['name'] ?? ''));
        }
    } catch (Throwable $e) {}

    $payload = [
        'event' => 'scenario_action_webhook',
        'sent_at' => date('c'),
        'source' => $source,
        'bot' => [
            'id' => $botId,
            'title' => (string)($bot['title'] ?? ($subscriber['bot_title'] ?? '')),
            'username' => (string)($bot['bot_username'] ?? ($subscriber['bot_username'] ?? '')),
            'channel_type' => (string)($bot['channel_type'] ?? 'telegram'),
        ],
        'subscriber' => [
            'id' => $subscriberId,
            'telegram_user_id' => (int)($subscriber['telegram_user_id'] ?? 0),
            'chat_id' => (string)($subscriber['chat_id'] ?? $chatId),
            'username' => (string)($subscriber['username'] ?? ''),
            'first_name' => (string)($subscriber['first_name'] ?? ''),
            'last_name' => (string)($subscriber['last_name'] ?? ''),
            'phone' => (string)($subscriber['phone'] ?? ''),
            'email' => (string)($subscriber['email'] ?? ''),
            'status' => (string)($subscriber['status'] ?? ''),
            'subscribed_at' => (string)($subscriber['subscribed_at'] ?? $subscriber['created_at'] ?? ''),
        ],
        'scenario' => [
            'id' => $scenarioId,
            'title' => $scenarioTitle,
        ],
        'block' => [
            'id' => $blockId,
            'title' => (string)($action['_block_title'] ?? ''),
            'type' => 'actions',
        ],
        'action' => [
            'type' => 'webhook_subscriber',
            'summary' => (string)($action['summary'] ?? ''),
        ],
    ];

    if (!empty($action['include_custom_fields'])) {
        $payload['custom_fields'] = asr_tg_runtime_actions_custom_payload($pdo, $subscriberId);
    }
    if (!empty($action['include_tags'])) {
        $payload['tags'] = asr_tg_runtime_actions_tags_payload($pdo, $subscriberId);
    }
    return $payload;
}

function asr_tg_runtime_actions_post_json(string $url, array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return ['ok' => false, 'status' => 0, 'error' => 'Не удалось собрать JSON payload.', 'response' => ''];
    if (strlen($json) > 512000) return ['ok' => false, 'status' => 0, 'error' => 'Payload webhook слишком большой.', 'response' => ''];

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json, text/plain, */*',
        'User-Agent: AdminApp-ScenarioWebhook/1.0',
        'X-Admin-App-Event: scenario_action_webhook',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $snippet = mb_substr((string)$body, 0, 1000, 'UTF-8');
        if ($body === false) return ['ok' => false, 'status' => $status, 'error' => $err ?: 'Ошибка HTTP-запроса.', 'response' => $snippet];
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status), 'response' => $snippet];
    }

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ];
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) { $status = (int)$m[1]; break; }
        }
    }
    $snippet = mb_substr((string)$body, 0, 1000, 'UTF-8');
    if ($body === false) return ['ok' => false, 'status' => $status, 'error' => 'Ошибка HTTP-запроса.', 'response' => $snippet];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status), 'response' => $snippet];
}

function asr_tg_runtime_actions_send_subscriber_webhook(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, array $block, array $action, string $source = 'manual'): array {
    $urlCheck = asr_tg_runtime_actions_public_http_url((string)($action['url'] ?? ''));
    if (empty($urlCheck['ok'])) return ['ok' => false, 'status' => 0, 'url' => (string)($action['url'] ?? ''), 'error' => (string)($urlCheck['error'] ?? 'Некорректный URL webhook.'), 'response' => ''];
    $action['_block_title'] = (string)($block['title'] ?? '');
    $payload = asr_tg_runtime_actions_webhook_payload($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, $action, $source);
    $result = asr_tg_runtime_actions_post_json((string)$urlCheck['url'], $payload);
    $result['url'] = (string)$urlCheck['url'];
    $result['payload_keys'] = array_keys($payload);
    return $result;
}


function asr_tg_runtime_actions_delete_step_message_block_ids(array $action): array {
    $raw = $action['block_ids'] ?? $action['step_block_ids'] ?? [];
    if (!is_array($raw)) {
        $raw = preg_split('/[,\s]+/', (string)$raw) ?: [];
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
}

function asr_tg_runtime_actions_sent_messages_for_delete(PDO $pdo, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $blockIds): array {
    $blockIds = array_values(array_unique(array_filter(array_map('intval', $blockIds), static fn($id) => $id > 0)));
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || !$blockIds) return [];
    $chatId = trim((string)$chatId);
    $items = [];
    $seen = [];

    try {
        if (function_exists('asr_tg_repository_ensure_scenario_schema')) asr_tg_repository_ensure_scenario_schema($pdo);
        $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
        $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
        $params = [$scenarioId, $botId];
        foreach ($blockIds as $id) $params[] = $id;
        $whereSubscriber = 'subscriber_id = ?';
        $params[] = $subscriberId;
        if ($telegramUserId > 0) {
            $whereSubscriber = '(' . $whereSubscriber . ' OR telegram_user_id = ?)';
            $params[] = $telegramUserId;
        }
        if ($chatId !== '') {
            $whereSubscriber .= ' AND chat_id = ?';
            $params[] = $chatId;
        }
        $stmt = $pdo->prepare("SELECT id, scenario_id, block_id, bot_id, subscriber_id, telegram_user_id, chat_id, card_index, card_type, telegram_message_id, sent_at, deleted_at
            FROM oca_telegram_bot_scenario_sent_messages
            WHERE scenario_id = ? AND bot_id = ? AND block_id IN ({$placeholders})
              AND {$whereSubscriber}
              AND deleted_at IS NULL
            ORDER BY sent_at DESC, id DESC
            LIMIT 200");
        $stmt->execute($params);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $mid = (int)($row['telegram_message_id'] ?? 0);
            $cid = trim((string)($row['chat_id'] ?? $chatId));
            if ($mid <= 0 || $cid === '') continue;
            $key = $cid . ':' . $mid;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = [
                'source' => 'sent_messages',
                'record_id' => (int)($row['id'] ?? 0),
                'block_id' => (int)($row['block_id'] ?? 0),
                'chat_id' => $cid,
                'telegram_message_id' => $mid,
                'sent_at' => (string)($row['sent_at'] ?? ''),
                'card_index' => (int)($row['card_index'] ?? 0),
                'card_type' => (string)($row['card_type'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // Ниже попробуем fallback по журналу сообщений.
    }

    // Fallback для сообщений, отправленных до появления отдельной таблицы scenario_sent_messages.
    // В журнале oca_telegram_bot_messages уже хранился payload_json с scenario_id / block_id.
    try {
        $stmt = $pdo->prepare("SELECT id, telegram_message_id, payload_json, created_at
            FROM oca_telegram_bot_messages
            WHERE bot_id = ? AND subscriber_id = ? AND direction = 'out' AND telegram_message_id IS NOT NULL AND telegram_message_id > 0
            ORDER BY id DESC
            LIMIT 300");
        $stmt->execute([$botId, $subscriberId]);
        $blockMap = array_fill_keys($blockIds, true);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $mid = (int)($row['telegram_message_id'] ?? 0);
            if ($mid <= 0 || $chatId === '') continue;
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) continue;
            $payloadScenarioId = (int)($payload['scenario_id'] ?? 0);
            $payloadBlockId = (int)($payload['block_id'] ?? 0);
            if ($payloadScenarioId !== $scenarioId || !isset($blockMap[$payloadBlockId])) continue;
            $key = $chatId . ':' . $mid;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = [
                'source' => 'message_log',
                'record_id' => 0,
                'message_log_id' => (int)($row['id'] ?? 0),
                'block_id' => $payloadBlockId,
                'chat_id' => $chatId,
                'telegram_message_id' => $mid,
                'sent_at' => (string)($row['created_at'] ?? ''),
                'card_index' => (int)($payload['card_index'] ?? 0),
                'card_type' => (string)($payload['card_type'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // Если fallback не сработал — вернём то, что нашли в основной таблице.
    }

    usort($items, static function(array $a, array $b): int {
        return strcmp((string)($b['sent_at'] ?? ''), (string)($a['sent_at'] ?? ''));
    });
    return $items;
}

function asr_tg_runtime_actions_mark_sent_message_deleted(PDO $pdo, int $recordId, string $error = ''): void {
    if ($recordId <= 0) return;
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_sent_messages SET deleted_at = NOW(), delete_error = ? WHERE id = ?');
        $stmt->execute([$error !== '' ? $error : null, $recordId]);
    } catch (Throwable $ignored) {}
}

function asr_tg_runtime_actions_mark_sent_message_delete_error(PDO $pdo, int $recordId, string $error): void {
    if ($recordId <= 0) return;
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_sent_messages SET delete_error = ? WHERE id = ?');
        $stmt->execute([mb_substr($error, 0, 1000, 'UTF-8'), $recordId]);
    } catch (Throwable $ignored) {}
}

function asr_tg_runtime_actions_delete_step_messages(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, array $action): array {
    $blockIds = asr_tg_runtime_actions_delete_step_message_block_ids($action);
    if (!$blockIds) return ['ok' => false, 'deleted' => 0, 'failed' => 0, 'skipped_old' => 0, 'found' => 0, 'error' => 'Не выбраны шаги-сообщения для удаления.', 'items' => []];

    $token = '';
    try {
        $token = function_exists('asr_tg_decrypt_token') ? asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? '')) : '';
    } catch (Throwable $e) {
        $token = '';
    }
    if ($token === '') return ['ok' => false, 'deleted' => 0, 'failed' => 0, 'skipped_old' => 0, 'found' => 0, 'error' => 'Не удалось расшифровать токен Telegram-канала.', 'items' => []];

    $items = asr_tg_runtime_actions_sent_messages_for_delete($pdo, $botId, $chatId, $subscriberId, $scenarioId, $blockIds);
    $deleted = 0;
    $failed = 0;
    $skippedOld = 0;
    $details = [];
    $now = time();

    foreach ($items as $item) {
        $recordId = (int)($item['record_id'] ?? 0);
        $sentAt = trim((string)($item['sent_at'] ?? ''));
        $ageSeconds = 0;
        if ($sentAt !== '') {
            $ts = strtotime($sentAt);
            if ($ts !== false) $ageSeconds = max(0, $now - $ts);
        }

        // Telegram обычно не даёт удалять сообщения старше 48 часов. Не делаем заведомо бесполезный запрос,
        // но сохраняем понятную диагностику в результатах действия.
        if ($ageSeconds > 0 && $ageSeconds > 48 * 3600) {
            $skippedOld++;
            $reason = 'Сообщение старше 48 часов.';
            asr_tg_runtime_actions_mark_sent_message_delete_error($pdo, $recordId, $reason);
            $details[] = [
                'block_id' => (int)($item['block_id'] ?? 0),
                'telegram_message_id' => (int)($item['telegram_message_id'] ?? 0),
                'status' => 'skipped_old',
                'error' => $reason,
            ];
            continue;
        }

        try {
            if (!function_exists('asr_tg_api_request')) throw new RuntimeException('Функция Telegram API недоступна.');
            asr_tg_api_request($token, 'deleteMessage', [
                'chat_id' => (string)($item['chat_id'] ?? $chatId),
                'message_id' => (int)($item['telegram_message_id'] ?? 0),
            ]);
            $deleted++;
            asr_tg_runtime_actions_mark_sent_message_deleted($pdo, $recordId);
            $details[] = [
                'block_id' => (int)($item['block_id'] ?? 0),
                'telegram_message_id' => (int)($item['telegram_message_id'] ?? 0),
                'status' => 'deleted',
                'error' => '',
            ];
        } catch (Throwable $e) {
            $failed++;
            $error = $e->getMessage();
            asr_tg_runtime_actions_mark_sent_message_delete_error($pdo, $recordId, $error);
            $details[] = [
                'block_id' => (int)($item['block_id'] ?? 0),
                'telegram_message_id' => (int)($item['telegram_message_id'] ?? 0),
                'status' => 'failed',
                'error' => mb_substr($error, 0, 500, 'UTF-8'),
            ];
        }
    }

    $found = count($items);
    $ok = $found > 0 && $deleted > 0 && $failed === 0;
    $error = '';
    if ($found <= 0) $error = 'Не найдены отправленные сообщения выбранных шагов. Удалить можно только сообщения, у которых сохранён telegram_message_id.';
    elseif ($deleted <= 0 && ($failed > 0 || $skippedOld > 0)) $error = 'Не удалось удалить выбранные сообщения.';

    return [
        'ok' => $ok,
        'found' => $found,
        'deleted' => $deleted,
        'failed' => $failed,
        'skipped_old' => $skippedOld,
        'block_ids' => $blockIds,
        'error' => $error,
        'items' => array_slice($details, 0, 30),
    ];
}




function asr_tg_runtime_actions_render_external_value(PDO $pdo, int $botId, int $subscriberId, string $value): string {
    $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
    if (function_exists('asr_tg_render_subscriber_macros')) {
        try {
            return (string)asr_tg_render_subscriber_macros($pdo, $value, $subscriber);
        } catch (Throwable $e) {
            return $value;
        }
    }
    return $value;
}

function asr_tg_runtime_actions_external_url(string $url): array {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'url' => '', 'error' => 'URL внешнего запроса не указан.'];
    if (mb_strlen($url, 'UTF-8') > 1500) return ['ok' => false, 'url' => '', 'error' => 'URL внешнего запроса слишком длинный.'];
    $parts = parse_url($url);
    if (!is_array($parts)) return ['ok' => false, 'url' => '', 'error' => 'URL внешнего запроса указан некорректно.'];
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https') return ['ok' => false, 'url' => '', 'error' => 'Внешний запрос поддерживает только HTTPS URL.'];
    $host = trim((string)($parts['host'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => '', 'error' => 'В URL внешнего запроса не указан домен.'];
    $hostLower = strtolower($host);
    if (in_array($hostLower, ['localhost'], true) || preg_match('/(^|\.)local$/i', $hostLower)) {
        return ['ok' => false, 'url' => '', 'error' => 'Внешний запрос на локальные адреса запрещён.'];
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return ['ok' => false, 'url' => '', 'error' => 'Внешний запрос на приватные или служебные IP-адреса запрещён.'];
        }
    }
    return ['ok' => true, 'url' => $url, 'error' => ''];
}

function asr_tg_runtime_actions_external_headers(PDO $pdo, int $botId, int $subscriberId, array $headersRaw, string $method, string $body): array {
    $headers = [];
    $hasContentType = false;
    $hasAccept = false;
    foreach ($headersRaw as $header) {
        if (!is_array($header)) continue;
        $key = trim((string)($header['key'] ?? ''));
        $value = trim((string)($header['value'] ?? ''));
        if ($key === '' || $value === '') continue;
        if (!preg_match('/^[A-Za-z0-9\-]{1,120}$/', $key)) continue;
        $rendered = asr_tg_runtime_actions_render_external_value($pdo, $botId, $subscriberId, $value);
        $rendered = str_replace(["\r", "\n"], ' ', $rendered);
        if (strcasecmp($key, 'Content-Type') === 0) $hasContentType = true;
        if (strcasecmp($key, 'Accept') === 0) $hasAccept = true;
        $headers[] = $key . ': ' . mb_substr($rendered, 0, 1000, 'UTF-8');
        if (count($headers) >= 30) break;
    }
    if (!$hasAccept) $headers[] = 'Accept: application/json, text/plain, */*';
    if (!$hasContentType && $method !== 'GET' && trim($body) !== '') $headers[] = 'Content-Type: application/json; charset=utf-8';
    $headers[] = 'User-Agent: AdminApp-ExternalRequest/1.0';
    $headers[] = 'X-Admin-App-Event: scenario_action_external_request';
    return $headers;
}

function asr_tg_runtime_actions_http_request(string $method, string $url, array $headers, string $body): array {
    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) $method = 'GET';
    if (strlen($body) > 512000) return ['ok' => false, 'status' => 0, 'error' => 'Тело внешнего запроса слишком большое.', 'response' => ''];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HEADER => false,
        ];
        if ($method !== 'GET' && $body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $responseBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $snippet = mb_substr((string)$responseBody, 0, 2000, 'UTF-8');
        if ($responseBody === false) return ['ok' => false, 'status' => $status, 'error' => $err ?: 'Ошибка HTTP-запроса.', 'response' => $snippet];
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status), 'response' => $snippet];
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ];
    if ($method !== 'GET' && $body !== '') $opts['http']['content'] = $body;
    $context = stream_context_create($opts);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) { $status = (int)$m[1]; break; }
        }
    }
    $snippet = mb_substr((string)$responseBody, 0, 2000, 'UTF-8');
    if ($responseBody === false) return ['ok' => false, 'status' => $status, 'error' => 'Ошибка HTTP-запроса.', 'response' => $snippet];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status), 'response' => $snippet];
}


function asr_tg_runtime_actions_external_response_path_value($data, string $path, &$found = null) {
    $found = false;
    $path = trim($path);
    if ($path === '') return null;
    if ($path === '$') { $found = true; return $data; }
    if (str_starts_with($path, '$.')) $path = substr($path, 2);
    elseif (str_starts_with($path, '$')) $path = ltrim(substr($path, 1), '.');
    if ($path === '') { $found = true; return $data; }

    $tokens = [];
    foreach (explode('.', $path) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^([^\[]+)((?:\[\d+\])*)$/', $part, $m)) {
            $tokens[] = $m[1];
            if (!empty($m[2]) && preg_match_all('/\[(\d+)\]/', $m[2], $mm)) {
                foreach ($mm[1] as $idx) $tokens[] = (int)$idx;
            }
        } elseif (preg_match('/^\[(\d+)\]$/', $part, $m)) {
            $tokens[] = (int)$m[1];
        } else {
            $tokens[] = $part;
        }
    }

    $cur = $data;
    foreach ($tokens as $token) {
        if (is_array($cur)) {
            if (array_key_exists($token, $cur)) {
                $cur = $cur[$token];
                continue;
            }
            $stringToken = (string)$token;
            if (array_key_exists($stringToken, $cur)) {
                $cur = $cur[$stringToken];
                continue;
            }
        }
        return null;
    }
    $found = true;
    return $cur;
}

function asr_tg_runtime_actions_external_value_to_string($value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_scalar($value)) return (string)$value;
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '' : $json;
}

function asr_tg_runtime_actions_apply_external_mappings(PDO $pdo, int $botId, int $subscriberId, array $action, string $responseBody): array {
    $mappings = $action['mappings'] ?? [];
    if (!is_array($mappings) || !$mappings) return ['ok' => true, 'total' => 0, 'saved' => 0, 'failed' => 0, 'items' => [], 'error' => ''];
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'total' => count($mappings), 'saved' => 0, 'failed' => count($mappings), 'items' => [], 'error' => 'Ответ внешнего запроса не является JSON-объектом или JSON-массивом.'];
    }

    $items = [];
    $saved = 0;
    $failed = 0;
    foreach ($mappings as $idx => $mapping) {
        if (!is_array($mapping)) continue;
        $path = trim((string)($mapping['response_path'] ?? $mapping['path'] ?? ''));
        $target = trim((string)($mapping['target_param_key'] ?? $mapping['param_key'] ?? ''));
        if ($path === '' || $target === '') continue;
        $found = false;
        $value = asr_tg_runtime_actions_external_response_path_value($decoded, $path, $found);
        if (!$found) {
            $failed++;
            $items[] = ['index' => $idx, 'path' => $path, 'target' => $target, 'ok' => false, 'error' => 'Путь не найден в JSON-ответе.'];
            continue;
        }
        $valueString = asr_tg_runtime_actions_external_value_to_string($value);
        $result = asr_tg_runtime_actions_apply_field($pdo, $botId, $subscriberId, ['param_key' => $target, 'operation' => 'set', 'value' => $valueString]);
        if (!empty($result['ok'])) {
            $saved++;
        } else {
            $failed++;
        }
        $items[] = [
            'index' => $idx,
            'path' => $path,
            'target' => $target,
            'ok' => !empty($result['ok']),
            'value' => mb_substr($valueString, 0, 500, 'UTF-8'),
            'error' => (string)($result['error'] ?? ''),
            'affected' => (int)($result['affected'] ?? 0),
        ];
    }
    return ['ok' => $failed === 0, 'total' => count($mappings), 'saved' => $saved, 'failed' => $failed, 'items' => $items, 'error' => $failed > 0 ? 'Часть сопоставлений не удалось записать.' : ''];
}

function asr_tg_runtime_actions_send_external_request(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, array $action): array {
    $method = strtoupper(trim((string)($action['method'] ?? 'GET')));
    if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) $method = 'GET';
    $rawUrl = (string)($action['url'] ?? '');
    $url = asr_tg_runtime_actions_render_external_value($pdo, $botId, $subscriberId, $rawUrl);
    $urlCheck = asr_tg_runtime_actions_external_url($url);
    if (empty($urlCheck['ok'])) {
        return ['ok' => false, 'method' => $method, 'url' => $url, 'status' => 0, 'error' => (string)($urlCheck['error'] ?? 'Некорректный URL внешнего запроса.'), 'response' => ''];
    }

    $body = (string)($action['body'] ?? '');
    $body = asr_tg_runtime_actions_render_external_value($pdo, $botId, $subscriberId, $body);
    if ($method === 'GET') $body = '';

    $headersRaw = $action['headers'] ?? [];
    if (!is_array($headersRaw)) $headersRaw = [];
    $headers = asr_tg_runtime_actions_external_headers($pdo, $botId, $subscriberId, $headersRaw, $method, $body);

    $result = asr_tg_runtime_actions_http_request($method, (string)$urlCheck['url'], $headers, $body);
    $result['method'] = $method;
    $result['url'] = (string)$urlCheck['url'];
    $result['headers_count'] = count($headersRaw);
    $result['body_sent'] = ($method !== 'GET' && trim($body) !== '');
    $result['mapping'] = ['total' => 0, 'saved' => 0, 'failed' => 0, 'items' => [], 'error' => ''];
    if (!empty($result['ok'])) {
        $result['mapping'] = asr_tg_runtime_actions_apply_external_mappings($pdo, $botId, $subscriberId, $action, (string)($result['response'] ?? ''));
    }
    return $result;
}


function asr_tg_runtime_actions_custom_code_from_param_key(string $paramKey): string {
    $paramKey = trim($paramKey);
    if (strpos($paramKey, 'custom:') === 0) return trim(substr($paramKey, 7));
    return '';
}

function asr_tg_runtime_actions_yandex_client_id(PDO $pdo, int $subscriberId, string $paramKey): array {
    $code = asr_tg_runtime_actions_custom_code_from_param_key($paramKey);
    if ($code === '') return ['ok' => false, 'value' => '', 'error' => 'Не выбрано пользовательское поле Yandex Client ID.'];
    $field = asr_tg_runtime_actions_custom_field_by_code($pdo, $code);
    if (!$field) return ['ok' => false, 'value' => '', 'error' => 'Пользовательское поле Yandex Client ID не найдено.'];
    $fieldId = (int)($field['id'] ?? 0);
    $fieldType = (string)($field['field_type'] ?? 'text');
    if ($fieldType !== 'text') return ['ok' => false, 'value' => '', 'error' => 'Yandex Client ID должен храниться в текстовом пользовательском поле.'];
    $value = trim(asr_tg_runtime_actions_current_custom_value($pdo, $subscriberId, $fieldId, 'text'));
    return ['ok' => true, 'value' => $value, 'error' => '', 'field_id' => $fieldId, 'field_code' => $code, 'field_title' => (string)($field['title'] ?? $code)];
}

function asr_tg_runtime_actions_enqueue_yandex_metrika(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $blockId, int $actionIndex, array $action): array {
    $counterId = mb_substr(trim((string)($action['counter_id'] ?? '')), 0, 40, 'UTF-8');
    $target = mb_substr(trim((string)($action['target'] ?? '')), 0, 120, 'UTF-8');
    $token = trim((string)($action['token'] ?? ''));
    $clientParamKey = trim((string)($action['client_id_param_key'] ?? ''));

    if ($counterId === '' || $target === '' || $token === '' || $clientParamKey === '') {
        return ['ok' => false, 'queued' => false, 'skipped' => false, 'id' => 0, 'status' => 'failed', 'error' => 'Действие Яндекс.Метрики настроено не полностью.'];
    }

    $client = asr_tg_runtime_actions_yandex_client_id($pdo, $subscriberId, $clientParamKey);
    $clientId = trim((string)($client['value'] ?? ''));
    $status = $clientId !== '' ? 'pending' : 'skipped';
    $error = $clientId !== '' ? '' : ((string)($client['error'] ?? '') ?: 'У подписчика не заполнен Yandex Client ID.');

    if (!function_exists('asr_tg_yandex_metrika_event_enqueue')) {
        return ['ok' => false, 'queued' => false, 'skipped' => false, 'id' => 0, 'status' => 'failed', 'error' => 'Слой очереди Яндекс.Метрики не подключён.'];
    }

    $payload = [
        'source' => 'scenario_action',
        'bot_title' => (string)($bot['title'] ?? $bot['name'] ?? ''),
        'bot_username' => (string)($bot['bot_username'] ?? ''),
        'chat_id' => (string)$chatId,
        'client_id_param_key' => $clientParamKey,
        'client_id_field_code' => (string)($client['field_code'] ?? ''),
        'client_id_field_title' => (string)($client['field_title'] ?? ''),
        'token_present' => $token !== '',
    ];

    $queued = asr_tg_yandex_metrika_event_enqueue($pdo, [
        'bot_id' => $botId,
        'subscriber_id' => $subscriberId,
        'scenario_id' => $scenarioId,
        'block_id' => $blockId,
        'action_index' => $actionIndex,
        'counter_id' => $counterId,
        'target' => $target,
        'client_id' => $clientId,
        'status' => $status,
        'last_error' => $error,
        'payload' => $payload,
    ]);

    if (empty($queued['ok'])) {
        return ['ok' => false, 'queued' => false, 'skipped' => false, 'id' => 0, 'status' => 'failed', 'error' => (string)($queued['error'] ?? 'Не удалось поставить событие в очередь.')];
    }

    return [
        'ok' => true,
        'queued' => $status === 'pending',
        'skipped' => $status === 'skipped',
        'id' => (int)($queued['id'] ?? 0),
        'status' => $status,
        'counter_id' => $counterId,
        'target' => $target,
        'client_id' => $clientId,
        'client_id_field_title' => (string)($client['field_title'] ?? ''),
        'error' => $error,
    ];
}


function asr_tg_runtime_actions_telegram_group_chat_id(array $action): string {
    return mb_substr(trim((string)($action['chat_id'] ?? $action['group_ref'] ?? '')), 0, 190, 'UTF-8');
}

function asr_tg_runtime_actions_is_telegram_group_action(string $type): bool {
    return in_array($type, ['telegram_unban_user','telegram_kick_user','telegram_approve_join','telegram_decline_join'], true);
}

function asr_tg_runtime_actions_telegram_group_label(string $type): string {
    $map = [
        'telegram_unban_user' => 'Разблокировать пользователя',
        'telegram_kick_user' => 'Исключить из группы/канала',
        'telegram_approve_join' => 'Подтвердить заявку на вступление',
        'telegram_decline_join' => 'Отклонить заявку на вступление',
    ];
    return $map[$type] ?? 'Управление группой/каналом';
}

function asr_tg_runtime_actions_telegram_group_method(string $type): string {
    $map = [
        'telegram_unban_user' => 'unbanChatMember',
        'telegram_kick_user' => 'banChatMember',
        'telegram_approve_join' => 'approveChatJoinRequest',
        'telegram_decline_join' => 'declineChatJoinRequest',
    ];
    return $map[$type] ?? '';
}

function asr_tg_runtime_actions_telegram_group_api_call(string $token, string $method, array $payload): array {
    try {
        $response = asr_tg_api_request($token, $method, $payload);
        return [
            'ok' => !empty($response['ok']),
            'method' => $method,
            'payload' => $payload,
            'response' => $response,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'method' => $method,
            'payload' => $payload,
            'response' => null,
            'error' => $e->getMessage(),
        ];
    }
}

function asr_tg_runtime_actions_telegram_group_member_status(array $call): string {
    $result = is_array($call['response']['result'] ?? null) ? $call['response']['result'] : [];
    return (string)($result['status'] ?? '');
}

function asr_tg_runtime_actions_telegram_group_normalize_chat_id(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('#^https?://t\.me/([A-Za-z0-9_]{5,})/?$#i', $value, $m)) return '@' . $m[1];
    if (preg_match('#^t\.me/([A-Za-z0-9_]{5,})/?$#i', $value, $m)) return '@' . $m[1];
    if ($value !== '' && $value[0] !== '@' && preg_match('/^[A-Za-z0-9_]{5,}$/', $value)) return '@' . $value;
    return $value;
}

function asr_tg_runtime_actions_telegram_group_apply(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, array $action): array {
    $type = (string)($action['type'] ?? '');
    $method = asr_tg_runtime_actions_telegram_group_method($type);
    $groupChatIdRaw = asr_tg_runtime_actions_telegram_group_chat_id($action);
    $groupChatId = asr_tg_runtime_actions_telegram_group_normalize_chat_id($groupChatIdRaw);
    if ($method === '') return ['ok' => false, 'method' => '', 'chat_id' => $groupChatId, 'telegram_user_id' => 0, 'error' => 'Неизвестное действие управления группой/каналом.'];
    if ($groupChatId === '') return ['ok' => false, 'method' => $method, 'chat_id' => '', 'telegram_user_id' => 0, 'error' => 'Не указана группа или канал.'];
    if (!function_exists('asr_tg_api_request')) return ['ok' => false, 'method' => $method, 'chat_id' => $groupChatId, 'telegram_user_id' => 0, 'error' => 'Функция Telegram API недоступна.'];

    $token = trim((string)($bot['token'] ?? $bot['bot_token'] ?? ''));
    $tokenSource = $token !== '' ? 'plain_bot_array' : '';

    if ($token === '' && !empty($bot['bot_token_encrypted']) && function_exists('asr_tg_decrypt_token')) {
        try {
            $token = trim((string)asr_tg_decrypt_token((string)$bot['bot_token_encrypted']));
            if ($token !== '') $tokenSource = 'bot_array_encrypted';
        } catch (Throwable $e) {
            $token = '';
        }
    }

    // В runtime сценариев сюда иногда прилетает облегчённый массив бота без токена.
    // Для действий управления группами/каналами нужен именно реальный токен Telegram-бота,
    // поэтому при пустом токене добираем полный канал из репозитория.
    if ($token === '' && $botId > 0 && function_exists('asr_tg_bot_find')) {
        try {
            $fullBot = asr_tg_bot_find($pdo, $botId);
            if (is_array($fullBot)) {
                if (!empty($fullBot['bot_token_encrypted']) && function_exists('asr_tg_decrypt_token')) {
                    $token = trim((string)asr_tg_decrypt_token((string)$fullBot['bot_token_encrypted']));
                    if ($token !== '') $tokenSource = 'repository_encrypted';
                }
                if ($token === '') {
                    $token = trim((string)($fullBot['token'] ?? $fullBot['bot_token'] ?? ''));
                    if ($token !== '') $tokenSource = 'repository_plain';
                }
            }
        } catch (Throwable $e) {
            $token = '';
        }
    }

    if ($token === '') return ['ok' => false, 'method' => $method, 'chat_id' => $groupChatId, 'telegram_user_id' => 0, 'token_source' => $tokenSource, 'error' => 'У бота не найден или не расшифрован токен.'];

    $subscriber = asr_tg_runtime_actions_subscriber_context($pdo, $botId, $subscriberId);
    $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
    if ($telegramUserId <= 0) return ['ok' => false, 'method' => $method, 'chat_id' => $groupChatId, 'telegram_user_id' => 0, 'error' => 'У подписчика не найден Telegram user_id.'];

    $diagnostics = [
        'chat_id_raw' => $groupChatIdRaw,
        'chat_id_normalized' => $groupChatId,
        'telegram_user_id' => $telegramUserId,
        'token_source' => $tokenSource,
    ];

    $getChat = asr_tg_runtime_actions_telegram_group_api_call($token, 'getChat', ['chat_id' => $groupChatId]);
    $getMemberBefore = asr_tg_runtime_actions_telegram_group_api_call($token, 'getChatMember', ['chat_id' => $groupChatId, 'user_id' => $telegramUserId]);
    $diagnostics['get_chat'] = $getChat;
    $diagnostics['member_before'] = $getMemberBefore;
    $diagnostics['member_before_status'] = asr_tg_runtime_actions_telegram_group_member_status($getMemberBefore);

    $payload = ['chat_id' => $groupChatId, 'user_id' => $telegramUserId];
    if ($type === 'telegram_unban_user') $payload['only_if_banned'] = true;
    if ($type === 'telegram_kick_user') $payload['revoke_messages'] = !empty($action['revoke_messages']);

    $mainCall = asr_tg_runtime_actions_telegram_group_api_call($token, $method, $payload);
    $ok = !empty($mainCall['ok']);
    $error = $ok ? '' : (string)($mainCall['error'] ?: 'Telegram API вернул ошибку.');

    $getMemberAfter = asr_tg_runtime_actions_telegram_group_api_call($token, 'getChatMember', ['chat_id' => $groupChatId, 'user_id' => $telegramUserId]);
    $diagnostics['main_call'] = $mainCall;
    $diagnostics['member_after_main'] = $getMemberAfter;
    $diagnostics['member_after_main_status'] = asr_tg_runtime_actions_telegram_group_member_status($getMemberAfter);

    $unbanCall = null;
    $getMemberAfterUnban = null;
    $unbanOk = false;
    $unbanError = '';

    // В BotHelp действие «Исключить из группы/канала» фактически работает как banChatMember.
    // По умолчанию не снимаем бан сразу: для каналов это самый надёжный вариант удаления подписчика.
    if ($ok && $type === 'telegram_kick_user' && !empty($action['unban_after_kick'])) {
        $unbanPayload = ['chat_id' => $groupChatId, 'user_id' => $telegramUserId, 'only_if_banned' => true];
        $unbanCall = asr_tg_runtime_actions_telegram_group_api_call($token, 'unbanChatMember', $unbanPayload);
        $unbanOk = !empty($unbanCall['ok']);
        $unbanError = $unbanOk ? '' : (string)($unbanCall['error'] ?: 'Telegram API вернул ошибку при снятии бана.');
        $getMemberAfterUnban = asr_tg_runtime_actions_telegram_group_api_call($token, 'getChatMember', ['chat_id' => $groupChatId, 'user_id' => $telegramUserId]);
    }

    $diagnostics['unban_call'] = $unbanCall;
    $diagnostics['member_after_unban'] = $getMemberAfterUnban;
    $diagnostics['member_after_unban_status'] = $getMemberAfterUnban ? asr_tg_runtime_actions_telegram_group_member_status($getMemberAfterUnban) : '';

    return [
        'ok' => $ok,
        'method' => $method,
        'chat_id' => $groupChatId,
        'chat_id_raw' => $groupChatIdRaw,
        'telegram_user_id' => $telegramUserId,
        'token_source' => $tokenSource,
        'revoke_messages' => !empty($action['revoke_messages']),
        'unban_after_kick' => !empty($action['unban_after_kick']),
        'unban_ok' => $unbanOk,
        'unban_error' => $unbanError,
        'response' => $mainCall['response'],
        'unban_response' => $unbanCall['response'] ?? null,
        'diagnostics' => $diagnostics,
        'member_before_status' => (string)$diagnostics['member_before_status'],
        'member_after_main_status' => (string)$diagnostics['member_after_main_status'],
        'member_after_unban_status' => (string)$diagnostics['member_after_unban_status'],
        'error' => $error,
    ];
}

function asr_tg_runtime_actions_validate(array $actions): array {
    if (!$actions) return ['ok' => false, 'error' => 'В блоке «Действия» не добавлено ни одного действия.'];
    foreach ($actions as $index => $action) {
        $type = (string)($action['type'] ?? '');
        if ($type === 'add_tag' || $type === 'remove_tag') {
            $tagId = (int)($action['tag_id'] ?? 0);
            if ($tagId <= 0) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не выбран тег.'];
            continue;
        }
        if ($type === 'set_field') {
            $paramKey = trim((string)($action['param_key'] ?? ''));
            $operation = trim((string)($action['operation'] ?? 'set'));
            $value = trim((string)($action['value'] ?? ''));
            if ($paramKey === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не выбрано поле / переменная.'];
            if (!in_array($operation, ['set','clear','inc','dec'], true)) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' выбрана неизвестная операция.'];
            if ($operation !== 'clear' && $value === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не указано значение.'];
            continue;
        }
        if ($type === 'stop_scenario') {
            continue;
        }
        if ($type === 'yandex_metrika') {
            // Настройки Яндекс.Метрики валидируются ниже, без выполнения runtime на этапе проверки.
        }


        if (asr_tg_runtime_actions_is_telegram_group_action($type)) {
            if (asr_tg_runtime_actions_telegram_group_chat_id($action) === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не указана группа или канал.'];
            continue;
        }

        if ($type === 'unsubscribe_bot') {
            continue;
        }
        if ($type === 'notify_staff') {
            $staffUserId = (int)($action['staff_user_id'] ?? 0);
            $message = trim((string)($action['message'] ?? ''));
            if ($staffUserId <= 0) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не выбран сотрудник.'];
            if ($message === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не заполнен текст уведомления.'];
            continue;
        }
        if ($type === 'webhook_subscriber') {
            $urlCheck = asr_tg_runtime_actions_public_http_url((string)($action['url'] ?? ''));
            if (empty($urlCheck['ok'])) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ': ' . (string)($urlCheck['error'] ?? 'некорректный URL webhook.')];
            continue;
        }

        if ($type === 'delete_step_message') {
            if (!asr_tg_runtime_actions_delete_step_message_block_ids($action)) {
                return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не выбраны шаги-сообщения для удаления.'];
            }
            continue;
        }
        if ($type === 'external_request') {
            $method = strtoupper(trim((string)($action['method'] ?? 'GET')));
            $url = trim((string)($action['url'] ?? ''));
            if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' выбран неизвестный тип внешнего запроса.'];
            if ($url === '' || !preg_match('~^https://~i', $url)) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' укажите HTTPS URL внешнего запроса.'];
            $mappings = $action['mappings'] ?? [];
            if (is_array($mappings)) {
                foreach ($mappings as $mapping) {
                    if (!is_array($mapping)) continue;
                    $path = trim((string)($mapping['response_path'] ?? $mapping['path'] ?? ''));
                    $target = trim((string)($mapping['target_param_key'] ?? $mapping['param_key'] ?? ''));
                    if (($path === '') xor ($target === '')) return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' есть незаполненное сопоставление ответа.'];
                }
            }
            continue;
        }
        if ($type === 'yandex_metrika') {
            $counterId = trim((string)($action['counter_id'] ?? ''));
            $token = trim((string)($action['token'] ?? ''));
            $target = trim((string)($action['target'] ?? ''));
            $clientParamKey = trim((string)($action['client_id_param_key'] ?? ''));
            if ($counterId === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не указан номер счётчика Яндекс.Метрики.'];
            if ($token === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не указан OAuth-токен Яндекс.Метрики.'];
            if ($target === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не указан идентификатор цели Яндекс.Метрики.'];
            if (asr_tg_runtime_actions_custom_code_from_param_key($clientParamKey) === '') return ['ok' => false, 'error' => 'В действии №' . ($index + 1) . ' не выбрано поле с Yandex Client ID.'];
            continue;
        }
        // Остальные действия уже могут сохраняться в UI, но runtime подключаем постепенно.
    }
    return ['ok' => true, 'error' => ''];
}

function asr_tg_runtime_execute_actions_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    $settings = asr_tg_runtime_actions_settings($block);
    $actions = $settings['actions'] ?? [];
    if (!is_array($actions)) $actions = [];

    $validation = asr_tg_runtime_actions_validate($actions);
    if (empty($validation['ok'])) {
        $error = (string)($validation['error'] ?? 'Блок «Действия» настроен некорректно.');
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_invalid', $error, ['source' => $source] + $sourcePayload);
        return true;
    }

    $results = [];
    $executed = 0;
    $skipped = 0;

    foreach ($actions as $index => $action) {
        if (!is_array($action)) continue;
        $type = (string)($action['type'] ?? '');
        $summary = trim((string)($action['summary'] ?? ''));
        if ($summary === '') $summary = $type;

        if ($type === 'add_tag' || $type === 'remove_tag') {
            $mode = $type === 'add_tag' ? 'add' : 'remove';
            $result = asr_tg_runtime_actions_apply_tag($pdo, $subscriberId, (int)($action['tag_id'] ?? 0), $mode);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'tag_id' => (int)($result['tag_id'] ?? 0),
                'tag_name' => (string)($result['tag_name'] ?? ''),
                'affected' => (int)($result['affected'] ?? 0),
                'subscriber_ids' => $result['subscriber_ids'] ?? [],
                'error' => (string)($result['error'] ?? ''),
            ];
            continue;
        }

        if ($type === 'set_field') {
            $result = asr_tg_runtime_actions_apply_field($pdo, $botId, $subscriberId, $action);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'param_key' => (string)($action['param_key'] ?? ''),
                'operation' => (string)($action['operation'] ?? 'set'),
                'affected' => (int)($result['affected'] ?? 0),
                'field_id' => (int)($result['field_id'] ?? 0),
                'field_code' => (string)($result['field_code'] ?? ''),
                'field_type' => (string)($result['field_type'] ?? ''),
                'value' => (string)($result['value'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
            ];
            if (empty($result['ok'])) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_action_field_failed', 'Действие с полем не выполнено: ' . (string)($result['error'] ?? 'ошибка'), ['action_index' => $index, 'param_key' => (string)($action['param_key'] ?? ''), 'operation' => (string)($action['operation'] ?? 'set')]);
            }
            continue;
        }

        if ($type === 'notify_staff') {
            $result = asr_tg_runtime_actions_send_staff_notification($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, $action);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'staff_user_id' => (int)($result['staff_user_id'] ?? 0),
                'staff_label' => (string)($result['staff_label'] ?? ''),
                'add_dialog_link' => !empty($result['add_dialog_link']),
                'error' => (string)($result['error'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_notify_staff' : 'runtime_action_notify_staff_failed',
                !empty($result['ok']) ? 'Уведомление сотруднику отправлено.' : 'Не удалось отправить уведомление сотруднику: ' . (string)($result['error'] ?? 'ошибка'),
                ['action_index' => $index, 'staff_user_id' => (int)($result['staff_user_id'] ?? 0), 'staff_label' => (string)($result['staff_label'] ?? ''), 'source' => $source] + $sourcePayload
            );
            continue;
        }

        if ($type === 'webhook_subscriber') {
            $result = asr_tg_runtime_actions_send_subscriber_webhook($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, $block, $action, $source);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'url' => (string)($result['url'] ?? ''),
                'status' => (int)($result['status'] ?? 0),
                'error' => (string)($result['error'] ?? ''),
                'response' => (string)($result['response'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_webhook_subscriber' : 'runtime_action_webhook_subscriber_failed',
                !empty($result['ok']) ? 'Webhook с данными подписчика отправлен.' : 'Не удалось отправить webhook с данными подписчика: ' . (string)($result['error'] ?? 'ошибка'),
                ['action_index' => $index, 'url' => (string)($result['url'] ?? ''), 'status' => (int)($result['status'] ?? 0), 'response' => (string)($result['response'] ?? ''), 'source' => $source] + $sourcePayload
            );
            continue;
        }

        if (asr_tg_runtime_actions_is_telegram_group_action($type)) {
            $result = asr_tg_runtime_actions_telegram_group_apply($pdo, $bot, $botId, $chatId, $subscriberId, $action);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'method' => (string)($result['method'] ?? ''),
                'chat_id' => (string)($result['chat_id'] ?? ''),
                'telegram_user_id' => (int)($result['telegram_user_id'] ?? 0),
                'revoke_messages' => !empty($result['revoke_messages']),
                'unban_after_kick' => !empty($result['unban_after_kick']),
                'unban_ok' => !empty($result['unban_ok']),
                'unban_error' => (string)($result['unban_error'] ?? ''),
                'telegram_response' => $result['response'] ?? null,
                'telegram_unban_response' => $result['unban_response'] ?? null,
                'diagnostics' => $result['diagnostics'] ?? [],
                'member_before_status' => (string)($result['member_before_status'] ?? ''),
                'member_after_main_status' => (string)($result['member_after_main_status'] ?? ''),
                'member_after_unban_status' => (string)($result['member_after_unban_status'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_telegram_group' : 'runtime_action_telegram_group_failed',
                !empty($result['ok']) ? asr_tg_runtime_actions_telegram_group_label($type) . ' выполнено.' : asr_tg_runtime_actions_telegram_group_label($type) . ' не выполнено: ' . (string)($result['error'] ?? 'ошибка'),
                [
                    'action_index' => $index,
                    'action_type' => $type,
                    'method' => (string)($result['method'] ?? ''),
                    'chat_id' => (string)($result['chat_id'] ?? ''),
                    'telegram_user_id' => (int)($result['telegram_user_id'] ?? 0),
                    'revoke_messages' => !empty($result['revoke_messages']),
                    'unban_after_kick' => !empty($result['unban_after_kick']),
                    'unban_ok' => !empty($result['unban_ok']),
                    'unban_error' => (string)($result['unban_error'] ?? ''),
                    'telegram_response' => $result['response'] ?? null,
                    'telegram_unban_response' => $result['unban_response'] ?? null,
                    'diagnostics' => $result['diagnostics'] ?? [],
                    'member_before_status' => (string)($result['member_before_status'] ?? ''),
                    'member_after_main_status' => (string)($result['member_after_main_status'] ?? ''),
                    'member_after_unban_status' => (string)($result['member_after_unban_status'] ?? ''),
                    'source' => $source,
                ] + $sourcePayload
            );
            continue;
        }

        if ($type === 'delete_step_message') {
            $result = asr_tg_runtime_actions_delete_step_messages($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, $action);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'found' => (int)($result['found'] ?? 0),
                'deleted' => (int)($result['deleted'] ?? 0),
                'failed' => (int)($result['failed'] ?? 0),
                'skipped_old' => (int)($result['skipped_old'] ?? 0),
                'block_ids' => $result['block_ids'] ?? [],
                'error' => (string)($result['error'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_delete_step_message' : 'runtime_action_delete_step_message_failed',
                !empty($result['ok'])
                    ? 'Сообщения выбранных шагов удалены из Telegram-чата.'
                    : 'Удаление сообщений выбранных шагов выполнено не полностью: ' . (string)($result['error'] ?? 'ошибка'),
                [
                    'action_index' => $index,
                    'found' => (int)($result['found'] ?? 0),
                    'deleted' => (int)($result['deleted'] ?? 0),
                    'failed' => (int)($result['failed'] ?? 0),
                    'skipped_old' => (int)($result['skipped_old'] ?? 0),
                    'block_ids' => $result['block_ids'] ?? [],
                    'items' => $result['items'] ?? [],
                    'source' => $source,
                ] + $sourcePayload
            );
            continue;
        }


        if ($type === 'external_request') {
            $result = asr_tg_runtime_actions_send_external_request($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $blockId, $action);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'method' => (string)($result['method'] ?? ''),
                'url' => (string)($result['url'] ?? ''),
                'status' => (int)($result['status'] ?? 0),
                'body_sent' => !empty($result['body_sent']),
                'mapping_saved' => (int)($result['mapping']['saved'] ?? 0),
                'mapping_failed' => (int)($result['mapping']['failed'] ?? 0),
                'error' => (string)($result['error'] ?? ''),
                'response' => (string)($result['response'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_external_request' : 'runtime_action_external_request_failed',
                !empty($result['ok']) ? 'Внешний запрос выполнен.' : 'Не удалось выполнить внешний запрос: ' . (string)($result['error'] ?? 'ошибка'),
                [
                    'action_index' => $index,
                    'method' => (string)($result['method'] ?? ''),
                    'url' => (string)($result['url'] ?? ''),
                    'status' => (int)($result['status'] ?? 0),
                    'response' => (string)($result['response'] ?? ''),
                    'mapping' => $result['mapping'] ?? [],
                    'source' => $source,
                ] + $sourcePayload
            );
            continue;
        }


        if ($type === 'unsubscribe_bot') {
            $result = asr_tg_runtime_actions_unsubscribe_bot($pdo, $botId, $subscriberId, $scenarioId, $blockId);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'unsubscribed' => !empty($result['ok']),
                'affected' => (int)($result['affected'] ?? 0),
                'reason' => (string)($result['reason'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
            ];
            asr_tg_runtime_log_event(
                $pdo,
                $botId,
                $subscriberId,
                $scenarioId,
                $blockId,
                !empty($result['ok']) ? 'runtime_action_unsubscribe_bot' : 'runtime_action_unsubscribe_bot_failed',
                !empty($result['ok']) ? 'Подписчик отписан от бота действием блока «Действия».' : 'Не удалось отписать подписчика от бота: ' . (string)($result['error'] ?? 'ошибка'),
                ['action_index' => $index, 'affected' => (int)($result['affected'] ?? 0), 'source' => $source] + $sourcePayload
            );

            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_executed', 'Блок «Действия» выполнен: действий выполнено — ' . $executed . ', подписчик отписан от бота.', [
                'source' => $source,
                'actions_total' => count($actions),
                'executed' => $executed,
                'skipped' => $skipped,
                'unsubscribed' => !empty($result['ok']),
                'results' => $results,
            ] + $sourcePayload);

            return true;
        }

        if ($type === 'stop_scenario') {
            $result = asr_tg_runtime_actions_stop_scenario($pdo, $botId, $subscriberId, $scenarioId, $blockId);
            $executed++;
            $results[] = [
                'index' => $index,
                'type' => $type,
                'summary' => $summary,
                'ok' => !empty($result['ok']),
                'stopped' => !empty($result['ok']),
                'reason' => (string)($result['reason'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
            ];
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, !empty($result['ok']) ? 'runtime_action_stop_scenario' : 'runtime_action_stop_scenario_failed', !empty($result['ok']) ? 'Сценарий остановлен действием блока «Действия».' : 'Не удалось остановить сценарий: ' . (string)($result['error'] ?? 'ошибка'), ['action_index' => $index, 'source' => $source] + $sourcePayload);

            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_executed', 'Блок «Действия» выполнен: действий выполнено — ' . $executed . ', сценарий остановлен.', [
                'source' => $source,
                'actions_total' => count($actions),
                'executed' => $executed,
                'skipped' => $skipped,
                'stopped' => true,
                'results' => $results,
            ] + $sourcePayload);

            return true;
        }

        $skipped++;
        $results[] = [
            'index' => $index,
            'type' => $type,
            'summary' => $summary,
            'ok' => true,
            'skipped' => true,
            'reason' => 'Runtime для этого действия будет подключён отдельным этапом.',
        ];
    }

    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_executed', 'Блок «Действия» выполнен: действий выполнено — ' . $executed . ', ожидают будущих этапов — ' . $skipped . '.', [
        'source' => $source,
        'actions_total' => count($actions),
        'executed' => $executed,
        'skipped' => $skipped,
        'results' => $results,
    ] + $sourcePayload);

    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');

    $nextBlockId = asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId);
    if ($nextBlockId > 0 && $nextBlockId !== $blockId) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_next_auto', 'После блока «Действия» запускаем следующий шаг.', ['next_block_id' => $nextBlockId, 'source' => $source]);
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $nextBlockId, 'actions_next', ['from_block_id' => $blockId, 'source' => $source]);
    }

    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_actions_next_missing', 'Блок «Действия» выполнен, но следующий шаг не задан.', ['source' => $source, 'executed' => $executed, 'skipped' => $skipped]);
    return true;
}
