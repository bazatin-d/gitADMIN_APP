<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/repository.php';

function asr_tg_can(string $permission): bool {
    $key = 'telegram_bots.' . $permission;
    return function_exists('asr_user_has_permission') ? asr_user_has_permission($key) : (function_exists('isAdmin') && isAdmin());
}

function asr_tg_redirect(string $message = '', string $error = '', array $extra = []): void {
    $params = array_merge(['tab' => 'telegram_bots'], $extra);
    if ($message !== '') $params['tg_msg'] = $message;
    if ($error !== '') $params['tg_error'] = $error;
    header('Location: admin.php?' . http_build_query($params));
    exit;
}


function asr_tg_normalize_channel_type(string $type): string {
    $type = strtolower(trim($type));
    if (in_array($type, ['vk', 'vkontakte', 'vk_bot'], true)) return 'vk';
    return 'telegram';
}

function asr_tg_channel_label(string $type): string {
    return asr_tg_normalize_channel_type($type) === 'vk' ? 'ВК' : 'Telegram';
}

function asr_tg_normalize_vk_text(string $value, int $limit = 190): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    return mb_substr($value, 0, $limit, 'UTF-8');
}

function asr_tg_channel_type_of(array $bot): string {
    return asr_tg_normalize_channel_type((string)($bot['channel_type'] ?? 'telegram'));
}

function asr_tg_normalize_title(string $title): string {
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') throw new InvalidArgumentException('Укажите название бота.');
    if (mb_strlen($title, 'UTF-8') > 190) throw new InvalidArgumentException('Название бота слишком длинное. Максимум 190 символов.');
    return $title;
}

function asr_tg_normalize_token(string $token): string {
    $token = trim($token);
    if ($token === '') throw new InvalidArgumentException('Укажите токен Telegram-бота.');
    if (!preg_match('/^\d{5,20}:[A-Za-z0-9_-]{20,}$/', $token)) {
        throw new InvalidArgumentException('Токен не похож на токен Telegram Bot API. Проверьте, что скопировали его полностью из BotFather.');
    }
    return $token;
}

function asr_tg_normalize_welcome_text(string $text): string {
    $text = trim($text);
    if ($text === '') return 'Здравствуйте! Бот подключён и готов к работе.';
    if (mb_strlen($text, 'UTF-8') > 4000) throw new InvalidArgumentException('Приветственное сообщение слишком длинное. Максимум 4000 символов.');
    return $text;
}

function asr_tg_public_webhook_url(int $botId, string $secret): string {
    $path = '/telegram_bot_webhook.php?bot_id=' . $botId . '&secret=' . rawurlencode($secret);
    if (function_exists('asr_config_app_url')) {
        return asr_config_app_url($path);
    }
    $scheme = defined('APP_SCHEME') ? APP_SCHEME : 'https';
    $domain = defined('APP_DOMAIN') ? APP_DOMAIN : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($scheme . '://' . $domain, '/') . $path;
}


function asr_tg_normalize_command_name(string $command): string {
    $command = trim($command);
    $command = ltrim($command, '/');
    $command = strtolower($command);
    $command = preg_replace('/[^a-z0-9_]/', '', $command) ?: '';
    if ($command === '') return '';
    if (strlen($command) > 32) $command = substr($command, 0, 32);
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $command)) {
        throw new InvalidArgumentException('Команда «/' . $command . '» содержит недопустимые символы. Используйте латиницу, цифры и подчёркивание.');
    }
    return $command;
}

function asr_tg_build_commands_from_post(array $post): array {
    $rawCommands = $post['command'] ?? [];
    $rawDescriptions = $post['description'] ?? [];
    $rawScenarioIds = $post['scenario_id'] ?? [];
    $rawStepIds = $post['step_id'] ?? [];
    if (!is_array($rawCommands)) $rawCommands = [$rawCommands];
    if (!is_array($rawDescriptions)) $rawDescriptions = [$rawDescriptions];
    if (!is_array($rawScenarioIds)) $rawScenarioIds = [$rawScenarioIds];
    if (!is_array($rawStepIds)) $rawStepIds = [$rawStepIds];

    $items = [];
    $seen = [];
    foreach ($rawCommands as $i => $rawCommand) {
        $command = asr_tg_normalize_command_name((string)$rawCommand);
        $description = trim(preg_replace('/\s+/u', ' ', (string)($rawDescriptions[$i] ?? '')) ?: '');
        if ($command === '' && $description === '') continue;
        if ($command === '') throw new InvalidArgumentException('У одной из строк не указана команда.');
        if ($description === '') throw new InvalidArgumentException('Для команды «/' . $command . '» укажите описание.');
        if (mb_strlen($description, 'UTF-8') > 256) {
            $description = mb_substr($description, 0, 256, 'UTF-8');
        }
        if (isset($seen[$command])) {
            throw new InvalidArgumentException('Команда «/' . $command . '» указана дважды.');
        }
        $seen[$command] = true;
        $scenarioId = (int)($rawScenarioIds[$i] ?? 0);
        $stepId = (int)($rawStepIds[$i] ?? 0);
        $items[] = [
            'command' => $command,
            'description' => $description,
            'scenario_id' => $scenarioId > 0 ? $scenarioId : null,
            'step_id' => $stepId > 0 ? $stepId : null,
            'sort_order' => (count($items) + 1) * 10,
        ];
        if (count($items) >= 100) break;
    }
    return $items;
}

function asr_tg_sync_bot_commands_to_telegram(PDO $pdo, array $bot, array $commands): void {
    $botId = (int)($bot['id'] ?? 0);
    if (asr_tg_channel_type_of($bot) !== 'telegram') {
        throw new RuntimeException('Меню команд доступно только для Telegram-каналов.');
    }
    $encryptedToken = (string)($bot['bot_token_encrypted'] ?? '');
    if ($encryptedToken === '') throw new RuntimeException('У Telegram-канала не сохранён токен.');
    $token = asr_tg_decrypt_token($encryptedToken);
    $payloadCommands = [];
    foreach ($commands as $item) {
        $payloadCommands[] = [
            'command' => (string)$item['command'],
            'description' => (string)$item['description'],
        ];
    }
    asr_tg_api_request($token, 'setMyCommands', [
        'commands' => json_encode($payloadCommands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    asr_tg_log($pdo, $botId, 'info', 'bot_commands_synced', 'Меню команд отправлено в Telegram.', ['commands_count' => count($commands)]);
}

function asr_tg_save_bot_commands_from_post(PDO $pdo, array $post): array {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки меню команд.');
    $botId = (int)($post['bot_id'] ?? 0);
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Канал не найден.');
    if (asr_tg_channel_type_of($bot) !== 'telegram') throw new RuntimeException('Меню команд доступно только для Telegram-каналов.');

    $commands = asr_tg_build_commands_from_post($post);
    asr_tg_bot_commands_replace($pdo, $botId, $commands);

    try {
        asr_tg_sync_bot_commands_to_telegram($pdo, $bot, $commands);
        return ['count' => count($commands), 'warning' => ''];
    } catch (Throwable $e) {
        asr_tg_log($pdo, $botId, 'error', 'bot_commands_sync_failed', $e->getMessage(), ['commands_count' => count($commands)]);
        return ['count' => count($commands), 'warning' => 'Команды сохранены в админке, но не отправились в Telegram: ' . $e->getMessage()];
    }
}


function asr_tg_create_tag_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для управления тегами.');
    asr_tg_repository_ensure_schema($pdo);
    $botId = 0;
    $tagId = max(0, (int)($post['tag_id'] ?? 0));
    $tagName = asr_tg_tag_normalize_name((string)($post['tag_name'] ?? ''));
    if ($tagName === '') throw new RuntimeException('Укажите название тега.');

    if ($tagId > 0) {
        $current = asr_tg_tag_find($pdo, $tagId);
        if (!$current) throw new RuntimeException('Тег не найден.');
        $conflict = $pdo->prepare('SELECT id FROM oca_telegram_bot_tags WHERE bot_id = 0 AND name = ? AND id <> ? LIMIT 1');
        $conflict->execute([$tagName, $tagId]);
        if ((int)$conflict->fetchColumn() > 0) throw new RuntimeException('Тег с таким названием уже есть.');
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_tags SET name = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$tagName, $tagId]);
        asr_tg_log($pdo, $botId, 'info', 'tag_updated', 'Глобальный тег подписчиков обновлён.', ['tag_id' => $tagId]);
        return $tagId;
    }

    $tagId = asr_tg_tag_create($pdo, 0, $tagName, '#F3F4F6');
    asr_tg_log($pdo, $botId, 'info', 'tag_saved', 'Глобальный тег подписчиков сохранён.', ['tag_id' => $tagId]);
    return $tagId;
}

function asr_tg_delete_tag_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для удаления тегов.');
    $tagId = (int)($post['tag_id'] ?? 0);
    $tag = asr_tg_tag_find($pdo, $tagId);
    if (!$tag) throw new RuntimeException('Тег не найден.');
    asr_tg_tag_delete($pdo, $tagId);
    asr_tg_log($pdo, 0, 'warning', 'tag_deleted', 'Глобальный тег подписчиков удалён.', ['tag_id' => $tagId, 'tag_name' => $tag['name'] ?? '']);
}

function asr_tg_add_subscriber_tag_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для назначения тегов.');
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    $tagId = (int)($post['tag_id'] ?? 0);
    asr_tg_subscriber_tag_add($pdo, $subscriberId, $tagId);
}

function asr_tg_remove_subscriber_tag_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для снятия тегов.');
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    $tagId = (int)($post['tag_id'] ?? 0);
    asr_tg_subscriber_tag_remove($pdo, $subscriberId, $tagId);
}

function asr_tg_update_subscriber_status_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для изменения статуса подписчика.');
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    $status = (string)($post['status'] ?? 'active');
    if (!in_array($status, ['active','inactive','unsubscribed','blocked'], true)) {
        throw new InvalidArgumentException('Некорректный статус подписчика.');
    }
    asr_tg_subscriber_mark_status($pdo, $subscriberId, $status);
}



function asr_tg_save_subscriber_profile_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для редактирования подписчика.');
    $botId = (int)($post['bot_id'] ?? 0);
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    asr_tg_subscriber_profile_update($pdo, $subscriberId, $botId, $post);
    return $subscriberId;
}


function asr_tg_save_subscriber_custom_values_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для редактирования подписчика.');
    $botId = (int)($post['bot_id'] ?? 0);
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    $values = $post['custom_values'] ?? [];
    if (!is_array($values)) $values = [];
    asr_tg_subscriber_custom_values_save($pdo, $subscriberId, $botId, $values);
    return $subscriberId;
}

function asr_tg_delete_subscriber_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для удаления подписчика.');
    $botId = (int)($post['bot_id'] ?? 0);
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    asr_tg_subscribers_delete_many($pdo, $botId, [$subscriberId]);
    return $subscriberId;
}


function asr_tg_subscriber_macro_text_value($value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_scalar($value)) return trim((string)$value);
    return '';
}

function asr_tg_subscriber_macro_full_name(array $subscriber): string {
    $first = trim((string)($subscriber['first_name'] ?? ''));
    $last = trim((string)($subscriber['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    if ($name !== '') return $name;
    $username = trim((string)($subscriber['username'] ?? ''));
    if ($username !== '') return ltrim($username, '@');
    $telegramUserId = trim((string)($subscriber['telegram_user_id'] ?? ''));
    return $telegramUserId;
}

function asr_tg_format_custom_value_for_message(array $field, array $valuesMap): string {
    $fieldId = (int)($field['id'] ?? 0);
    if ($fieldId <= 0 || empty($valuesMap[$fieldId])) return '';
    $row = $valuesMap[$fieldId];
    $type = (string)($field['field_type'] ?? 'text');
    if ($type === 'number') {
        $value = $row['value_number'] ?? null;
        if ($value === null || $value === '') return '';
        return rtrim(rtrim((string)$value, '0'), '.');
    }
    if ($type === 'date') {
        $value = trim((string)($row['value_date'] ?? ''));
        if ($value === '') return '';
        $dt = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
        return $dt ? $dt->format('d.m.Y') : $value;
    }
    if ($type === 'datetime') {
        $value = trim((string)($row['value_datetime'] ?? ''));
        if ($value === '') return '';
        $normalized = str_replace('T', ' ', $value);
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', strlen($normalized) === 16 ? $normalized . ':00' : $normalized);
        return $dt ? $dt->format('d.m.Y H:i') : $value;
    }
    return trim((string)($row['value_text'] ?? ''));
}

function asr_tg_render_subscriber_macros(PDO $pdo, string $text, array $subscriber): string {
    if ($text === '' || strpos($text, '{{') === false) return $text;

    $fullName = asr_tg_subscriber_macro_full_name($subscriber);
    $base = [
        'full_name' => $fullName,
        'name' => $fullName,
        'first_name' => asr_tg_subscriber_macro_text_value($subscriber['first_name'] ?? ''),
        'last_name' => asr_tg_subscriber_macro_text_value($subscriber['last_name'] ?? ''),
        'username' => ltrim(asr_tg_subscriber_macro_text_value($subscriber['username'] ?? ''), '@'),
        'telegram_username' => ltrim(asr_tg_subscriber_macro_text_value($subscriber['username'] ?? ''), '@'),
        'telegram_user_id' => asr_tg_subscriber_macro_text_value($subscriber['telegram_user_id'] ?? ''),
        'user_id' => asr_tg_subscriber_macro_text_value($subscriber['telegram_user_id'] ?? ''),
        'chat_id' => asr_tg_subscriber_macro_text_value($subscriber['chat_id'] ?? ''),
        'email' => asr_tg_subscriber_macro_text_value($subscriber['email'] ?? ''),
        'phone' => asr_tg_subscriber_macro_text_value($subscriber['phone'] ?? ''),
        'status' => asr_tg_subscriber_macro_text_value($subscriber['status'] ?? ''),
        'bot_title' => asr_tg_subscriber_macro_text_value($subscriber['bot_title'] ?? ''),
        'bot_username' => ltrim(asr_tg_subscriber_macro_text_value($subscriber['bot_username'] ?? ''), '@'),
        'ref' => asr_tg_subscriber_macro_text_value($subscriber['ref'] ?? ''),
        'utm_source' => asr_tg_subscriber_macro_text_value($subscriber['utm_source'] ?? ''),
        'utm_medium' => asr_tg_subscriber_macro_text_value($subscriber['utm_medium'] ?? ''),
        'utm_campaign' => asr_tg_subscriber_macro_text_value($subscriber['utm_campaign'] ?? ''),
        'utm_content' => asr_tg_subscriber_macro_text_value($subscriber['utm_content'] ?? ''),
        'utm_term' => asr_tg_subscriber_macro_text_value($subscriber['utm_term'] ?? ''),
    ];

    $custom = [];
    try {
        $fields = asr_tg_custom_fields_all($pdo, 0, true);
        $valuesMap = asr_tg_subscriber_custom_values_get($pdo, (int)($subscriber['id'] ?? 0));
        foreach ($fields as $field) {
            $code = trim((string)($field['code'] ?? ''));
            if ($code === '') continue;
            $custom[$code] = asr_tg_format_custom_value_for_message($field, $valuesMap);
        }
    } catch (Throwable $e) {
        $custom = [];
    }

    return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/u', static function(array $m) use ($base, $custom): string {
        $key = trim((string)($m[1] ?? ''));
        $lower = strtolower($key);
        if (isset($base[$lower])) return (string)$base[$lower];
        if (preg_match('/^custom\.([a-zA-Z0-9_\-]+)$/', $key, $mm)) {
            $code = (string)$mm[1];
            return array_key_exists($code, $custom) ? (string)$custom[$code] : $m[0];
        }
        return $m[0];
    }, $text);
}

function asr_tg_send_subscriber_message_from_post(PDO $pdo, array $post, array $files = []): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для отправки сообщений.');
    $botId = (int)($post['bot_id'] ?? 0);
    $subscriberId = (int)($post['subscriber_id'] ?? 0);
    $originalText = trim((string)($post['message_text'] ?? ''));
    $text = $originalText;
    $attachment = asr_tg_save_chat_attachment($files['chat_attachment'] ?? []);
    $hasAttachment = !empty($attachment);
    if ($text === '' && !$hasAttachment) throw new InvalidArgumentException('Введите текст сообщения или прикрепите файл.');
    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден.');
    $text = asr_tg_render_subscriber_macros($pdo, $text, $subscriber);
    $limit = $hasAttachment ? 1024 : 4096;
    if (mb_strlen($text, 'UTF-8') > $limit) throw new InvalidArgumentException('Сообщение после подстановки переменных слишком длинное. Лимит Telegram: ' . $limit . ' символов' . ($hasAttachment ? ' для подписи к файлу.' : '.'));
    if (asr_tg_channel_type_of($subscriber) !== 'telegram') throw new RuntimeException('Ручная отправка сейчас подключена только для Telegram-каналов.');
    $token = asr_tg_decrypt_token((string)($subscriber['bot_token_encrypted'] ?? ''));
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен Telegram-канала.');
    $chatId = (string)($subscriber['chat_id'] ?? '');
    if ($chatId === '') throw new RuntimeException('У подписчика нет chat ID.');

    if ($hasAttachment) {
        $sent = asr_tg_api_send_broadcast_payload($token, $chatId, $text, '', (string)$attachment['media_type'], '', true, (string)$attachment['absolute_path']);
    } else {
        $sent = asr_tg_api_send_message($token, $chatId, $text);
    }
    $sentMessage = is_array($sent['result'] ?? null) ? $sent['result'] : [];
    $payload = $sentMessage;
    if ($originalText !== $text) {
        $payload = ['telegram' => $sentMessage, 'original_text' => $originalText];
    }
    if ($hasAttachment) {
        $payload = [
            'telegram' => $sentMessage,
            'original_text' => $originalText !== $text ? $originalText : null,
            'attachment' => [
                'media_type' => $attachment['media_type'],
                'file_name' => $attachment['file_name'],
                'file_path' => $attachment['file_path'],
                'file_url' => $attachment['file_url'],
                'mime' => $attachment['mime'],
                'size' => $attachment['size'],
            ],
        ];
    }
    asr_tg_message_add($pdo, $botId, $subscriberId, 'out', $hasAttachment ? (string)$attachment['media_type'] : 'text', $text, isset($sentMessage['message_id']) ? (int)$sentMessage['message_id'] : null, $payload);
    asr_tg_log($pdo, $botId, 'info', $hasAttachment ? 'manual_file_sent' : 'manual_message_sent', $hasAttachment ? 'Файл подписчику отправлен вручную.' : 'Сообщение подписчику отправлено вручную.', ['subscriber_id' => $subscriberId]);
    return $subscriberId;
}

function asr_tg_bulk_subscriber_ids_from_post(array $post): array {
    $ids = $post['subscriber_ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$ids) throw new InvalidArgumentException('Выберите хотя бы одного подписчика.');
    return $ids;
}

function asr_tg_bulk_add_tag_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для назначения тегов.');
    $botId = (int)($post['bot_id'] ?? 0);
    $tagId = (int)($post['tag_id'] ?? 0);
    $tag = asr_tg_tag_find($pdo, $tagId);
    if (!$tag) throw new RuntimeException('Тег не найден.');
    $count = 0;
    foreach (asr_tg_bulk_subscriber_ids_from_post($post) as $subscriberId) {
        asr_tg_subscriber_tag_add($pdo, $subscriberId, $tagId);
        $count++;
    }
    return $count;
}

function asr_tg_bulk_remove_tag_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для снятия тегов.');
    $botId = (int)($post['bot_id'] ?? 0);
    $tagId = (int)($post['tag_id'] ?? 0);
    $tag = asr_tg_tag_find($pdo, $tagId);
    if (!$tag) throw new RuntimeException('Тег не найден.');
    $count = 0;
    foreach (asr_tg_bulk_subscriber_ids_from_post($post) as $subscriberId) {
        asr_tg_subscriber_tag_remove($pdo, $subscriberId, $tagId);
        $count++;
    }
    return $count;
}

function asr_tg_bulk_delete_subscribers_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для удаления подписчиков.');
    $confirm = mb_strtolower(trim((string)($post['confirm_word'] ?? '')), 'UTF-8');
    if ($confirm !== 'удалить') {
        throw new InvalidArgumentException('Для удаления нужно ввести слово «удалить».');
    }
    return asr_tg_subscribers_delete_many($pdo, (int)($post['bot_id'] ?? 0), asr_tg_bulk_subscriber_ids_from_post($post));
}

function asr_tg_bulk_stop_scenario_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    return count(asr_tg_bulk_subscriber_ids_from_post($post));
}

function asr_tg_create_bot_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для подключения каналов.');

    $channelType = asr_tg_normalize_channel_type((string)($post['channel_type'] ?? 'telegram'));
    $title = asr_tg_normalize_title((string)($post['title'] ?? ''));
    $welcomeText = asr_tg_normalize_welcome_text((string)($post['welcome_text'] ?? ''));
    $secret = bin2hex(random_bytes(24));
    $secretToken = bin2hex(random_bytes(16));

    if ($channelType === 'vk') {
        $vkToken = trim((string)($post['vk_api_token'] ?? ''));
        $vkScreenName = asr_tg_normalize_vk_text((string)($post['vk_screen_name'] ?? ''));
        $vkGroupId = asr_tg_normalize_vk_text((string)($post['vk_group_id'] ?? ''), 80);
        $vkConfirmationCode = asr_tg_normalize_vk_text((string)($post['vk_confirmation_code'] ?? ''));
        $vkSecretKey = asr_tg_normalize_vk_text((string)($post['vk_secret_key'] ?? ''));
        $vkTokenEncrypted = null;
        if ($vkToken !== '') {
            if (!asr_tg_has_crypto_key()) throw new RuntimeException('Не настроен ключ шифрования ACCESS_VAULT_KEY.');
            $vkTokenEncrypted = asr_tg_encrypt_token($vkToken);
        }

        $botId = asr_tg_bot_create($pdo, [
            'created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            'channel_type' => 'vk',
            'title' => $title,
            'bot_username' => $vkScreenName ?: null,
            'bot_first_name' => 'ВК',
            'vk_group_id' => $vkGroupId ?: null,
            'vk_screen_name' => $vkScreenName ?: null,
            'vk_confirmation_code' => $vkConfirmationCode ?: null,
            'vk_secret_key' => $vkSecretKey ?: null,
            'vk_api_token_encrypted' => $vkTokenEncrypted,
            'bot_token_encrypted' => '',
            'webhook_secret' => $secret,
            'webhook_secret_token' => $secretToken,
            'status' => 'inactive',
            'welcome_text' => $welcomeText,
        ]);
        asr_tg_log($pdo, $botId, 'info', 'vk_channel_created', 'ВК-канал сохранён как первичная настройка. Механика webhook будет подключена отдельным шагом.');
        return $botId;
    }

    if (!asr_tg_has_crypto_key()) throw new RuntimeException('Не настроен ключ шифрования ACCESS_VAULT_KEY.');
    $token = asr_tg_normalize_token((string)($post['bot_token'] ?? ''));

    $me = asr_tg_api_get_me($token);
    if (empty($me['is_bot'])) throw new RuntimeException('Этот токен не принадлежит Telegram-боту.');

    $botId = asr_tg_bot_create($pdo, [
        'created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
        'channel_type' => 'telegram',
        'title' => $title,
        'bot_username' => (string)($me['username'] ?? ''),
        'bot_first_name' => (string)($me['first_name'] ?? ''),
        'telegram_bot_id' => (int)($me['id'] ?? 0) ?: null,
        'bot_token_encrypted' => asr_tg_encrypt_token($token),
        'webhook_secret' => $secret,
        'webhook_secret_token' => $secretToken,
        'status' => 'created',
        'welcome_text' => $welcomeText,
    ]);

    $webhookUrl = asr_tg_public_webhook_url($botId, $secret);
    asr_tg_bot_update($pdo, $botId, ['webhook_url' => $webhookUrl]);
    try {
        asr_tg_api_set_webhook($token, $webhookUrl, $secretToken);
        asr_tg_bot_update($pdo, $botId, ['status' => 'active', 'last_error' => null]);
        asr_tg_log($pdo, $botId, 'info', 'webhook_set', 'Webhook установлен при подключении бота.', ['url' => $webhookUrl]);
    } catch (Throwable $e) {
        asr_tg_bot_update($pdo, $botId, ['status' => 'webhook_error', 'last_error' => $e->getMessage()]);
        asr_tg_log($pdo, $botId, 'error', 'webhook_set_failed', $e->getMessage(), ['url' => $webhookUrl]);
        throw new RuntimeException('Бот сохранён, но webhook не установился: ' . $e->getMessage());
    }

    return $botId;
}

function asr_tg_update_bot_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для изменения каналов.');
    $botId = (int)($post['bot_id'] ?? 0);
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Канал не найден.');

    $channelType = asr_tg_channel_type_of($bot);
    $update = [
        'title' => asr_tg_normalize_title((string)($post['title'] ?? '')),
        'welcome_text' => asr_tg_normalize_welcome_text((string)($post['welcome_text'] ?? '')),
    ];

    if ($channelType === 'vk') {
        $vkToken = trim((string)($post['vk_api_token'] ?? ''));
        $vkScreenName = asr_tg_normalize_vk_text((string)($post['vk_screen_name'] ?? ''));
        $update['vk_group_id'] = asr_tg_normalize_vk_text((string)($post['vk_group_id'] ?? ''), 80) ?: null;
        $update['vk_screen_name'] = $vkScreenName ?: null;
        $update['bot_username'] = $vkScreenName ?: null;
        $update['vk_confirmation_code'] = asr_tg_normalize_vk_text((string)($post['vk_confirmation_code'] ?? '')) ?: null;
        $newVkSecretKey = asr_tg_normalize_vk_text((string)($post['vk_secret_key'] ?? ''));
        if ($newVkSecretKey !== '') {
            $update['vk_secret_key'] = $newVkSecretKey;
        }
        if ($vkToken !== '') {
            if (!asr_tg_has_crypto_key()) throw new RuntimeException('Не настроен ключ шифрования ACCESS_VAULT_KEY.');
            $update['vk_api_token_encrypted'] = asr_tg_encrypt_token($vkToken);
        }
        asr_tg_bot_update($pdo, $botId, $update);
        asr_tg_log($pdo, $botId, 'info', 'vk_channel_updated', 'Первичные настройки ВК-канала обновлены.');
        return $botId;
    }

    $newToken = trim((string)($post['bot_token'] ?? ''));
    if ($newToken !== '') {
        if (!asr_tg_has_crypto_key()) throw new RuntimeException('Не настроен ключ шифрования ACCESS_VAULT_KEY.');
        $newToken = asr_tg_normalize_token($newToken);
        $me = asr_tg_api_get_me($newToken);
        if (empty($me['is_bot'])) throw new RuntimeException('Этот токен не принадлежит Telegram-боту.');
        $update['bot_token_encrypted'] = asr_tg_encrypt_token($newToken);
        $update['bot_username'] = (string)($me['username'] ?? '');
        $update['bot_first_name'] = (string)($me['first_name'] ?? '');
        $update['telegram_bot_id'] = (int)($me['id'] ?? 0) ?: null;
    }
    asr_tg_bot_update($pdo, $botId, $update);
    asr_tg_log($pdo, $botId, 'info', 'bot_updated', 'Настройки бота обновлены.');
    return $botId;
}

function asr_tg_install_webhook(PDO $pdo, int $botId): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для управления webhook.');
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Бот не найден.');
    if (asr_tg_channel_type_of($bot) !== 'telegram') throw new RuntimeException('Webhook сейчас доступен только для Telegram-каналов.');
    $token = asr_tg_decrypt_token((string)$bot['bot_token_encrypted']);
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен бота.');
    $secret = (string)($bot['webhook_secret'] ?? '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        asr_tg_bot_update($pdo, $botId, ['webhook_secret' => $secret]);
    }
    $secretToken = (string)($bot['webhook_secret_token'] ?? '');
    if ($secretToken === '') {
        $secretToken = bin2hex(random_bytes(16));
    }
    $webhookUrl = asr_tg_public_webhook_url($botId, $secret);
    asr_tg_api_set_webhook($token, $webhookUrl, $secretToken);
    asr_tg_bot_update($pdo, $botId, ['webhook_url' => $webhookUrl, 'webhook_secret_token' => $secretToken, 'status' => 'active', 'last_error' => null]);
    asr_tg_log($pdo, $botId, 'info', 'webhook_set', 'Webhook установлен вручную.', ['url' => $webhookUrl]);
}

function asr_tg_delete_webhook(PDO $pdo, int $botId): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для управления webhook.');
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Бот не найден.');
    if (asr_tg_channel_type_of($bot) !== 'telegram') throw new RuntimeException('Webhook сейчас доступен только для Telegram-каналов.');
    $token = asr_tg_decrypt_token((string)$bot['bot_token_encrypted']);
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен бота.');
    asr_tg_api_delete_webhook($token);
    asr_tg_bot_update($pdo, $botId, ['status' => 'paused', 'last_error' => null]);
    asr_tg_log($pdo, $botId, 'info', 'webhook_deleted', 'Webhook удалён вручную.');
}



function asr_tg_disable_channel(PDO $pdo, int $botId): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для отключения каналов.');
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Канал не найден.');
    if (asr_tg_channel_type_of($bot) === 'telegram') {
        asr_tg_delete_webhook($pdo, $botId);
        return;
    }
    asr_tg_bot_update($pdo, $botId, ['status' => 'paused', 'last_error' => null]);
    asr_tg_log($pdo, $botId, 'info', 'channel_disabled', 'Канал отключён вручную.');
}

function asr_tg_enable_channel(PDO $pdo, int $botId): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для включения каналов.');
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot) throw new RuntimeException('Канал не найден.');
    if (asr_tg_channel_type_of($bot) === 'telegram') {
        asr_tg_install_webhook($pdo, $botId);
        return;
    }
    asr_tg_bot_update($pdo, $botId, ['status' => 'active', 'last_error' => null]);
    asr_tg_log($pdo, $botId, 'info', 'channel_enabled', 'ВК-канал включён вручную. Полная механика будет подключена отдельным шагом.');
}



function asr_tg_normalize_broadcast_text(string $text, string $mediaType = '', bool $required = true): string {
    $text = trim($text);
    $limit = $mediaType !== '' ? 1024 : 4096;
    if ($required && $text === '') throw new InvalidArgumentException($mediaType !== '' ? 'Укажите подпись к медиа или удалите пустую карточку.' : 'Укажите текст сообщения.');
    if (mb_strlen($text, 'UTF-8') > $limit) throw new InvalidArgumentException('Сообщение слишком длинное. Лимит Telegram: ' . $limit . ' символов' . ($mediaType !== '' ? ' для подписи к медиа.' : ' для текстового сообщения.'));
    return $text;
}

function asr_tg_normalize_parse_mode(string $parseMode): string {
    $parseMode = trim($parseMode);
    return 'HTML';
}

function asr_tg_broadcast_upload_dir(): string {
    $dir = dirname(__DIR__, 3) . '/uploads/telegram_bots/broadcasts';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать папку для медиа рассылок: ' . $dir);
        }
    }
    return $dir;
}

function asr_tg_project_root_dir(): string {
    return dirname(__DIR__, 3);
}

function asr_tg_broadcast_public_upload_url(string $filename): string {
    $path = 'uploads/telegram_bots/broadcasts/' . ltrim($filename, '/');
    if (function_exists('asr_config_app_url')) return asr_config_app_url($path);
    $scheme = defined('APP_SCHEME') ? APP_SCHEME : 'https';
    $domain = defined('APP_DOMAIN') ? APP_DOMAIN : ($_SERVER['HTTP_HOST'] ?? '');
    return rtrim($scheme . '://' . $domain, '/') . '/' . $path;
}

function asr_tg_chat_upload_dir(): string {
    $dir = dirname(__DIR__, 3) . '/uploads/telegram_bots/chats';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать папку для файлов чата: ' . $dir);
        }
    }
    return $dir;
}

function asr_tg_chat_public_upload_url(string $filename): string {
    $path = 'uploads/telegram_bots/chats/' . ltrim($filename, '/');
    if (function_exists('asr_config_app_url')) return asr_config_app_url($path);
    $scheme = defined('APP_SCHEME') ? APP_SCHEME : 'https';
    $domain = defined('APP_DOMAIN') ? APP_DOMAIN : ($_SERVER['HTTP_HOST'] ?? '');
    return rtrim($scheme . '://' . $domain, '/') . '/' . $path;
}

function asr_tg_save_chat_attachment(array $file): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_NO_FILE) return [];
        throw new RuntimeException(asr_tg_upload_error_message($error));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return [];
    $max = 45 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max) throw new RuntimeException('Файл слишком большой. Лимит для ручной отправки: 45 МБ.');
    $original = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = asr_tg_allowed_upload_extensions();
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Тип файла не разрешён. Разрешены изображения, видео, аудио и документы.');
    $mime = function_exists('mime_content_type') ? (string)mime_content_type((string)$file['tmp_name']) : '';
    asr_tg_validate_upload_mime($mime, $ext);
    $safe = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir = asr_tg_chat_upload_dir();
    $target = $dir . '/' . $safe;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('Не удалось сохранить файл чата.');
    @chmod($target, 0644);
    return [
        'media_type' => asr_tg_detect_media_type($mime, $ext),
        'file_url' => asr_tg_chat_public_upload_url($safe),
        'file_path' => 'uploads/telegram_bots/chats/' . $safe,
        'absolute_path' => $target,
        'file_name' => mb_substr($original, 0, 255),
        'mime' => $mime ?: 'application/octet-stream',
        'size' => (int)($file['size'] ?? 0),
    ];
}

function asr_tg_detect_media_type(string $mime, string $ext): string {
    $mime = strtolower($mime);
    $ext = strtolower($ext);
    if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return 'photo';
    if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4','mov','m4v','webm'], true)) return 'video';
    if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3','m4a','ogg','wav'], true)) return 'audio';
    return 'document';
}

function asr_tg_allowed_upload_extensions(): array {
    return ['jpg','jpeg','png','webp','gif','mp4','mov','m4v','webm','mp3','m4a','ogg','wav','pdf','zip','doc','docx','xls','xlsx','ppt','pptx','txt'];
}

function asr_tg_validate_upload_mime(string $mime, string $ext): void {
    $mime = strtolower(trim($mime));
    $ext = strtolower(trim($ext));
    if ($mime === '' || $mime === 'application/octet-stream') return;

    $image = ['jpg','jpeg','png','webp','gif'];
    $video = ['mp4','mov','m4v','webm'];
    $audio = ['mp3','m4a','ogg','wav'];

    if (in_array($ext, $image, true) && !str_starts_with($mime, 'image/')) {
        throw new RuntimeException('Расширение файла не совпадает с его типом. Загрузите корректное изображение.');
    }
    if (in_array($ext, $video, true) && !str_starts_with($mime, 'video/')) {
        throw new RuntimeException('Расширение файла не совпадает с его типом. Загрузите корректное видео.');
    }
    if (in_array($ext, $audio, true) && !str_starts_with($mime, 'audio/')) {
        throw new RuntimeException('Расширение файла не совпадает с его типом. Загрузите корректное аудио.');
    }
}

function asr_tg_upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл не загрузился: размер больше лимита сервера. Уменьшите файл, вставьте ссылку на медиа или увеличим upload_max_filesize/post_max_size на сервере.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
        UPLOAD_ERR_NO_TMP_DIR => 'На сервере не найдена временная папка для загрузок.',
        UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
        UPLOAD_ERR_EXTENSION => 'PHP-расширение остановило загрузку файла.',
        default => 'Файл медиа не загрузился. Код ошибки: ' . $code,
    };
}

function asr_tg_save_broadcast_media(array $file): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_NO_FILE) return [];
        throw new RuntimeException(asr_tg_upload_error_message($error));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) return [];
    $max = 45 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max) throw new RuntimeException('Файл слишком большой. На первом этапе лимит в админке: 45 МБ. Для больших видео лучше использовать ссылку на файл.');
    $original = (string)($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = asr_tg_allowed_upload_extensions();
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Тип файла не разрешён для рассылки. Разрешены изображения, видео, аудио и документы.');
    $mime = function_exists('mime_content_type') ? (string)mime_content_type((string)$file['tmp_name']) : '';
    asr_tg_validate_upload_mime($mime, $ext);
    $safe = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir = asr_tg_broadcast_upload_dir();
    $target = $dir . '/' . $safe;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('Не удалось сохранить файл медиа.');
    @chmod($target, 0644);
    return [
        'media_type' => asr_tg_detect_media_type($mime, $ext),
        'media_url' => asr_tg_broadcast_public_upload_url($safe),
        'media_file_path' => 'uploads/telegram_bots/broadcasts/' . $safe,
        'media_file_name' => mb_substr($original, 0, 255),
    ];
}

function asr_tg_file_from_multi(array $files, string $field, int $i): array {
    if (empty($files[$field]) || !is_array($files[$field])) return [];
    $f = $files[$field];
    if (is_array($f['name'] ?? null)) {
        return [
            'name' => $f['name'][$i] ?? '',
            'type' => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $f['size'][$i] ?? 0,
        ];
    }
    return $i === 0 ? $f : [];
}


function asr_tg_validate_public_http_url(string $url, string $context = 'Ссылка'): string {
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException($context . ' не указана.');
    }
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        throw new RuntimeException($context . ' содержит пробелы или служебные символы. Вставьте полный адрес без пробелов, например https://example.kz/page');
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new RuntimeException($context . ' указана некорректно. Вставьте полный адрес, например https://example.kz/page');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException($context . ' должна начинаться с http:// или https://');
    }

    $host = trim((string)($parts['host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException($context . ' должна содержать домен, например https://example.kz/page');
    }

    $hostForCheck = trim($host, '[]');
    $isIp = filter_var($hostForCheck, FILTER_VALIDATE_IP) !== false;
    if (!$isIp) {
        if (strpos($hostForCheck, '_') !== false) {
            throw new RuntimeException($context . ' содержит некорректный домен. В домене нельзя использовать подчёркивание.');
        }
        if (strpos($hostForCheck, '.') === false) {
            throw new RuntimeException($context . ' должна быть полноценным адресом с доменом, например https://example.kz/page. Адрес «' . $url . '» Telegram не принимает.');
        }
        $labels = explode('.', $hostForCheck);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9-]+$/i', $label) || $label[0] === '-' || substr($label, -1) === '-') {
                throw new RuntimeException($context . ' содержит некорректный домен. Проверьте адрес и вставьте полную ссылку.');
            }
        }
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException($context . ' указана некорректно. Вставьте полный адрес, например https://example.kz/page');
    }

    return $url;
}

function asr_tg_normalize_inline_buttons_json(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    $rows = [];
    foreach ($data as $rowIndex => $row) {
        if (!is_array($row)) continue;
        $cleanRow = [];
        foreach ($row as $btnIndex => $btn) {
            if (!is_array($btn)) continue;
            $text = trim((string)($btn['text'] ?? ''));
            $rawUrl = trim((string)($btn['url'] ?? ''));
            $type = ((string)($btn['type'] ?? 'url') === 'action') ? 'action' : 'url';

            // Пустую кнопку игнорируем, но если администратор указал текст кнопки,
            // ссылка обязательна. Иначе рассылка визуально выглядит готовой, а кнопка
            // тихо пропадает при отправке.
            if ($text === '') {
                if ($type === 'url' && $rawUrl !== '') {
                    throw new RuntimeException('У кнопки указана ссылка, но не указан текст. Заполните текст кнопки или удалите её.');
                }
                continue;
            }

            $text = mb_substr($text, 0, 64, 'UTF-8');
            $item = ['text' => $text, 'type' => $type];
            if ($type === 'url') {
                if ($rawUrl === '') {
                    throw new RuntimeException('У кнопки «' . $text . '» не указана ссылка.');
                }
                $validUrl = asr_tg_validate_public_http_url($rawUrl, 'Ссылка у кнопки «' . $text . '»');
                $item['url'] = mb_substr($validUrl, 0, 1000, 'UTF-8');
            } else {
                $item['callback_data'] = 'action_stub';
            }
            $cleanRow[] = $item;
            if (count($cleanRow) >= 4) break;
        }
        if ($cleanRow) $rows[] = $cleanRow;
        if (count($rows) >= 8) break;
    }
    return $rows;
}

function asr_tg_broadcast_card_plain_text(string $html): string {
    $text = preg_replace('/<br\s*\/?>(\s*)/i', "
", $html);
    $text = preg_replace('/<\/(p|div|blockquote|pre)>/i', "
", (string)$text);
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x{00A0}\s]+/u', ' ', (string)$text);
    return trim((string)$text);
}

function asr_tg_build_broadcast_cards(array $post, array $files = []): array {
    $types = $post['card_type'] ?? [];
    if (!is_array($types) || !$types) {
        $types = ['text'];
        $post['card_text'] = [(string)($post['message_text'] ?? '')];
        $post['card_parse_mode'] = [(string)($post['parse_mode'] ?? 'HTML')];
        $post['card_media_url'] = [(string)($post['media_url'] ?? '')];
    }
    $cards = [];
    // Важно: input type=file присутствует только у медиа-карточек.
    // Поэтому индекс файла в $_FILES не совпадает с индексом карточки, если перед медиа есть текстовые блоки.
    $mediaFileCursor = 0;
    foreach ($types as $i => $rawType) {
        $type = trim((string)$rawType);
        if ($type === 'image') $type = 'photo';
        if ($type === 'file') $type = 'document';
        if ($type === 'voice') $type = 'audio';
        if (!in_array($type, ['text','photo','video','audio','document'], true)) $type = 'text';
        $parseMode = asr_tg_normalize_parse_mode((string)($post['card_parse_mode'][$i] ?? $post['parse_mode'] ?? 'HTML'));
        $disablePreviewValues = $post['card_disable_preview'] ?? [];
        if (!is_array($disablePreviewValues)) $disablePreviewValues = [];
        $protectContentValues = $post['card_protect_content'] ?? [];
        if (!is_array($protectContentValues)) $protectContentValues = [];
        $disablePreview = in_array((string)$i, array_map('strval', $disablePreviewValues), true) || !empty($post['disable_web_page_preview']);
        $protectContent = in_array((string)$i, array_map('strval', $protectContentValues), true);
        $text = (string)($post['card_text'][$i] ?? '');
        $media = [];
        if ($type !== 'text') {
            $file = asr_tg_file_from_multi($files, 'card_media_file', $mediaFileCursor);
            $mediaFileCursor++;
            if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $media = asr_tg_save_broadcast_media($file);
                if ($media) $type = (string)($media['media_type'] ?? $type);
            }
            if (!$media) {
                $url = trim((string)($post['card_media_url'][$i] ?? ''));
                if ($url !== '') {
                    $validMediaUrl = asr_tg_validate_public_http_url($url, 'Ссылка на медиа в карточке «' . $type . '»');
                    $media = ['media_type' => $type, 'media_url' => $validMediaUrl, 'media_file_path' => null, 'media_file_name' => null];
                }
            }
            if (!$media) throw new RuntimeException('В карточке медиа выбран тип «' . $type . '», но файл или ссылка не указаны.');
            $text = asr_tg_normalize_broadcast_text($text, $type, false);
        } else {
            $text = asr_tg_normalize_broadcast_text($text, '', true);
        }
        $buttons = asr_tg_normalize_inline_buttons_json((string)($post['card_buttons_json'][$i] ?? '[]'));
        $card = [
            'type' => $type,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => $disablePreview,
            'protect_content' => $protectContent,
            'buttons' => $buttons,
        ];
        if ($media) {
            $card['media_type'] = $media['media_type'] ?? $type;
            $card['media_url'] = $media['media_url'] ?? '';
            $card['media_file_path'] = $media['media_file_path'] ?? null;
            $card['media_file_name'] = $media['media_file_name'] ?? null;
        }

        $plainText = asr_tg_broadcast_card_plain_text($text);
        if ($type === 'text' && $plainText === '') {
            throw new RuntimeException('Заполните текстовую карточку или удалите её.');
        }
        if ($type !== 'text' && !$media) {
            throw new RuntimeException('В карточке медиа выбран тип «' . $type . '», но файл или ссылка не указаны.');
        }
        if ($plainText === '' && !$media && empty($buttons)) {
            throw new RuntimeException('Карточка рассылки пустая. Добавьте текст, медиа или удалите карточку.');
        }

        $cards[] = $card;
    }
    if (!$cards) throw new RuntimeException('Добавьте хотя бы одну карточку сообщения.');
    return $cards;
}

function asr_tg_legacy_options_from_cards(array $cards): array {
    $first = $cards[0] ?? ['type'=>'text','text'=>''];
    $firstMedia = null;
    foreach ($cards as $card) {
        if (($card['type'] ?? 'text') !== 'text') { $firstMedia = $card; break; }
    }
    return [
        'message_text' => (string)($first['text'] ?? ''),
        'parse_mode' => (string)($first['parse_mode'] ?? 'HTML'),
        'media_type' => $firstMedia['media_type'] ?? $firstMedia['type'] ?? null,
        'media_url' => $firstMedia['media_url'] ?? null,
        'media_file_path' => $firstMedia['media_file_path'] ?? null,
        'media_file_name' => $firstMedia['media_file_name'] ?? null,
        'disable_web_page_preview' => !empty($first['disable_web_page_preview']),
        'payload_json' => json_encode(['cards' => $cards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}


function asr_tg_build_segment_from_array(array $data): array {
    $json = trim((string)($data['segment_filter'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $rawConditions = $decoded['conditions'] ?? [];
            if (!is_array($rawConditions)) $rawConditions = [];
            $conditions = [];
            $allowedOps = ['equals','not_equals','contains','not_contains','known','unknown','has_tag','not_has_tag','greater','less','between','after_date','before_date','has_value','empty'];
            foreach (array_slice($rawConditions, 0, 20) as $idx => $rawCondition) {
                if (!is_array($rawCondition)) continue;
                $field = trim((string)($rawCondition['field'] ?? ''));
                $op = trim((string)($rawCondition['op'] ?? ''));
                $value = trim((string)($rawCondition['value'] ?? ''));
                $value2 = trim((string)($rawCondition['value2'] ?? ''));
                $joiner = strtolower((string)($rawCondition['joiner'] ?? 'and')) === 'or' ? 'or' : 'and';
                if (!preg_match('/^(system\.full_name|subscriber\.[A-Za-z0-9_]+|bot\.(title|username|channel_type)|tag\.id|custom\.[0-9]+)$/', $field)) continue;
                if (!in_array($op, $allowedOps, true)) continue;
                if (!in_array($op, ['known','unknown','has_value','empty'], true) && $value === '') continue;
                if ($op === 'between' && $value2 === '') continue;
                $conditions[] = [
                    'field' => mb_substr($field, 0, 120),
                    'op' => $op,
                    'value' => mb_substr($value, 0, 190),
                    'value2' => mb_substr($value2, 0, 190),
                    'joiner' => $idx > 0 ? $joiner : 'and',
                ];
            }
            return ['match' => 'custom', 'conditions' => $conditions];
        }
    }

    $match = ((string)($data['segment_match'] ?? 'all') === 'any') ? 'any' : 'all';
    $fields = $data['segment_field'] ?? [];
    $ops = $data['segment_operator'] ?? [];
    $values = $data['segment_value'] ?? [];
    if (!is_array($fields)) $fields = [];
    if (!is_array($ops)) $ops = [];
    if (!is_array($values)) $values = [];
    $legacyMap = ['name'=>'system.full_name','username'=>'subscriber.username','language'=>'subscriber.language_code','subscribed_at'=>'subscriber.first_seen_at','last_contact_at'=>'subscriber.last_seen_at','status'=>'subscriber.status'];
    $legacyOpMap = ['has_value'=>'known','empty'=>'unknown','more_days'=>'more_days','less_days'=>'less_days'];
    $allowedFields = array_keys($legacyMap);
    $allowedOps = ['equals','not_equals','contains','not_contains','has_value','empty','more_days','less_days','after_date','before_date','greater','less'];
    $conditions = [];
    foreach ($fields as $i => $rawField) {
        $field = (string)$rawField;
        $op = (string)($ops[$i] ?? 'contains');
        $value = trim((string)($values[$i] ?? ''));
        if (!in_array($field, $allowedFields, true) || !in_array($op, $allowedOps, true)) continue;
        if (!in_array($op, ['has_value','empty'], true) && $value === '') continue;
        $conditions[] = ['field' => $legacyMap[$field], 'op' => $legacyOpMap[$op] ?? $op, 'value' => mb_substr($value, 0, 190), 'value2' => '', 'joiner' => count($conditions) > 0 && $match === 'any' ? 'or' : 'and'];
    }
    return ['match' => $match, 'conditions' => $conditions];
}

function asr_tg_segment_human_summary(array $segment): string {
    $count = count($segment['conditions'] ?? []);
    if ($count === 0) return 'Все активные подписчики выбранного бота';
    return ($segment['match'] ?? 'all') === 'any'
        ? 'Подписчики, соответствующие хотя бы одному условию: ' . $count
        : 'Подписчики, соответствующие каждому условию: ' . $count;
}

function asr_tg_broadcast_bot_ids_from_post(PDO $pdo, array $post): array {
    $raw = $post['bot_ids'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    $fallback = (int)($post['bot_id'] ?? 0);
    if (!$ids && $fallback > 0) $ids[] = $fallback;
    if (!$ids) throw new RuntimeException('Выберите хотя бы один канал для рассылки.');

    $bots = [];
    foreach ($ids as $botId) {
        $bot = asr_tg_bot_find($pdo, $botId);
        if (!$bot) throw new RuntimeException('Бот не найден.');
        if ((string)($bot['status'] ?? '') !== 'active') throw new RuntimeException('Бот «' . (string)($bot['title'] ?? ('#' . $botId)) . '» не активен. Сначала проверьте webhook и статус бота.');
        $bots[$botId] = $bot;
    }
    return [$ids, $bots];
}

function asr_tg_broadcast_scheduled_at_from_post(array $post): ?string {
    $sendMode = (string)($post['send_mode'] ?? 'now');
    if ($sendMode !== 'scheduled') return null;

    $date = trim((string)($post['scheduled_date'] ?? ''));
    $time = trim((string)($post['scheduled_time'] ?? ''));
    if ($date === '' || $time === '') {
        throw new RuntimeException('Укажите дату и время запланированной рассылки.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Дата запланированной рассылки указана некорректно.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new RuntimeException('Время запланированной рассылки указано некорректно.');
    }

    $tz = function_exists('asr_tg_broadcast_app_timezone') ? asr_tg_broadcast_app_timezone() : new DateTimeZone('Asia/Almaty');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time . ':00', $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt || (is_array($errors) && (($errors['warning_count'] ?? 0) + ($errors['error_count'] ?? 0)) > 0)) {
        throw new RuntimeException('Дата или время запланированной рассылки указаны некорректно.');
    }
    $now = new DateTimeImmutable('now', $tz);
    if ($dt->getTimestamp() <= $now->getTimestamp()) {
        throw new RuntimeException('Нельзя запланировать рассылку на прошедшее время.');
    }
    return $dt->format('Y-m-d H:i:s');
}

function asr_tg_create_single_broadcast_from_cards(PDO $pdo, int $botId, string $title, array $cards, array $segment, string $status = 'queued', ?string $scheduledAt = null): int {
    $legacy = asr_tg_legacy_options_from_cards($cards);
    $segmentJson = json_encode($segment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return asr_tg_broadcast_add($pdo, $botId, $title, $legacy['message_text'], (int)($_SESSION['user_id'] ?? 0), [
        'parse_mode' => $legacy['parse_mode'],
        'media_type' => $legacy['media_type'],
        'media_url' => $legacy['media_url'],
        'media_file_path' => $legacy['media_file_path'],
        'media_file_name' => $legacy['media_file_name'],
        'payload_json' => $legacy['payload_json'],
        'segment_json' => $segmentJson,
        'disable_web_page_preview' => $legacy['disable_web_page_preview'],
        'status' => $status,
        'scheduled_at' => $scheduledAt,
    ]);
}


function asr_tg_broadcast_test_bot_config(): array {
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    $token = trim((string)($settings['telegram_broadcast_test_bot_token'] ?? (function_exists('asr_get_setting') ? asr_get_setting('telegram_broadcast_test_bot_token', '') : '')));
    $username = trim((string)($settings['telegram_broadcast_test_bot_username'] ?? (function_exists('asr_get_setting') ? asr_get_setting('telegram_broadcast_test_bot_username', '') : '')));
    $secret = trim((string)($settings['telegram_broadcast_test_webhook_secret'] ?? (function_exists('asr_get_setting') ? asr_get_setting('telegram_broadcast_test_webhook_secret', '') : '')));
    return ['bot_token' => $token, 'bot_username' => ltrim($username, '@'), 'webhook_secret' => $secret];
}


function asr_tg_broadcast_test_ensure_user_schema(PDO $pdo): void {
    try {
        $usersRepo = dirname(__DIR__) . '/users/repository.php';
        if (is_file($usersRepo)) {
            require_once $usersRepo;
            if (function_exists('asr_users_repository_ensure_schema')) {
                asr_users_repository_ensure_schema($pdo);
                return;
            }
        }
    } catch (Throwable $e) {}
    foreach ([
        'telegram_broadcast_test_receive_broadcasts' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_receive_broadcasts` TINYINT(1) NOT NULL DEFAULT 1",
        'telegram_broadcast_test_notify_dialogs' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_notify_dialogs` TINYINT(1) NOT NULL DEFAULT 0",
    ] as $column => $sql) {
        $exists = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', $column)
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', $column));
        if (!$exists) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
    }
}

function asr_tg_broadcast_test_recipients(PDO $pdo): array {
    asr_tg_broadcast_test_ensure_user_schema($pdo);

    $hasChat = function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'telegram_broadcast_test_chat_id')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_chat_id'));
    if (!$hasChat) return [];

    $where = ["COALESCE(telegram_broadcast_test_chat_id, '') <> ''"];
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_receive_broadcasts')) {
        $where[] = 'COALESCE(telegram_broadcast_test_receive_broadcasts, 1) = 1';
    }
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'is_active')) {
        $where[] = 'COALESCE(is_active, 1) = 1';
    }
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_at')) {
        $where[] = 'archived_at IS NULL';
    }
    $sql = 'SELECT id, full_name, username, telegram_broadcast_test_chat_id, telegram_broadcast_test_username FROM oca_users WHERE ' . implode(' AND ', $where) . ' ORDER BY full_name ASC, username ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function asr_tg_broadcast_test_macro_context(array $user, array $bot): array {
    $fullName = trim((string)($user['full_name'] ?? '')) ?: trim((string)($user['username'] ?? ''));
    $parts = preg_split('/\s+/u', $fullName, 2) ?: [];
    return [
        'id' => (int)($user['id'] ?? 0),
        'first_name' => (string)($parts[0] ?? $fullName),
        'last_name' => (string)($parts[1] ?? ''),
        'username' => (string)($user['telegram_broadcast_test_username'] ?? ''),
        'telegram_user_id' => '',
        'chat_id' => (string)($user['telegram_broadcast_test_chat_id'] ?? ''),
        'email' => (string)($user['username'] ?? ''),
        'phone' => '',
        'status' => 'test',
        'bot_title' => (string)($bot['title'] ?? 'Тестовая рассылка'),
        'bot_username' => (string)($bot['bot_username'] ?? ''),
        'ref' => '',
        'utm_source' => '',
        'utm_medium' => '',
        'utm_campaign' => '',
        'utm_content' => '',
        'utm_term' => '',
    ];
}


function asr_tg_render_broadcast_test_macros(string $text, array $ctx): string {
    if ($text === '' || strpos($text, '{{') === false) return $text;
    $fullName = trim(((string)($ctx['first_name'] ?? '')) . ' ' . ((string)($ctx['last_name'] ?? '')));
    $base = [
        'full_name' => $fullName,
        'name' => $fullName,
        'first_name' => (string)($ctx['first_name'] ?? ''),
        'last_name' => (string)($ctx['last_name'] ?? ''),
        'username' => ltrim((string)($ctx['username'] ?? ''), '@'),
        'telegram_username' => ltrim((string)($ctx['username'] ?? ''), '@'),
        'telegram_user_id' => (string)($ctx['telegram_user_id'] ?? ''),
        'user_id' => (string)($ctx['telegram_user_id'] ?? ''),
        'chat_id' => (string)($ctx['chat_id'] ?? ''),
        'email' => (string)($ctx['email'] ?? ''),
        'phone' => (string)($ctx['phone'] ?? ''),
        'status' => (string)($ctx['status'] ?? ''),
        'bot_title' => (string)($ctx['bot_title'] ?? ''),
        'bot_username' => ltrim((string)($ctx['bot_username'] ?? ''), '@'),
    ];
    return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/u', static function(array $m) use ($base): string {
        $key = strtolower(trim((string)($m[1] ?? '')));
        return array_key_exists($key, $base) ? (string)$base[$key] : $m[0];
    }, $text);
}

function asr_tg_send_broadcast_test_from_post(PDO $pdo, array $post, array $files = []): array {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для проверки рассылок.');
    asr_tg_repository_ensure_schema($pdo);

    $cfg = asr_tg_broadcast_test_bot_config();
    $token = trim((string)($cfg['bot_token'] ?? ''));
    if ($token === '') throw new RuntimeException('В настройках не указан токен технического бота для проверки рассылок.');

    $recipients = asr_tg_broadcast_test_recipients($pdo);
    if (!$recipients) throw new RuntimeException('Нет сотрудников с подключённым тестовым ботом рассылок. Откройте карточку сотрудника в «Наша команда», скопируйте персональную ссылку из блока «Тестовый бот рассылок» и нажмите Start. Если бот уже открыт без ссылки, отправьте /start ещё раз по персональной ссылке.');

    [$botIds, $botsById] = asr_tg_broadcast_bot_ids_from_post($pdo, $post);
    $primaryBot = [];
    if ($botIds) {
        $primaryBot = $botsById[(int)$botIds[0]] ?? [];
    }

    $title = trim((string)($post['broadcast_title'] ?? 'Тестовая рассылка'));
    if ($title === '') $title = 'Тестовая рассылка';
    $cards = asr_tg_build_broadcast_cards($post, $files);
    if (!$cards) throw new RuntimeException('Добавьте хотя бы одну карточку сообщения.');

    $sent = 0;
    $failed = 0;
    $errors = [];
    foreach ($recipients as $recipient) {
        $chatId = trim((string)($recipient['telegram_broadcast_test_chat_id'] ?? ''));
        if ($chatId === '') continue;
        try {
            asr_tg_api_send_message($token, $chatId, "🧪 Тестовая проверка рассылки\n" . $title, ['parse_mode' => '']);
            $macroContext = asr_tg_broadcast_test_macro_context($recipient, $primaryBot);
            foreach ($cards as $card) {
                if (isset($card['text'])) {
                    $card['text'] = asr_tg_render_broadcast_test_macros((string)$card['text'], $macroContext);
                }
                asr_tg_api_send_broadcast_card($token, $chatId, $card);
                usleep(120000);
            }
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $label = trim((string)($recipient['full_name'] ?? '')) ?: (string)($recipient['username'] ?? ('user #' . (int)($recipient['id'] ?? 0)));
            $errors[] = $label . ': ' . $e->getMessage();
        }
    }

    if ($sent <= 0 && $failed > 0) {
        throw new RuntimeException('Тестовая рассылка не отправлена. ' . mb_substr(implode('; ', $errors), 0, 500));
    }

    return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors, 'recipients' => count($recipients)];
}

function asr_tg_create_broadcasts_from_post(PDO $pdo, array $post, array $files = []): array {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для создания рассылок.');

    [$botIds, $botsById] = asr_tg_broadcast_bot_ids_from_post($pdo, $post);
    $allowDuplicates = !empty($post['broadcast_allow_duplicates']);
    $scheduledAt = asr_tg_broadcast_scheduled_at_from_post($post);
    $initialStatus = $scheduledAt ? 'scheduled' : 'queued';

    $title = trim((string)($post['broadcast_title'] ?? ''));
    if ($title === '') $title = 'Рассылка от ' . date('d.m.Y H:i');
    $title = mb_substr($title, 0, 190);

    $cards = asr_tg_build_broadcast_cards($post, $files);
    $segment = asr_tg_build_segment_from_array($post);
    $multiBot = count($botIds) > 1;

    $broadcastIdsByBot = [];
    foreach ($botIds as $botId) {
        $botTitle = trim((string)($botsById[$botId]['title'] ?? ('#' . $botId)));
        $broadcastTitle = $multiBot ? mb_substr($title . ' / ' . $botTitle, 0, 190) : $title;
        $broadcastIdsByBot[$botId] = asr_tg_create_single_broadcast_from_cards($pdo, $botId, $broadcastTitle, $cards, $segment, $initialStatus, $scheduledAt);
    }

    $queueResult = asr_tg_broadcast_queue_recipients_for_group($pdo, $broadcastIdsByBot, $botIds, $segment, $allowDuplicates, $scheduledAt);
    $countsByBot = is_array($queueResult['counts_by_bot'] ?? null) ? $queueResult['counts_by_bot'] : [];
    $total = (int)($queueResult['total'] ?? 0);

    foreach ($broadcastIdsByBot as $botId => $broadcastId) {
        $count = (int)($countsByBot[$botId] ?? 0);
        if ($count <= 0) {
            // Это не ошибка отправки: канал просто не дал получателей по выбранным условиям.
            // Для мультиканального отчёта такая запись должна быть пропуском,
            // иначе один пустой канал красит всю группу как ошибочную.
            asr_tg_broadcast_update_result($pdo, $broadcastId, [
                'status' => 'skipped',
                'total_recipients' => 0,
                'last_error' => 'Канал пропущен: нет подходящих активных подписчиков.',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            continue;
        }
        asr_tg_broadcast_update_result($pdo, $broadcastId, [
            'status' => $initialStatus,
            'scheduled_at' => $scheduledAt,
            'total_recipients' => $count,
            'queued_at' => $scheduledAt ? null : date('Y-m-d H:i:s'),
        ]);
        asr_tg_log($pdo, $botId, 'info', $scheduledAt ? 'broadcast_scheduled' : 'broadcast_queued', $scheduledAt ? 'Рассылка запланирована.' : 'Рассылка поставлена в очередь.', [
            'broadcast_id' => $broadcastId,
            'recipients' => $count,
            'cards' => count($cards),
            'segment' => asr_tg_segment_human_summary($segment),
            'multi_bot' => $multiBot,
            'allow_duplicates' => $allowDuplicates,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    if ($total <= 0) {
        throw new RuntimeException('В выбранных каналах нет подходящих активных подписчиков. Рассылка не поставлена в очередь.');
    }

    $primaryId = 0;
    foreach ($broadcastIdsByBot as $botId => $broadcastId) {
        if ((int)($countsByBot[$botId] ?? 0) > 0) { $primaryId = (int)$broadcastId; break; }
    }
    if ($primaryId <= 0) $primaryId = (int)reset($broadcastIdsByBot);

    return [
        'ids' => array_values(array_map('intval', $broadcastIdsByBot)),
        'ids_by_bot' => $broadcastIdsByBot,
        'primary_id' => $primaryId,
        'primary_bot_id' => (int)array_search($primaryId, $broadcastIdsByBot, true),
        'total' => $total,
        'bot_count' => count($botIds),
        'allow_duplicates' => $allowDuplicates,
        'scheduled' => $scheduledAt !== null,
        'scheduled_at' => $scheduledAt,
    ];
}

function asr_tg_create_broadcast_from_post(PDO $pdo, array $post, array $files = []): int {
    $result = asr_tg_create_broadcasts_from_post($pdo, $post, $files);
    return (int)($result['primary_id'] ?? 0);
}

function asr_tg_cards_from_broadcast_item(array $item): array {
    $payload = json_decode((string)($item['payload_json'] ?? ''), true);
    $cards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
    if (!$cards) {
        $cards = [[
            'type' => !empty($item['media_type']) ? (string)$item['media_type'] : 'text',
            'text' => (string)($item['message_text'] ?? ''),
            'parse_mode' => (string)($item['parse_mode'] ?? 'HTML'),
            'media_url' => (string)($item['media_url'] ?? ''),
            'media_file_path' => $item['media_file_path'] ?? null,
            'media_file_name' => $item['media_file_name'] ?? null,
            'disable_web_page_preview' => !empty($item['disable_web_page_preview']),
        ]];
    }
    foreach ($cards as &$card) {
        $relative = (string)($card['media_file_path'] ?? '');
        if ($relative !== '') {
            $absolute = asr_tg_project_root_dir() . '/' . ltrim($relative, '/');
            if (is_file($absolute)) $card['local_file_path'] = $absolute;
        }
        if (($card['type'] ?? '') === 'gallery' && is_array($card['gallery_items'] ?? null)) {
            foreach ($card['gallery_items'] as &$galleryItem) {
                if (!is_array($galleryItem)) continue;
                $galleryRelative = (string)($galleryItem['media_file_path'] ?? '');
                if ($galleryRelative !== '') {
                    $galleryAbsolute = asr_tg_project_root_dir() . '/' . ltrim($galleryRelative, '/');
                    if (is_file($galleryAbsolute)) $galleryItem['local_file_path'] = $galleryAbsolute;
                }
            }
            unset($galleryItem);
        }
    }
    unset($card);
    return $cards;
}

function asr_tg_process_broadcast_queue(PDO $pdo, int $limit = 30, int $broadcastId = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    asr_tg_broadcast_activate_due_scheduled($pdo, 200);
    asr_tg_broadcast_reset_stale_processing($pdo, $broadcastId, 10);
    $items = asr_tg_broadcast_recipients_next($pdo, $limit, $broadcastId);
    $sent = 0; $failed = 0; $processedBroadcasts = [];
    foreach ($items as $item) {
        $recipientId = (int)$item['id'];
        $bid = (int)$item['broadcast_id'];
        $processedBroadcasts[$bid] = true;
        asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'processing', 'processing_at' => date('Y-m-d H:i:s'), 'attempts' => ((int)$item['attempts']) + 1]);
        $token = asr_tg_decrypt_token((string)$item['bot_token_encrypted']);
        try {
            if ($token === '') throw new RuntimeException('Не удалось расшифровать токен бота.');
            $cards = asr_tg_cards_from_broadcast_item($item);
            $subscriber = asr_tg_subscriber_find($pdo, (int)$item['subscriber_id'], (int)$item['bot_id']);
            if ($subscriber) {
                foreach ($cards as &$cardForMacros) {
                    if (isset($cardForMacros['text'])) {
                        $cardForMacros['original_text'] = (string)$cardForMacros['text'];
                        $cardForMacros['text'] = asr_tg_render_subscriber_macros($pdo, (string)$cardForMacros['text'], $subscriber);
                    }
                }
                unset($cardForMacros);
            }
            $lastMessageId = null;
            $sentTelegram = [];
            foreach ($cards as $cardIndex => $card) {
                $response = asr_tg_api_send_broadcast_card($token, (string)$item['chat_id'], $card);
                $sentMessage = is_array($response['result'] ?? null) ? $response['result'] : [];
                $messageId = isset($sentMessage['message_id']) ? (int)$sentMessage['message_id'] : null;
                $lastMessageId = $messageId ?: $lastMessageId;
                $sentTelegram[] = $sentMessage;
                $messagePayload = ['broadcast_id' => $bid, 'card_index' => $cardIndex, 'telegram' => $sentMessage];
                if (isset($card['original_text']) && (string)$card['original_text'] !== (string)($card['text'] ?? '')) $messagePayload['original_text'] = (string)$card['original_text'];
                asr_tg_message_add($pdo, (int)$item['bot_id'], (int)$item['subscriber_id'], 'out', (string)($card['type'] ?? 'text'), (string)($card['text'] ?? ''), $messageId, $messagePayload);
                usleep(120000);
            }
            asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'sent', 'telegram_message_id' => $lastMessageId, 'last_error' => null, 'sent_at' => date('Y-m-d H:i:s')]);
            $sent++;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $retryAfter = 0;
            if (preg_match('/retry after (\d+)/i', $error, $m)) $retryAfter = (int)$m[1];
            $attempts = ((int)$item['attempts']) + 1;
            $temporary = stripos($error, 'Too Many Requests') !== false || stripos($error, '429') !== false || stripos($error, 'timed out') !== false || stripos($error, 'temporarily') !== false;
            if ($temporary && $attempts < 5) {
                $delay = max($retryAfter, min(300, 30 * $attempts));
                asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'pending', 'last_error' => $error, 'scheduled_at' => date('Y-m-d H:i:s', time() + $delay)]);
            } else {
                asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'failed', 'last_error' => $error]);
                $failed++;
                if (stripos($error, 'bot was blocked') !== false || stripos($error, 'chat not found') !== false || stripos($error, 'user is deactivated') !== false) {
                    asr_tg_subscriber_mark_status($pdo, (int)$item['subscriber_id'], 'blocked');
                }
            }
            asr_tg_log($pdo, (int)$item['bot_id'], 'error', 'broadcast_queue_send_failed', $error, ['broadcast_id' => $bid, 'recipient_id' => $recipientId]);
        }
        usleep(50000);
    }
    foreach (array_keys($processedBroadcasts) as $bid) {
        asr_tg_broadcast_recalc($pdo, (int)$bid);
    }
    return ['processed' => count($items), 'sent' => $sent, 'failed' => $failed, 'broadcasts' => array_keys($processedBroadcasts)];
}

function asr_tg_cancel_broadcast(PDO $pdo, int $broadcastId): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для отмены рассылок.');
    $broadcast = asr_tg_broadcast_find($pdo, $broadcastId);
    if (!$broadcast) throw new RuntimeException('Рассылка не найдена.');
    $status = (string)($broadcast['status'] ?? '');
    if (in_array($status, ['finished', 'finished_with_errors', 'skipped', 'cancelled'], true)) {
        throw new RuntimeException('Эту рассылку уже нельзя отменить.');
    }
    $pdo->prepare("UPDATE oca_telegram_bot_broadcast_recipients SET status = 'skipped', updated_at = NOW() WHERE broadcast_id = ? AND status IN ('pending','processing')")->execute([$broadcastId]);
    asr_tg_broadcast_update_result($pdo, $broadcastId, [
        'status' => 'cancelled',
        'cancelled_at' => date('Y-m-d H:i:s'),
        'last_error' => null,
    ]);
    asr_tg_log($pdo, (int)$broadcast['bot_id'], 'warning', 'broadcast_cancelled', 'Рассылка отменена вручную.', ['broadcast_id' => $broadcastId]);
}

function asr_tg_cancel_scheduled_broadcast_group(PDO $pdo, int $broadcastId, array $explicitIds = []): array {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для отмены рассылок.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $explicitIds), static fn($id) => $id > 0)));
    if (!$ids) $ids = asr_tg_broadcast_scheduled_group_ids($pdo, $broadcastId);
    if (!$ids && $broadcastId > 0) $ids = [$broadcastId];

    $cancelled = [];
    foreach ($ids as $id) {
        $broadcast = asr_tg_broadcast_find($pdo, (int)$id);
        if (!$broadcast || (string)($broadcast['status'] ?? '') !== 'scheduled') continue;
        $pdo->prepare("UPDATE oca_telegram_bot_broadcast_recipients SET status = 'skipped', updated_at = NOW() WHERE broadcast_id = ? AND status IN ('pending','processing')")->execute([(int)$id]);
        asr_tg_broadcast_update_result($pdo, (int)$id, [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ]);
        asr_tg_log($pdo, (int)$broadcast['bot_id'], 'warning', 'broadcast_scheduled_cancelled', 'Запланированная рассылка отменена до старта.', ['broadcast_id' => (int)$id]);
        $cancelled[] = (int)$id;
    }
    if (!$cancelled) throw new RuntimeException('Нет запланированных рассылок, которые можно отменить.');
    return $cancelled;
}

function asr_tg_send_dialog_auto_reply_if_needed(PDO $pdo, array $bot, int $botId, int $subscriberId, $chatId, ?string $incomingText): void {
    if ($botId <= 0 || $subscriberId <= 0 || (string)$chatId === '') return;

    $trimmedIncoming = trim((string)$incomingText);
    if ($trimmedIncoming !== '' && strpos($trimmedIncoming, '/') === 0) {
        // Команды Telegram не автоотвечаем, чтобы не дублировать /start и будущие команды меню.
        return;
    }

    $settings = asr_tg_dialog_settings_get($pdo, $botId);
    if (empty($settings['auto_reply_enabled'])) return;

    $replyText = trim((string)($settings['auto_reply_text'] ?? ''));
    if ($replyText === '') return;
    if (mb_strlen($replyText, 'UTF-8') > 4096) {
        $replyText = mb_substr($replyText, 0, 4096, 'UTF-8');
    }

    $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
    if ($token === '') {
        asr_tg_log($pdo, $botId, 'warning', 'dialog_auto_reply_skipped', 'Автоответ не отправлен: не удалось расшифровать токен Telegram.', ['subscriber_id' => $subscriberId]);
        return;
    }

    try {
        $sent = asr_tg_api_send_message($token, (string)$chatId, $replyText);
        $sentMessage = is_array($sent['result'] ?? null) ? $sent['result'] : [];
        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', 'text', $replyText, isset($sentMessage['message_id']) ? (int)$sentMessage['message_id'] : null, [
            'dialog_auto_reply' => true,
            'telegram' => $sentMessage,
        ]);
        asr_tg_log($pdo, $botId, 'info', 'dialog_auto_reply_sent', 'Автоответ в диалоге отправлен.', ['subscriber_id' => $subscriberId]);
    } catch (Throwable $e) {
        asr_tg_bot_update($pdo, $botId, ['last_error' => $e->getMessage()]);
        asr_tg_log($pdo, $botId, 'error', 'dialog_auto_reply_failed', $e->getMessage(), ['subscriber_id' => $subscriberId]);
    }
}


function asr_tg_dialog_technical_recipients(PDO $pdo): array {
    asr_tg_broadcast_test_ensure_user_schema($pdo);
    $hasChat = function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'telegram_broadcast_test_chat_id')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_chat_id'));
    if (!$hasChat) return [];
    $where = ["COALESCE(telegram_broadcast_test_chat_id, '') <> ''"];
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_notify_dialogs')) {
        $where[] = 'COALESCE(telegram_broadcast_test_notify_dialogs, 0) = 1';
    } else {
        return [];
    }
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'is_active')) $where[] = 'COALESCE(is_active, 1) = 1';
    if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_at')) $where[] = 'archived_at IS NULL';
    $sql = 'SELECT id, full_name, username, telegram_broadcast_test_chat_id FROM oca_users WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function asr_tg_notify_technical_bot_about_dialog(PDO $pdo, array $bot, int $botId, int $subscriberId, array $message, ?string $text): void {
    if ($botId <= 0 || $subscriberId <= 0) return;
    $cfg = asr_tg_broadcast_test_bot_config();
    $token = trim((string)($cfg['bot_token'] ?? ''));
    if ($token === '') return;
    $recipients = asr_tg_dialog_technical_recipients($pdo);
    if (!$recipients) return;

    try {
        $stmt = $pdo->prepare('SELECT s.*, b.title AS bot_title, b.bot_username FROM oca_telegram_bot_subscribers s LEFT JOIN oca_telegram_bots b ON b.id = s.bot_id WHERE s.id = ? AND s.bot_id = ? LIMIT 1');
        $stmt->execute([$subscriberId, $botId]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $subscriber = []; }

    $name = trim(trim((string)($subscriber['first_name'] ?? '')) . ' ' . trim((string)($subscriber['last_name'] ?? '')));
    if ($name === '') $name = trim((string)($subscriber['username'] ?? ''));
    if ($name === '') $name = 'Подписчик #' . $subscriberId;
    $username = trim((string)($subscriber['username'] ?? ''));
    if ($username !== '') $username = '@' . ltrim($username, '@');
    $channel = trim((string)($subscriber['bot_title'] ?? ($bot['title'] ?? 'Канал')));
    $messageType = isset($message['text']) ? 'текст' : 'сообщение';
    $preview = trim((string)($text ?? ''));
    if ($preview === '') $preview = '[' . $messageType . ']';
    $preview = mb_substr($preview, 0, 900, 'UTF-8');
    $urlBase = function_exists('asr_current_base_url') ? rtrim(asr_current_base_url(), '/') : '';
    $dialogUrl = $urlBase !== '' ? ($urlBase . '/admin.php?tab=telegram_bots&page=messages&dialog_view=new&bot_id=' . $botId . '&subscriber_id=' . $subscriberId) : '';

    $body = "🔔 Новое сообщение в диалоге

";
    $body .= "Канал: " . $channel . "
";
    $body .= "Кто: " . $name . ($username !== '' ? ' (' . $username . ')' : '') . "

";
    $body .= "Сообщение:
" . $preview;

    $keyboard = [];
    if ($dialogUrl !== '') $keyboard[] = [['text' => 'Открыть диалог', 'url' => $dialogUrl]];
    $keyboard[] = [
        ['text' => 'Закрыть', 'callback_data' => 'btd_close_' . $botId . '_' . $subscriberId],
        ['text' => 'В спам', 'callback_data' => 'btd_spam_' . $botId . '_' . $subscriberId],
    ];
    $replyMarkup = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($recipients as $recipient) {
        $chatId = trim((string)($recipient['telegram_broadcast_test_chat_id'] ?? ''));
        if ($chatId === '') continue;
        try {
            asr_tg_api_send_message($token, $chatId, $body, ['parse_mode' => '', 'reply_markup' => $replyMarkup]);
        } catch (Throwable $e) {
            try { asr_tg_log($pdo, $botId, 'warning', 'dialog_technical_notify_failed', $e->getMessage(), ['user_id' => (int)($recipient['id'] ?? 0), 'subscriber_id' => $subscriberId]); } catch (Throwable $ignore) {}
        }
    }
}



function asr_tg_runtime_log_event(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId, string $eventType, string $eventText = '', array $payload = []): void {
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $telegramUserId = null;
        if ($subscriberId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT telegram_user_id FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1');
                $stmt->execute([$subscriberId]);
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null && $value !== '') $telegramUserId = (int)$value;
            } catch (Throwable $ignored) {}
        }
        $json = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_events (scenario_id, subscriber_id, telegram_user_id, bot_id, block_id, event_type, event_text, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $scenarioId > 0 ? $scenarioId : null,
            $subscriberId > 0 ? $subscriberId : null,
            $telegramUserId,
            $botId > 0 ? $botId : null,
            $blockId > 0 ? $blockId : null,
            mb_substr($eventType, 0, 80, 'UTF-8'),
            $eventText !== '' ? mb_substr($eventText, 0, 5000, 'UTF-8') : null,
            $json,
        ]);
    } catch (Throwable $ignored) {}
}

function asr_tg_runtime_extract_command(?string $text): string {
    $text = trim((string)$text);
    if ($text === '') return '';
    if (!preg_match('/^\/([a-zA-Z0-9_]{1,32})(?:@[a-zA-Z0-9_]{3,64})?(?:\s|$)/u', $text, $m)) return '';
    return asr_tg_normalize_command_name((string)($m[1] ?? ''));
}

function asr_tg_runtime_extract_start_payload(?string $text): string {
    $text = trim((string)$text);
    if ($text === '') return '';
    if (!preg_match('/^\/start(?:@[a-zA-Z0-9_]{3,64})?(?:\s+(.+))?$/u', $text, $m)) return '';
    $payload = trim((string)($m[1] ?? ''));
    if ($payload === '') return '';
    return mb_substr($payload, 0, 128, 'UTF-8');
}

function asr_tg_runtime_deeplink_find_by_code(PDO $pdo, string $code): ?array {
    $code = trim($code);
    if ($code === '') return null;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_deeplinks')) return null;
        $codeColumn = function_exists('asr_tg_scenario_deeplink_code_column') ? asr_tg_scenario_deeplink_code_column($pdo) : 'code';
        $stmt = $pdo->prepare("SELECT *, `{$codeColumn}` AS code FROM oca_telegram_bot_scenario_deeplinks WHERE `{$codeColumn}` = ? AND is_enabled = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (function_exists('asr_tg_scenario_deeplink_normalize_row')) {
            $row = asr_tg_scenario_deeplink_normalize_row($row);
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function asr_tg_runtime_is_service_command_text(?string $text): bool {
    return asr_tg_runtime_extract_command($text) !== '';
}

function asr_tg_runtime_command_find(PDO $pdo, int $botId, string $command): ?array {
    if ($botId <= 0 || $command === '') return null;
    asr_tg_repository_ensure_schema($pdo);
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) return null;

    $command = asr_tg_normalize_command_name($command);
    if ($command === '') return null;

    // Runtime v0.1.3: сначала быстрый SQL-путь, потом PHP-fallback.
    // Это защищает запуск от старых строк /help, NULL is_active и частично устаревшей схемы.
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM oca_telegram_bot_commands
            WHERE bot_id = ?
              AND (command = ? OR command = ? OR TRIM(LEADING '/' FROM command) = ?)
              AND (is_active = 1 OR is_active IS NULL)
            ORDER BY
              CASE WHEN scenario_id IS NOT NULL AND scenario_id > 0 THEN 0 ELSE 1 END,
              CASE WHEN step_id IS NOT NULL AND step_id > 0 THEN 0 ELSE 1 END,
              sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$botId, $command, '/' . $command, $command]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_command_fast_lookup_failed', $e->getMessage(), ['command' => $command]); } catch (Throwable $ignored) {}
    }

    try {
        $columns = ['id', 'bot_id', 'command', 'description', 'sort_order'];
        foreach (['scenario_id', 'step_id', 'is_active'] as $column) {
            if (asr_tg_column_exists($pdo, 'oca_telegram_bot_commands', $column)) $columns[] = $column;
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $columns)) . ' FROM oca_telegram_bot_commands WHERE bot_id = ? ORDER BY sort_order ASC, id ASC LIMIT 200');
        $stmt->execute([$botId]);
        $best = null;
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rowCommand = asr_tg_normalize_command_name((string)($row['command'] ?? ''));
            if ($rowCommand !== $command) continue;
            if (array_key_exists('is_active', $row) && $row['is_active'] !== null && (int)$row['is_active'] !== 1) continue;
            if (!$best) {
                $best = $row;
                continue;
            }
            $bestScore = ((int)($best['scenario_id'] ?? 0) > 0 ? 2 : 0) + ((int)($best['step_id'] ?? 0) > 0 ? 1 : 0);
            $rowScore = ((int)($row['scenario_id'] ?? 0) > 0 ? 2 : 0) + ((int)($row['step_id'] ?? 0) > 0 ? 1 : 0);
            if ($rowScore > $bestScore) $best = $row;
        }
        return $best ?: null;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_command_php_lookup_failed', $e->getMessage(), ['command' => $command]); } catch (Throwable $ignored) {}
        return null;
    }
}

function asr_tg_runtime_active_scenarios_for_bot(PDO $pdo, int $botId): array {
    if ($botId <= 0) return [];
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenarios') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_bots')) return [];
        $stmt = $pdo->prepare("SELECT s.id, s.title, s.status, sb.is_default
            FROM oca_telegram_bot_scenarios s
            JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id
            WHERE sb.bot_id = ?
              AND COALESCE(sb.is_enabled, 1) = 1
              AND s.status = 'active'
              AND s.archived_at IS NULL
            ORDER BY COALESCE(sb.is_default, 0) DESC, s.id ASC
            LIMIT 10");
        $stmt->execute([$botId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_runtime_active_scenarios_lookup_failed', $e->getMessage()); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_runtime_fallback_command_row(PDO $pdo, int $botId, string $command): ?array {
    if ($botId <= 0 || $command === '') return null;
    $command = asr_tg_normalize_command_name($command);
    if ($command === '') return null;

    $snapshot = asr_tg_runtime_command_snapshot($pdo, $botId);
    if ($snapshot) return null;

    $scenarios = asr_tg_runtime_active_scenarios_for_bot($pdo, $botId);
    if (count($scenarios) !== 1) {
        try {
            asr_tg_log($pdo, $botId, 'warning', 'scenario_command_autofallback_skipped', 'Автопривязка команды не выполнена: у канала не один активный сценарий.', [
                'command' => $command,
                'active_scenarios_count' => count($scenarios),
                'active_scenarios' => array_map(static fn($row) => ['id' => (int)($row['id'] ?? 0), 'title' => (string)($row['title'] ?? '')], $scenarios),
            ]);
        } catch (Throwable $ignored) {}
        return null;
    }

    $scenarioId = (int)($scenarios[0]['id'] ?? 0);
    if ($scenarioId <= 0) return null;
    $row = [
        'id' => 0,
        'bot_id' => $botId,
        'command' => $command,
        'description' => 'Автопривязка runtime',
        'scenario_id' => $scenarioId,
        'step_id' => null,
        'sort_order' => 10,
        'is_active' => 1,
        '_runtime_fallback' => 'single_active_scenario_without_saved_commands',
    ];

    try {
        // Если команды в админке по какой-то причине не сохранились, фиксируем безопасную строку.
        // Это не меняет меню Telegram через Bot API, но даёт runtime постоянную привязку.
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_commands (bot_id, command, description, scenario_id, step_id, sort_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, NULL, 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE scenario_id = VALUES(scenario_id), step_id = NULL, is_active = 1, updated_at = NOW()");
        $stmt->execute([$botId, $command, 'Автопривязка runtime']);
        $id = (int)$pdo->lastInsertId();
        if ($id <= 0) {
            $find = $pdo->prepare('SELECT id FROM oca_telegram_bot_commands WHERE bot_id = ? AND command = ? LIMIT 1');
            $find->execute([$botId, $command]);
            $id = (int)($find->fetchColumn() ?: 0);
        }
        if ($id > 0) $row['id'] = $id;
        asr_tg_log($pdo, $botId, 'info', 'scenario_command_autofallback_created', 'Команда runtime автоматически привязана к единственному активному сценарию канала.', ['command' => $command, 'scenario_id' => $scenarioId, 'command_id' => $row['id']]);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_command_autofallback_persist_failed', $e->getMessage(), ['command' => $command, 'scenario_id' => $scenarioId]); } catch (Throwable $ignored) {}
    }

    return $row;
}




function asr_tg_runtime_diagnostic(PDO $pdo, int $scenarioId, int $botId, string $command = 'help'): array {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для диагностики сценариев.');
    asr_tg_repository_ensure_schema($pdo);
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, $scenarioId);
    $botId = max(0, $botId);
    $command = asr_tg_normalize_command_name($command !== '' ? $command : 'help');

    $scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
    $scenarioBotId = $scenarioId > 0 ? asr_tg_scenario_bot_id($pdo, $scenarioId) : 0;
    if ($botId <= 0) $botId = $scenarioBotId;
    $bot = $botId > 0 ? asr_tg_bot_find($pdo, $botId) : null;

    $commandRow = null;
    if ($botId > 0 && $command !== '') {
        $commandRow = asr_tg_runtime_command_find($pdo, $botId, $command);
        if (!$commandRow) {
            $commandRow = asr_tg_runtime_fallback_command_row($pdo, $botId, $command);
        }
    }
    $snapshot = $botId > 0 ? asr_tg_runtime_command_snapshot($pdo, $botId) : [];

    $scenarioIdFromCommand = (int)($commandRow['scenario_id'] ?? 0);
    $stepId = (int)($commandRow['step_id'] ?? 0);
    if ($scenarioIdFromCommand <= 0 && $stepId > 0) {
        $scenarioIdFromCommand = asr_tg_runtime_scenario_from_step($pdo, $stepId);
    }
    $effectiveScenarioId = $scenarioIdFromCommand > 0 ? $scenarioIdFromCommand : $scenarioId;
    $entryBlockId = $effectiveScenarioId > 0 ? asr_tg_runtime_resolve_entry_block($pdo, $effectiveScenarioId, $stepId) : 0;
    $entryBlock = $entryBlockId > 0 ? asr_tg_scenario_block_find($pdo, $entryBlockId, $effectiveScenarioId) : null;
    $cards = $entryBlock ? asr_tg_runtime_cards_from_block($pdo, $effectiveScenarioId, $entryBlock) : [];

    $recentLogs = [];
    try {
        $params = [];
        $where = [];
        if ($botId > 0) { $where[] = '(bot_id = ? OR bot_id IS NULL)'; $params[] = $botId; }
        $runtimeEvents = [
            'webhook_received','scenario_command_received','scenario_command_link_found','runtime_started','runtime_message_sent',
            'runtime_message_empty','runtime_command_without_scenario','scenario_command_without_scenario','scenario_command_not_linked',
            'webhook_blocked_subscriber_ignored','scenario_runtime_failed','runtime_failed','runtime_bot_mismatch',
            'scenario_command_fast_lookup_failed','scenario_command_php_lookup_failed','scenario_command_scenario_repaired_from_step','scenario_command_autofallback_created','scenario_command_autofallback_skipped','scenario_command_autofallback_persist_failed'
        ];
        $where[] = 'event_type IN (' . implode(',', array_fill(0, count($runtimeEvents), '?')) . ')';
        $params = array_merge($params, $runtimeEvents);
        $sql = 'SELECT id, bot_id, level, event_type, message, context_json, created_at FROM oca_telegram_bot_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 40';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recentLogs = [['error' => $e->getMessage()]];
    }

    $recentScenarioEvents = [];
    try {
        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_events')) {
            $params = [];
            $where = [];
            if ($effectiveScenarioId > 0) { $where[] = 'scenario_id = ?'; $params[] = $effectiveScenarioId; }
            if ($botId > 0) { $where[] = '(bot_id = ? OR bot_id IS NULL)'; $params[] = $botId; }
            $sql = 'SELECT id, scenario_id, bot_id, subscriber_id, block_id, event_type, event_text, payload_json, created_at FROM oca_telegram_bot_scenario_events';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY id DESC LIMIT 40';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recentScenarioEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $recentScenarioEvents = [['error' => $e->getMessage()]];
    }

    $lastCommandMessages = [];
    try {
        if ($botId > 0 && asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')) {
            $stmt = $pdo->prepare("SELECT m.id, m.bot_id, m.subscriber_id, m.direction, m.message_text, m.created_at, s.status AS subscriber_status, s.chat_id, s.username, s.first_name, s.last_name FROM oca_telegram_bot_messages m LEFT JOIN oca_telegram_bot_subscribers s ON s.id = m.subscriber_id WHERE m.bot_id = ? AND m.message_text LIKE ? ORDER BY m.id DESC LIMIT 20");
            $stmt->execute([$botId, '/'.$command.'%']);
            $lastCommandMessages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $lastCommandMessages = [['error' => $e->getMessage()]];
    }

    return [
        'ok' => true,
        'runtime_version' => 'v0.1.5-command-autofallback',
        'checked_at' => date('Y-m-d H:i:s'),
        'input' => ['scenario_id' => $scenarioId, 'bot_id' => $botId, 'command' => $command],
        'scenario' => $scenario ? [
            'id' => (int)($scenario['id'] ?? 0),
            'title' => (string)($scenario['title'] ?? ''),
            'status' => (string)($scenario['status'] ?? ''),
            'linked_bot_id' => $scenarioBotId,
        ] : null,
        'bot' => $bot ? [
            'id' => (int)($bot['id'] ?? 0),
            'title' => (string)($bot['title'] ?? ''),
            'status' => (string)($bot['status'] ?? ''),
            'channel_type' => asr_tg_channel_type_of($bot),
            'bot_username' => (string)($bot['bot_username'] ?? ''),
            'last_webhook_at' => (string)($bot['last_webhook_at'] ?? ''),
            'last_error' => (string)($bot['last_error'] ?? ''),
        ] : null,
        'command_row' => $commandRow,
        'command_snapshot' => $snapshot,
        'effective' => [
            'scenario_id_from_command' => $scenarioIdFromCommand,
            'effective_scenario_id' => $effectiveScenarioId,
            'step_id' => $stepId,
            'entry_block_id' => $entryBlockId,
        ],
        'entry_block' => $entryBlock ? [
            'id' => (int)($entryBlock['id'] ?? 0),
            'scenario_id' => (int)($entryBlock['scenario_id'] ?? 0),
            'type' => (string)($entryBlock['type'] ?? ''),
            'title' => (string)($entryBlock['title'] ?? ''),
            'has_settings_json' => trim((string)($entryBlock['settings_json'] ?? '')) !== '',
            'has_message_text' => trim((string)($entryBlock['message_text'] ?? '')) !== '',
        ] : null,
        'cards' => array_map(static function($card) {
            $text = is_array($card) ? (string)($card['text'] ?? '') : '';
            return [
                'type' => is_array($card) ? (string)($card['type'] ?? '') : '',
                'text_preview' => mb_substr(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 180, 'UTF-8'),
                'has_text' => trim(strip_tags($text)) !== '',
            ];
        }, $cards),
        'last_command_messages' => $lastCommandMessages,
        'recent_logs' => $recentLogs,
        'recent_scenario_events' => $recentScenarioEvents,
        'next_hint' => 'После отправки /' . $command . ' сравните last_command_messages, recent_logs и recent_scenario_events. Если webhook_received есть, но scenario_command_received нет — команда не распознана или подписчик заблокирован. Если scenario_command_link_found есть, но runtime_message_sent нет — смотрите entry_block/cards и runtime_failed.',
    ];
}

function asr_tg_runtime_command_snapshot(PDO $pdo, int $botId): array {
    if ($botId <= 0 || !asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) return [];
    try {
        $stmt = $pdo->prepare('SELECT id, command, description, scenario_id, step_id, is_active FROM oca_telegram_bot_commands WHERE bot_id = ? ORDER BY sort_order ASC, id ASC LIMIT 20');
        $stmt->execute([$botId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [['error' => $e->getMessage()]];
    }
}

function asr_tg_runtime_first_block_after(PDO $pdo, int $scenarioId, int $fromBlockId): int {
    if ($scenarioId <= 0 || $fromBlockId <= 0) return 0;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare("SELECT to_block_id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY CASE WHEN link_type IN ('start','next') THEN 0 ELSE 1 END, sort_order ASC, id ASC LIMIT 1");
    $stmt->execute([$scenarioId, $fromBlockId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function asr_tg_runtime_resolve_entry_block(PDO $pdo, int $scenarioId, int $blockId = 0): int {
    if ($scenarioId <= 0) return 0;
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($blockId > 0) {
        $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
        if (!$block) return 0;
        if ((string)($block['type'] ?? '') !== 'start') return $blockId;
        return asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId);
    }
    $scenario = asr_tg_scenario_find($pdo, $scenarioId);
    $startBlockId = (int)($scenario['start_block_id'] ?? 0);
    if ($startBlockId <= 0) {
        $start = asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
        $startBlockId = (int)($start['id'] ?? 0);
    }
    if ($startBlockId > 0) return asr_tg_runtime_first_block_after($pdo, $scenarioId, $startBlockId);
    $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start' ORDER BY sort_order ASC, id ASC LIMIT 1");
    $stmt->execute([$scenarioId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function asr_tg_runtime_remember_position(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId, string $status = 'active', ?string $nextRunAt = null, string $lastError = ''): void {
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0) return;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $telegramUserId = 0;
    try {
        $stmt = $pdo->prepare('SELECT telegram_user_id FROM oca_telegram_bot_subscribers WHERE id = ? AND bot_id = ? LIMIT 1');
        $stmt->execute([$subscriberId, $botId]);
        $telegramUserId = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $ignored) {}
    try {
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET current_block_id = ?, status = ?, next_run_at = ?, last_error = ?, stopped_at = CASE WHEN ? IN ('stopped','finished','error') THEN NOW() ELSE stopped_at END, finished_at = CASE WHEN ? = 'finished' THEN NOW() ELSE finished_at END, updated_at = NOW() WHERE scenario_id = ? AND subscriber_id = ? AND bot_id = ? AND status IN ('active','waiting','error') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$blockId > 0 ? $blockId : null, $status, $nextRunAt, $lastError !== '' ? $lastError : null, $status, $status, $scenarioId, $subscriberId, $botId]);
        if ($stmt->rowCount() > 0) return;
    } catch (Throwable $ignored) {}
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_subscriber_scenarios (scenario_id, subscriber_id, telegram_user_id, bot_id, current_block_id, status, next_run_at, last_error, started_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())');
    $stmt->execute([$scenarioId, $subscriberId, $telegramUserId, $botId, $blockId > 0 ? $blockId : null, $status, $nextRunAt, $lastError !== '' ? $lastError : null]);
}

function asr_tg_runtime_card_text_to_telegram(string $html): string {
    $text = trim($html);
    if ($text === '') return '';

    // Редактор хранит HTML для админки, но Telegram принимает только ограниченный набор тегов.
    // Поэтому нормализуем переносы и убираем лишнюю разметку, иначе sendMessage может молча
    // упасть на неподдерживаемых <div>, <p>, <br> и похожих тегах.
    $text = preg_replace('/<\s*br\s*\/?>/iu', "
", $text) ?: $text;
    $text = preg_replace('/<\s*\/\s*(p|div)\s*>/iu', "
", $text) ?: $text;
    $text = preg_replace('/<\s*(p|div)(?:\s+[^>]*)?>/iu', '', $text) ?: $text;
    $text = preg_replace('/<\s*strong(?:\s+[^>]*)?>/iu', '<b>', $text) ?: $text;
    $text = preg_replace('/<\s*\/\s*strong\s*>/iu', '</b>', $text) ?: $text;
    $text = preg_replace('/<\s*em(?:\s+[^>]*)?>/iu', '<i>', $text) ?: $text;
    $text = preg_replace('/<\s*\/\s*em\s*>/iu', '</i>', $text) ?: $text;
    $text = preg_replace('/<\s*(strike|del)(?:\s+[^>]*)?>/iu', '<s>', $text) ?: $text;
    $text = preg_replace('/<\s*\/\s*(strike|del)\s*>/iu', '</s>', $text) ?: $text;
    $text = preg_replace('/<\s*span\s+class=["\']tg-spoiler["\'][^>]*>/iu', '<tg-spoiler>', $text) ?: $text;
    $text = preg_replace('/<\s*\/\s*span\s*>/iu', '</tg-spoiler>', $text) ?: $text;

    $text = strip_tags($text, '<b><i><u><s><code><pre><blockquote><a><tg-spoiler>');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/
{4,}/u", "


", $text) ?: $text;
    return trim($text);
}

function asr_tg_runtime_plain_text(string $html): string {
    $text = preg_replace('/<\s*br\s*\/?>/iu', "
", $html) ?: $html;
    $text = preg_replace('/<\s*\/\s*(p|div)\s*>/iu', "
", $text) ?: $text;
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/[ 	]+/u', ' ', $text) ?: $text;
    $text = preg_replace("/
{4,}/u", "


", $text) ?: $text;
    return trim($text);
}


function asr_tg_runtime_cards_from_block(PDO $pdo, int $scenarioId, array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (is_array($settings) && isset($settings['cards']) && is_array($settings['cards']) && $settings['cards']) {
        return array_values($settings['cards']);
    }

    $legacyText = trim((string)($block['message_text'] ?? ''));
    if ($legacyText !== '') {
        return [[
            'type' => 'text',
            'text' => $legacyText,
            'buttons' => [],
        ]];
    }

    // Защита от старых/промежуточных сохранений, где карточки могли попасть в отдельную таблицу.
    try {
        $blockId = (int)($block['id'] ?? 0);
        if ($scenarioId <= 0 || $blockId <= 0 || !asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_block_cards')) return [];
        $stmt = $pdo->prepare('SELECT card_type, title, body_text, settings_json FROM oca_telegram_bot_scenario_block_cards WHERE scenario_id = ? AND block_id = ? ORDER BY sort_order ASC, id ASC LIMIT 50');
        $stmt->execute([$scenarioId, $blockId]);
        $cards = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $type = (string)($row['card_type'] ?? 'text');
            if ($type === 'message') $type = 'text';
            $cardSettings = json_decode((string)($row['settings_json'] ?? ''), true);
            if (!is_array($cardSettings)) $cardSettings = [];
            $text = (string)($cardSettings['text'] ?? $cardSettings['body_text'] ?? $row['body_text'] ?? $row['title'] ?? '');
            if (trim($text) === '') continue;
            $cards[] = [
                'type' => $type ?: 'text',
                'text' => $text,
                'protect_content' => !empty($cardSettings['protect_content']),
                'buttons' => is_array($cardSettings['buttons'] ?? null) ? $cardSettings['buttons'] : [],
            ];
        }
        return $cards;
    } catch (Throwable $e) {
        try { asr_tg_runtime_log_event($pdo, 0, 0, $scenarioId, (int)($block['id'] ?? 0), 'runtime_legacy_cards_read_failed', $e->getMessage()); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_runtime_scenario_from_step(PDO $pdo, int $stepId): int {
    if ($stepId <= 0) return 0;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $stmt = $pdo->prepare('SELECT scenario_id FROM oca_telegram_bot_scenario_blocks WHERE id = ? LIMIT 1');
        $stmt->execute([$stepId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_tg_runtime_execute_message_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block): bool {
    $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен Telegram-канала.');
    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден для выполнения сценария.');
    $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
    if (!$cards) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, (int)$block['id'], 'runtime_message_empty', 'В блоке сообщения нет текстовых карточек для отправки.', [
            'has_settings_json' => trim((string)($block['settings_json'] ?? '')) !== '',
            'has_message_text' => trim((string)($block['message_text'] ?? '')) !== '',
        ]);
        return false;
    }
    $sentAny = false;
    foreach ($cards as $index => $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if ($type !== 'text') {
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, (int)$block['id'], 'runtime_card_skipped', 'Runtime v0.1 пока отправляет только текстовые карточки.', ['card_index' => $index, 'card_type' => $type]);
            continue;
        }
        $rawText = asr_tg_runtime_card_text_to_telegram((string)($card['text'] ?? ''));
        if ($rawText === '') continue;
        $text = asr_tg_render_subscriber_macros($pdo, $rawText, $subscriber);
        $plainText = asr_tg_runtime_plain_text($text);
        if (mb_strlen(trim($plainText), 'UTF-8') === 0) continue;
        if (mb_strlen($plainText, 'UTF-8') > 4096 || mb_strlen($text, 'UTF-8') > 4096) {
            throw new RuntimeException('Текст блока #' . (int)$block['id'] . ' длиннее лимита Telegram 4096 символов.');
        }
        try {
            $sent = asr_tg_api_send_broadcast_payload($token, $chatId, $text, 'HTML', '', '', true, '', [], !empty($card['protect_content']));
        } catch (Throwable $sendHtmlError) {
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, (int)$block['id'], 'runtime_message_html_retry_plain', 'Telegram не принял HTML-разметку, повторяем отправку обычным текстом.', ['card_index' => $index, 'error' => $sendHtmlError->getMessage()]);
            $sent = asr_tg_api_send_broadcast_payload($token, $chatId, $plainText, '', '', '', true, '', [], !empty($card['protect_content']));
        }
        $sentMessage = is_array($sent['result'] ?? null) ? $sent['result'] : [];
        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', 'text', $plainText, isset($sentMessage['message_id']) ? (int)$sentMessage['message_id'] : null, [
            'scenario_id' => $scenarioId,
            'block_id' => (int)$block['id'],
            'card_index' => $index,
            'telegram' => $sentMessage,
        ]);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, (int)$block['id'], 'runtime_message_sent', 'Отправлена текстовая карточка блока.', ['card_index' => $index]);
        $sentAny = true;
    }
    return $sentAny;
}

function asr_tg_runtime_start_scenario(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, int $entryBlockId = 0, string $source = 'manual', array $sourcePayload = []): bool {
    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0) return false;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenario = asr_tg_scenario_find($pdo, $scenarioId);
    if (!$scenario) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_scenario_missing', 'Сценарий не найден.', $sourcePayload);
        return false;
    }
    if ((string)($scenario['status'] ?? '') !== 'active') {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_scenario_not_active', 'Сценарий не запущен, потому что он не активен.', ['status' => (string)($scenario['status'] ?? ''), 'source' => $source] + $sourcePayload);
        return true;
    }
    $scenarioBotId = asr_tg_scenario_bot_id($pdo, $scenarioId);
    if ($scenarioBotId <= 0) {
        // Если команда уже сохранена именно в этом канале, но у сценария потерялась строка связи,
        // восстанавливаем её мягко: один сценарий всё равно должен иметь один канал.
        try {
            $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ?')->execute([$scenarioId]);
            $stmtRepair = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_bots (scenario_id, bot_id, is_enabled, is_default, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), NOW())');
            $stmtRepair->execute([$scenarioId, $botId]);
            $scenarioBotId = asr_tg_scenario_bot_id($pdo, $scenarioId);
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_bot_link_repaired', 'Связь сценария с каналом восстановлена при запуске команды.', ['source' => $source] + $sourcePayload);
        } catch (Throwable $repairError) {
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_bot_link_repair_failed', $repairError->getMessage(), ['source' => $source] + $sourcePayload);
        }
    }
    if ($scenarioBotId !== $botId) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_bot_mismatch', 'Сценарий привязан к другому каналу.', ['scenario_bot_id' => $scenarioBotId, 'source' => $source] + $sourcePayload);
        return true;
    }
    $blockId = asr_tg_runtime_resolve_entry_block($pdo, $scenarioId, $entryBlockId);
    if ($blockId <= 0) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, 0, 'runtime_entry_missing', 'Не найден стартовый блок сценария.', ['source' => $source] + $sourcePayload);
        return true;
    }
    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_block_missing', 'Блок сценария не найден.', ['source' => $source] + $sourcePayload);
        return true;
    }
    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_started', 'Сценарий запущен.', ['source' => $source] + $sourcePayload);

    $type = (string)($block['type'] ?? '');
    try {
        if ($type === 'message') {
            $sent = asr_tg_runtime_execute_message_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block);
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, $sent ? 'active' : 'error', null, $sent ? '' : 'В блоке сообщения нет отправленных текстовых карточек.');
            return true;
        }
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_block_unsupported', 'Runtime v0.1 пока выполняет только блок «Сообщение».', ['block_type' => $type, 'source' => $source]);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'Runtime v0.1 пока выполняет только блок «Сообщение».');
        return true;
    } catch (Throwable $e) {
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $e->getMessage());
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_failed', $e->getMessage(), ['source' => $source]);
        throw $e;
    }
}

function asr_tg_runtime_try_command(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, ?string $text): bool {
    $command = asr_tg_runtime_extract_command($text);
    if ($command === '') return false;
    try {
        asr_tg_log($pdo, $botId, 'info', 'scenario_command_received', 'Получена команда для runtime сценариев.', ['subscriber_id' => $subscriberId, 'command' => $command]);
    } catch (Throwable $ignored) {}
    $commandRow = asr_tg_runtime_command_find($pdo, $botId, $command);
    if (!$commandRow) {
        $commandRow = asr_tg_runtime_fallback_command_row($pdo, $botId, $command);
    }
    if (!$commandRow) {
        $snapshot = asr_tg_runtime_command_snapshot($pdo, $botId);
        try {
            asr_tg_log($pdo, $botId, 'warning', 'scenario_command_not_linked', 'Команда Telegram не найдена в меню команд канала или неактивна.', ['subscriber_id' => $subscriberId, 'command' => $command, 'saved_commands' => $snapshot]);
        } catch (Throwable $ignored) {}
        return false;
    }
    $scenarioId = (int)($commandRow['scenario_id'] ?? 0);
    $stepId = (int)($commandRow['step_id'] ?? 0);
    if ($scenarioId <= 0 && $stepId > 0) {
        $scenarioId = asr_tg_runtime_scenario_from_step($pdo, $stepId);
        if ($scenarioId > 0) {
            try {
                $pdo->prepare('UPDATE oca_telegram_bot_commands SET scenario_id = ?, updated_at = NOW() WHERE id = ?')->execute([$scenarioId, (int)($commandRow['id'] ?? 0)]);
                asr_tg_log($pdo, $botId, 'info', 'scenario_command_scenario_repaired_from_step', 'Сценарий команды восстановлен по выбранному блоку.', ['subscriber_id' => $subscriberId, 'command' => $command, 'scenario_id' => $scenarioId, 'step_id' => $stepId]);
            } catch (Throwable $ignored) {}
        }
    }
    if ($scenarioId <= 0) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, 0, 0, 'runtime_command_without_scenario', 'Команда Telegram найдена, но к ней не привязан сценарий.', ['command' => $command, 'command_id' => (int)($commandRow['id'] ?? 0), 'step_id' => $stepId]);
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_command_without_scenario', 'Команда найдена, но сценарий не выбран.', ['subscriber_id' => $subscriberId, 'command' => $command, 'command_id' => (int)($commandRow['id'] ?? 0), 'step_id' => $stepId]); } catch (Throwable $ignored) {}
        return false;
    }
    try {
        asr_tg_log($pdo, $botId, 'info', 'scenario_command_link_found', 'Команда Telegram привязана к сценарию.', ['subscriber_id' => $subscriberId, 'command' => $command, 'scenario_id' => $scenarioId, 'step_id' => $stepId]);
    } catch (Throwable $ignored) {}
    return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $stepId, 'telegram_command', ['command' => $command, 'command_id' => (int)($commandRow['id'] ?? 0)]);
}

function asr_tg_handle_webhook(PDO $pdo, int $botId, string $urlSecret, string $rawBody, string $headerSecret = ''): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($botId <= 0 || $urlSecret === '') {
        http_response_code(404);
        return;
    }
    $bot = asr_tg_bot_find($pdo, $botId);
    if (!$bot || !hash_equals((string)$bot['webhook_secret'], $urlSecret)) {
        asr_tg_log($pdo, $botId > 0 ? $botId : null, 'warning', 'webhook_rejected', 'Webhook отклонён: неверный bot_id или secret.');
        http_response_code(403);
        return;
    }
    $expectedHeaderSecret = (string)($bot['webhook_secret_token'] ?? '');
    if ($expectedHeaderSecret !== '' && ($headerSecret === '' || !hash_equals($expectedHeaderSecret, $headerSecret))) {
        asr_tg_log($pdo, $botId, 'warning', 'webhook_rejected_header', 'Webhook отклонён: отсутствует или неверный X-Telegram-Bot-Api-Secret-Token.');
        http_response_code(403);
        return;
    }
    if (strlen($rawBody) > 1024 * 1024) {
        asr_tg_log($pdo, $botId, 'warning', 'webhook_too_large', 'Telegram прислал слишком большой payload.');
        http_response_code(413);
        return;
    }
    $update = json_decode($rawBody, true);
    if (!is_array($update)) {
        asr_tg_log($pdo, $botId, 'warning', 'webhook_bad_json', 'Webhook payload не является JSON.');
        http_response_code(400);
        return;
    }

    asr_tg_bot_update($pdo, $botId, ['last_webhook_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
    asr_tg_log($pdo, $botId, 'info', 'webhook_received', 'Получено событие Telegram.', ['update_id' => $update['update_id'] ?? null]);

    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        http_response_code(200);
        return;
    }

    $from = is_array($message['from'] ?? null) ? $message['from'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = $chat['id'] ?? ($from['id'] ?? 0);
    $text = isset($message['text']) ? (string)$message['text'] : null;
    $isServiceCommand = asr_tg_runtime_is_service_command_text($text);
    $subscriberId = asr_tg_subscriber_upsert($pdo, $botId, $from, $chatId);
    if ($subscriberId > 0) {
        try {
            $blockedCheck = $pdo->prepare("SELECT status FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1");
            $blockedCheck->execute([$subscriberId]);
            $currentSubscriberStatus = (string)$blockedCheck->fetchColumn();
            if ($currentSubscriberStatus === 'blocked') {
                if ($isServiceCommand) {
                    $pdo->prepare("UPDATE oca_telegram_bot_subscribers SET status = 'active', last_seen_at = NOW(), updated_at = NOW() WHERE id = ? AND bot_id = ?")->execute([$subscriberId, $botId]);
                    asr_tg_log($pdo, $botId, 'info', 'webhook_blocked_subscriber_reactivated_for_command', 'Заблокированный подписчик активирован для служебной команды сценария.', [
                        'subscriber_id' => $subscriberId,
                        'command' => asr_tg_runtime_extract_command($text),
                    ]);
                } else {
                    $blockedText = isset($message['text']) ? (string)$message['text'] : '';
                    asr_tg_log($pdo, $botId, 'info', 'webhook_blocked_subscriber_ignored', 'Сообщение от заблокированного подписчика не добавлено в диалоги.', [
                        'subscriber_id' => $subscriberId,
                        'text_preview' => mb_substr($blockedText, 0, 120, 'UTF-8'),
                        'command' => asr_tg_runtime_extract_command($blockedText),
                    ]);
                    return;
                }
            }
        } catch (Throwable $e) {}
    }

    $scenarioRuntimeHandled = false;
    if ($subscriberId > 0 && $text !== null) {
        try {
            $startPayload = asr_tg_runtime_extract_start_payload($text);
            if ($startPayload !== '') {
                $deeplink = asr_tg_runtime_deeplink_find_by_code($pdo, $startPayload);
                if ($deeplink) {
                    $deeplinkScenarioId = (int)($deeplink['scenario_id'] ?? 0);
                    $deeplinkBlockId = (int)($deeplink['block_id'] ?? 0);
                    asr_tg_log($pdo, $botId, 'info', 'scenario_deeplink_received', 'Получен диплинк сценария.', [
                        'subscriber_id' => (int)$subscriberId,
                        'code' => $startPayload,
                        'scenario_id' => $deeplinkScenarioId,
                        'block_id' => $deeplinkBlockId,
                    ]);
                    if ($deeplinkScenarioId > 0 && $deeplinkBlockId > 0) {
                        $scenarioRuntimeHandled = asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, (int)$subscriberId, $deeplinkScenarioId, $deeplinkBlockId, 'telegram_deeplink', ['code' => $startPayload]);
                    }
                } else {
                    asr_tg_log($pdo, $botId, 'warning', 'scenario_deeplink_not_found', 'Диплинк сценария не найден или отключён.', [
                        'subscriber_id' => (int)$subscriberId,
                        'code' => $startPayload,
                    ]);
                }
            }
            if (!$scenarioRuntimeHandled) {
                $scenarioRuntimeHandled = asr_tg_runtime_try_command($pdo, $bot, $botId, $chatId, (int)$subscriberId, $text);
            }
        } catch (Throwable $e) {
            asr_tg_bot_update($pdo, $botId, ['last_error' => $e->getMessage()]);
            asr_tg_log($pdo, $botId, 'error', 'scenario_runtime_failed', $e->getMessage(), ['subscriber_id' => (int)$subscriberId, 'text' => $text]);
            $scenarioRuntimeHandled = true;
        }
    }

    if (!$isServiceCommand) {
        $messageType = $text !== null ? 'text' : 'other';
        asr_tg_message_add($pdo, $botId, $subscriberId ?: null, 'in', $messageType, $text, isset($message['message_id']) ? (int)$message['message_id'] : null, $message);
    } else {
        asr_tg_log($pdo, $botId, 'info', 'webhook_service_command_hidden', 'Служебная команда не добавлена в диалоги и не отправлена в технический бот.', [
            'subscriber_id' => (int)$subscriberId,
            'command' => asr_tg_runtime_extract_command($text),
            'text_preview' => mb_substr((string)$text, 0, 120, 'UTF-8'),
        ]);
    }

    if (!$isServiceCommand && $subscriberId > 0) {
        asr_tg_notify_technical_bot_about_dialog($pdo, $bot, $botId, (int)$subscriberId, $message, $text);
    }

    if (!$scenarioRuntimeHandled && !$isServiceCommand && $subscriberId > 0) {
        asr_tg_send_dialog_auto_reply_if_needed($pdo, $bot, $botId, (int)$subscriberId, $chatId, $text);
    }

    // Стартовое сообщение Telegram-бота больше не отправляем автоматически.
    // /start используется как служебная команда и как транспорт для диплинков сценариев.

    http_response_code(200);
}

function asr_tg_scenario_save_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    $botId = (int)($post['scenario_bot_id'] ?? 0);
    if ($botId <= 0) {
        $legacyBotIds = $post['scenario_bot_ids'] ?? [];
        if (!is_array($legacyBotIds)) $legacyBotIds = [$legacyBotIds];
        $legacyBotIds = array_values(array_filter(array_map('intval', $legacyBotIds), static fn($id) => $id > 0));
        $botId = (int)($legacyBotIds[0] ?? 0);
    }
    return asr_tg_scenario_save($pdo, [
        'id' => (int)($post['scenario_id'] ?? 0),
        'title' => (string)($post['title'] ?? ''),
        'description' => (string)($post['description'] ?? ''),
        'status' => (string)($post['status'] ?? 'draft'),
        'bot_id' => $botId,
    ]);
}

function asr_tg_scenario_archive_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    $scenarioId = (int)($post['scenario_id'] ?? 0);
    asr_tg_scenario_set_status($pdo, $scenarioId, 'archived');
    return $scenarioId;
}

function asr_tg_scenario_restore_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    $scenarioId = (int)($post['scenario_id'] ?? 0);
    asr_tg_scenario_set_status($pdo, $scenarioId, 'draft');
    return $scenarioId;
}

function asr_tg_scenario_delete_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
    $scenarioId = (int)($post['scenario_id'] ?? 0);
    asr_tg_scenario_delete($pdo, $scenarioId);
    return $scenarioId;
}

if (!function_exists('asr_tg_can_manage_flows')) {
    function asr_tg_can_manage_flows(): bool {
        return function_exists('asr_tg_can') ? asr_tg_can('flows') : false;
    }
}
