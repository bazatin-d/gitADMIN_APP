<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/vk_subscriber_service.php';

/**
 * VK Callback API layer.
 *
 * На текущем шаге слой принимает Callback API, подтверждает сервер ВК
 * и для события message_new создаёт/обновляет VK-подписчика и входящее
 * сообщение в существующей модели диалогов. Сценарии и исходящая отправка
 * пока намеренно не подключаются.
 */

if (!function_exists('asr_tg_vk_webhook_json_response')) {
    function asr_tg_vk_webhook_json_response(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('asr_tg_vk_webhook_text_response')) {
    function asr_tg_vk_webhook_text_response(string $text, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
        exit;
    }
}

if (!function_exists('asr_tg_vk_webhook_normalize_group_id')) {
    function asr_tg_vk_webhook_normalize_group_id($groupId): string {
        $groupId = trim((string)$groupId);
        return preg_replace('/[^0-9A-Za-z_\-]/', '', $groupId) ?: '';
    }
}

if (!function_exists('asr_tg_vk_webhook_find_channel')) {
    function asr_tg_vk_webhook_find_channel(PDO $pdo, string $groupId): ?array {
        if ($groupId === '') return null;
        if (function_exists('asr_tg_repository_ensure_schema')) {
            asr_tg_repository_ensure_schema($pdo);
        }
        $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bots WHERE channel_type = 'vk' AND vk_group_id = ? LIMIT 1");
        $stmt->execute([$groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('asr_tg_vk_webhook_event_context')) {
    function asr_tg_vk_webhook_event_context(array $event): array {
        $object = is_array($event['object'] ?? null) ? $event['object'] : [];
        $message = is_array($object['message'] ?? null) ? $object['message'] : [];
        $clientInfo = is_array($object['client_info'] ?? null) ? $object['client_info'] : [];

        $text = (string)($message['text'] ?? '');
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];

        return [
            'type' => (string)($event['type'] ?? ''),
            'group_id' => $event['group_id'] ?? null,
            'event_id' => $event['event_id'] ?? null,
            'user_id' => $message['from_id'] ?? ($object['user_id'] ?? null),
            'peer_id' => $message['peer_id'] ?? null,
            'message_id' => $message['id'] ?? null,
            'text_preview' => mb_substr($text, 0, 300, 'UTF-8'),
            'attachments_count' => count($attachments),
            'client_button_actions' => is_array($clientInfo['button_actions'] ?? null) ? $clientInfo['button_actions'] : null,
        ];
    }
}

if (!function_exists('asr_tg_vk_webhook_secret_is_valid')) {
    function asr_tg_vk_webhook_secret_is_valid(array $channel, array $event): bool {
        $expected = trim((string)($channel['vk_secret_key'] ?? ''));
        if ($expected === '') return true;
        $actual = trim((string)($event['secret'] ?? ''));
        return $actual !== '' && hash_equals($expected, $actual);
    }
}

if (!function_exists('asr_tg_vk_webhook_handle')) {
    function asr_tg_vk_webhook_handle(PDO $pdo, string $rawBody): void {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            asr_tg_vk_webhook_json_response(['ok' => false, 'error' => 'empty_body'], 400);
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            asr_tg_vk_webhook_json_response(['ok' => false, 'error' => 'bad_json'], 400);
        }

        $type = (string)($event['type'] ?? '');
        $groupId = asr_tg_vk_webhook_normalize_group_id($event['group_id'] ?? '');
        $channel = asr_tg_vk_webhook_find_channel($pdo, $groupId);

        if (!$channel) {
            // Канал не найден: возвращаем 404, чтобы не маскировать ошибочную настройку Callback API.
            asr_tg_vk_webhook_json_response(['ok' => false, 'error' => 'vk_channel_not_found'], 404);
        }

        $botId = (int)($channel['id'] ?? 0);

        if (!asr_tg_vk_webhook_secret_is_valid($channel, $event)) {
            try {
                asr_tg_bot_update($pdo, $botId, ['last_error' => 'VK Callback API: неверный secret key.']);
                asr_tg_log($pdo, $botId, 'warning', 'vk_callback_bad_secret', 'VK Callback API отклонён: неверный secret key.', asr_tg_vk_webhook_event_context($event));
            } catch (Throwable $ignored) {}
            asr_tg_vk_webhook_json_response(['ok' => false, 'error' => 'bad_secret'], 403);
        }

        if ($type === 'confirmation') {
            $confirmation = trim((string)($channel['vk_confirmation_code'] ?? ''));
            if ($confirmation === '') {
                try {
                    asr_tg_bot_update($pdo, $botId, ['last_error' => 'VK Callback API: не заполнена строка подтверждения сервера.']);
                    asr_tg_log($pdo, $botId, 'error', 'vk_callback_confirmation_missing', 'ВК запросил подтверждение сервера, но строка подтверждения не заполнена.');
                } catch (Throwable $ignored) {}
                asr_tg_vk_webhook_json_response(['ok' => false, 'error' => 'confirmation_code_missing'], 500);
            }

            try {
                asr_tg_bot_update($pdo, $botId, ['status' => 'active', 'last_error' => null, 'last_webhook_at' => date('Y-m-d H:i:s')]);
                asr_tg_log($pdo, $botId, 'info', 'vk_callback_confirmed', 'VK Callback API подтвердил сервер.');
            } catch (Throwable $ignored) {}
            asr_tg_vk_webhook_text_response($confirmation);
        }

        try {
            asr_tg_bot_update($pdo, $botId, ['last_error' => null, 'last_webhook_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $ignored) {}

        if ($type === 'message_new') {
            try {
                $result = asr_tg_vk_message_new_handle($pdo, $channel, $event);
                if (empty($result['handled'])) {
                    asr_tg_log($pdo, $botId, 'warning', 'vk_message_new_skipped', 'Событие VK message_new получено, но не добавлено в диалоги.', array_merge(asr_tg_vk_webhook_event_context($event), [
                        'reason' => (string)($result['reason'] ?? 'unknown'),
                    ]));
                }
            } catch (Throwable $e) {
                try {
                    asr_tg_bot_update($pdo, $botId, ['last_error' => 'VK message_new: ' . $e->getMessage()]);
                    asr_tg_log($pdo, $botId, 'error', 'vk_message_new_error', $e->getMessage(), asr_tg_vk_webhook_event_context($event));
                } catch (Throwable $ignored) {}
                // VK должен получить ok, иначе он будет ретраить событие и плодить дубли/нагрузку.
                asr_tg_vk_webhook_text_response('ok');
            }

            asr_tg_vk_webhook_text_response('ok');
        }

        try {
            asr_tg_log($pdo, $botId, 'info', 'vk_callback_event_received', 'Получено событие VK Callback API. На этом шаге этот тип события только диагностируется.', asr_tg_vk_webhook_event_context($event));
        } catch (Throwable $ignored) {}

        asr_tg_vk_webhook_text_response('ok');
    }
}
