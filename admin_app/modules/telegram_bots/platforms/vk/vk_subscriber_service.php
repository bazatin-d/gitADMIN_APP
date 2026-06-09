<?php
defined('ASR_ADMIN') || exit;

/**
 * VK subscriber/message adapter.
 *
 * This layer converts VK Callback API message_new events into the existing
 * subscriber + message + dialog model without touching Telegram runtime.
 */

if (!function_exists('asr_tg_vk_message_text_value')) {
    function asr_tg_vk_message_text_value($value, int $limit = 4000): string {
        $text = trim((string)$value);
        if ($text === '') return '';
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}

if (!function_exists('asr_tg_vk_legacy_numeric_id')) {
    function asr_tg_vk_legacy_numeric_id($value): int {
        $id = (int)$value;
        if ($id === 0) return 0;
        // Legacy compatibility: the existing table still has a required
        // telegram_user_id + unique(bot_id, telegram_user_id). For VK we keep
        // the real identifier in external_user_id and use a negative numeric
        // compatibility id so Telegram cross-channel sync never picks it up.
        return -abs($id);
    }
}

if (!function_exists('asr_tg_vk_attachment_summary')) {
    function asr_tg_vk_attachment_summary(array $attachments): array {
        $types = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $type = trim((string)($attachment['type'] ?? ''));
            if ($type !== '') $types[] = $type;
        }
        $types = array_values(array_unique($types));
        return [
            'count' => count($attachments),
            'types' => $types,
            'label' => $types ? implode(', ', $types) : 'вложение',
        ];
    }
}

if (!function_exists('asr_tg_vk_extract_message_new')) {
    function asr_tg_vk_extract_message_new(array $event): ?array {
        if ((string)($event['type'] ?? '') !== 'message_new') return null;
        $object = is_array($event['object'] ?? null) ? $event['object'] : [];
        $message = is_array($object['message'] ?? null) ? $object['message'] : [];
        if (!$message) return null;

        $fromId = (int)($message['from_id'] ?? 0);
        $peerId = (int)($message['peer_id'] ?? $fromId);
        if ($fromId <= 0 || $peerId <= 0) return null;

        // Incoming-only on this step. Outgoing VK events will be handled later
        // when the VK sender is connected, otherwise we can create duplicates.
        if (!empty($message['out'])) return null;

        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        $attachmentSummary = asr_tg_vk_attachment_summary($attachments);
        $text = asr_tg_vk_message_text_value($message['text'] ?? '');
        $messageType = $text !== '' ? 'text' : ($attachmentSummary['count'] > 0 ? 'vk_attachment' : 'text');
        $visibleText = $text;
        if ($visibleText === '' && $attachmentSummary['count'] > 0) {
            $visibleText = 'Вложение VK: ' . $attachmentSummary['label'];
        }

        return [
            'user_id' => (string)$fromId,
            'peer_id' => (string)$peerId,
            'legacy_user_id' => asr_tg_vk_legacy_numeric_id($fromId),
            'legacy_chat_id' => $peerId,
            'message_id' => isset($message['id']) ? (int)$message['id'] : null,
            'conversation_message_id' => isset($message['conversation_message_id']) ? (int)$message['conversation_message_id'] : null,
            'text' => $visibleText,
            'message_type' => $messageType,
            'attachments_summary' => $attachmentSummary,
            'payload' => $message['payload'] ?? null,
            'event_id' => $event['event_id'] ?? null,
        ];
    }
}

if (!function_exists('asr_tg_vk_subscriber_upsert')) {
    function asr_tg_vk_subscriber_upsert(PDO $pdo, int $botId, array $vkMessage): int {
        if ($botId <= 0) return 0;
        $externalUserId = trim((string)($vkMessage['user_id'] ?? ''));
        $externalChatId = trim((string)($vkMessage['peer_id'] ?? ''));
        $legacyUserId = (int)($vkMessage['legacy_user_id'] ?? 0);
        $legacyChatId = (int)($vkMessage['legacy_chat_id'] ?? 0);
        if ($externalUserId === '' || $legacyUserId === 0 || $legacyChatId === 0) return 0;

        if (function_exists('asr_tg_repository_ensure_schema')) {
            asr_tg_repository_ensure_schema($pdo);
        }

        $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND platform = 'vk' AND external_user_id = ? LIMIT 1");
        $stmt->execute([$botId, $externalUserId]);
        $subscriberId = (int)$stmt->fetchColumn();

        if ($subscriberId > 0) {
            $upd = $pdo->prepare("UPDATE oca_telegram_bot_subscribers
                SET chat_id = ?, external_chat_id = ?, status = IF(status = 'blocked', 'blocked', 'active'), last_seen_at = NOW(), updated_at = NOW()
                WHERE id = ? AND bot_id = ?");
            $upd->execute([$legacyChatId, $externalChatId, $subscriberId, $botId]);
            return $subscriberId;
        }

        try {
            $ins = $pdo->prepare("INSERT INTO oca_telegram_bot_subscribers
                (bot_id, platform, telegram_user_id, chat_id, external_user_id, external_chat_id, username, first_name, last_name, language_code, is_bot, status, first_seen_at, last_seen_at, created_at, updated_at)
                VALUES (?, 'vk', ?, ?, ?, ?, NULL, NULL, NULL, NULL, 0, 'active', NOW(), NOW(), NOW(), NOW())");
            $ins->execute([$botId, $legacyUserId, $legacyChatId, $externalUserId, $externalChatId]);
            return (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // If concurrent callback created the same subscriber through the
            // legacy unique key, fetch it instead of failing the webhook.
            $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND telegram_user_id = ? LIMIT 1");
            $stmt->execute([$botId, $legacyUserId]);
            $subscriberId = (int)$stmt->fetchColumn();
            if ($subscriberId > 0) {
                $upd = $pdo->prepare("UPDATE oca_telegram_bot_subscribers
                    SET platform = 'vk', chat_id = ?, external_user_id = ?, external_chat_id = ?, status = IF(status = 'blocked', 'blocked', 'active'), last_seen_at = NOW(), updated_at = NOW()
                    WHERE id = ? AND bot_id = ?");
                $upd->execute([$legacyChatId, $externalUserId, $externalChatId, $subscriberId, $botId]);
                return $subscriberId;
            }
            throw $e;
        }
    }
}

if (!function_exists('asr_tg_vk_message_new_handle')) {
    function asr_tg_vk_message_new_handle(PDO $pdo, array $channel, array $event): array {
        $botId = (int)($channel['id'] ?? 0);
        $vkMessage = asr_tg_vk_extract_message_new($event);
        if (!$vkMessage || $botId <= 0) {
            return ['handled' => false, 'subscriber_id' => 0, 'reason' => 'not_supported_message'];
        }

        $subscriberId = asr_tg_vk_subscriber_upsert($pdo, $botId, $vkMessage);
        if ($subscriberId <= 0) {
            return ['handled' => false, 'subscriber_id' => 0, 'reason' => 'subscriber_not_created'];
        }

        $payload = [
            'platform' => 'vk',
            'vk_user_id' => $vkMessage['user_id'],
            'vk_peer_id' => $vkMessage['peer_id'],
            'vk_message_id' => $vkMessage['message_id'],
            'vk_conversation_message_id' => $vkMessage['conversation_message_id'],
            'vk_event_id' => $vkMessage['event_id'],
            'vk_attachments' => $vkMessage['attachments_summary'],
        ];
        if ($vkMessage['payload'] !== null) {
            $payload['vk_payload'] = $vkMessage['payload'];
        }

        asr_tg_message_add(
            $pdo,
            $botId,
            $subscriberId,
            'in',
            (string)$vkMessage['message_type'],
            (string)$vkMessage['text'],
            $vkMessage['message_id'] !== null ? (int)$vkMessage['message_id'] : null,
            $payload
        );

        try {
            asr_tg_log($pdo, $botId, 'info', 'vk_message_new_received', 'Входящее сообщение VK добавлено в диалоги.', [
                'subscriber_id' => $subscriberId,
                'vk_user_id' => $vkMessage['user_id'],
                'vk_peer_id' => $vkMessage['peer_id'],
                'message_type' => $vkMessage['message_type'],
                'attachments_count' => (int)($vkMessage['attachments_summary']['count'] ?? 0),
            ]);
        } catch (Throwable $ignored) {}

        return ['handled' => true, 'subscriber_id' => $subscriberId, 'reason' => 'ok'];
    }
}
