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

        $wasKnownSubscriber = false;
        try {
            $knownStmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND platform = 'vk' AND external_user_id = ? LIMIT 1");
            $knownStmt->execute([$botId, (string)$vkMessage['user_id']]);
            $wasKnownSubscriber = (int)$knownStmt->fetchColumn() > 0;
        } catch (Throwable $ignored) {}

        $subscriberId = asr_tg_vk_subscriber_upsert($pdo, $botId, $vkMessage);
        if ($subscriberId <= 0) {
            return ['handled' => false, 'subscriber_id' => 0, 'reason' => 'subscriber_not_created'];
        }

        $text = (string)$vkMessage['text'];
        $vkPayload = $vkMessage['payload'] ?? null;
        $scenarioRuntimeHandled = false;
        $isServiceCommand = function_exists('asr_tg_runtime_is_service_command_text') ? asr_tg_runtime_is_service_command_text($text) : false;

        if (($text !== '' || $vkPayload !== null) && function_exists('asr_tg_runtime_start_scenario')) {
            try {
                if ($vkPayload !== null && function_exists('asr_tg_runtime_try_vk_payload')) {
                    $scenarioRuntimeHandled = asr_tg_runtime_try_vk_payload($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, $vkPayload);
                }
                $startPayload = (!$scenarioRuntimeHandled && function_exists('asr_tg_runtime_extract_start_payload')) ? asr_tg_runtime_extract_start_payload($text) : '';
                if ($startPayload !== '' && function_exists('asr_tg_runtime_deeplink_find_by_code')) {
                    $deeplink = asr_tg_runtime_deeplink_find_by_code($pdo, $startPayload);
                    if ($deeplink) {
                        $deeplinkScenarioId = (int)($deeplink['scenario_id'] ?? 0);
                        $deeplinkBlockId = (int)($deeplink['block_id'] ?? 0);
                        if ($deeplinkScenarioId > 0 && $deeplinkBlockId > 0) {
                            $scenarioRuntimeHandled = asr_tg_runtime_start_scenario($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, $deeplinkScenarioId, $deeplinkBlockId, 'vk_deeplink', ['code' => $startPayload]);
                        }
                    }
                }
                if (!$scenarioRuntimeHandled && !$isServiceCommand && function_exists('asr_tg_runtime_try_waiting_question_text')) {
                    $scenarioRuntimeHandled = asr_tg_runtime_try_waiting_question_text($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, $text);
                }
                if (!$scenarioRuntimeHandled && function_exists('asr_tg_runtime_try_command')) {
                    $scenarioRuntimeHandled = asr_tg_runtime_try_command($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, $text);
                }
                if (!$scenarioRuntimeHandled && !$isServiceCommand && function_exists('asr_tg_runtime_try_keyword_trigger')) {
                    $scenarioRuntimeHandled = asr_tg_runtime_try_keyword_trigger($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, $text, 'vk');
                }
            } catch (Throwable $e) {
                try {
                    asr_tg_bot_update($pdo, $botId, ['last_error' => 'VK-сценарий: ' . $e->getMessage()]);
                    asr_tg_log($pdo, $botId, 'error', 'vk_scenario_runtime_failed', $e->getMessage(), [
                        'subscriber_id' => $subscriberId,
                        'vk_user_id' => $vkMessage['user_id'],
                        'vk_peer_id' => $vkMessage['peer_id'],
                        'text_preview' => mb_substr($text, 0, 160, 'UTF-8'),
                    ]);
                } catch (Throwable $ignored) {}
                $scenarioRuntimeHandled = true;
            }
        }

        if (!$scenarioRuntimeHandled && !$isServiceCommand) {
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
                $text,
                $vkMessage['message_id'] !== null ? (int)$vkMessage['message_id'] : null,
                $payload
            );

            if (function_exists('asr_tg_notify_technical_bot_about_dialog')) {
                try {
                    asr_tg_notify_technical_bot_about_dialog($pdo, $channel, $botId, $subscriberId, [
                        'text' => $text,
                        'platform' => 'vk',
                        'vk_user_id' => $vkMessage['user_id'],
                        'vk_peer_id' => $vkMessage['peer_id'],
                        'vk_message_id' => $vkMessage['message_id'],
                        'vk_attachments' => $vkMessage['attachments_summary'],
                    ], $text);
                } catch (Throwable $e) {
                    try {
                        asr_tg_log($pdo, $botId, 'warning', 'vk_dialog_technical_notify_failed', $e->getMessage(), [
                            'subscriber_id' => $subscriberId,
                            'vk_user_id' => $vkMessage['user_id'],
                        ]);
                    } catch (Throwable $ignored) {}
                }
            }

            try {
                asr_tg_log($pdo, $botId, 'info', 'vk_message_new_received', 'Входящее сообщение VK добавлено в диалоги.', [
                    'subscriber_id' => $subscriberId,
                    'vk_user_id' => $vkMessage['user_id'],
                    'vk_peer_id' => $vkMessage['peer_id'],
                    'message_type' => $vkMessage['message_type'],
                    'attachments_count' => (int)($vkMessage['attachments_summary']['count'] ?? 0),
                ]);
            } catch (Throwable $ignored) {}
        } else {
            try {
                asr_tg_log($pdo, $botId, 'info', 'vk_message_new_runtime_handled', 'Входящее VK-сообщение обработано сценарным runtime и не добавлено как обычный диалог.', [
                    'subscriber_id' => $subscriberId,
                    'vk_user_id' => $vkMessage['user_id'],
                    'vk_peer_id' => $vkMessage['peer_id'],
                    'runtime_handled' => $scenarioRuntimeHandled,
                    'service_command' => $isServiceCommand,
                ]);
            } catch (Throwable $ignored) {}
        }

        if (!$wasKnownSubscriber && !$scenarioRuntimeHandled && function_exists('asr_tg_send_welcome_message_if_enabled')) {
            asr_tg_send_welcome_message_if_enabled($pdo, $channel, $botId, (string)$vkMessage['peer_id'], $subscriberId, 'vk_first_message');
        }

        return ['handled' => true, 'subscriber_id' => $subscriberId, 'reason' => $scenarioRuntimeHandled ? 'scenario_runtime' : 'ok'];
    }
}
