<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/queue_diagnostics.php';
require_once __DIR__ . '/telegram_error_policy.php';
require_once __DIR__ . '/queue_service.php';
require_once __DIR__ . '/scenario_stats.php';
require_once __DIR__ . '/scenario_condition_runtime.php';
require_once __DIR__ . '/scenario_action_runtime.php';
require_once __DIR__ . '/scenario_formula_runtime.php';
require_once __DIR__ . '/platforms/vk/vk_client.php';

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

function asr_tg_public_vk_callback_url(): string {
    $path = '/vk_bot_webhook.php';
    if (function_exists('asr_config_app_url')) {
        return asr_config_app_url($path);
    }
    $scheme = defined('APP_SCHEME') ? APP_SCHEME : 'https';
    $domain = defined('APP_DOMAIN') ? APP_DOMAIN : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($scheme . '://' . $domain, '/') . $path;
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
        // /start — системная команда Telegram. Её нельзя добавлять в меню команд,
        // иначе она перехватывает диплинки вида /start dl-... и запускает сценарий с начала.
        if ($command === 'start') continue;
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
        $commandName = asr_tg_normalize_command_name((string)($item['command'] ?? ''));
        if ($commandName === '' || $commandName === 'start') continue;
        $payloadCommands[] = [
            'command' => $commandName,
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

    // Runtime v0.2.1b: жёстко проверяем привязку команды к выбранному шагу.
    // Иначе команда из Telegram-меню может сохраниться как «сначала сценария» и запускать первый блок.
    if ($commands) {
        asr_tg_repository_ensure_scenario_schema($pdo);
        foreach ($commands as &$commandItem) {
            $scenarioId = (int)($commandItem['scenario_id'] ?? 0);
            $stepId = (int)($commandItem['step_id'] ?? 0);
            if ($stepId <= 0) continue;

            $block = asr_tg_scenario_block_find($pdo, $stepId);
            if (!$block) {
                throw new InvalidArgumentException('Выбранный шаг для команды «/' . (string)($commandItem['command'] ?? '') . '» не найден. Откройте меню команд и выберите шаг заново.');
            }

            $blockScenarioId = (int)($block['scenario_id'] ?? 0);
            if ($scenarioId <= 0) {
                $commandItem['scenario_id'] = $blockScenarioId > 0 ? $blockScenarioId : null;
            } elseif ($blockScenarioId > 0 && $blockScenarioId !== $scenarioId) {
                throw new InvalidArgumentException('Выбранный шаг для команды «/' . (string)($commandItem['command'] ?? '') . '» относится к другому сценарию. Откройте меню команд и выберите шаг текущего сценария.');
            }
        }
        unset($commandItem);
    }

    asr_tg_bot_commands_replace($pdo, $botId, $commands);
    try { asr_tg_bot_commands_delete_reserved_start($pdo, $botId); } catch (Throwable $ignored) {}

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

    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден.');

    $channelType = asr_tg_channel_type_of($subscriber);
    $hasUploadedFile = !empty($files['chat_attachment']) && is_array($files['chat_attachment']) && (int)($files['chat_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($channelType === 'vk') {
        if ($hasUploadedFile) {
            throw new RuntimeException('Для VK на этом шаге подключена только текстовая ручная отправка. Файлы добавим отдельным патчем, чтобы не смешивать отправку текста и VK-вложения.');
        }
        if ($text === '') throw new InvalidArgumentException('Введите текст сообщения.');

        $text = asr_tg_render_subscriber_macros($pdo, $text, $subscriber);
        if (mb_strlen($text, 'UTF-8') > 4096) {
            throw new InvalidArgumentException('Сообщение после подстановки переменных слишком длинное. Лимит VK: 4096 символов.');
        }

        $bot = asr_tg_bot_find($pdo, $botId);
        if (!$bot || asr_tg_channel_type_of($bot) !== 'vk') throw new RuntimeException('VK-канал не найден.');
        $token = asr_tg_decrypt_token((string)($bot['vk_api_token_encrypted'] ?? ''));
        if ($token === '') throw new RuntimeException('Не удалось расшифровать токен VK-канала. Проверьте ключ доступа сообщества.');

        $peerId = trim((string)($subscriber['external_chat_id'] ?? ''));
        if ($peerId === '') $peerId = trim((string)($subscriber['chat_id'] ?? ''));
        if ($peerId === '') throw new RuntimeException('У VK-подписчика нет peer_id. Попросите пользователя написать в сообщество ещё раз.');

        $sent = asr_tg_vk_send_message($token, $peerId, $text);
        $vkResponse = $sent['response'] ?? null;
        $vkMessageId = is_numeric($vkResponse) ? (int)$vkResponse : null;

        $payload = [
            'platform' => 'vk',
            'vk_response' => $vkResponse,
            'vk_peer_id' => $peerId,
        ];
        if ($originalText !== $text) $payload['original_text'] = $originalText;

        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', 'text', $text, $vkMessageId, $payload);
        asr_tg_log($pdo, $botId, 'info', 'manual_vk_message_sent', 'Сообщение VK-подписчику отправлено вручную.', ['subscriber_id' => $subscriberId, 'vk_peer_id' => $peerId]);
        return $subscriberId;
    }

    $attachment = asr_tg_save_chat_attachment($files['chat_attachment'] ?? []);
    $hasAttachment = !empty($attachment);
    if ($text === '' && !$hasAttachment) throw new InvalidArgumentException('Введите текст сообщения или прикрепите файл.');

    $text = asr_tg_render_subscriber_macros($pdo, $text, $subscriber);
    $limit = $hasAttachment ? 1024 : 4096;
    if (mb_strlen($text, 'UTF-8') > $limit) throw new InvalidArgumentException('Сообщение после подстановки переменных слишком длинное. Лимит Telegram: ' . $limit . ' символов' . ($hasAttachment ? ' для подписи к файлу.' : '.'));

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
    $welcomeEnabled = !empty($post['welcome_enabled']) ? 1 : 0;
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
            'webhook_url' => asr_tg_public_vk_callback_url(),
            'webhook_secret' => $secret,
            'webhook_secret_token' => $secretToken,
            'status' => 'inactive',
            'welcome_text' => $welcomeText,
            'welcome_enabled' => $welcomeEnabled,
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
        'welcome_enabled' => $welcomeEnabled,
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
        'welcome_enabled' => !empty($post['welcome_enabled']) ? 1 : 0,
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
        if (empty($bot['webhook_url'])) {
            $update['webhook_url'] = asr_tg_public_vk_callback_url();
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



function asr_tg_broadcast_card_vk_text(string $html): string {
    $text = (string)$html;
    $text = preg_replace_callback("~<a\\s+[^>]*href=([\"'])(.*?)\\1[^>]*>(.*?)</a>~isu", static function(array $m): string {
        $url = html_entity_decode(trim((string)$m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = html_entity_decode(trim(strip_tags((string)$m[3])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') return $label;
        if ($label === '' || $label === $url) return $url;
        return $label . ' (' . $url . ')';
    }, $text);
    $text = preg_replace('/<br\s*\/?>(\s*)/iu', "\n", $text);
    $text = preg_replace('/<\/(p|div|blockquote|pre|li|h[1-6])>/iu', "\n", (string)$text);
    $text = preg_replace('/<li[^>]*>/iu', '• ', (string)$text);
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x{00A0}\t ]+/u', ' ', (string)$text);
    $text = preg_replace('/ ?\n ?/u', "\n", (string)$text);
    $text = preg_replace('/\n{3,}/u', "\n\n", (string)$text);
    return trim((string)$text);
}

function asr_tg_broadcast_selected_platform(array $botsById): string {
    $platforms = [];
    foreach ($botsById as $bot) {
        $platform = function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : strtolower((string)($bot['channel_type'] ?? 'telegram'));
        $platforms[$platform === 'vk' ? 'vk' : 'telegram'] = true;
    }
    if (isset($platforms['vk']) && isset($platforms['telegram'])) return 'mixed';
    return isset($platforms['vk']) ? 'vk' : 'telegram';
}

function asr_tg_broadcast_validate_cards_for_platform(array $cards, string $platform): void {
    if ($platform !== 'vk') return;
    foreach ($cards as $index => $card) {
        $n = $index + 1;
        $type = (string)($card['type'] ?? 'text');
        if ($type === 'image') $type = 'photo';
        if ($type === 'file') $type = 'document';
        if ($type === 'video') {
            throw new RuntimeException('VK-видео через прямую загрузку временно отключено: VK обрабатывает video-вложения нестабильно, из-за этого очередь может зависнуть. Используйте картинку/обложку + кнопку-ссылку на видео.');
        }
        if (!in_array($type, ['text', 'photo', 'document'], true)) {
            throw new RuntimeException('VK-рассылка сейчас поддерживает текст, картинки и файлы. Удалите карточку #' . $n . ' или выберите Telegram-канал.');
        }
        if (in_array($type, ['photo', 'document'], true) && empty($card['media_url']) && empty($card['media_file_path']) && empty($card['local_file_path'])) {
            throw new RuntimeException('В VK-карточке #' . $n . ' выберите файл или укажите ссылку.');
        }
        $buttons = $card['buttons'] ?? [];
        if (is_array($buttons) && count($buttons) > 0) {
            $vkButtonCount = 0;
            foreach ($buttons as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $btn) {
                    if (!is_array($btn)) continue;
                    $buttonText = trim((string)($btn['text'] ?? ''));
                    if ($buttonText === '') continue;
                    $vkButtonCount++;
                    if ($vkButtonCount > 5) {
                        throw new RuntimeException('В VK-рассылке можно добавить максимум 5 кнопок-ссылок. Уменьшите количество кнопок в карточке #' . $n . '.');
                    }
                    if (mb_strlen($buttonText, 'UTF-8') > 40) {
                        throw new RuntimeException('Текст VK-кнопки «' . mb_substr($buttonText, 0, 40, 'UTF-8') . '…» слишком длинный. Лимит: 40 символов.');
                    }
                    $buttonType = (string)($btn['type'] ?? 'url');
                    if ($buttonType !== 'url') {
                        throw new RuntimeException('VK-рассылка сейчас поддерживает только кнопки-ссылки. Уберите кнопку-действие в карточке #' . $n . '.');
                    }
                    $buttonUrl = trim((string)($btn['url'] ?? ''));
                    if ($buttonUrl === '') {
                        throw new RuntimeException('У VK-кнопки «' . $buttonText . '» в карточке #' . $n . ' не указана ссылка.');
                    }
                    if (!preg_match('~^https?://~i', $buttonUrl)) {
                        throw new RuntimeException('Ссылка VK-кнопки «' . $buttonText . '» должна начинаться с http:// или https://.');
                    }
                }
            }
        }
        $text = asr_tg_broadcast_card_vk_text((string)($card['text'] ?? ''));
        if ($type === 'text' && $text === '') {
            throw new RuntimeException('Заполните текстовую карточку #' . $n . ' для VK-рассылки.');
        }
        if (mb_strlen($text, 'UTF-8') > 4096) {
            throw new RuntimeException('Текст карточки #' . $n . ' слишком длинный для VK. Лимит: 4096 символов.');
        }
    }
}


function asr_tg_broadcast_vk_download_image_to_temp(string $url): string {
    $url = asr_tg_validate_public_http_url($url, 'Ссылка на VK-картинку');
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Для отправки VK-картинок по ссылке нужен cURL. Загрузите файл картинки напрямую.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asr_vk_img_');
    if ($tmp === false) throw new RuntimeException('Не удалось создать временный файл для VK-картинки.');
    $fh = fopen($tmp, 'wb');
    if (!$fh) throw new RuntimeException('Не удалось открыть временный файл для VK-картинки.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_USERAGENT => 'ExecBooster Admin App VK Broadcast/1.0',
        CURLOPT_FAILONERROR => false,
    ]);
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось скачать VK-картинку по ссылке. HTTP ' . $httpCode . ($error ? ': ' . $error : ''));
    }
    $size = is_file($tmp) ? filesize($tmp) : 0;
    if ($size <= 0) {
        @unlink($tmp);
        throw new RuntimeException('VK-картинка по ссылке пустая.');
    }
    if ($size > 10 * 1024 * 1024) {
        @unlink($tmp);
        throw new RuntimeException('VK-картинка слишком большая. На этом шаге лимит: 10 МБ.');
    }
    $mime = function_exists('mime_content_type') ? (string)mime_content_type($tmp) : $contentType;
    if ($mime === '' || !str_starts_with(strtolower($mime), 'image/')) {
        @unlink($tmp);
        throw new RuntimeException('Ссылка для VK должна вести прямо на изображение JPG, PNG, WEBP или GIF.');
    }
    return $tmp;
}

function asr_tg_broadcast_vk_photo_local_path(array $card): array {
    $local = trim((string)($card['local_file_path'] ?? ''));
    if ($local !== '' && is_file($local) && is_readable($local)) return [$local, false];

    $relative = trim((string)($card['media_file_path'] ?? ''));
    if ($relative !== '') {
        $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
        if (is_file($candidate) && is_readable($candidate)) return [$candidate, false];
    }

    $url = trim((string)($card['media_url'] ?? ''));
    if ($url !== '') return [asr_tg_broadcast_vk_download_image_to_temp($url), true];

    throw new RuntimeException('Для VK-картинки не найден файл или ссылка.');
}


function asr_tg_broadcast_vk_download_file_to_temp(string $url): array {
    $url = asr_tg_validate_public_http_url($url, 'Ссылка на VK-файл');
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Для отправки VK-файлов по ссылке нужен cURL. Загрузите файл напрямую.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asr_vk_doc_');
    if ($tmp === false) throw new RuntimeException('Не удалось создать временный файл для VK-файла.');
    $fh = fopen($tmp, 'wb');
    if (!$fh) throw new RuntimeException('Не удалось открыть временный файл для VK-файла.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'ExecBooster Admin App VK Broadcast/1.0',
        CURLOPT_FAILONERROR => false,
    ]);
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось скачать VK-файл по ссылке. HTTP ' . $httpCode . ($error ? ': ' . $error : ''));
    }
    $size = is_file($tmp) ? filesize($tmp) : 0;
    if ($size <= 0) {
        @unlink($tmp);
        throw new RuntimeException('VK-файл по ссылке пустой.');
    }
    if ($size > 45 * 1024 * 1024) {
        @unlink($tmp);
        throw new RuntimeException('VK-файл слишком большой. На этом шаге лимит: 45 МБ.');
    }
    $path = parse_url($url, PHP_URL_PATH);
    $name = $path ? basename((string)$path) : '';
    $name = trim(rawurldecode($name));
    if ($name === '' || $name === '/' || $name === '.') $name = 'file';
    return [$tmp, true, $name];
}

function asr_tg_broadcast_vk_document_local_path(array $card): array {
    $name = trim((string)($card['media_file_name'] ?? ''));
    $local = trim((string)($card['local_file_path'] ?? ''));
    if ($local !== '' && is_file($local) && is_readable($local)) return [$local, false, $name !== '' ? $name : basename($local)];

    $relative = trim((string)($card['media_file_path'] ?? ''));
    if ($relative !== '') {
        $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
        if (is_file($candidate) && is_readable($candidate)) return [$candidate, false, $name !== '' ? $name : basename($candidate)];
    }

    $url = trim((string)($card['media_url'] ?? ''));
    if ($url !== '') return asr_tg_broadcast_vk_download_file_to_temp($url);

    throw new RuntimeException('Для VK-файла не найден файл или ссылка.');
}


function asr_tg_broadcast_vk_download_video_to_temp(string $url): array {
    $url = asr_tg_validate_public_http_url($url, 'Ссылка на VK-видео');
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Для отправки VK-видео по ссылке нужен cURL. Загрузите файл напрямую.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asr_vk_video_');
    if ($tmp === false) throw new RuntimeException('Не удалось создать временный файл для VK-видео.');
    $fh = fopen($tmp, 'wb');
    if (!$fh) throw new RuntimeException('Не удалось открыть временный файл для VK-видео.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'ExecBooster Admin App VK Broadcast/1.0',
        CURLOPT_FAILONERROR => false,
    ]);
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось скачать VK-видео по ссылке. HTTP ' . $httpCode . ($error ? ': ' . $error : ''));
    }
    $size = is_file($tmp) ? filesize($tmp) : 0;
    if ($size <= 0) {
        @unlink($tmp);
        throw new RuntimeException('VK-видео по ссылке пустое.');
    }
    if ($size > 200 * 1024 * 1024) {
        @unlink($tmp);
        throw new RuntimeException('VK-видео слишком большое. На этом шаге лимит: 200 МБ.');
    }
    $path = parse_url($url, PHP_URL_PATH);
    $name = $path ? basename((string)$path) : '';
    $name = trim(rawurldecode($name));
    if ($name === '' || $name === '/' || $name === '.') $name = 'video.mp4';
    return [$tmp, true, $name];
}

function asr_tg_broadcast_vk_video_local_path(array $card): array {
    $name = trim((string)($card['media_file_name'] ?? ''));
    $local = trim((string)($card['local_file_path'] ?? ''));
    if ($local !== '' && is_file($local) && is_readable($local)) return [$local, false, $name !== '' ? $name : basename($local)];

    $relative = trim((string)($card['media_file_path'] ?? ''));
    if ($relative !== '') {
        $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
        if (is_file($candidate) && is_readable($candidate)) return [$candidate, false, $name !== '' ? $name : basename($candidate)];
    }

    $url = trim((string)($card['media_url'] ?? ''));
    if ($url !== '') return asr_tg_broadcast_vk_download_video_to_temp($url);

    throw new RuntimeException('Для VK-видео не найден файл или ссылка.');
}


function asr_tg_broadcast_vk_persist_prepared_cards(PDO $pdo, int $broadcastId, array $cards): void {
    if ($broadcastId <= 0) return;
    $payload = json_encode(['cards' => $cards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false || $payload === '') return;
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_broadcasts SET payload_json = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$payload, $broadcastId]);
    } catch (Throwable $e) {
        // Кэш вложений — оптимизация. Если запись не удалась, отправку не блокируем.
    }
}

function asr_tg_broadcast_vk_prepare_photo_attachments(PDO $pdo, int $broadcastId, string $token, int|string $peerId, array $cards): array {
    if ($broadcastId <= 0 || $token === '' || (string)$peerId === '') return $cards;
    $changed = false;
    foreach ($cards as $idx => $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if ($type === 'image') $type = 'photo';
        if ($type !== 'photo') continue;
        $cached = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($cached !== '' && preg_match('/^photo-?\d+_\d+(?:_[A-Za-z0-9]+)?$/', $cached)) {
            continue;
        }
        [$path, $cleanup] = asr_tg_broadcast_vk_photo_local_path($card);
        try {
            $attachment = asr_tg_vk_upload_message_photo($token, $peerId, $path);
        } finally {
            if (!empty($cleanup)) @unlink($path);
        }
        if ($attachment === '') {
            throw new RuntimeException('VK вернул пустой attachment для картинки рассылки.');
        }
        $cards[$idx]['vk_prepared_attachment'] = $attachment;
        $cards[$idx]['vk_prepared_at'] = date('Y-m-d H:i:s');
        $changed = true;
    }
    if ($changed) {
        asr_tg_broadcast_vk_persist_prepared_cards($pdo, $broadcastId, $cards);
        asr_tg_log($pdo, 0, 'info', 'vk_broadcast_attachments_prepared', 'VK-вложения рассылки подготовлены один раз и сохранены в рассылке.', ['broadcast_id' => $broadcastId]);
    }
    return $cards;
}

function asr_tg_broadcast_vk_prepare_document_attachments(PDO $pdo, int $broadcastId, string $token, int|string $peerId, array $cards): array {
    if ($broadcastId <= 0 || $token === '' || (string)$peerId === '') return $cards;
    $changed = false;
    foreach ($cards as $idx => $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if ($type === 'file') $type = 'document';
        if ($type !== 'document') continue;
        $cached = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($cached !== '' && preg_match('/^doc-?\d+_\d+(?:_[A-Za-z0-9]+)?$/', $cached)) {
            continue;
        }
        [$path, $cleanup, $title] = asr_tg_broadcast_vk_document_local_path($card);
        try {
            $attachment = asr_tg_vk_upload_message_document($token, $peerId, $path, $title);
        } finally {
            if (!empty($cleanup)) @unlink($path);
        }
        if ($attachment === '') {
            throw new RuntimeException('VK вернул пустой attachment для файла рассылки.');
        }
        $cards[$idx]['vk_prepared_attachment'] = $attachment;
        $cards[$idx]['vk_prepared_at'] = date('Y-m-d H:i:s');
        $changed = true;
    }
    if ($changed) {
        asr_tg_broadcast_vk_persist_prepared_cards($pdo, $broadcastId, $cards);
        asr_tg_log($pdo, 0, 'info', 'vk_broadcast_document_attachments_prepared', 'VK-файлы рассылки подготовлены один раз и сохранены в рассылке.', ['broadcast_id' => $broadcastId]);
    }
    return $cards;
}


function asr_tg_broadcast_vk_prepare_video_attachments(PDO $pdo, int $broadcastId, string $token, int|string $peerId, array $cards): array {
    if ($broadcastId <= 0 || $token === '' || (string)$peerId === '') return $cards;
    $changed = false;
    foreach ($cards as $idx => $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if ($type !== 'video') continue;
        $cached = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($cached !== '' && preg_match('/^video-?\d+_\d+(?:_[A-Za-z0-9]+)?$/', $cached)) {
            continue;
        }
        [$path, $cleanup, $title] = asr_tg_broadcast_vk_video_local_path($card);
        try {
            $attachment = asr_tg_vk_upload_message_video($token, $peerId, $path, $title);
        } finally {
            if (!empty($cleanup)) @unlink($path);
        }
        if ($attachment === '') {
            throw new RuntimeException('VK вернул пустой attachment для видео рассылки.');
        }
        $cards[$idx]['vk_prepared_attachment'] = $attachment;
        $cards[$idx]['vk_prepared_at'] = date('Y-m-d H:i:s');
        $changed = true;
    }
    if ($changed) {
        asr_tg_broadcast_vk_persist_prepared_cards($pdo, $broadcastId, $cards);
        asr_tg_log($pdo, 0, 'info', 'vk_broadcast_video_attachments_prepared', 'VK-видео рассылки подготовлены один раз и сохранены в рассылке.', ['broadcast_id' => $broadcastId]);
    }
    return $cards;
}

function asr_tg_broadcast_vk_send_card(string $token, int|string $peerId, array $card): array {
    $type = (string)($card['type'] ?? 'text');
    if ($type === 'image') $type = 'photo';
    if ($type === 'file') $type = 'document';
    $vkText = asr_tg_broadcast_card_vk_text((string)($card['text'] ?? ''));
    $extra = [];
    $vkKeyboard = function_exists('asr_tg_vk_keyboard_from_buttons') ? asr_tg_vk_keyboard_from_buttons((array)($card['buttons'] ?? [])) : '';
    if ($vkKeyboard !== '') $extra['keyboard'] = $vkKeyboard;

    if ($type === 'photo') {
        $preparedAttachment = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($preparedAttachment !== '') {
            $extra['attachment'] = $preparedAttachment;
        } else {
            [$path, $cleanup] = asr_tg_broadcast_vk_photo_local_path($card);
            try {
                $extra['attachment'] = asr_tg_vk_upload_message_photo($token, $peerId, $path);
            } finally {
                if (!empty($cleanup)) @unlink($path);
            }
        }
        $response = asr_tg_vk_send_message($token, $peerId, $vkText, $extra);
        return ['response' => $response, 'text' => $vkText, 'type' => 'photo', 'keyboard' => $vkKeyboard !== '', 'attachment' => (string)($extra['attachment'] ?? '')];
    }

    if ($type === 'document') {
        $preparedAttachment = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($preparedAttachment !== '') {
            $extra['attachment'] = $preparedAttachment;
        } else {
            [$path, $cleanup, $title] = asr_tg_broadcast_vk_document_local_path($card);
            try {
                $extra['attachment'] = asr_tg_vk_upload_message_document($token, $peerId, $path, $title);
            } finally {
                if (!empty($cleanup)) @unlink($path);
            }
        }
        $response = asr_tg_vk_send_message($token, $peerId, $vkText, $extra);
        return ['response' => $response, 'text' => $vkText, 'type' => 'document', 'keyboard' => $vkKeyboard !== '', 'attachment' => (string)($extra['attachment'] ?? '')];
    }

    if ($type === 'video') {
        $preparedAttachment = trim((string)($card['vk_prepared_attachment'] ?? ''));
        if ($preparedAttachment !== '') {
            $extra['attachment'] = $preparedAttachment;
        } else {
            [$path, $cleanup, $title] = asr_tg_broadcast_vk_video_local_path($card);
            try {
                $extra['attachment'] = asr_tg_vk_upload_message_video($token, $peerId, $path, $title);
            } finally {
                if (!empty($cleanup)) @unlink($path);
            }
        }
        $response = asr_tg_vk_send_message($token, $peerId, $vkText, $extra);
        return ['response' => $response, 'text' => $vkText, 'type' => 'video', 'keyboard' => $vkKeyboard !== '', 'attachment' => (string)($extra['attachment'] ?? '')];
    }

    if ($vkText === '') throw new RuntimeException('Пустой текст VK-рассылки.');
    $response = asr_tg_vk_send_message($token, $peerId, $vkText, $extra);
    return ['response' => $response, 'text' => $vkText, 'type' => 'text', 'keyboard' => $vkKeyboard !== '', 'attachment' => ''];
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


function asr_tg_broadcast_vk_test_target(PDO $pdo, array $botIds, string $vkPeerId): array {
    $vkPeerId = trim($vkPeerId);
    if ($vkPeerId === '') throw new RuntimeException('Укажите VK ID для тестовой отправки.');
    if (!preg_match('/^-?\d{3,20}$/', $vkPeerId)) {
        throw new RuntimeException('VK ID должен быть числовым. Вставьте VK User ID / Peer ID без ссылки и лишних символов.');
    }
    $botIds = array_values(array_unique(array_filter(array_map('intval', $botIds), static fn($id) => $id > 0)));
    if (!$botIds) throw new RuntimeException('Выберите VK-канал для тестовой отправки.');

    $placeholders = implode(',', array_fill(0, count($botIds), '?'));
    $params = array_merge($botIds, [$vkPeerId, $vkPeerId, $vkPeerId]);
    $sql = "SELECT s.*, b.title AS bot_title, b.bot_username, b.channel_type, b.vk_screen_name, b.vk_api_token_encrypted
        FROM oca_telegram_bot_subscribers s
        JOIN oca_telegram_bots b ON b.id = s.bot_id
        WHERE s.bot_id IN ($placeholders)
          AND COALESCE(s.status, 'active') = 'active'
          AND (
              CAST(COALESCE(s.external_chat_id, '') AS CHAR) = ?
              OR CAST(COALESCE(s.external_user_id, '') AS CHAR) = ?
              OR CAST(COALESCE(s.chat_id, '') AS CHAR) = ?
          )
        ORDER BY FIELD(s.bot_id, $placeholders)
        LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $botIds));
    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscriber) {
        throw new RuntimeException('VK-подписчик с таким ID не найден среди активных подписчиков выбранного VK-канала. Чтобы тест пришёл, пользователь должен быть подписан на выбранный VK-канал и хотя бы раз написать в сообщения сообщества.');
    }
    $peerId = trim((string)($subscriber['external_chat_id'] ?? ''));
    if ($peerId === '') $peerId = trim((string)($subscriber['chat_id'] ?? ''));
    if ($peerId === '') throw new RuntimeException('У найденного VK-подписчика нет Peer ID. Попросите пользователя написать в сообщения сообщества ещё раз.');
    return [$subscriber, $peerId];
}

function asr_tg_send_broadcast_test_from_post(PDO $pdo, array $post, array $files = []): array {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для проверки рассылок.');
    asr_tg_repository_ensure_schema($pdo);

    [$botIds, $botsById] = asr_tg_broadcast_bot_ids_from_post($pdo, $post);
    $primaryBot = [];
    if ($botIds) {
        $primaryBot = $botsById[(int)$botIds[0]] ?? [];
    }

    $title = trim((string)($post['broadcast_title'] ?? 'Тестовая рассылка'));
    if ($title === '') $title = 'Тестовая рассылка';
    $cards = asr_tg_build_broadcast_cards($post, $files);
    if (!$cards) throw new RuntimeException('Добавьте хотя бы одну карточку сообщения.');
    $platform = asr_tg_broadcast_selected_platform($botsById);
    if ($platform === 'mixed') {
        throw new RuntimeException('Тест смешанной Telegram+VK рассылки пока не поддерживается. Выберите каналы одной платформы.');
    }
    asr_tg_broadcast_validate_cards_for_platform($cards, $platform);

    if ($platform === 'vk') {
        $vkPeerId = trim((string)($post['vk_test_peer_id'] ?? ''));
        [$subscriber, $peerId] = asr_tg_broadcast_vk_test_target($pdo, $botIds, $vkPeerId);
        $vkBot = $botsById[(int)($subscriber['bot_id'] ?? 0)] ?? $primaryBot;
        if (!$vkBot || asr_tg_channel_type_of($vkBot) !== 'vk') throw new RuntimeException('VK-канал для тестовой отправки не найден.');
        $token = asr_tg_decrypt_token((string)($vkBot['vk_api_token_encrypted'] ?? ''));
        if ($token === '') throw new RuntimeException('Не удалось расшифровать токен VK-канала. Проверьте ключ доступа сообщества.');

        $sent = 0;
        $errors = [];
        try {
            asr_tg_vk_send_message($token, $peerId, "🧪 Тестовая проверка VK-рассылки
" . $title);
            foreach ($cards as $card) {
                $cardForVk = $card;
                $cardForVk['text'] = asr_tg_render_subscriber_macros($pdo, (string)($card['text'] ?? ''), $subscriber);
                asr_tg_broadcast_vk_send_card($token, $peerId, $cardForVk);
                usleep(220000);
            }
            $sent = 1;
            asr_tg_log($pdo, (int)($vkBot['id'] ?? 0), 'info', 'vk_broadcast_test_sent', 'Тестовая VK-рассылка отправлена.', ['subscriber_id' => (int)($subscriber['id'] ?? 0), 'vk_peer_id' => $peerId]);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            throw new RuntimeException('Тестовая VK-рассылка не отправлена: ' . $e->getMessage());
        }
        return ['sent' => $sent, 'failed' => $sent ? 0 : 1, 'errors' => $errors, 'recipients' => 1, 'platform' => 'vk'];
    }

    $cfg = asr_tg_broadcast_test_bot_config();
    $token = trim((string)($cfg['bot_token'] ?? ''));
    if ($token === '') throw new RuntimeException('В настройках не указан токен технического бота для проверки рассылок.');

    $recipients = asr_tg_broadcast_test_recipients($pdo);
    if (!$recipients) throw new RuntimeException('Нет сотрудников с подключённым тестовым ботом рассылок. Откройте карточку сотрудника в «Наша команда», скопируйте персональную ссылку из блока «Тестовый бот рассылок» и нажмите Start. Если бот уже открыт без ссылки, отправьте /start ещё раз по персональной ссылке.');

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
    $platform = asr_tg_broadcast_selected_platform($botsById);
    if ($platform === 'mixed') {
        throw new RuntimeException('Пока нельзя запускать одну рассылку одновременно по Telegram и VK. Выберите каналы одной платформы. Смешанные рассылки подключим отдельным шагом.');
    }
    asr_tg_broadcast_validate_cards_for_platform($cards, $platform);
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
        'platform' => $platform,
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
    if (function_exists('asr_tg_send_queue_ensure_schema')) {
        asr_tg_send_queue_ensure_schema($pdo);
    }
    $limit = max(1, min(200, $limit));
    asr_tg_broadcast_activate_due_scheduled($pdo, 200);
    $staleReset = asr_tg_broadcast_reset_stale_processing($pdo, $broadcastId, 3);
    $items = asr_tg_broadcast_recipients_next($pdo, $limit, $broadcastId);
    $sent = 0; $failed = 0; $processedBroadcasts = [];
    foreach ($items as $item) {
        $recipientId = (int)$item['id'];
        $bid = (int)$item['broadcast_id'];
        $processedBroadcasts[$bid] = true;
        asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'processing', 'processing_at' => date('Y-m-d H:i:s'), 'next_attempt_at' => null, 'attempts' => ((int)$item['attempts']) + 1]);
        $channelType = function_exists('asr_tg_normalize_channel_type') ? asr_tg_normalize_channel_type((string)($item['channel_type'] ?? 'telegram')) : strtolower((string)($item['channel_type'] ?? 'telegram'));
        $token = $channelType === 'vk'
            ? asr_tg_decrypt_token((string)($item['vk_api_token_encrypted'] ?? ''))
            : asr_tg_decrypt_token((string)$item['bot_token_encrypted']);
        try {
            if ($token === '') {
                throw new RuntimeException($channelType === 'vk' ? 'Не удалось расшифровать токен VK-сообщества.' : 'Не удалось расшифровать токен бота.');
            }
            $cards = asr_tg_cards_from_broadcast_item($item);
            asr_tg_broadcast_validate_cards_for_platform($cards, $channelType);
            if ($channelType === 'vk') {
                $cards = asr_tg_broadcast_vk_prepare_photo_attachments($pdo, $bid, $token, (string)$item['chat_id'], $cards);
                $cards = asr_tg_broadcast_vk_prepare_document_attachments($pdo, $bid, $token, (string)$item['chat_id'], $cards);
            }
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
            foreach ($cards as $cardIndex => $card) {
                if ($channelType === 'vk') {
                    $vkSent = asr_tg_broadcast_vk_send_card($token, (string)$item['chat_id'], $card);
                    $response = (array)($vkSent['response'] ?? []);
                    $messageId = isset($response['response']) && is_numeric($response['response']) ? (int)$response['response'] : null;
                    $lastMessageId = $messageId ?: $lastMessageId;
                    $vkText = (string)($vkSent['text'] ?? '');
                    $vkType = (string)($vkSent['type'] ?? 'text');
                    $messagePayload = ['broadcast_id' => $bid, 'card_index' => $cardIndex, 'platform' => 'vk', 'vk' => $response, 'vk_keyboard' => !empty($vkSent['keyboard']), 'vk_attachment' => (string)($vkSent['attachment'] ?? '')];
                    if (isset($card['original_text']) && (string)$card['original_text'] !== (string)($card['text'] ?? '')) $messagePayload['original_text'] = (string)$card['original_text'];
                    asr_tg_message_add($pdo, (int)$item['bot_id'], (int)$item['subscriber_id'], 'out', $vkType, $vkText, $messageId, $messagePayload);
                } else {
                    $response = asr_tg_api_send_broadcast_card($token, (string)$item['chat_id'], $card);
                    $sentMessage = is_array($response['result'] ?? null) ? (array)$response['result'] : [];
                    $messageId = isset($sentMessage['message_id']) ? (int)$sentMessage['message_id'] : null;
                    $lastMessageId = $messageId ?: $lastMessageId;
                    $messagePayload = ['broadcast_id' => $bid, 'card_index' => $cardIndex, 'telegram' => $sentMessage];
                    if (isset($card['original_text']) && (string)$card['original_text'] !== (string)($card['text'] ?? '')) $messagePayload['original_text'] = (string)$card['original_text'];
                    asr_tg_message_add($pdo, (int)$item['bot_id'], (int)$item['subscriber_id'], 'out', (string)($card['type'] ?? 'text'), (string)($card['text'] ?? ''), $messageId, $messagePayload);
                }
                usleep($channelType === 'vk' ? 180000 : 120000);
            }
            asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'sent', 'telegram_message_id' => $lastMessageId, 'last_error' => null, 'processing_at' => null, 'next_attempt_at' => null, 'sent_at' => date('Y-m-d H:i:s')]);
            $sent++;
        } catch (Throwable $e) {
            $attempts = ((int)$item['attempts']) + 1;
            if ($channelType === 'vk') {
                $error = $e->getMessage();
                $isPermanentVkError = str_contains($error, 'VK-видео через прямую загрузку временно отключено')
                    || str_contains($error, 'VK-рассылка сейчас поддерживает текст, картинки и файлы')
                    || str_contains($error, 'VK-рассылка сейчас поддерживает только кнопки-ссылки')
                    || str_contains($error, 'можно добавить максимум 5 кнопок-ссылок');
                if ($isPermanentVkError) {
                    asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'failed', 'last_error' => $error, 'processing_at' => null, 'next_attempt_at' => null]);
                    $failed++;
                } elseif ($attempts < 4) {
                    $delay = 60 * $attempts;
                    asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'retry', 'last_error' => $error, 'processing_at' => null, 'next_attempt_at' => date('Y-m-d H:i:s', time() + $delay)]);
                } else {
                    asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'failed', 'last_error' => $error, 'processing_at' => null, 'next_attempt_at' => null]);
                    $failed++;
                }
                asr_tg_log($pdo, (int)$item['bot_id'], 'error', 'vk_broadcast_queue_send_failed', $error, ['broadcast_id' => $bid, 'recipient_id' => $recipientId, 'attempts' => $attempts, 'permanent' => $isPermanentVkError]);
            } else {
                $policy = asr_tg_classify_telegram_send_error($e);
                $error = (string)($policy['message'] ?? $e->getMessage());
                if (!empty($policy['is_retryable']) && $attempts < 5) {
                    $delay = asr_tg_telegram_retry_delay_seconds($policy, $attempts);
                    asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'retry', 'last_error' => $error, 'next_attempt_at' => date('Y-m-d H:i:s', time() + $delay)]);
                } else {
                    asr_tg_broadcast_recipient_update($pdo, $recipientId, ['status' => 'failed', 'last_error' => $error]);
                    $failed++;
                    if (!empty($policy['mark_subscriber_blocked'])) {
                        asr_tg_subscriber_mark_status($pdo, (int)$item['subscriber_id'], 'blocked');
                    }
                }
                asr_tg_log($pdo, (int)$item['bot_id'], 'error', 'broadcast_queue_send_failed', $error, ['broadcast_id' => $bid, 'recipient_id' => $recipientId, 'error_policy' => $policy['kind'] ?? 'unknown']);
            }
        }
        usleep(50000);
    }
    foreach (array_keys($processedBroadcasts) as $bid) {
        asr_tg_broadcast_recalc($pdo, (int)$bid);
    }
    return ['processed' => count($items), 'sent' => $sent, 'failed' => $failed, 'stale_processing_reset' => $staleReset, 'broadcasts' => array_keys($processedBroadcasts)];
}

function asr_tg_cancel_broadcast(PDO $pdo, int $broadcastId): void {
    if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для отмены рассылок.');
    $broadcast = asr_tg_broadcast_find($pdo, $broadcastId);
    if (!$broadcast) throw new RuntimeException('Рассылка не найдена.');
    $status = (string)($broadcast['status'] ?? '');
    if (in_array($status, ['finished', 'finished_with_errors', 'skipped', 'cancelled'], true)) {
        throw new RuntimeException('Эту рассылку уже нельзя отменить.');
    }
    $pdo->prepare("UPDATE oca_telegram_bot_broadcast_recipients SET status = 'skipped', updated_at = NOW() WHERE broadcast_id = ? AND status IN ('pending','processing','retry')")->execute([$broadcastId]);
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
        $pdo->prepare("UPDATE oca_telegram_bot_broadcast_recipients SET status = 'skipped', updated_at = NOW() WHERE broadcast_id = ? AND status IN ('pending','processing','retry')")->execute([(int)$id]);
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
        $stmt = $pdo->prepare('SELECT s.*, b.title AS bot_title, b.bot_username, b.channel_type, b.vk_screen_name FROM oca_telegram_bot_subscribers s LEFT JOIN oca_telegram_bots b ON b.id = s.bot_id WHERE s.id = ? AND s.bot_id = ? LIMIT 1');
        $stmt->execute([$subscriberId, $botId]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $subscriber = []; }

    $name = trim(trim((string)($subscriber['first_name'] ?? '')) . ' ' . trim((string)($subscriber['last_name'] ?? '')));
    if ($name === '') $name = trim((string)($subscriber['username'] ?? ''));
    if ($name === '') $name = 'Подписчик #' . $subscriberId;
    $username = trim((string)($subscriber['username'] ?? ''));
    if ($username !== '') $username = '@' . ltrim($username, '@');
    $platform = strtolower(trim((string)($subscriber['platform'] ?? ($subscriber['channel_type'] ?? ($bot['channel_type'] ?? 'telegram')))));
    if ($platform === '') $platform = strtolower(trim((string)($bot['channel_type'] ?? 'telegram')));
    $platformLabel = $platform === 'vk' ? 'ВК' : 'Telegram';
    $externalUserId = trim((string)($subscriber['external_user_id'] ?? ''));
    $externalChatId = trim((string)($subscriber['external_chat_id'] ?? ''));
    $vkScreenName = trim((string)($subscriber['vk_screen_name'] ?? ($bot['vk_screen_name'] ?? '')));
    $channel = trim((string)($subscriber['bot_title'] ?? ($bot['title'] ?? 'Канал')));
    $messageType = isset($message['text']) ? 'текст' : 'сообщение';
    $preview = trim((string)($text ?? ''));
    if ($preview === '') $preview = '[' . $messageType . ']';
    $preview = mb_substr($preview, 0, 900, 'UTF-8');
    $urlBase = function_exists('asr_current_base_url') ? rtrim(asr_current_base_url(), '/') : '';
    $dialogUrl = $urlBase !== '' ? ($urlBase . '/admin.php?tab=telegram_bots&page=messages&dialog_view=new&bot_id=' . $botId . '&subscriber_id=' . $subscriberId) : '';

    $body = "🔔 Новое сообщение в диалоге

";
    $body .= "Источник: " . $platformLabel . "
";
    $body .= "Канал: " . $channel . "
";
    if ($platform === 'vk') {
        if ($externalUserId !== '') $body .= "VK User ID: " . $externalUserId . "
";
        if ($externalChatId !== '' && $externalChatId !== $externalUserId) $body .= "VK Peer ID: " . $externalChatId . "
";
        if ($vkScreenName !== '') $body .= "Адрес: vk.com/" . ltrim($vkScreenName, '@/') . "
";
    }
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


function asr_tg_bot_commands_delete_reserved_start(PDO $pdo, int $botId): void {
    if ($botId <= 0 || !asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) return;
    try {
        $stmt = $pdo->prepare("DELETE FROM oca_telegram_bot_commands WHERE bot_id = ? AND (command = 'start' OR command = '/start')");
        $stmt->execute([$botId]);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'bot_reserved_start_command_cleanup_failed', $e->getMessage()); } catch (Throwable $ignored) {}
    }
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

    // Нормальный Telegram deep link приходит в Bot API как: /start <payload>.
    if (preg_match('/^\/start(?:@[a-zA-Z0-9_]{3,64})?(?:\s+(.+))?$/u', $text, $m)) {
        $payload = trim((string)($m[1] ?? ''));
        if ($payload !== '') return mb_substr(rawurldecode($payload), 0, 128, 'UTF-8');
        return '';
    }

    // Страховка для случаев, когда в webhook/лог прилетает не команда, а кусок ссылки.
    if (preg_match('/(?:[?&]|^)(?:start|startapp)=([^&\s]+)/u', $text, $m)) {
        $payload = trim(rawurldecode((string)($m[1] ?? '')));
        if ($payload !== '') return mb_substr($payload, 0, 128, 'UTF-8');
    }

    return '';
}

function asr_tg_runtime_deeplink_normalize_runtime_row(PDO $pdo, array $row, string $code): ?array {
    if (function_exists('asr_tg_scenario_deeplink_normalize_row')) {
        try { $row = asr_tg_scenario_deeplink_normalize_row($row); } catch (Throwable $ignored) {}
    }

    $scenarioId = 0;
    foreach (['scenario_id','flow_id'] as $column) {
        if (isset($row[$column]) && (int)$row[$column] > 0) { $scenarioId = (int)$row[$column]; break; }
    }

    $blockId = 0;
    foreach (['block_id','scenario_block_id','target_block_id','start_block_id','step_id'] as $column) {
        if (isset($row[$column]) && (int)$row[$column] > 0) { $blockId = (int)$row[$column]; break; }
    }

    if ($scenarioId <= 0 && $blockId > 0) {
        $scenarioId = asr_tg_runtime_scenario_from_step($pdo, $blockId);
    }

    if ($scenarioId <= 0 || $blockId <= 0) return null;
    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block || (string)($block['type'] ?? '') === 'start') return null;

    $row['scenario_id'] = $scenarioId;
    $row['block_id'] = $blockId;
    $row['code'] = (string)($row['code'] ?? $row['token'] ?? $code);
    return $row;
}

function asr_tg_runtime_deeplink_find_by_code(PDO $pdo, string $code): ?array {
    $code = trim(rawurldecode($code));
    if (preg_match('/^\/start(?:@[a-zA-Z0-9_]{3,64})?\s+(.+)$/u', $code, $m)) {
        $code = trim((string)$m[1]);
    }
    if (preg_match('/(?:[?&]|^)(?:start|startapp)=([^&\s]+)/u', $code, $m)) {
        $code = trim(rawurldecode((string)$m[1]));
    }
    $code = trim($code);
    if ($code === '') return null;

    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_deeplinks')) {
            $lookupColumns = [];
            if (function_exists('asr_tg_scenario_deeplink_code_column')) {
                $lookupColumns[] = asr_tg_scenario_deeplink_code_column($pdo);
            }
            foreach (['code','token','payload','start_code'] as $column) {
                if (asr_tg_column_exists($pdo, 'oca_telegram_bot_scenario_deeplinks', $column)) $lookupColumns[] = $column;
            }
            $lookupColumns = array_values(array_unique(array_filter(array_map('strval', $lookupColumns))));
            $isEnabledSql = asr_tg_column_exists($pdo, 'oca_telegram_bot_scenario_deeplinks', 'is_enabled') ? ' AND (is_enabled = 1 OR is_enabled IS NULL)' : '';
            foreach ($lookupColumns as $column) {
                $safeColumn = '`' . str_replace('`', '``', $column) . '`';
                $stmt = $pdo->prepare("SELECT *, {$safeColumn} AS code FROM oca_telegram_bot_scenario_deeplinks WHERE {$safeColumn} = ?{$isEnabledSql} ORDER BY id ASC LIMIT 1");
                $stmt->execute([$code]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $normalized = asr_tg_runtime_deeplink_normalize_runtime_row($pdo, $row, $code);
                    if ($normalized) return $normalized;
                }
            }
        }

        // Страховка: наши диплинки детерминированные — dl-{scenario_id}-{block_id}.
        // Поддерживаем и старый вариант с подчёркиваниями: dl_1_23.
        if (preg_match('/^dl[-_](\d+)[-_](\d+)$/', $code, $m)) {
            $scenarioId = (int)$m[1];
            $blockId = (int)$m[2];
            if ($scenarioId > 0 && $blockId > 0) {
                $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
                if ($block && (string)($block['type'] ?? '') !== 'start') {
                    return [
                        'id' => 0,
                        'scenario_id' => $scenarioId,
                        'block_id' => $blockId,
                        'code' => $code,
                        'title' => (string)($block['title'] ?? ''),
                        'is_enabled' => 1,
                        '_runtime_fallback' => 'deterministic_dl_code',
                    ];
                }
            }
        }

        return null;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, null, 'warning', 'scenario_deeplink_lookup_failed', $e->getMessage(), ['code' => $code]); } catch (Throwable $ignored) {}
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


function asr_tg_runtime_question_no_answer_target_block_id(PDO $pdo, int $scenarioId, int $blockId, array $card): int {
    $targetBlockId = max(0, (int)($card['no_answer_target_block_id'] ?? 0));
    if ($targetBlockId > 0) return $targetBlockId;
    if ($scenarioId <= 0 || $blockId <= 0) return 0;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_links')) return 0;
        $stmt = $pdo->prepare("SELECT to_block_id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = 'question_no_answer' AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$scenarioId, $blockId]);
        return max(0, (int)($stmt->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0;
    }
}


function asr_tg_runtime_question_datetime_with_offset(array $card, string $valueKey, string $unitKey, int $defaultValue, string $defaultUnit, ?DateTimeImmutable $now = null): string {
    $value = max(1, min(999, (int)($card[$valueKey] ?? $defaultValue)));
    $unit = (string)($card[$unitKey] ?? $defaultUnit);
    if (!in_array($unit, ['minutes', 'hours', 'days'], true)) $unit = $defaultUnit;
    $tzName = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Almaty'); }
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    return $now->modify('+' . $value . ' ' . $unit)->format('Y-m-d H:i:s');
}

function asr_tg_runtime_question_wait_deadline_sql(array $card, ?DateTimeImmutable $now = null): ?string {
    $hasNoAnswerTarget = max(0, (int)($card['no_answer_target_block_id'] ?? 0)) > 0;
    $hasCheck = !empty($card['enable_check']);
    $hasReminder = !empty($card['remind_no_answer']);
    if (!$hasNoAnswerTarget && !$hasCheck && !$hasReminder) return null;
    return asr_tg_runtime_question_datetime_with_offset($card, 'wait_value', 'wait_unit', 24, 'hours', $now);
}

function asr_tg_runtime_question_reminder_next_sql(array $card, ?DateTimeImmutable $now = null): string {
    return asr_tg_runtime_question_datetime_with_offset($card, 'remind_value', 'remind_unit', 5, 'minutes', $now);
}

function asr_tg_runtime_question_first_check_sql(array $card, ?DateTimeImmutable $now = null): ?string {
    $waitDeadlineAt = asr_tg_runtime_question_wait_deadline_sql($card, $now);
    if ($waitDeadlineAt === null) return null;
    if (empty($card['remind_no_answer'])) return $waitDeadlineAt;
    $reminderAt = asr_tg_runtime_question_reminder_next_sql($card, $now);
    return strcmp($reminderAt, $waitDeadlineAt) < 0 ? $reminderAt : $waitDeadlineAt;
}

function asr_tg_runtime_question_deadline_sql(array $card, ?DateTimeImmutable $now = null): ?string {
    return asr_tg_runtime_question_first_check_sql($card, $now);
}

function asr_tg_runtime_question_wait_event(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId): ?array {
    try {
        $stmt = $pdo->prepare("SELECT id, payload_json, created_at FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND block_id = ? AND event_type = 'runtime_question_waiting' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$botId, $subscriberId, $scenarioId, $blockId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $row['_payload'] = is_array($payload) ? $payload : [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function asr_tg_runtime_question_has_answer_after(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId, int $eventId): bool {
    if ($eventId <= 0) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND block_id = ? AND id > ? AND event_type IN ('runtime_question_text_received','runtime_question_answer_received','runtime_question_no_answer') LIMIT 1");
        $stmt->execute([$botId, $subscriberId, $scenarioId, $blockId, $eventId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_runtime_question_has_reminder_after(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId, int $eventId): bool {
    if ($eventId <= 0) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND block_id = ? AND id > ? AND event_type = 'runtime_question_reminder_sent' LIMIT 1");
        $stmt->execute([$botId, $subscriberId, $scenarioId, $blockId, $eventId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET current_block_id = ?, status = ?, next_run_at = ?, last_error = ?, stopped_at = CASE WHEN ? IN ('stopped','finished','error') THEN NOW() ELSE stopped_at END, finished_at = CASE WHEN ? = 'finished' THEN NOW() ELSE finished_at END, updated_at = NOW() WHERE scenario_id = ? AND subscriber_id = ? AND bot_id = ? AND status IN ('active','waiting','error','queued','processing') ORDER BY id DESC LIMIT 1");
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

function asr_tg_runtime_button_rows_for_telegram(array $rows, int $sourceBlockId = 0): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) $row = [$row];
        $outRow = [];
        foreach ($row as $btn) {
            if (!is_array($btn)) continue;
            $text = trim((string)($btn['text'] ?? $btn['title'] ?? ''));
            if ($text === '') continue;
            $item = [
                'text' => mb_substr($text, 0, 64, 'UTF-8'),
            ];
            $url = trim((string)($btn['url'] ?? ''));
            $targetBlockId = max(0, (int)($btn['target_block_id'] ?? $btn['target'] ?? 0));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $item['url'] = $url;
            } elseif ($targetBlockId > 0) {
                // Runtime v0.2.1 только выводит кнопки. Сам тихий переход по callback_query
                // подключим следующим отдельным патчем, чтобы не ломать оживлённый /help.
                $item['callback_data'] = $sourceBlockId > 0 ? ('scn_goto_' . $sourceBlockId . '_' . $targetBlockId) : ('scn_goto_' . $targetBlockId);
            } else {
                $item['callback_data'] = $sourceBlockId > 0 ? ('scn_noop_' . $sourceBlockId) : 'scn_noop';
            }
            $outRow[] = $item;
            if (count($outRow) >= 4) break;
        }
        if ($outRow) $out[] = $outRow;
        if (count($out) >= 8) break;
    }
    return $out;
}


function asr_tg_runtime_card_local_file_path(array $card): string {
    $localPath = trim((string)($card['local_file_path'] ?? ''));
    if ($localPath !== '' && is_file($localPath) && is_readable($localPath)) return $localPath;

    $relative = trim((string)($card['media_file_path'] ?? ''));
    if ($relative !== '' && function_exists('asr_tg_project_root_dir')) {
        $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
        if (is_file($candidate) && is_readable($candidate)) return $candidate;
    }

    return '';
}

function asr_tg_runtime_media_items_count(array $items): int {
    $count = 0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $mediaUrl = trim((string)($item['media_url'] ?? $item['url'] ?? ''));
        $relative = trim((string)($item['media_file_path'] ?? ''));
        $localPath = trim((string)($item['local_file_path'] ?? ''));
        if ($localPath === '' && $relative !== '' && function_exists('asr_tg_project_root_dir')) {
            $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
            if (is_file($candidate) && is_readable($candidate)) $localPath = $candidate;
        }
        if ($mediaUrl !== '' || $localPath !== '') $count++;
    }
    return $count;
}

function asr_tg_runtime_question_answer_rows(array $card, int $blockId, int $cardIndex): array {
    $answers = isset($card['answers']) && is_array($card['answers']) ? array_values($card['answers']) : [];
    $rows = [];
    $row = [];
    foreach ($answers as $answerIndex => $answer) {
        if (!is_array($answer)) continue;
        $text = trim((string)($answer['text'] ?? $answer['title'] ?? ''));
        if ($text === '') continue;
        $targetBlockId = max(0, (int)($answer['target_block_id'] ?? 0));
        $callback = $targetBlockId > 0
            ? ('scn_goto_' . $blockId . '_' . $targetBlockId)
            : ('scn_answer_' . $blockId . '_' . $cardIndex . '_' . $answerIndex);
        $row[] = [
            'text' => mb_substr($text, 0, 64, 'UTF-8'),
            'callback_data' => mb_substr($callback, 0, 64, 'UTF-8'),
        ];
        if (count($row) >= 2) {
            $rows[] = $row;
            $row = [];
        }
        if (count($rows) >= 8) break;
    }
    if ($row && count($rows) < 8) $rows[] = $row;
    return $rows;
}


function asr_tg_runtime_answer_callback(PDO $pdo, array $bot, int $botId, string $callbackQueryId, string $text = '', bool $showAlert = false): void {
    if ($callbackQueryId === '') return;
    try {
        $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
        if ($token === '') return;
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert ? true : false,
        ];
        if ($text !== '') $payload['text'] = mb_substr($text, 0, 180, 'UTF-8');
        asr_tg_api_request($token, 'answerCallbackQuery', $payload);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_callback_answer_failed', $e->getMessage()); } catch (Throwable $ignored) {}
    }
}

function asr_tg_runtime_recent_context(PDO $pdo, int $botId, int $subscriberId, int $blockId = 0): ?array {
    if ($botId <= 0 || $subscriberId <= 0) return null;
    asr_tg_repository_ensure_scenario_schema($pdo);
    try {
        $where = 'bot_id = ? AND subscriber_id = ? AND status IN (\'active\',\'waiting\')';
        $params = [$botId, $subscriberId];
        if ($blockId > 0) {
            $where .= ' AND current_block_id = ?';
            $params[] = $blockId;
        }
        $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_subscriber_scenarios WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_context_lookup_failed', $e->getMessage(), ['subscriber_id' => $subscriberId, 'block_id' => $blockId]); } catch (Throwable $ignored) {}
        return null;
    }
}

function asr_tg_runtime_find_question_card(PDO $pdo, int $scenarioId, int $blockId, int $cardIndex): ?array {
    if ($scenarioId <= 0 || $blockId <= 0 || $cardIndex < 0) return null;
    try {
        $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
        if (!$block) return null;
        $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
        $card = $cards[$cardIndex] ?? null;
        if (!is_array($card) || (string)($card['type'] ?? '') !== 'question') return null;
        return $card;
    } catch (Throwable $e) {
        try { asr_tg_runtime_log_event($pdo, 0, 0, $scenarioId, $blockId, 'runtime_question_card_lookup_failed', $e->getMessage(), ['card_index' => $cardIndex]); } catch (Throwable $ignored) {}
        return null;
    }
}

function asr_tg_runtime_question_save_field_code(array $card): string {
    $candidates = [
        $card['save_field_code'] ?? null,
        $card['saveFieldCode'] ?? null,
        $card['field_code'] ?? null,
        $card['save_to_field_code'] ?? null,
        $card['variable_code'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $code = trim((string)$candidate);
        if ($code !== '') return $code;
    }
    return '';
}

function asr_tg_runtime_question_field_prepare_value(PDO $pdo, int $botId, string $code, string $answerText): array {
    $code = trim($code);
    $answerText = trim($answerText);
    if ($code === '') {
        return ['ok' => true, 'field_id' => 0, 'field_type' => '', 'value' => $answerText, 'message' => ''];
    }

    $fields = asr_tg_custom_fields_all($pdo, 0, true);
    $field = null;
    foreach ($fields as $item) {
        if ((string)($item['code'] ?? '') === $code) {
            $field = $item;
            break;
        }
    }
    if (!$field) {
        return ['ok' => false, 'field_id' => 0, 'field_type' => '', 'value' => '', 'message' => 'Поле для сохранения ответа не найдено.'];
    }

    $fieldId = (int)($field['id'] ?? 0);
    $fieldType = (string)($field['field_type'] ?? 'text');
    if (!in_array($fieldType, ['text', 'number'], true)) {
        return ['ok' => false, 'field_id' => $fieldId, 'field_type' => $fieldType, 'value' => '', 'message' => 'В этот вопрос можно сохранять только текст или число.'];
    }

    if ($fieldType === 'number') {
        $normalized = trim(str_replace([',', ' '], ['.', ''], $answerText));
        if ($normalized === '' || !preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $normalized)) {
            return ['ok' => false, 'field_id' => $fieldId, 'field_type' => $fieldType, 'value' => '', 'message' => 'Пожалуйста, введите только число.'];
        }
        return ['ok' => true, 'field_id' => $fieldId, 'field_type' => $fieldType, 'value' => $normalized, 'message' => ''];
    }

    return ['ok' => true, 'field_id' => $fieldId, 'field_type' => $fieldType, 'value' => mb_substr($answerText, 0, 5000, 'UTF-8'), 'message' => ''];
}

function asr_tg_runtime_save_question_answer(PDO $pdo, int $botId, int $subscriberId, array $card, string $answerText): array {
    $code = asr_tg_runtime_question_save_field_code($card);
    if ($code === '' || trim($answerText) === '') {
        return ['ok' => true, 'saved' => false, 'field_id' => 0, 'field_type' => '', 'message' => ''];
    }
    try {
        $prepared = asr_tg_runtime_question_field_prepare_value($pdo, $botId, $code, $answerText);
        if (empty($prepared['ok'])) return ['ok' => false, 'saved' => false] + $prepared;
        $fieldId = (int)($prepared['field_id'] ?? 0);
        if ($fieldId <= 0) return ['ok' => true, 'saved' => false] + $prepared;
        asr_tg_subscriber_custom_values_save($pdo, $subscriberId, $botId, [$fieldId => (string)($prepared['value'] ?? '')]);
        return ['ok' => true, 'saved' => true] + $prepared;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_question_answer_save_failed', $e->getMessage(), ['subscriber_id' => $subscriberId, 'save_field_code' => $code]); } catch (Throwable $ignored) {}
        return ['ok' => false, 'saved' => false, 'field_id' => 0, 'field_type' => '', 'value' => '', 'message' => $e->getMessage()];
    }
}

function asr_tg_runtime_waiting_question_context(PDO $pdo, int $botId, int $subscriberId): ?array {
    if ($botId <= 0 || $subscriberId <= 0) return null;
    asr_tg_repository_ensure_scenario_schema($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_subscriber_scenarios WHERE bot_id = ? AND subscriber_id = ? AND status = 'waiting' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([$botId, $subscriberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_waiting_question_lookup_failed', $e->getMessage(), ['subscriber_id' => $subscriberId]); } catch (Throwable $ignored) {}
    }

    // Fallback: иногда строка состояния уже не находится как waiting, но событие отправленного вопроса
    // есть. Тогда текстовый ответ не должен уходить в Диалоги, а должен сохраниться в поле вопроса.
    try {
        $stmt = $pdo->prepare("SELECT id, scenario_id, block_id, payload_json, created_at FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND event_type = 'runtime_question_waiting' ORDER BY id DESC LIMIT 10");
        $stmt->execute([$botId, $subscriberId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $answeredStmt = $pdo->prepare("SELECT 1 FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND block_id = ? AND event_type IN ('runtime_question_text_received','runtime_question_answer_received') AND id > ? LIMIT 1");
        foreach ($rows as $row) {
            $scenarioId = (int)($row['scenario_id'] ?? 0);
            $blockId = (int)($row['block_id'] ?? 0);
            $eventId = (int)($row['id'] ?? 0);
            if ($scenarioId <= 0 || $blockId <= 0 || $eventId <= 0) continue;
            $answeredStmt->execute([$botId, $subscriberId, $scenarioId, $blockId, $eventId]);
            if ($answeredStmt->fetchColumn()) continue;
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            return [
                'scenario_id' => $scenarioId,
                'current_block_id' => $blockId,
                'status' => 'waiting',
                '_wait_event_id' => $eventId,
                '_wait_payload' => is_array($payload) ? $payload : [],
            ];
        }
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_waiting_question_event_fallback_failed', $e->getMessage(), ['subscriber_id' => $subscriberId]); } catch (Throwable $ignored) {}
    }
    return null;
}

function asr_tg_runtime_waiting_question_card_index(PDO $pdo, int $botId, int $subscriberId, int $scenarioId, int $blockId): int {
    try {
        $stmt = $pdo->prepare("SELECT payload_json FROM oca_telegram_bot_scenario_events WHERE bot_id = ? AND subscriber_id = ? AND scenario_id = ? AND block_id = ? AND event_type = 'runtime_question_waiting' ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute([$botId, $subscriberId, $scenarioId, $blockId]);
        $payload = (string)($stmt->fetchColumn() ?: '');
        if ($payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['card_index'])) return max(0, (int)$decoded['card_index']);
        }
    } catch (Throwable $e) {}
    return 0;
}

function asr_tg_runtime_first_question_card_index(array $cards): int {
    foreach ($cards as $index => $card) {
        if (is_array($card) && (string)($card['type'] ?? '') === 'question') return (int)$index;
    }
    return 0;
}

function asr_tg_runtime_send_system_text(PDO $pdo, array $bot, int $botId, int|string $chatId, string $text): void {
    $text = trim($text);
    if ($text === '') return;
    try {
        $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
        if ($token === '') return;
        asr_tg_api_send_broadcast_payload($token, $chatId, $text, '', '', '', true, '', [], false);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'runtime_system_text_send_failed', $e->getMessage(), ['text' => mb_substr($text, 0, 120, 'UTF-8')]); } catch (Throwable $ignored) {}
    }
}

function asr_tg_runtime_try_waiting_question_text(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, ?string $text): bool {
    $answerText = trim((string)$text);
    if ($answerText === '') return false;

    $context = asr_tg_runtime_waiting_question_context($pdo, $botId, $subscriberId);
    if (!$context) return false;

    $scenarioId = (int)($context['scenario_id'] ?? 0);
    $blockId = (int)($context['current_block_id'] ?? 0);
    $waitPayload = is_array($context['_wait_payload'] ?? null) ? (array)$context['_wait_payload'] : [];
    if ($scenarioId <= 0 || $blockId <= 0) return false;

    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block) return false;
    $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
    $cardIndex = isset($waitPayload['card_index']) ? max(0, (int)$waitPayload['card_index']) : asr_tg_runtime_waiting_question_card_index($pdo, $botId, $subscriberId, $scenarioId, $blockId);
    $card = $cards[$cardIndex] ?? null;
    if (!is_array($card) || (string)($card['type'] ?? '') !== 'question') {
        $cardIndex = asr_tg_runtime_first_question_card_index($cards);
        $card = $cards[$cardIndex] ?? null;
    }
    if (!is_array($card) || (string)($card['type'] ?? '') !== 'question') return false;
    if (asr_tg_runtime_question_save_field_code($card) === '' && trim((string)($waitPayload['save_field_code'] ?? '')) !== '') {
        $card['save_field_code'] = trim((string)$waitPayload['save_field_code']);
    }

    $saveResult = asr_tg_runtime_save_question_answer($pdo, $botId, $subscriberId, $card, $answerText);
    if (empty($saveResult['ok'])) {
        $message = trim((string)($saveResult['message'] ?? 'Не удалось сохранить ответ.'));
        asr_tg_runtime_send_system_text($pdo, $bot, $botId, $chatId, $message ?: 'Не удалось сохранить ответ.');
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_text_invalid', 'Ответ на вопрос не прошёл проверку.', ['card_index' => $cardIndex, 'answer' => mb_substr($answerText, 0, 120, 'UTF-8'), 'error' => $message]);
        return true;
    }

    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_text_received', 'Получен текстовый ответ на вопрос.', ['card_index' => $cardIndex, 'saved' => !empty($saveResult['saved']), 'field_id' => (int)($saveResult['field_id'] ?? 0), 'field_type' => (string)($saveResult['field_type'] ?? ''), 'save_field_code' => asr_tg_runtime_question_save_field_code($card), 'context_source' => isset($context['_wait_event_id']) ? 'event_fallback' : 'subscriber_scenarios']);
    if (!empty($saveResult['saved'])) {
        asr_tg_runtime_send_system_text($pdo, $bot, $botId, $chatId, 'Спасибо, ответ записан.');
    } else {
        asr_tg_runtime_send_system_text($pdo, $bot, $botId, $chatId, 'Ответ принят.');
    }

    $nextBlockId = asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId);
    if ($nextBlockId > 0 && $nextBlockId !== $blockId) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_next_auto', 'После текстового ответа запускаем следующий шаг.', ['card_index' => $cardIndex, 'next_block_id' => $nextBlockId]);
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $nextBlockId, 'telegram_question_text', ['from_block_id' => $blockId, 'answer' => mb_substr($answerText, 0, 120, 'UTF-8')]);
    }
    return true;
}

function asr_tg_runtime_target_scenario_for_block(PDO $pdo, int $targetBlockId): int {
    if ($targetBlockId <= 0) return 0;
    try {
        $stmt = $pdo->prepare('SELECT scenario_id FROM oca_telegram_bot_scenario_blocks WHERE id = ? LIMIT 1');
        $stmt->execute([$targetBlockId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_tg_runtime_callback_source(array $bot, string $callbackQueryId): string {
    $channelType = function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : strtolower((string)($bot['channel_type'] ?? 'telegram'));
    if ($channelType === 'vk' || $callbackQueryId === '') return 'vk_button';
    return 'telegram_callback';
}

function asr_tg_runtime_vk_payload_callback_data($payload): string {
    if (is_array($payload)) {
        $candidates = [
            $payload['callback_data'] ?? null,
            $payload['callbackData'] ?? null,
            $payload['data'] ?? null,
            $payload['cmd'] ?? null,
            $payload['command'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '' && strncmp($value, 'scn_', 4) === 0) return $value;
        }
        return '';
    }

    $raw = trim((string)$payload);
    if ($raw === '') return '';
    if (strncmp($raw, 'scn_', 4) === 0) return $raw;

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return asr_tg_runtime_vk_payload_callback_data($decoded);
    }

    return '';
}

function asr_tg_runtime_try_vk_payload(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, $payload): bool {
    $callbackData = asr_tg_runtime_vk_payload_callback_data($payload);
    if ($callbackData === '') return false;
    try {
        asr_tg_log($pdo, $botId, 'info', 'vk_scenario_button_payload_received', 'Получено нажатие VK-кнопки сценария.', [
            'subscriber_id' => $subscriberId,
            'callback_data' => mb_substr($callbackData, 0, 120, 'UTF-8'),
        ]);
    } catch (Throwable $ignored) {}
    return asr_tg_runtime_try_callback($pdo, $bot, $botId, $chatId, $subscriberId, '', $callbackData);
}

function asr_tg_runtime_try_callback(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, string $callbackQueryId, string $callbackData): bool {
    $data = trim($callbackData);
    if ($data === '' || strncmp($data, 'scn_', 4) !== 0) return false;

    if ($data === 'scn_noop' || preg_match('/^scn_noop_(\d+)$/', $data, $noopMatch)) {
        $sourceBlockId = isset($noopMatch[1]) ? (int)$noopMatch[1] : 0;
        if ($sourceBlockId > 0) {
            $sourceScenarioId = asr_tg_runtime_target_scenario_for_block($pdo, $sourceBlockId);
            if ($sourceScenarioId > 0) {
                asr_tg_scenario_stats_increment_click($pdo, $sourceScenarioId, $sourceBlockId);
            }
        }
        asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Действие пока не настроено.');
        try { asr_tg_log($pdo, $botId, 'info', 'runtime_callback_noop', 'Нажата кнопка сценария без настроенного перехода.', ['subscriber_id' => $subscriberId, 'source_block_id' => $sourceBlockId]); } catch (Throwable $ignored) {}
        return true;
    }

    if (preg_match('/^scn_goto_(\d+)_(\d+)$/', $data, $m)) {
        $sourceBlockId = (int)$m[1];
        $targetBlockId = (int)$m[2];
        $scenarioId = asr_tg_runtime_target_scenario_for_block($pdo, $targetBlockId);
        if ($scenarioId <= 0) {
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Шаг не найден.', true);
            try { asr_tg_log($pdo, $botId, 'warning', 'runtime_callback_target_missing', 'Целевой шаг кнопки не найден.', ['subscriber_id' => $subscriberId, 'callback_data' => $data, 'source_block_id' => $sourceBlockId, 'target_block_id' => $targetBlockId]); } catch (Throwable $ignored) {}
            return true;
        }
        $sourceScenarioId = asr_tg_runtime_target_scenario_for_block($pdo, $sourceBlockId);
        if ($sourceBlockId > 0 && $sourceScenarioId > 0) {
            asr_tg_scenario_stats_increment_click($pdo, $sourceScenarioId, $sourceBlockId);
        }
        asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $targetBlockId, 'runtime_callback_goto', 'Переход по кнопке сценария.', ['callback_data' => $data, 'source_block_id' => $sourceBlockId]);
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, asr_tg_runtime_callback_source($bot, $callbackQueryId), ['callback_data' => $data, 'from_block_id' => $sourceBlockId]);
    }

    if (preg_match('/^scn_goto_(\d+)$/', $data, $m)) {
        $targetBlockId = (int)$m[1];
        $scenarioId = asr_tg_runtime_target_scenario_for_block($pdo, $targetBlockId);
        if ($scenarioId <= 0) {
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Шаг не найден.', true);
            try { asr_tg_log($pdo, $botId, 'warning', 'runtime_callback_target_missing', 'Целевой шаг кнопки не найден.', ['subscriber_id' => $subscriberId, 'callback_data' => $data, 'target_block_id' => $targetBlockId]); } catch (Throwable $ignored) {}
            return true;
        }
        $sourceContext = asr_tg_runtime_recent_context($pdo, $botId, $subscriberId);
        $sourceBlockId = (int)($sourceContext['current_block_id'] ?? 0);
        $sourceScenarioId = (int)($sourceContext['scenario_id'] ?? 0);
        if ($sourceBlockId > 0 && $sourceScenarioId > 0) {
            asr_tg_scenario_stats_increment_click($pdo, $sourceScenarioId, $sourceBlockId);
        }
        asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $targetBlockId, 'runtime_callback_goto', 'Переход по кнопке сценария.', ['callback_data' => $data]);
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, asr_tg_runtime_callback_source($bot, $callbackQueryId), ['callback_data' => $data]);
    }

    if (preg_match('/^scn_answer_(\d+)_(\d+)_(\d+)$/', $data, $m)) {
        $questionBlockId = (int)$m[1];
        $cardIndex = (int)$m[2];
        $answerIndex = (int)$m[3];
        $context = asr_tg_runtime_recent_context($pdo, $botId, $subscriberId, $questionBlockId);
        $scenarioId = (int)($context['scenario_id'] ?? 0);
        if ($scenarioId <= 0) $scenarioId = asr_tg_runtime_target_scenario_for_block($pdo, $questionBlockId);
        $card = asr_tg_runtime_find_question_card($pdo, $scenarioId, $questionBlockId, $cardIndex);
        $answers = is_array($card) && is_array($card['answers'] ?? null) ? array_values($card['answers']) : [];
        $answer = $answers[$answerIndex] ?? null;
        if (!is_array($answer)) {
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Ответ не найден.', true);
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $questionBlockId, 'runtime_question_answer_missing', 'Ответ вопроса не найден.', ['callback_data' => $data, 'card_index' => $cardIndex, 'answer_index' => $answerIndex]);
            return true;
        }
        $answerText = trim((string)($answer['text'] ?? $answer['title'] ?? ''));
        $saveResult = asr_tg_runtime_save_question_answer($pdo, $botId, $subscriberId, $card ?: [], $answerText);
        if (empty($saveResult['ok'])) {
            $message = trim((string)($saveResult['message'] ?? 'Не удалось сохранить ответ.'));
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, $message ?: 'Не удалось сохранить ответ.', true);
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $questionBlockId, 'runtime_question_answer_invalid', 'Ответ на вопрос не прошёл проверку.', ['answer' => $answerText, 'error' => $message, 'callback_data' => $data]);
            return true;
        }
        $targetBlockId = max(0, (int)($answer['target_block_id'] ?? 0));
        if ($scenarioId > 0 && $questionBlockId > 0) {
            asr_tg_scenario_stats_increment_click($pdo, $scenarioId, $questionBlockId);
        }
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $questionBlockId, 'runtime_question_answer_received', 'Получен ответ на вопрос.', ['answer' => $answerText, 'saved' => !empty($saveResult['saved']), 'field_type' => (string)($saveResult['field_type'] ?? ''), 'target_block_id' => $targetBlockId, 'callback_data' => $data]);
        if ($targetBlockId > 0) {
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId);
            return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, asr_tg_runtime_callback_source($bot, $callbackQueryId) . '_question_answer', ['callback_data' => $data, 'answer' => $answerText]);
        }
        if ($scenarioId > 0 && $questionBlockId > 0) {
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $questionBlockId, 'active');
            $nextBlockId = asr_tg_runtime_first_block_after($pdo, $scenarioId, $questionBlockId);
            if ($nextBlockId > 0 && $nextBlockId !== $questionBlockId) {
                asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId);
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $questionBlockId, 'runtime_question_next_auto', 'После ответа кнопкой запускаем следующий шаг.', ['next_block_id' => $nextBlockId, 'answer' => $answerText, 'callback_data' => $data]);
                return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $nextBlockId, asr_tg_runtime_callback_source($bot, $callbackQueryId) . '_question_answer_next', ['from_block_id' => $questionBlockId, 'answer' => $answerText, 'callback_data' => $data]);
            }
        }
        asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Ответ принят.');
        return true;
    }

    asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackQueryId, 'Команда кнопки пока не поддерживается.');
    try { asr_tg_log($pdo, $botId, 'warning', 'runtime_callback_unsupported', 'Неподдерживаемая кнопка сценария.', ['subscriber_id' => $subscriberId, 'callback_data' => $data]); } catch (Throwable $ignored) {}
    return true;
}


function asr_tg_runtime_prepare_card_for_send(PDO $pdo, array $card, array $subscriber, int $blockId, int $cardIndex): array {
    $type = strtolower(trim((string)($card['type'] ?? 'text')));
    if ($type === 'image') $type = 'photo';
    if ($type === 'file') $type = 'document';
    if ($type === 'voice') $type = 'audio';
    if ($type === '') $type = 'text';

    $rawText = asr_tg_runtime_card_text_to_telegram((string)($card['text'] ?? ''));
    $text = $rawText !== '' ? asr_tg_render_subscriber_macros($pdo, $rawText, $subscriber) : '';
    $plainText = asr_tg_runtime_plain_text($text);
    // По умолчанию предпросмотр ссылок в Telegram должен быть включён.
    // Отключаем его только если пользователь явно поставил галочку в карточке.
    $disablePreview = array_key_exists('disable_web_page_preview', $card) ? !empty($card['disable_web_page_preview']) : false;
    $protectContent = !empty($card['protect_content']);
    $buttons = [];

    if ($type === 'question') {
        $buttons = asr_tg_runtime_question_answer_rows($card, $blockId, $cardIndex);
        // Telegram не принимает sendMessage с пустым text, даже если есть inline-кнопки.
        // В редакторе карточка «Вопрос» может использоваться только как набор вариантов ответа,
        // поэтому при пустом тексте даём безопасную короткую подпись вместо падения runtime после предыдущей карточки.
        if (trim($plainText) === '' && $buttons) {
            $text = 'Выберите вариант ответа:';
            $plainText = 'Выберите вариант ответа:';
        }
        $type = 'text';
    } else {
        $buttons = asr_tg_runtime_button_rows_for_telegram((array)($card['buttons'] ?? []), $blockId);
    }

    $runtimeCard = [
        'type' => $type,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => $disablePreview,
        'protect_content' => $protectContent,
        'buttons' => $buttons,
    ];

    $mediaUrl = trim((string)($card['media_url'] ?? ''));
    $localPath = asr_tg_runtime_card_local_file_path($card);
    if ($mediaUrl !== '') $runtimeCard['media_url'] = $mediaUrl;
    if ($localPath !== '') $runtimeCard['local_file_path'] = $localPath;
    if (!empty($card['media_file_path'])) $runtimeCard['media_file_path'] = (string)$card['media_file_path'];
    if (!empty($card['media_file_name'])) $runtimeCard['media_file_name'] = (string)$card['media_file_name'];

    if ($type === 'gallery') {
        $runtimeCard['gallery_items'] = isset($card['gallery_items']) && is_array($card['gallery_items']) ? array_values($card['gallery_items']) : [];
    }

    return [$runtimeCard, $plainText, $buttons];
}

function asr_tg_runtime_sent_message_id(array $sent): ?int {
    $result = $sent['result'] ?? null;
    if (is_array($result)) {
        if (isset($result['message_id'])) return (int)$result['message_id'];
        if (isset($result[0]) && is_array($result[0]) && isset($result[0]['message_id'])) return (int)$result[0]['message_id'];
    }
    return null;
}

function asr_tg_runtime_card_message_type(array $card): string {
    $type = strtolower(trim((string)($card['type'] ?? 'text')));
    if ($type === 'image') return 'photo';
    if ($type === 'file') return 'document';
    if ($type === 'gallery') return 'gallery';
    if ($type === 'video_note') return 'video_note';
    if ($type === 'question') return 'question';
    if (in_array($type, ['photo','video','audio','document','voice'], true)) return $type;
    return 'text';
}




function asr_tg_runtime_random_settings(array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $outputsRaw = isset($settings['outputs']) && is_array($settings['outputs']) ? $settings['outputs'] : [];
    $outputs = [];
    foreach ($outputsRaw as $idx => $output) {
        if (!is_array($output)) continue;
        $key = trim((string)($output['key'] ?? ''));
        if ($key === '' || !preg_match('~^[a-zA-Z0-9_\-]{1,40}$~', $key)) $key = 'r' . ((int)$idx + 1);
        $title = trim((string)($output['title'] ?? ''));
        if ($title === '') $title = 'Выход ' . (count($outputs) + 1);
        $percent = max(0, min(100, (int)round((float)($output['percent'] ?? 0))));
        $outputs[] = [
            'key' => $key,
            'title' => mb_substr($title, 0, 80, 'UTF-8'),
            'percent' => $percent,
        ];
        if (count($outputs) >= 10) break;
    }
    $total = array_sum(array_map(static fn($item) => (int)($item['percent'] ?? 0), $outputs));
    return [
        'outputs' => array_values($outputs),
        'total' => $total,
        'valid' => count($outputs) >= 2 && $total === 100,
    ];
}

function asr_tg_runtime_random_pick_output(array $outputs): ?array {
    if (count($outputs) < 2) return null;
    $pool = [];
    foreach ($outputs as $output) {
        if (!is_array($output)) continue;
        $key = trim((string)($output['key'] ?? ''));
        $percent = max(0, min(100, (int)($output['percent'] ?? 0)));
        if ($key === '' || $percent <= 0) continue;
        $pool[] = $output + ['percent' => $percent, 'key' => $key];
    }
    if (!$pool) return null;
    $total = array_sum(array_map(static fn($item) => (int)($item['percent'] ?? 0), $pool));
    if ($total <= 0) return null;
    $roll = random_int(1, $total);
    $cursor = 0;
    foreach ($pool as $output) {
        $cursor += (int)($output['percent'] ?? 0);
        if ($roll <= $cursor) return $output;
    }
    return $pool[count($pool) - 1] ?? null;
}

function asr_tg_runtime_random_target_block_id(PDO $pdo, int $scenarioId, int $blockId, string $key): int {
    if ($scenarioId <= 0 || $blockId <= 0 || $key === '') return 0;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $stmt = $pdo->prepare("SELECT to_block_id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = 'random_choice' AND JSON_EXTRACT(condition_json, '$.random_key') = ? AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$scenarioId, $blockId, json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) return $id;
        // Fallback for older MySQL/MariaDB setups where JSON_EXTRACT returns an unquoted scalar.
        $stmt = $pdo->prepare("SELECT id, to_block_id, condition_json FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = 'random_choice' AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY sort_order ASC, id ASC LIMIT 20");
        $stmt->execute([$scenarioId, $blockId]);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $condition = json_decode((string)($row['condition_json'] ?? ''), true);
            if (!is_array($condition)) continue;
            if (trim((string)($condition['random_key'] ?? '')) === $key) return max(0, (int)($row['to_block_id'] ?? 0));
        }
    } catch (Throwable $e) {}
    return 0;
}

function asr_tg_runtime_execute_random_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    if ($scenarioId <= 0 || $blockId <= 0) return false;
    $settings = asr_tg_runtime_random_settings($block);
    if (empty($settings['valid'])) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_random_invalid', 'Блок «Случайный выбор» настроен некорректно.', ['settings' => $settings, 'source' => $source] + $sourcePayload);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'Блок «Случайный выбор» настроен некорректно.');
        return true;
    }
    $picked = asr_tg_runtime_random_pick_output($settings['outputs']);
    if (!$picked) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_random_empty', 'Блок «Случайный выбор» не смог выбрать выход.', ['settings' => $settings, 'source' => $source] + $sourcePayload);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'Блок «Случайный выбор» не смог выбрать выход.');
        return true;
    }
    $key = trim((string)($picked['key'] ?? ''));
    $targetBlockId = asr_tg_runtime_random_target_block_id($pdo, $scenarioId, $blockId, $key);
    $payload = [
        'random_block_id' => $blockId,
        'selected_key' => $key,
        'selected_title' => (string)($picked['title'] ?? ''),
        'selected_percent' => (int)($picked['percent'] ?? 0),
        'target_block_id' => $targetBlockId,
        'source' => $source,
    ] + $sourcePayload;
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_random_selected', 'Блок «Случайный выбор» выбрал выход.', $payload);
    if ($targetBlockId > 0 && $targetBlockId !== $blockId) {
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, 'random_choice', ['from_block_id' => $blockId, 'random_key' => $key, 'source' => $source]);
    }
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_random_missing_next', 'У выбранного выхода блока «Случайный выбор» нет связи со следующим шагом.', $payload);
    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'У выбранного выхода блока «Случайный выбор» нет связи со следующим шагом.');
    return true;
}

function asr_tg_runtime_schedule_settings(array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $mode = (string)($settings['schedule_mode'] ?? 'fixed');
    if (!in_array($mode, ['fixed','field'], true)) $mode = 'fixed';
    $timezone = trim((string)($settings['timezone'] ?? 'Asia/Almaty'));
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = 'Asia/Almaty'; }
    return [
        'mode' => $mode,
        'date' => trim((string)($settings['schedule_date'] ?? '')),
        'time' => asr_tg_runtime_delay_normalize_time((string)($settings['schedule_time'] ?? '12:00')),
        'field_code' => trim((string)($settings['schedule_field_code'] ?? '')),
        'field_type' => trim((string)($settings['schedule_field_type'] ?? '')),
        'field_fallback_date' => trim((string)($settings['schedule_field_fallback_date'] ?? '')),
        'field_fallback_time' => asr_tg_runtime_delay_normalize_time((string)($settings['schedule_field_fallback_time'] ?? '12:00')),
        'timezone' => $timezone,
        'valid' => !empty($settings['schedule_valid']),
    ];
}

function asr_tg_runtime_schedule_custom_field_value(PDO $pdo, int $subscriberId, string $fieldCode): string {
    if ($subscriberId <= 0 || $fieldCode === '' || !function_exists('asr_tg_custom_fields_all') || !function_exists('asr_tg_subscriber_custom_values_get')) return '';
    $fieldId = 0;
    $fieldType = '';
    try {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            if (trim((string)($field['code'] ?? '')) !== $fieldCode) continue;
            $fieldId = (int)($field['id'] ?? 0);
            $fieldType = trim((string)($field['field_type'] ?? ''));
            break;
        }
        if ($fieldId <= 0) return '';
        $values = asr_tg_subscriber_custom_values_get($pdo, $subscriberId);
        $row = $values[$fieldId] ?? null;
        if (!is_array($row)) return '';
        if ($fieldType === 'date') return trim((string)($row['value_date'] ?? $row['value_text'] ?? ''));
        if ($fieldType === 'datetime') return trim((string)($row['value_datetime'] ?? $row['value_text'] ?? ''));
        return trim((string)($row['value_text'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function asr_tg_runtime_schedule_target_at(PDO $pdo, int $subscriberId, array $settings): ?DateTimeImmutable {
    $tz = new DateTimeZone((string)($settings['timezone'] ?? 'Asia/Almaty'));
    if ((string)($settings['mode'] ?? 'fixed') === 'field') {
        $raw = asr_tg_runtime_schedule_custom_field_value($pdo, $subscriberId, (string)($settings['field_code'] ?? ''));
        $raw = trim($raw);
        if ($raw === '') {
            $fallbackDate = trim((string)($settings['field_fallback_date'] ?? ''));
            $fallbackTime = asr_tg_runtime_delay_normalize_time((string)($settings['field_fallback_time'] ?? '12:00'));
            if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $fallbackDate)) $raw = $fallbackDate . ' ' . $fallbackTime . ':00';
        }
        if ($raw === '') return null;
        try {
            if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $raw)) return new DateTimeImmutable($raw . ' 00:00:00', $tz);
            if (preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}~', $raw)) $raw = str_replace('T', ' ', substr($raw, 0, 16));
            return new DateTimeImmutable($raw, $tz);
        } catch (Throwable $e) { return null; }
    }
    $date = trim((string)($settings['date'] ?? ''));
    $time = asr_tg_runtime_delay_normalize_time((string)($settings['time'] ?? '12:00'));
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return null;
    try { return new DateTimeImmutable($date . ' ' . $time . ':00', $tz); } catch (Throwable $e) { return null; }
}

function asr_tg_runtime_schedule_target_block_id(PDO $pdo, int $scenarioId, int $blockId, string $branch): int {
    if ($scenarioId <= 0 || $blockId <= 0) return 0;
    $linkType = $branch === 'expired' ? 'schedule_expired' : 'schedule_on_time';
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        $stmt = $pdo->prepare("SELECT to_block_id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = ? AND to_block_id IS NOT NULL AND to_block_id > 0 ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$scenarioId, $blockId, $linkType]);
        return max(0, (int)($stmt->fetchColumn() ?: 0));
    } catch (Throwable $e) { return 0; }
}

function asr_tg_runtime_execute_schedule_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    if ($scenarioId <= 0 || $blockId <= 0) return false;
    $settings = asr_tg_runtime_schedule_settings($block);
    $target = asr_tg_runtime_schedule_target_at($pdo, $subscriberId, $settings);
    $tz = new DateTimeZone((string)($settings['timezone'] ?? 'Asia/Almaty'));
    $now = new DateTimeImmutable('now', $tz);
    if (!$target) {
        $targetBlockId = asr_tg_runtime_schedule_target_block_id($pdo, $scenarioId, $blockId, 'expired');
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_schedule_invalid', 'Блок «Расписание» не смог определить дату.', ['settings' => $settings, 'source' => $source] + $sourcePayload);
        if ($targetBlockId > 0 && $targetBlockId !== $blockId) return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, 'schedule_expired', ['from_block_id' => $blockId, 'source' => $source]);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'У блока «Расписание» не указана дата.');
        return true;
    }
    if ($target <= $now) {
        $targetBlockId = asr_tg_runtime_schedule_target_block_id($pdo, $scenarioId, $blockId, 'expired');
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_schedule_expired', 'Дата и время расписания уже прошли.', ['target_at' => $target->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s'), 'target_block_id' => $targetBlockId, 'source' => $source] + $sourcePayload);
        if ($targetBlockId > 0 && $targetBlockId !== $blockId) return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $targetBlockId, 'schedule_expired', ['from_block_id' => $blockId, 'source' => $source]);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'stopped', null, 'Дата и время прошли, ветка не настроена.');
        return true;
    }
    $nextBlockId = asr_tg_runtime_schedule_target_block_id($pdo, $scenarioId, $blockId, 'on_time');
    if ($nextBlockId <= 0 || $nextBlockId === $blockId) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_schedule_missing_next', 'У блока «Расписание» не настроен выход «По расписанию».', ['target_at' => $target->format('Y-m-d H:i:s'), 'source' => $source] + $sourcePayload);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'У блока «Расписание» не настроен выход «По расписанию».');
        return true;
    }
    $nextRunAt = $target->format('Y-m-d H:i:s');
    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $nextBlockId, 'delayed', $nextRunAt, '');
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_schedule_scheduled', 'Блок «Расписание» поставил продолжение в очередь.', ['schedule_block_id' => $blockId, 'next_block_id' => $nextBlockId, 'next_run_at' => $nextRunAt, 'settings' => $settings, 'source' => $source] + $sourcePayload);
    return true;
}

function asr_tg_runtime_delay_settings(array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];

    $mode = (string)($settings['delay_mode'] ?? $settings['mode'] ?? 'after');
    if (!in_array($mode, ['after', 'tomorrow', 'at'], true)) $mode = 'after';

    $value = max(1, min(999, (int)($settings['delay_value'] ?? $settings['value'] ?? 1)));
    $unit = (string)($settings['delay_unit'] ?? $settings['unit'] ?? 'days');
    if (!in_array($unit, ['seconds', 'minutes', 'hours', 'days'], true)) $unit = 'days';

    $timeMode = (string)($settings['send_time_mode'] ?? 'any');
    if (!in_array($timeMode, ['any', 'exact', 'interval'], true)) $timeMode = 'any';

    $timeExact = asr_tg_runtime_delay_normalize_time((string)($settings['send_time_exact'] ?? '00:00'));
    $timeFrom = asr_tg_runtime_delay_normalize_time((string)($settings['send_time_from'] ?? '00:00'));
    $timeTo = asr_tg_runtime_delay_normalize_time((string)($settings['send_time_to'] ?? '00:00'));

    $timezone = trim((string)($settings['timezone'] ?? 'Asia/Almaty'));
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = 'Asia/Almaty'; }

    $weekdays = $settings['weekdays'] ?? ['mon','tue','wed','thu','fri','sat','sun'];
    if (!is_array($weekdays)) $weekdays = [];
    $weekdays = array_values(array_intersect(array_map('strval', $weekdays), ['mon','tue','wed','thu','fri','sat','sun']));
    if (!$weekdays) $weekdays = ['mon','tue','wed','thu','fri','sat','sun'];

    return [
        'mode' => $mode,
        'value' => $value,
        'unit' => $unit,
        'time_mode' => $timeMode,
        'time_exact' => $timeExact,
        'time_from' => $timeFrom,
        'time_to' => $timeTo,
        'timezone' => $timezone,
        'weekdays' => $weekdays,
    ];
}

function asr_tg_runtime_delay_normalize_time(string $time): string {
    $time = trim($time);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) return '00:00';
    $h = max(0, min(23, (int)$m[1]));
    $i = max(0, min(59, (int)$m[2]));
    return sprintf('%02d:%02d', $h, $i);
}

function asr_tg_runtime_delay_weekday_key(DateTimeImmutable $dt): string {
    return ['mon','tue','wed','thu','fri','sat','sun'][(int)$dt->format('N') - 1] ?? 'mon';
}

function asr_tg_runtime_delay_apply_allowed_weekdays(DateTimeImmutable $dt, array $weekdays, string $fallbackTime = ''): DateTimeImmutable {
    $allowed = array_fill_keys($weekdays ?: ['mon','tue','wed','thu','fri','sat','sun'], true);
    $safe = $dt;
    for ($i = 0; $i < 8; $i++) {
        if (!empty($allowed[asr_tg_runtime_delay_weekday_key($safe)])) return $safe;
        $safe = $safe->modify('+1 day');
        if ($fallbackTime !== '') {
            [$h, $m] = array_map('intval', explode(':', asr_tg_runtime_delay_normalize_time($fallbackTime)));
            $safe = $safe->setTime($h, $m, 0);
        }
    }
    return $dt;
}

function asr_tg_runtime_delay_compute_next_run_at(array $settings, ?DateTimeImmutable $now = null): string {
    $tz = new DateTimeZone((string)($settings['timezone'] ?? 'Asia/Almaty'));
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $mode = (string)($settings['mode'] ?? 'after');
    $timeMode = (string)($settings['time_mode'] ?? 'any');
    $exact = asr_tg_runtime_delay_normalize_time((string)($settings['time_exact'] ?? '00:00'));
    $from = asr_tg_runtime_delay_normalize_time((string)($settings['time_from'] ?? '00:00'));
    $to = asr_tg_runtime_delay_normalize_time((string)($settings['time_to'] ?? '00:00'));
    $weekdays = is_array($settings['weekdays'] ?? null) ? (array)$settings['weekdays'] : ['mon','tue','wed','thu','fri','sat','sun'];

    if ($mode === 'tomorrow') {
        [$h, $m] = array_map('intval', explode(':', $exact));
        $target = $now->modify('+1 day')->setTime($h, $m, 0);
        $target = asr_tg_runtime_delay_apply_allowed_weekdays($target, $weekdays, $exact);
        return $target->format('Y-m-d H:i:s');
    }

    if ($mode === 'at') {
        [$h, $m] = array_map('intval', explode(':', $exact));
        $target = $now->setTime($h, $m, 0);
        if ($target <= $now) $target = $target->modify('+1 day');
        $target = asr_tg_runtime_delay_apply_allowed_weekdays($target, $weekdays, $exact);
        return $target->format('Y-m-d H:i:s');
    }

    $value = max(1, min(999, (int)($settings['value'] ?? 1)));
    $unit = (string)($settings['unit'] ?? 'days');
    $unitMap = ['seconds' => 'seconds', 'minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days'];
    $target = $now->modify('+' . $value . ' ' . ($unitMap[$unit] ?? 'days'));

    if ($timeMode === 'exact') {
        [$h, $m] = array_map('intval', explode(':', $exact));
        $candidate = $target->setTime($h, $m, 0);
        if ($candidate < $target) $candidate = $candidate->modify('+1 day');
        $target = $candidate;
    } elseif ($timeMode === 'interval') {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));
        $start = $target->setTime($fh, $fm, 0);
        $end = $target->setTime($th, $tm, 59);
        if ($end < $start) $end = $end->modify('+1 day');
        if ($target < $start) {
            $target = $start;
        } elseif ($target > $end) {
            $target = $start->modify('+1 day');
        }
    }

    $fallbackTime = $timeMode === 'interval' ? $from : ($timeMode === 'exact' ? $exact : $target->format('H:i'));
    $target = asr_tg_runtime_delay_apply_allowed_weekdays($target, $weekdays, $fallbackTime);
    return $target->format('Y-m-d H:i:s');
}


function asr_tg_runtime_now_sql(): string {
    if (function_exists('asr_tg_broadcast_now_sql')) {
        return asr_tg_broadcast_now_sql();
    }
    $tzName = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Almaty');
    }
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
}

function asr_tg_runtime_due_delays_count(PDO $pdo): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'delayed' AND next_run_at IS NOT NULL AND next_run_at <= ?");
    $stmt->execute([asr_tg_runtime_now_sql()]);
    return (int)$stmt->fetchColumn();
}

function asr_tg_runtime_pending_delays_count(PDO $pdo): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return 0;
    $stmt = $pdo->query("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'delayed' AND next_run_at IS NOT NULL");
    return (int)$stmt->fetchColumn();
}

function asr_tg_runtime_execute_delay_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    if ($scenarioId <= 0 || $blockId <= 0) return false;
    $nextBlockId = asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId);
    if ($nextBlockId <= 0 || $nextBlockId === $blockId) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_missing_next', 'У блока «Задержка» не настроен следующий шаг.', ['source' => $source] + $sourcePayload);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'У блока «Задержка» не настроен следующий шаг.');
        return true;
    }

    $settings = asr_tg_runtime_delay_settings($block);
    if (function_exists('asr_tg_scenario_timezone') && trim((string)($settings['timezone'] ?? '')) === '') {
        $settings['timezone'] = asr_tg_scenario_timezone($pdo, $scenarioId);
    }
    $nextRunAt = asr_tg_runtime_delay_compute_next_run_at($settings);
    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $nextBlockId, 'delayed', $nextRunAt, '');
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_scheduled', 'Блок «Задержка» поставил следующий шаг в очередь.', [
        'source' => $source,
        'delay_block_id' => $blockId,
        'next_block_id' => $nextBlockId,
        'next_run_at' => $nextRunAt,
        'settings' => $settings,
    ] + $sourcePayload);
    return true;
}

function asr_tg_runtime_process_due_delays(PDO $pdo, int $limit = 30): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $limit = max(1, min(200, $limit));
    $result = ['processed' => 0, 'queued' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return $result;

    if (!function_exists('asr_tg_send_queue_enqueue')) {
        // Без универсальной очереди безопаснее ничего не запускать массово напрямую.
        return $result;
    }

    $nowSql = asr_tg_runtime_now_sql();
    // Не используем SQL NOW(): у БД и приложения может быть разный часовой пояс.
    // next_run_at рассчитывается в часовом поясе приложения, поэтому и сравнение делаем тем же временем.
    $sql = "SELECT ss.*, sub.chat_id, sub.status AS subscriber_status, b.bot_token_encrypted, b.status AS bot_status, s.status AS scenario_status
            FROM oca_telegram_bot_subscriber_scenarios ss
            JOIN oca_telegram_bot_subscribers sub ON sub.id = ss.subscriber_id AND sub.bot_id = ss.bot_id
            JOIN oca_telegram_bots b ON b.id = ss.bot_id
            JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id
            WHERE ss.status = 'delayed' AND ss.next_run_at IS NOT NULL AND ss.next_run_at <= ?
            ORDER BY ss.next_run_at ASC, ss.id ASC
            LIMIT " . (int)$limit;
    $stmtDue = $pdo->prepare($sql);
    $stmtDue->execute([$nowSql]);
    $rows = $stmtDue->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $result['processed']++;
        $stateId = (int)($row['id'] ?? 0);
        $botId = (int)($row['bot_id'] ?? 0);
        $subscriberId = (int)($row['subscriber_id'] ?? 0);
        $scenarioId = (int)($row['scenario_id'] ?? 0);
        $blockId = (int)($row['current_block_id'] ?? 0);
        $chatId = $row['chat_id'] ?? 0;
        if ($stateId <= 0 || $botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || !$chatId) {
            $result['failed']++;
            continue;
        }
        if ((string)($row['bot_status'] ?? '') !== 'active' || (string)($row['scenario_status'] ?? '') !== 'active' || (string)($row['subscriber_status'] ?? '') === 'blocked') {
            $result['skipped']++;
            try {
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ? AND status = 'delayed'")
                    ->execute(['Канал, сценарий или подписчик недоступен для продолжения задержки.', $stateId]);
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_skipped', 'Задержанный шаг не поставлен в очередь: канал, сценарий или подписчик недоступен.', ['state_id' => $stateId]);
            } catch (Throwable $ignored) {}
            continue;
        }
        try {
            $claim = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'queued', last_error = NULL, updated_at = NOW() WHERE id = ? AND status = 'delayed' AND next_run_at <= ?");
            $claim->execute([$stateId, $nowSql]);
            if ($claim->rowCount() <= 0) { $result['skipped']++; continue; }

            $queueId = asr_tg_send_queue_enqueue($pdo, [
                'bot_id' => $botId,
                'subscriber_id' => $subscriberId,
                'chat_id' => $chatId,
                'task_type' => 'scenario_delay_continue',
                'source_type' => 'scenario_delay',
                'source_id' => $stateId,
                'scenario_id' => $scenarioId,
                'block_id' => $blockId,
                'state_id' => $stateId,
                'payload' => [
                    'state_id' => $stateId,
                    'source' => 'delay_runner',
                    'queued_at' => $nowSql,
                    'due_at' => (string)($row['next_run_at'] ?? ''),
                ],
                'status' => 'pending',
                'scheduled_at' => $nowSql,
                'max_attempts' => 5,
                'dedupe_key' => 'scenario_delay_continue:' . $stateId . ':' . $blockId,
            ]);
            if ($queueId <= 0) {
                throw new RuntimeException('Не удалось поставить задержанный сценарий в универсальную очередь.');
            }

            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_queued', 'Задержка завершена, продолжение поставлено в универсальную очередь.', [
                'state_id' => $stateId,
                'queue_id' => $queueId,
            ]);
            $result['queued']++;
        } catch (Throwable $e) {
            $result['failed']++;
            try {
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute([$e->getMessage(), $stateId]);
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_delay_queue_failed', $e->getMessage(), ['state_id' => $stateId]);
            } catch (Throwable $ignored) {}
        }
    }
    return $result;
}

function asr_tg_runtime_due_questions_count(PDO $pdo): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'waiting' AND next_run_at IS NOT NULL AND next_run_at <= ?");
    $stmt->execute([asr_tg_runtime_now_sql()]);
    return (int)$stmt->fetchColumn();
}

function asr_tg_runtime_pending_questions_count(PDO $pdo): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return 0;
    $stmt = $pdo->query("SELECT COUNT(*) FROM oca_telegram_bot_subscriber_scenarios WHERE status = 'waiting' AND next_run_at IS NOT NULL");
    return (int)$stmt->fetchColumn();
}

function asr_tg_runtime_process_due_questions(PDO $pdo, int $limit = 30): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $limit = max(1, min(200, $limit));
    $result = ['processed' => 0, 'queued' => 0, 'reminded' => 0, 'started' => 0, 'failed' => 0, 'skipped' => 0];
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return $result;

    if (!function_exists('asr_tg_send_queue_enqueue')) {
        // Без универсальной очереди не запускаем массовые timeout-вопросы напрямую.
        return $result;
    }

    $nowSql = asr_tg_runtime_now_sql();
    $sql = "SELECT ss.*, sub.chat_id, sub.status AS subscriber_status, b.bot_token_encrypted, b.status AS bot_status, s.status AS scenario_status
            FROM oca_telegram_bot_subscriber_scenarios ss
            JOIN oca_telegram_bot_subscribers sub ON sub.id = ss.subscriber_id AND sub.bot_id = ss.bot_id
            JOIN oca_telegram_bots b ON b.id = ss.bot_id
            JOIN oca_telegram_bot_scenarios s ON s.id = ss.scenario_id
            WHERE ss.status = 'waiting' AND ss.next_run_at IS NOT NULL AND ss.next_run_at <= ?
            ORDER BY ss.next_run_at ASC, ss.id ASC
            LIMIT " . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nowSql]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $result['processed']++;
        $stateId = (int)($row['id'] ?? 0);
        $botId = (int)($row['bot_id'] ?? 0);
        $subscriberId = (int)($row['subscriber_id'] ?? 0);
        $scenarioId = (int)($row['scenario_id'] ?? 0);
        $blockId = (int)($row['current_block_id'] ?? 0);
        $chatId = (string)($row['chat_id'] ?? '');
        try {
            if ($stateId <= 0 || $botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || $chatId === '') {
                $result['skipped']++;
                continue;
            }
            if ((string)($row['subscriber_status'] ?? '') !== 'active' || (string)($row['bot_status'] ?? '') !== 'active' || (string)($row['scenario_status'] ?? '') !== 'active') {
                $result['skipped']++;
                continue;
            }
            $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
            if (!$block || (string)($block['type'] ?? '') !== 'message') {
                $result['skipped']++;
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute(['Ожидаемый вопрос не найден.', $stateId]);
                continue;
            }
            $waitEvent = asr_tg_runtime_question_wait_event($pdo, $botId, $subscriberId, $scenarioId, $blockId);
            $waitEventId = (int)($waitEvent['id'] ?? 0);
            if ($waitEventId > 0 && asr_tg_runtime_question_has_answer_after($pdo, $botId, $subscriberId, $scenarioId, $blockId, $waitEventId)) {
                $result['skipped']++;
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET next_run_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$stateId]);
                continue;
            }
            $payload = is_array($waitEvent['_payload'] ?? null) ? (array)$waitEvent['_payload'] : [];
            $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
            $cardIndex = isset($payload['card_index']) ? max(0, (int)$payload['card_index']) : asr_tg_runtime_first_question_card_index($cards);
            $card = $cards[$cardIndex] ?? null;
            if (!is_array($card) || (string)($card['type'] ?? '') !== 'question') {
                $cardIndex = asr_tg_runtime_first_question_card_index($cards);
                $card = $cards[$cardIndex] ?? null;
            }
            if (!is_array($card) || (string)($card['type'] ?? '') !== 'question') {
                $result['skipped']++;
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute(['Карточка вопроса не найдена.', $stateId]);
                continue;
            }

            $remind = !empty($card['remind_no_answer']);
            $remindedAlready = $waitEventId > 0 && asr_tg_runtime_question_has_reminder_after($pdo, $botId, $subscriberId, $scenarioId, $blockId, $waitEventId);
            $waitDeadlineAt = trim((string)($payload['wait_deadline_at'] ?? ''));
            if ($waitDeadlineAt === '') {
                // Совместимость со старыми waiting-записями, созданными до фикса: считаем дедлайн от времени события ожидания.
                try {
                    $tzName = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
                    $tz = new DateTimeZone($tzName ?: 'Asia/Almaty');
                    $eventCreatedAt = trim((string)($waitEvent['created_at'] ?? ''));
                    $base = $eventCreatedAt !== '' ? new DateTimeImmutable($eventCreatedAt, $tz) : new DateTimeImmutable('now', $tz);
                    $waitDeadlineAt = (string)asr_tg_runtime_question_wait_deadline_sql($card, $base);
                } catch (Throwable $ignored) {
                    $waitDeadlineAt = (string)($row['next_run_at'] ?? '');
                }
            }
            $isFinalDeadlineDue = $waitDeadlineAt !== '' && strcmp($nowSql, $waitDeadlineAt) >= 0;

            if ($remind && !$remindedAlready && !$isFinalDeadlineDue) {
                $remindText = trim((string)($card['remind_text'] ?? '')) ?: 'Пожалуйста, ответьте на вопрос.';
                // После единственного напоминания следующий контроль должен быть финальным дедлайном ожидания, а не ещё одним remind_value.
                $nextRunAt = $waitDeadlineAt !== '' ? $waitDeadlineAt : asr_tg_runtime_question_wait_deadline_sql($card);
                $claim = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET next_run_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ? AND status = 'waiting' AND next_run_at <= ?");
                $claim->execute([$stateId, $nowSql]);
                if ($claim->rowCount() <= 0) { $result['skipped']++; continue; }

                $queueId = asr_tg_send_queue_enqueue($pdo, [
                    'bot_id' => $botId,
                    'subscriber_id' => $subscriberId,
                    'chat_id' => $chatId,
                    'task_type' => 'scenario_question_reminder',
                    'source_type' => 'scenario_question',
                    'source_id' => $stateId,
                    'scenario_id' => $scenarioId,
                    'block_id' => $blockId,
                    'state_id' => $stateId,
                    'payload' => [
                        'state_id' => $stateId,
                        'wait_event_id' => $waitEventId,
                        'card_index' => $cardIndex,
                        'remind_text' => $remindText,
                        'next_run_at' => $nextRunAt,
                        'wait_deadline_at' => $waitDeadlineAt,
                        'queued_at' => $nowSql,
                    ],
                    'status' => 'pending',
                    'scheduled_at' => $nowSql,
                    'max_attempts' => 5,
                    'dedupe_key' => 'scenario_question_reminder:' . $stateId . ':' . $blockId . ':' . $waitEventId,
                ]);
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_reminder_queued', 'Напоминание по вопросу поставлено в универсальную очередь.', ['card_index' => $cardIndex, 'next_run_at' => $nextRunAt, 'wait_deadline_at' => $waitDeadlineAt, 'queue_id' => $queueId]);
                $result['queued']++;
                continue;
            }

            if (!$isFinalDeadlineDue) {
                $nextCheckAt = $waitDeadlineAt !== '' ? $waitDeadlineAt : null;
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET next_run_at = ?, updated_at = NOW() WHERE id = ? AND status = 'waiting'")->execute([$nextCheckAt, $stateId]);
                $result['skipped']++;
                continue;
            }

            $targetBlockId = asr_tg_runtime_question_no_answer_target_block_id($pdo, $scenarioId, $blockId, $card);
            if ($targetBlockId <= 0) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_no_answer_no_target', 'Подписчик не ответил, но выход «Подписчик не ответил» не настроен.', ['card_index' => $cardIndex]);
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'active', next_run_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$stateId]);
                $result['skipped']++;
                continue;
            }

            $claim = $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'queued', next_run_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ? AND status = 'waiting' AND next_run_at <= ?");
            $claim->execute([$stateId, $nowSql]);
            if ($claim->rowCount() <= 0) { $result['skipped']++; continue; }

            $queueId = asr_tg_send_queue_enqueue($pdo, [
                'bot_id' => $botId,
                'subscriber_id' => $subscriberId,
                'chat_id' => $chatId,
                'task_type' => 'scenario_question_timeout',
                'source_type' => 'scenario_question',
                'source_id' => $stateId,
                'scenario_id' => $scenarioId,
                'block_id' => $blockId,
                'state_id' => $stateId,
                'payload' => [
                    'state_id' => $stateId,
                    'wait_event_id' => $waitEventId,
                    'card_index' => $cardIndex,
                    'target_block_id' => $targetBlockId,
                    'queued_at' => $nowSql,
                ],
                'status' => 'pending',
                'scheduled_at' => $nowSql,
                'max_attempts' => 5,
                'dedupe_key' => 'scenario_question_timeout:' . $stateId . ':' . $blockId . ':' . $targetBlockId,
            ]);
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_no_answer_queued', 'Ветка «Подписчик не ответил» поставлена в универсальную очередь.', ['card_index' => $cardIndex, 'target_block_id' => $targetBlockId, 'queue_id' => $queueId]);
            $result['queued']++;
        } catch (Throwable $e) {
            $result['failed']++;
            try {
                $pdo->prepare("UPDATE oca_telegram_bot_subscriber_scenarios SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")->execute([$e->getMessage(), $stateId]);
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_due_failed', $e->getMessage(), ['state_id' => $stateId]);
            } catch (Throwable $ignored) {}
        }
    }
    return $result;
}

function asr_tg_runtime_channel_type(array $bot): string {
    $type = function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : strtolower(trim((string)($bot['channel_type'] ?? 'telegram')));
    return $type === 'vk' ? 'vk' : 'telegram';
}

function asr_tg_runtime_vk_message_id(array $sent): ?int {
    $response = $sent['response']['response'] ?? ($sent['response'] ?? null);
    if (is_numeric($response)) return (int)$response;
    if (is_array($response)) {
        foreach (['message_id', 'id'] as $key) {
            if (isset($response[$key]) && is_numeric($response[$key])) return (int)$response[$key];
        }
    }
    return null;
}

function asr_tg_runtime_execute_message_block_vk(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block): bool {
    $token = asr_tg_decrypt_token((string)($bot['vk_api_token_encrypted'] ?? ''));
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен VK-канала. Проверьте ключ доступа сообщества.');

    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден для выполнения VK-сценария.');

    $peerId = trim((string)($subscriber['external_chat_id'] ?? ''));
    if ($peerId === '') $peerId = trim((string)$chatId);
    if ($peerId === '') $peerId = trim((string)($subscriber['chat_id'] ?? ''));
    if ($peerId === '') throw new RuntimeException('У VK-подписчика нет peer_id. Попросите пользователя написать в сообщество ещё раз.');

    $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
    $blockId = (int)$block['id'];
    $GLOBALS['asr_tg_runtime_last_message_waiting_question'] = null;

    if (!$cards) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_message_empty', 'В VK-блоке сообщения нет карточек для отправки.', [
            'has_settings_json' => trim((string)($block['settings_json'] ?? '')) !== '',
            'has_message_text' => trim((string)($block['message_text'] ?? '')) !== '',
        ]);
        return false;
    }

    $sentAny = false;
    foreach ($cards as $index => $card) {
        if (!is_array($card)) continue;
        $originalType = strtolower(trim((string)($card['type'] ?? 'text')));
        if ($originalType === '') $originalType = 'text';

        [$runtimeCard, $plainText, $buttons] = asr_tg_runtime_prepare_card_for_send($pdo, $card, $subscriber, $blockId, (int)$index);
        $runtimeType = (string)($runtimeCard['type'] ?? 'text');
        if ($runtimeType === 'image') $runtimeType = 'photo';
        if ($runtimeType === 'file') $runtimeType = 'document';

        if ($runtimeType === 'gallery') {
            $items = (array)($runtimeCard['gallery_items'] ?? []);
            $itemsCount = asr_tg_runtime_media_items_count($items);
            if ($itemsCount <= 0) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_card_skipped', 'VK-галерея пропущена: не найдены картинки.', ['card_index' => $index, 'card_type' => $originalType]);
                continue;
            }
            if ($itemsCount > 1) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_gallery_limited', 'VK-сценарий на этом шаге отправляет из галереи только первую картинку.', ['card_index' => $index, 'items_count' => $itemsCount]);
            }
            $single = [];
            foreach ($items as $item) { if (is_array($item)) { $single = $item; break; } }
            $runtimeCard['type'] = 'photo';
            $runtimeCard['media_url'] = (string)($single['media_url'] ?? $single['url'] ?? '');
            $singleLocal = asr_tg_runtime_card_local_file_path($single);
            if ($singleLocal !== '') $runtimeCard['local_file_path'] = $singleLocal;
            $runtimeType = 'photo';
        }

        if (in_array($runtimeType, ['video', 'audio', 'voice'], true)) {
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_card_skipped', 'VK-сценарий пропустил медиа, которое пока не поддерживается в VK-runtime. Для видео используйте картинку/файл + URL-кнопку.', ['card_index' => $index, 'card_type' => $originalType, 'runtime_type' => $runtimeType]);
            continue;
        }

        if ($runtimeType === 'text' && mb_strlen(trim($plainText), 'UTF-8') === 0 && !$buttons) continue;

        $vkCard = $runtimeCard;
        $vkCard['type'] = $runtimeType;
        $vkCard['buttons'] = (array)($runtimeCard['buttons'] ?? []);

        $sent = asr_tg_broadcast_vk_send_card($token, $peerId, $vkCard);
        $vkMessageId = asr_tg_runtime_vk_message_id($sent);
        $sentText = (string)($sent['text'] ?? (function_exists('asr_tg_broadcast_card_vk_text') ? asr_tg_broadcast_card_vk_text((string)($runtimeCard['text'] ?? '')) : $plainText));
        $messageType = (string)($sent['type'] ?? ($runtimeType === 'document' ? 'file' : $runtimeType));

        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', $messageType, $sentText, $vkMessageId, [
            'platform' => 'vk',
            'scenario_id' => $scenarioId,
            'block_id' => $blockId,
            'card_index' => $index,
            'card_type' => $originalType,
            'runtime_type' => $runtimeType,
            'buttons_count' => count($buttons),
            'vk_peer_id' => $peerId,
            'vk_response' => $sent['response'] ?? null,
            'vk_attachment' => (string)($sent['attachment'] ?? ''),
            'vk_keyboard' => !empty($sent['keyboard']),
        ]);

        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_message_sent', 'Отправлена VK-карточка блока «Сообщение».', [
            'card_index' => $index,
            'card_type' => $originalType,
            'runtime_type' => $runtimeType,
            'buttons_count' => count($buttons),
            'vk_keyboard' => !empty($sent['keyboard']),
        ]);

        if ($originalType === 'question') {
            $questionDeadline = asr_tg_runtime_question_first_check_sql($card);
            $waitDeadlineAt = asr_tg_runtime_question_wait_deadline_sql($card);
            $reminderAt = !empty($card['remind_no_answer']) ? asr_tg_runtime_question_reminder_next_sql($card) : null;
            $GLOBALS['asr_tg_runtime_last_message_waiting_question'] = [
                'block_id' => $blockId,
                'card_index' => (int)$index,
                'save_field_code' => asr_tg_runtime_question_save_field_code($card),
                'answers_count' => count((array)($card['answers'] ?? [])),
                'no_answer_target_block_id' => asr_tg_runtime_question_no_answer_target_block_id($pdo, $scenarioId, $blockId, $card),
                'enable_check' => !empty($card['enable_check']),
                'remind_no_answer' => !empty($card['remind_no_answer']),
                'reminder_at' => $reminderAt,
                'wait_deadline_at' => $waitDeadlineAt,
                'next_run_at' => $questionDeadline,
            ];
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_vk_question_waiting', 'VK-вопрос отправлен, ожидаем текстовый ответ подписчика.', $GLOBALS['asr_tg_runtime_last_message_waiting_question']);
        }

        $sentAny = true;
    }

    if ($sentAny) {
        asr_tg_scenario_stats_increment_sent($pdo, $scenarioId, $blockId);
    }
    return $sentAny;
}

function asr_tg_runtime_execute_message_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block): bool {
    if (asr_tg_runtime_channel_type($bot) === 'vk') {
        return asr_tg_runtime_execute_message_block_vk($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block);
    }

    $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
    if ($token === '') throw new RuntimeException('Не удалось расшифровать токен Telegram-канала.');
    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден для выполнения сценария.');
    $cards = asr_tg_runtime_cards_from_block($pdo, $scenarioId, $block);
    $blockId = (int)$block['id'];
    $GLOBALS['asr_tg_runtime_last_message_waiting_question'] = null;

    if (!$cards) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_message_empty', 'В блоке сообщения нет карточек для отправки.', [
            'has_settings_json' => trim((string)($block['settings_json'] ?? '')) !== '',
            'has_message_text' => trim((string)($block['message_text'] ?? '')) !== '',
        ]);
        return false;
    }

    $sentAny = false;
    foreach ($cards as $index => $card) {
        if (!is_array($card)) continue;
        $originalType = strtolower(trim((string)($card['type'] ?? 'text')));
        if ($originalType === '') $originalType = 'text';

        [$runtimeCard, $plainText, $buttons] = asr_tg_runtime_prepare_card_for_send($pdo, $card, $subscriber, $blockId, (int)$index);
        $runtimeType = (string)($runtimeCard['type'] ?? 'text');
        $messageType = asr_tg_runtime_card_message_type($card);

        if ($runtimeType === 'text') {
            if (mb_strlen(trim($plainText), 'UTF-8') === 0 && !$buttons) continue;
            if (mb_strlen($plainText, 'UTF-8') > 4096 || mb_strlen((string)$runtimeCard['text'], 'UTF-8') > 4096) {
                throw new RuntimeException('Текст блока #' . $blockId . ' длиннее лимита Telegram 4096 символов.');
            }
        } elseif ($runtimeType === 'gallery') {
            $items = (array)($runtimeCard['gallery_items'] ?? []);
            $itemsCount = asr_tg_runtime_media_items_count($items);
            if ($itemsCount <= 0) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_card_skipped', 'Галерея пропущена: не найдены картинки.', ['card_index' => $index, 'card_type' => $originalType]);
                continue;
            }
            if ($itemsCount === 1) {
                $single = [];
                foreach ($items as $item) {
                    if (is_array($item)) { $single = $item; break; }
                }
                $runtimeCard['type'] = 'photo';
                $runtimeCard['media_url'] = (string)($single['media_url'] ?? $single['url'] ?? '');
                $singleLocal = asr_tg_runtime_card_local_file_path($single);
                if ($singleLocal !== '') $runtimeCard['local_file_path'] = $singleLocal;
                $runtimeType = 'photo';
                $messageType = 'photo';
            }
        } else {
            $hasMedia = trim((string)($runtimeCard['media_url'] ?? '')) !== '' || trim((string)($runtimeCard['local_file_path'] ?? '')) !== '';
            if (!$hasMedia) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_card_skipped', 'Медиа-карточка пропущена: не указан файл или ссылка.', ['card_index' => $index, 'card_type' => $originalType]);
                continue;
            }
            if (mb_strlen((string)$runtimeCard['text'], 'UTF-8') > 1024) {
                $runtimeCard['text'] = mb_substr((string)$runtimeCard['text'], 0, 1024, 'UTF-8');
                $plainText = mb_substr($plainText, 0, 1024, 'UTF-8');
            }
        }

        try {
            $sent = asr_tg_api_send_broadcast_card($token, $chatId, $runtimeCard);
        } catch (Throwable $sendHtmlError) {
            if ((string)($runtimeCard['parse_mode'] ?? '') === 'HTML' && (string)($runtimeCard['text'] ?? '') !== '') {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_message_html_retry_plain', 'Telegram не принял HTML-разметку, повторяем отправку обычным текстом.', ['card_index' => $index, 'card_type' => $originalType, 'error' => $sendHtmlError->getMessage()]);
                $runtimeCard['text'] = $plainText;
                $runtimeCard['parse_mode'] = '';
                $sent = asr_tg_api_send_broadcast_card($token, $chatId, $runtimeCard);
            } else {
                throw $sendHtmlError;
            }
        }

        $sentMessageId = asr_tg_runtime_sent_message_id($sent);
        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', $messageType, $plainText, $sentMessageId, [
            'scenario_id' => $scenarioId,
            'block_id' => $blockId,
            'card_index' => $index,
            'card_type' => $originalType,
            'buttons_count' => count($buttons),
            'telegram' => $sent['result'] ?? null,
        ]);

        if ($sentMessageId > 0 && function_exists('asr_tg_scenario_sent_message_record')) {
            try {
                asr_tg_scenario_sent_message_record($pdo, [
                    'scenario_id' => $scenarioId,
                    'block_id' => $blockId,
                    'bot_id' => $botId,
                    'subscriber_id' => $subscriberId,
                    'chat_id' => (string)$chatId,
                    'card_index' => (int)$index,
                    'card_type' => $originalType,
                    'telegram_message_id' => $sentMessageId,
                ]);
            } catch (Throwable $recordError) {
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_message_sent_record_failed', 'Не удалось сохранить идентификатор отправленного сообщения для будущего удаления.', ['card_index' => $index, 'error' => $recordError->getMessage()]);
            }
        }

        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_message_sent', 'Отправлена карточка блока «Сообщение».', [
            'card_index' => $index,
            'card_type' => $originalType,
            'runtime_type' => $runtimeType,
            'buttons_count' => count($buttons),
        ]);

        if ($originalType === 'question') {
            $questionDeadline = asr_tg_runtime_question_first_check_sql($card);
            $waitDeadlineAt = asr_tg_runtime_question_wait_deadline_sql($card);
            $reminderAt = !empty($card['remind_no_answer']) ? asr_tg_runtime_question_reminder_next_sql($card) : null;
            $GLOBALS['asr_tg_runtime_last_message_waiting_question'] = [
                'block_id' => $blockId,
                'card_index' => (int)$index,
                'save_field_code' => asr_tg_runtime_question_save_field_code($card),
                'answers_count' => count((array)($card['answers'] ?? [])),
                'no_answer_target_block_id' => asr_tg_runtime_question_no_answer_target_block_id($pdo, $scenarioId, $blockId, $card),
                'enable_check' => !empty($card['enable_check']),
                'remind_no_answer' => !empty($card['remind_no_answer']),
                'reminder_at' => $reminderAt,
                'wait_deadline_at' => $waitDeadlineAt,
                'next_run_at' => $questionDeadline,
            ];
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_question_waiting', 'Вопрос отправлен, ожидаем ответ подписчика.', $GLOBALS['asr_tg_runtime_last_message_waiting_question']);
        }

        $sentAny = true;
    }
    if ($sentAny) {
        asr_tg_scenario_stats_increment_sent($pdo, $scenarioId, $blockId);
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

    $runtimeStackKey = $botId . ':' . $subscriberId . ':' . $scenarioId;
    if (!isset($GLOBALS['asr_tg_runtime_block_stack']) || !is_array($GLOBALS['asr_tg_runtime_block_stack'])) {
        $GLOBALS['asr_tg_runtime_block_stack'] = [];
    }
    if (!isset($GLOBALS['asr_tg_runtime_block_stack'][$runtimeStackKey]) || !is_array($GLOBALS['asr_tg_runtime_block_stack'][$runtimeStackKey])) {
        $GLOBALS['asr_tg_runtime_block_stack'][$runtimeStackKey] = [];
    }
    $stack =& $GLOBALS['asr_tg_runtime_block_stack'][$runtimeStackKey];
    if (count($stack) > 120) {
        $message = 'Сценарий остановлен: слишком длинная цепочка шагов без ожидания ответа или задержки. Проверьте связи между блоками.';
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $message);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_guard_too_long', $message, ['source' => $source, 'stack_size' => count($stack)] + $sourcePayload);
        return true;
    }
    $sameBlockHits = 0;
    foreach ($stack as $visitedBlockId) {
        if ((int)$visitedBlockId === $blockId) $sameBlockHits++;
    }
    if ($sameBlockHits >= 3) {
        $message = 'Сценарий остановлен: найден повторный проход по одному и тому же блоку. Проверьте зацикленные связи.';
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $message);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_guard_loop_detected', $message, ['source' => $source, 'stack' => array_slice($stack, -20)] + $sourcePayload);
        return true;
    }
    $stack[] = $blockId;

    // Помечаем, что штатный обработчик уже запустил сценарий в этом webhook-запросе.
    // Это не даёт страховочному wrapper повторно запускать тот же /help с начала сценария.
    $GLOBALS['asr_tg_runtime_started_in_request'] = [
        'bot_id' => $botId,
        'chat_id' => (string)$chatId,
        'subscriber_id' => $subscriberId,
        'scenario_id' => $scenarioId,
        'entry_block_id' => $entryBlockId,
        'resolved_block_id' => $blockId,
        'source' => $source,
    ];

    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_started', 'Сценарий запущен.', ['source' => $source] + $sourcePayload);

    $type = (string)($block['type'] ?? '');
    try {
        if ($type === 'message') {
            $sent = asr_tg_runtime_execute_message_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block);
            $waitingQuestion = !empty($GLOBALS['asr_tg_runtime_last_message_waiting_question']) && is_array($GLOBALS['asr_tg_runtime_last_message_waiting_question']);
            $runtimeStatus = $waitingQuestion ? 'waiting' : ($sent ? 'active' : 'error');
            $runtimeError = $sent ? '' : 'В блоке сообщения нет отправленных карточек.';
            $waitingNextRunAt = $waitingQuestion ? ($GLOBALS['asr_tg_runtime_last_message_waiting_question']['next_run_at'] ?? null) : null;
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, $runtimeStatus, is_string($waitingNextRunAt) && $waitingNextRunAt !== '' ? $waitingNextRunAt : null, $runtimeError);
            if ($sent && !$waitingQuestion) {
                $nextBlockId = asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId);
                if ($nextBlockId > 0 && $nextBlockId !== $blockId) {
                    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_next_auto', 'После блока «Сообщение» запускаем следующий шаг.', ['next_block_id' => $nextBlockId, 'source' => $source]);
                    return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $nextBlockId, 'auto_next', ['from_block_id' => $blockId, 'source' => $source]);
                }
            }
            return true;
        }
        if ($type === 'delay') {
            return asr_tg_runtime_execute_delay_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        if ($type === 'schedule') {
            return asr_tg_runtime_execute_schedule_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        if ($type === 'random') {
            return asr_tg_runtime_execute_random_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        if ($type === 'formula') {
            return asr_tg_runtime_execute_formula_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        if ($type === 'condition') {
            return asr_tg_runtime_execute_condition_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        if ($type === 'actions') {
            return asr_tg_runtime_execute_actions_block($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $block, $source, $sourcePayload);
        }
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_block_unsupported', 'Этот тип блока пока не поддерживается при запуске сценария.', ['block_type' => $type, 'source' => $source]);
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, 'Этот тип блока пока не поддерживается при запуске сценария.');
        return true;
    } catch (Throwable $e) {
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $e->getMessage());
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_failed', $e->getMessage(), ['source' => $source]);
        throw $e;
    }
}

function asr_tg_runtime_default_start_scenario_for_bot(PDO $pdo, int $botId): ?array {
    if ($botId <= 0) return null;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenarios') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_bots')) return null;
        $stmt = $pdo->prepare("SELECT s.id, s.title, s.status, s.start_block_id, sb.is_default
            FROM oca_telegram_bot_scenarios s
            JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id
            WHERE sb.bot_id = ?
              AND COALESCE(sb.is_enabled, 1) = 1
              AND s.status = 'active'
              AND s.archived_at IS NULL
            ORDER BY COALESCE(sb.is_default, 0) DESC, s.id ASC
            LIMIT 2");
        $stmt->execute([$botId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return null;
        if (count($rows) > 1 && (int)($rows[0]['is_default'] ?? 0) !== 1) {
            try {
                asr_tg_log($pdo, $botId, 'warning', 'scenario_plain_start_multiple_active', 'Для /start найдено несколько активных сценариев без явного сценария по умолчанию. Запущен первый по порядку.', [
                    'selected_scenario_id' => (int)($rows[0]['id'] ?? 0),
                    'active_scenarios_count' => count($rows),
                ]);
            } catch (Throwable $ignored) {}
        }
        return $rows[0];
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_plain_start_lookup_failed', $e->getMessage()); } catch (Throwable $ignored) {}
        return null;
    }
}



function asr_tg_runtime_keyword_normalize_text(?string $text): string {
    $value = trim((string)$text);
    if ($value === '') return '';
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return trim($value);
}

function asr_tg_runtime_keyword_phrases_from_settings(array $settings): array {
    $raw = $settings['keyword_phrases'] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/\R/u', $raw) ?: [];
    }
    if (!is_array($raw)) return [];
    $phrases = [];
    foreach ($raw as $phrase) {
        $phrase = trim((string)$phrase);
        if ($phrase === '') continue;
        $normalized = asr_tg_runtime_keyword_normalize_text($phrase);
        if ($normalized === '') continue;
        $phrases[$normalized] = mb_substr($phrase, 0, 160, 'UTF-8');
    }
    return array_values($phrases);
}

function asr_tg_runtime_keyword_matches(string $text, array $phrases, string $mode): ?string {
    $normalizedText = asr_tg_runtime_keyword_normalize_text($text);
    if ($normalizedText === '') return null;
    $mode = $mode === 'exact' ? 'exact' : 'contains';
    foreach ($phrases as $phrase) {
        $normalizedPhrase = asr_tg_runtime_keyword_normalize_text((string)$phrase);
        if ($normalizedPhrase === '') continue;
        if ($mode === 'exact') {
            if ($normalizedText === $normalizedPhrase) return (string)$phrase;
            continue;
        }
        if (mb_strpos($normalizedText, $normalizedPhrase, 0, 'UTF-8') !== false) {
            return (string)$phrase;
        }
    }
    return null;
}

function asr_tg_runtime_subscriber_has_active_scenario(PDO $pdo, int $botId, int $subscriberId): bool {
    if ($botId <= 0 || $subscriberId <= 0) return false;
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_scenarios')) return false;
        $stmt = $pdo->prepare("SELECT scenario_id, current_block_id, status FROM oca_telegram_bot_subscriber_scenarios WHERE bot_id = ? AND subscriber_id = ? AND status IN ('active','waiting','delayed','queued','processing') ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([$botId, $subscriberId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_runtime_keyword_trigger_candidates(PDO $pdo, int $botId): array {
    if ($botId <= 0) return [];
    try {
        asr_tg_repository_ensure_scenario_schema($pdo);
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_scenarios') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_bots') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_blocks')) return [];
        $stmt = $pdo->prepare("SELECT s.id AS scenario_id, s.title AS scenario_title, b.id AS start_block_id, b.settings_json
            FROM oca_telegram_bot_scenarios s
            JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id
            JOIN oca_telegram_bot_scenario_blocks b ON b.scenario_id = s.id AND b.type = 'start'
            WHERE sb.bot_id = ?
              AND COALESCE(sb.is_enabled, 1) = 1
              AND s.status = 'active'
              AND s.archived_at IS NULL
            ORDER BY s.id ASC, b.id ASC");
        $stmt->execute([$botId]);
        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $settings = json_decode((string)($row['settings_json'] ?? ''), true);
            if (!is_array($settings) || empty($settings['keyword_triggers_enabled'])) continue;
            $phrases = asr_tg_runtime_keyword_phrases_from_settings($settings);
            if (!$phrases) continue;
            $priority = (int)($settings['keyword_priority'] ?? 50);
            if ($priority < 1) $priority = 1;
            if ($priority > 100) $priority = 100;
            $items[] = [
                'scenario_id' => (int)($row['scenario_id'] ?? 0),
                'scenario_title' => (string)($row['scenario_title'] ?? ''),
                'start_block_id' => (int)($row['start_block_id'] ?? 0),
                'phrases' => $phrases,
                'match_mode' => (string)($settings['keyword_match_mode'] ?? 'contains') === 'exact' ? 'exact' : 'contains',
                'priority' => $priority,
            ];
        }
        usort($items, static function(array $a, array $b): int {
            $prio = ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0));
            if ($prio !== 0) return $prio;
            return ((int)($a['scenario_id'] ?? 0)) <=> ((int)($b['scenario_id'] ?? 0));
        });
        return $items;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, $botId, 'warning', 'scenario_keyword_candidates_failed', $e->getMessage()); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_runtime_keyword_diagnostic_log(PDO $pdo, int $botId, string $platform, string $eventType, string $message, array $context = []): void {
    $platform = $platform !== '' ? $platform : 'telegram';
    $context['platform'] = $platform;
    try { asr_tg_log($pdo, $botId, 'info', $eventType, $message, $context); } catch (Throwable $ignored) {}
}

function asr_tg_runtime_try_keyword_trigger(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, ?string $text, string $platform = ''): bool {
    $text = trim((string)$text);
    $platform = $platform !== '' ? $platform : (function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : (string)($bot['channel_type'] ?? 'telegram'));
    if ($botId <= 0 || $subscriberId <= 0 || $text === '') return false;
    if (function_exists('asr_tg_runtime_is_service_command_text') && asr_tg_runtime_is_service_command_text($text)) return false;

    if (asr_tg_runtime_subscriber_has_active_scenario($pdo, $botId, $subscriberId)) {
        asr_tg_runtime_keyword_diagnostic_log($pdo, $botId, $platform, 'scenario_keyword_skipped_active_state', 'Ключевые слова не проверяются: подписчик уже находится в активном сценарии.', [
            'subscriber_id' => $subscriberId,
            'reason' => 'subscriber_already_in_scenario',
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
        return false;
    }

    $candidates = asr_tg_runtime_keyword_trigger_candidates($pdo, $botId);
    if (!$candidates) {
        asr_tg_runtime_keyword_diagnostic_log($pdo, $botId, $platform, 'scenario_keyword_no_active_triggers', 'Для канала нет активных сценариев с включёнными ключевыми словами.', [
            'subscriber_id' => $subscriberId,
            'reason' => 'no_active_keyword_triggers',
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
        return false;
    }

    $matches = [];
    foreach ($candidates as $candidate) {
        $matchedPhrase = asr_tg_runtime_keyword_matches($text, (array)($candidate['phrases'] ?? []), (string)($candidate['match_mode'] ?? 'contains'));
        if ($matchedPhrase === null) continue;
        $candidate['matched_phrase'] = $matchedPhrase;
        $candidate['matched_phrase_len'] = mb_strlen(asr_tg_runtime_keyword_normalize_text($matchedPhrase), 'UTF-8');
        $matches[] = $candidate;
    }
    if (!$matches) {
        asr_tg_runtime_keyword_diagnostic_log($pdo, $botId, $platform, 'scenario_keyword_no_match', 'Входящее сообщение не совпало с ключевыми словами активных сценариев.', [
            'subscriber_id' => $subscriberId,
            'reason' => 'keyword_not_matched',
            'active_keyword_scenarios' => count($candidates),
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
        return false;
    }

    usort($matches, static function(array $a, array $b): int {
        $prio = ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0));
        if ($prio !== 0) return $prio;
        $len = ((int)($b['matched_phrase_len'] ?? 0)) <=> ((int)($a['matched_phrase_len'] ?? 0));
        if ($len !== 0) return $len;
        return ((int)($a['scenario_id'] ?? 0)) <=> ((int)($b['scenario_id'] ?? 0));
    });

    $selected = $matches[0];
    $scenarioId = (int)($selected['scenario_id'] ?? 0);
    if ($scenarioId <= 0) {
        asr_tg_runtime_keyword_diagnostic_log($pdo, $botId, $platform, 'scenario_keyword_start_failed', 'Ключевое слово совпало, но сценарий не определён.', [
            'subscriber_id' => $subscriberId,
            'reason' => 'scenario_id_empty',
            'matches_count' => count($matches),
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
        return false;
    }

    try {
        asr_tg_log($pdo, $botId, 'info', 'scenario_keyword_trigger_matched', 'Входящее сообщение совпало с ключевым словом сценария.', [
            'subscriber_id' => $subscriberId,
            'platform' => $platform,
            'scenario_id' => $scenarioId,
            'scenario_title' => (string)($selected['scenario_title'] ?? ''),
            'matched_phrase' => (string)($selected['matched_phrase'] ?? ''),
            'match_mode' => (string)($selected['match_mode'] ?? 'contains'),
            'priority' => (int)($selected['priority'] ?? 50),
            'matches_count' => count($matches),
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
    } catch (Throwable $ignored) {}

    $started = asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, 0, $platform . '_keyword_trigger', [
        'matched_phrase' => (string)($selected['matched_phrase'] ?? ''),
        'match_mode' => (string)($selected['match_mode'] ?? 'contains'),
        'priority' => (int)($selected['priority'] ?? 50),
        'matches_count' => count($matches),
    ]);
    if (!$started) {
        asr_tg_runtime_keyword_diagnostic_log($pdo, $botId, $platform, 'scenario_keyword_start_failed', 'Ключевое слово совпало, но runtime не запустил сценарий.', [
            'subscriber_id' => $subscriberId,
            'reason' => 'runtime_start_returned_false',
            'scenario_id' => $scenarioId,
            'matched_phrase' => (string)($selected['matched_phrase'] ?? ''),
            'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
        ]);
    }
    return $started;
}

function asr_tg_send_welcome_message_if_enabled(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, string $source = 'manual'): bool {
    if ($botId <= 0 || $subscriberId <= 0 || (string)$chatId === '') return false;
    if (empty($bot['welcome_enabled'])) return false;
    $welcomeText = trim((string)($bot['welcome_text'] ?? ''));
    if ($welcomeText === '') return false;
    try {
        $channelType = function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : (string)($bot['channel_type'] ?? 'telegram');
        if ($channelType === 'vk') {
            $token = asr_tg_decrypt_token((string)($bot['vk_api_token_encrypted'] ?? ''));
            if ($token === '') throw new RuntimeException('Не удалось расшифровать токен VK-канала.');
            asr_tg_vk_send_message($token, $chatId, $welcomeText);
        } else {
            $token = asr_tg_decrypt_token((string)($bot['bot_token_encrypted'] ?? ''));
            if ($token === '') throw new RuntimeException('Не удалось расшифровать токен Telegram-канала.');
            asr_tg_api_send_message($token, $chatId, $welcomeText);
        }
        asr_tg_message_add($pdo, $botId, $subscriberId, 'out', 'text', $welcomeText, null, ['welcome_message' => true, 'source' => $source]);
        asr_tg_log($pdo, $botId, 'info', 'channel_welcome_sent', 'Стартовое сообщение канала отправлено.', ['subscriber_id' => $subscriberId, 'source' => $source]);
        return true;
    } catch (Throwable $e) {
        try {
            asr_tg_bot_update($pdo, $botId, ['last_error' => 'Стартовое сообщение: ' . $e->getMessage()]);
            asr_tg_log($pdo, $botId, 'warning', 'channel_welcome_failed', $e->getMessage(), ['subscriber_id' => $subscriberId, 'source' => $source]);
        } catch (Throwable $ignored) {}
        return false;
    }
}

function asr_tg_runtime_try_command(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, ?string $text): bool {
    $command = asr_tg_runtime_extract_command($text);
    if ($command === '') return false;
    if ($command === 'start') {
        $startPayload = asr_tg_runtime_extract_start_payload($text);
        if ($startPayload !== '') {
            try { asr_tg_log($pdo, $botId, 'info', 'scenario_start_payload_reserved', 'Команда /start с payload обрабатывается только как диплинк сценария.', ['subscriber_id' => $subscriberId, 'payload' => mb_substr($startPayload, 0, 120, 'UTF-8')]); } catch (Throwable $ignored) {}
            return true;
        }

        $defaultScenario = asr_tg_runtime_default_start_scenario_for_bot($pdo, $botId);
        $scenarioId = (int)($defaultScenario['id'] ?? 0);
        if ($scenarioId <= 0) {
            if (asr_tg_send_welcome_message_if_enabled($pdo, $bot, $botId, $chatId, $subscriberId, 'telegram_start_without_scenario')) {
                return true;
            }
            try { asr_tg_log($pdo, $botId, 'warning', 'scenario_plain_start_without_active_scenario', 'Получен обычный /start, но у канала нет активного сценария для запуска.', ['subscriber_id' => $subscriberId]); } catch (Throwable $ignored) {}
            return true;
        }

        try {
            asr_tg_log($pdo, $botId, 'info', 'scenario_plain_start_received', 'Получен обычный /start: запускаем активный сценарий канала с начала.', [
                'subscriber_id' => $subscriberId,
                'scenario_id' => $scenarioId,
                'scenario_title' => (string)($defaultScenario['title'] ?? ''),
            ]);
        } catch (Throwable $ignored) {}
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, 0, 'telegram_start', ['command' => 'start']);
    }
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
    try { asr_tg_bot_commands_delete_reserved_start($pdo, $botId); } catch (Throwable $ignored) {}
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

    $callbackQuery = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
    if ($callbackQuery) {
        $callbackId = (string)($callbackQuery['id'] ?? '');
        $callbackData = (string)($callbackQuery['data'] ?? '');
        $callbackMessage = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];
        $chat = is_array($callbackMessage['chat'] ?? null) ? $callbackMessage['chat'] : [];
        $chatId = $chat['id'] ?? ($from['id'] ?? 0);
        $subscriberId = asr_tg_subscriber_upsert($pdo, $botId, $from, $chatId);
        try {
            asr_tg_log($pdo, $botId, 'info', 'scenario_callback_received', 'Получено нажатие inline-кнопки сценария.', [
                'subscriber_id' => (int)$subscriberId,
                'callback_data' => mb_substr($callbackData, 0, 120, 'UTF-8'),
            ]);
        } catch (Throwable $ignored) {}
        if ($subscriberId > 0 && $chatId) {
            try {
                asr_tg_runtime_try_callback($pdo, $bot, $botId, $chatId, (int)$subscriberId, $callbackId, $callbackData);
            } catch (Throwable $e) {
                asr_tg_bot_update($pdo, $botId, ['last_error' => $e->getMessage()]);
                asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackId, 'Не удалось выполнить кнопку.', true);
                asr_tg_log($pdo, $botId, 'error', 'scenario_callback_failed', $e->getMessage(), ['subscriber_id' => (int)$subscriberId, 'callback_data' => $callbackData]);
            }
        } else {
            asr_tg_runtime_answer_callback($pdo, $bot, $botId, $callbackId, 'Подписчик не найден.', true);
        }
        http_response_code(200);
        return;
    }

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
            if (!$scenarioRuntimeHandled && !$isServiceCommand) {
                $scenarioRuntimeHandled = asr_tg_runtime_try_waiting_question_text($pdo, $bot, $botId, $chatId, (int)$subscriberId, $text);
            }
            if (!$scenarioRuntimeHandled) {
                $scenarioRuntimeHandled = asr_tg_runtime_try_command($pdo, $bot, $botId, $chatId, (int)$subscriberId, $text);
            }
            if (!$scenarioRuntimeHandled && !$isServiceCommand && function_exists('asr_tg_runtime_try_keyword_trigger')) {
                $scenarioRuntimeHandled = asr_tg_runtime_try_keyword_trigger($pdo, $bot, $botId, $chatId, (int)$subscriberId, $text, 'telegram');
            }
        } catch (Throwable $e) {
            asr_tg_bot_update($pdo, $botId, ['last_error' => $e->getMessage()]);
            asr_tg_log($pdo, $botId, 'error', 'scenario_runtime_failed', $e->getMessage(), ['subscriber_id' => (int)$subscriberId, 'text' => $text]);
            $scenarioRuntimeHandled = true;
        }
    }

    if (!$isServiceCommand && !$scenarioRuntimeHandled) {
        $messageType = $text !== null ? 'text' : 'other';
        asr_tg_message_add($pdo, $botId, $subscriberId ?: null, 'in', $messageType, $text, isset($message['message_id']) ? (int)$message['message_id'] : null, $message);
    } elseif ($isServiceCommand) {
        asr_tg_log($pdo, $botId, 'info', 'webhook_service_command_hidden', 'Служебная команда не добавлена в диалоги и не отправлена в технический бот.', [
            'subscriber_id' => (int)$subscriberId,
            'command' => asr_tg_runtime_extract_command($text),
            'text_preview' => mb_substr((string)$text, 0, 120, 'UTF-8'),
        ]);
    } else {
        asr_tg_log($pdo, $botId, 'info', 'webhook_scenario_answer_hidden', 'Ответ на вопрос сценария не добавлен в диалоги и не отправлен в технический бот.', [
            'subscriber_id' => (int)$subscriberId,
            'text_preview' => mb_substr((string)$text, 0, 120, 'UTF-8'),
        ]);
    }

    if (!$isServiceCommand && !$scenarioRuntimeHandled && $subscriberId > 0) {
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
        'timezone' => (string)($post['timezone'] ?? ''),
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
