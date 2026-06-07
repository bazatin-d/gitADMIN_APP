<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';


function asr_tg_json_response(array $payload, int $status = 200): void {
    header('Content-Type: application/json; charset=utf-8', true, $status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function asr_tg_handle_segment_count(PDO $pdo, array $source): void {
    try {
        if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав.');
        asr_tg_repository_ensure_schema($pdo);
        [$botIds] = asr_tg_broadcast_bot_ids_from_post($pdo, $source);
        $segment = asr_tg_build_segment_from_array($source);
        $allowDuplicates = !empty($source['broadcast_allow_duplicates']);
        $count = asr_tg_segment_count_for_bots($pdo, $botIds, $segment, $allowDuplicates);
        asr_tg_json_response(['ok' => true, 'count' => $count, 'summary' => asr_tg_segment_human_summary($segment), 'bot_count' => count($botIds), 'allow_duplicates' => $allowDuplicates]);
    } catch (Throwable $e) {
        asr_tg_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}


function asr_tg_ajax_requested(array $source): bool {
    if (isset($source['tg_ajax']) && (string)$source['tg_ajax'] !== '') return true;

    $requestedWith = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if (strcasecmp($requestedWith, 'XMLHttpRequest') === 0) return true;

    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && stripos($accept, 'application/json') !== false) return true;

    return false;
}

function asr_tg_assignable_user_ajax_payload(array $row): array {
    $label = asr_tg_dialog_assignee_label([
        'assigned_user_id' => (int)($row['id'] ?? 0),
        'assigned_full_name' => (string)($row['full_name'] ?? ''),
        'assigned_name' => (string)($row['name'] ?? ''),
        'assigned_first_name' => (string)($row['first_name'] ?? ''),
        'assigned_last_name' => (string)($row['last_name'] ?? ''),
        'assigned_username' => (string)($row['username'] ?? ''),
        'assigned_telegram_username' => (string)($row['telegram_username'] ?? ''),
        'assigned_email' => (string)($row['email'] ?? ''),
    ]);
    return [
        'id' => (int)($row['id'] ?? 0),
        'label' => $label,
        'full_name' => (string)($row['full_name'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'first_name' => (string)($row['first_name'] ?? ''),
        'last_name' => (string)($row['last_name'] ?? ''),
        'username' => (string)($row['username'] ?? ''),
        'telegram_username' => (string)($row['telegram_username'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
    ];
}

function asr_tg_dialogs_ajax_payload(PDO $pdo, array $source): array {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для просмотра диалогов.');
    asr_tg_repository_ensure_schema($pdo);

    $filters = asr_tg_dialog_filters_from_source($source);
    $limit = max(1, min(200, (int)($source['limit'] ?? 80)));
    $botId = 0; // Рабочий центр диалогов показывает все каналы. Фильтр по каналам идёт через dialog_channel[].
    $counts = asr_tg_dialogs_counts($pdo, $botId, $filters);
    $rows = asr_tg_dialogs_recent($pdo, $botId, $limit, $filters);
    $dialogs = array_map('asr_tg_dialog_row_payload', $rows);

    $selectedSubscriberId = (int)($source['subscriber_id'] ?? 0);
    $selectedBotId = (int)($source['bot_id'] ?? 0);
    if ($selectedSubscriberId <= 0 && $dialogs) {
        $selectedSubscriberId = (int)$dialogs[0]['subscriber_id'];
        $selectedBotId = (int)$dialogs[0]['bot_id'];
    }

    $latestMessageId = 0;
    $latestDialogAt = '';
    foreach ($dialogs as $dialog) {
        $latestMessageId = max($latestMessageId, (int)($dialog['last_message_id'] ?? 0));
        $lastAt = (string)($dialog['last_message_at'] ?? '');
        if ($lastAt !== '' && ($latestDialogAt === '' || strcmp($lastAt, $latestDialogAt) > 0)) {
            $latestDialogAt = $lastAt;
        }
    }

    return [
        'ok' => true,
        'view' => (string)$filters['view'],
        'filters' => $filters,
        'counts' => $counts,
        'dialogs' => $dialogs,
        'selected_subscriber_id' => $selectedSubscriberId,
        'selected_bot_id' => $selectedBotId,
        'latest_message_id' => $latestMessageId,
        'latest_dialog_at' => $latestDialogAt,
        'assignable_users' => array_map('asr_tg_assignable_user_ajax_payload', asr_tg_dialog_assignable_users($pdo)),
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

function asr_tg_dialog_messages_ajax_payload(PDO $pdo, array $source): array {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для просмотра диалога.');
    asr_tg_repository_ensure_schema($pdo);

    $subscriberId = (int)($source['subscriber_id'] ?? 0);
    $botId = (int)($source['bot_id'] ?? 0);
    if ($subscriberId <= 0) throw new InvalidArgumentException('Диалог не выбран.');

    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден.');

    $afterMessageId = max(0, (int)($source['after_message_id'] ?? 0));
    $limit = max(1, min(500, (int)($source['limit'] ?? 200)));
    $messages = asr_tg_dialog_messages_since($pdo, $subscriberId, $afterMessageId, $limit, $botId);
    $payloadMessages = array_map('asr_tg_dialog_message_payload', $messages);
    $lastMessageId = $afterMessageId;
    foreach ($payloadMessages as $message) {
        $lastMessageId = max($lastMessageId, (int)($message['id'] ?? 0));
    }

    return [
        'ok' => true,
        'subscriber' => asr_tg_dialog_subscriber_payload($pdo, $subscriber),
        'messages' => $payloadMessages,
        'last_message_id' => $lastMessageId,
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

function asr_tg_dialog_panel_ajax_payload(PDO $pdo, array $source): array {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для просмотра карточки.');
    asr_tg_repository_ensure_schema($pdo);

    $subscriberId = (int)($source['subscriber_id'] ?? 0);
    $botId = (int)($source['bot_id'] ?? 0);
    if ($subscriberId <= 0) throw new InvalidArgumentException('Диалог не выбран.');

    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new RuntimeException('Подписчик не найден.');

    return [
        'ok' => true,
        'subscriber' => asr_tg_dialog_subscriber_payload($pdo, $subscriber),
        'assignable_users' => array_map('asr_tg_assignable_user_ajax_payload', asr_tg_dialog_assignable_users($pdo)),
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

function asr_tg_dialog_after_action_ajax_payload(PDO $pdo, array $source, int $subscriberId = 0, int $botId = 0): array {
    $payload = asr_tg_dialogs_ajax_payload($pdo, $source + ['subscriber_id' => $subscriberId, 'bot_id' => $botId]);
    if ($subscriberId > 0) {
        try {
            $payload['messages_payload'] = asr_tg_dialog_messages_ajax_payload($pdo, ['subscriber_id' => $subscriberId, 'bot_id' => $botId, 'limit' => 200]);
            $payload['panel_payload'] = asr_tg_dialog_panel_ajax_payload($pdo, ['subscriber_id' => $subscriberId, 'bot_id' => $botId]);
        } catch (Throwable $e) {
            $payload['selected_warning'] = $e->getMessage();
        }
    }
    return $payload;
}

function asr_tg_handle_dialog_ajax(PDO $pdo, array $source): void {
    try {
        $ajax = (string)($source['tg_ajax'] ?? '');
        if ($ajax === 'dialogs_state') {
            asr_tg_json_response(asr_tg_dialogs_ajax_payload($pdo, $source));
        }
        if ($ajax === 'dialog_messages') {
            asr_tg_json_response(asr_tg_dialog_messages_ajax_payload($pdo, $source));
        }
        if ($ajax === 'dialog_panel') {
            asr_tg_json_response(asr_tg_dialog_panel_ajax_payload($pdo, $source));
        }
        throw new InvalidArgumentException('Неизвестное Ajax-действие диалогов.');
    } catch (Throwable $e) {
        asr_tg_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}



function asr_tg_handle_runtime_diagnostic(PDO $pdo, array $source): void {
    try {
        if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав.');
        $scenarioId = (int)($source['scenario_id'] ?? 0);
        $botId = (int)($source['bot_id'] ?? 0);
        $command = (string)($source['command'] ?? 'help');
        asr_tg_json_response(asr_tg_runtime_diagnostic($pdo, $scenarioId, $botId, $command));
    } catch (Throwable $e) {
        asr_tg_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function asr_tg_handle_broadcast_process_ajax(PDO $pdo, array $source): void {
    try {
        if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для обработки рассылок.');
        asr_tg_repository_ensure_schema($pdo);
        $broadcastId = (int)($source['broadcast_id'] ?? 0);
        if ($broadcastId <= 0) throw new InvalidArgumentException('Рассылка не выбрана.');
        $limit = max(1, min(50, (int)($source['limit'] ?? 25)));
        $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
        $stats = asr_tg_broadcast_recalc($pdo, $broadcastId);
        asr_tg_json_response([
            'ok' => true,
            'processed' => (int)($result['processed'] ?? 0),
            'sent' => (int)($result['sent'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'broadcast_id' => $broadcastId,
            'stats' => $stats,
        ]);
    } catch (Throwable $e) {
        asr_tg_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['tab'] ?? '') === 'telegram_bots' && ($_GET['tg_ajax'] ?? '') === 'broadcast_process') {
    asr_tg_handle_broadcast_process_ajax($pdo, $_GET);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['tab'] ?? '') === 'telegram_bots' && ($_GET['tg_ajax'] ?? '') === 'segment_count') {
    asr_tg_handle_segment_count($pdo, $_GET);
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['tab'] ?? '') === 'telegram_bots' && ($_GET['tg_ajax'] ?? '') === 'scenario_runtime_diag') {
    asr_tg_handle_runtime_diagnostic($pdo, $_GET);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['tab'] ?? '') === 'telegram_bots' && ($_GET['page'] ?? '') === 'messages' && in_array((string)($_GET['tg_ajax'] ?? ''), ['dialogs_state','dialog_messages','dialog_panel'], true)) {
    asr_tg_handle_dialog_ajax($pdo, $_GET);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if (in_array($action, ['tg_bot_create','tg_bot_update','tg_channel_enable','tg_channel_disable','tg_bot_set_webhook','tg_bot_delete_webhook','tg_bot_delete','tg_dialog_settings_save','tg_dialog_status','tg_dialog_read_state','tg_dialog_assign','tg_dialog_close_all','tg_broadcast_send','tg_broadcast_test','tg_broadcast_process','tg_broadcast_cancel','tg_broadcast_cancel_scheduled','tg_broadcast_count_segment','tg_tag_save','tg_tag_delete','tg_subscriber_add_tag','tg_subscriber_tag_add_or_create','tg_subscriber_remove_tag','tg_subscriber_status','tg_subscriber_profile_save','tg_subscriber_custom_values_save','tg_subscriber_send_message','tg_subscriber_delete','tg_bot_commands_save','tg_subscribers_bulk_add_tag','tg_subscribers_bulk_remove_tag','tg_subscribers_bulk_delete','tg_subscribers_bulk_stop_scenario','tg_custom_field_save','tg_custom_field_archive','tg_custom_field_restore','tg_custom_field_delete','tg_scenario_save','tg_scenario_pause','tg_scenario_resume','tg_scenario_archive','tg_scenario_restore','tg_scenario_delete','tg_scenario_block_save','tg_scenario_block_delete','tg_scenario_block_duplicate','tg_scenario_block_deeplink_create','tg_scenario_blocks_positions_save','tg_scenario_link_save','tg_scenario_link_delete','tg_scenario_quick_message_create','tg_scenario_quick_delay_create','tg_scenario_quick_condition_create','tg_scenario_stats_reset'], true)) {
        try {
            asr_tg_repository_ensure_schema($pdo);

            if ($action === 'tg_broadcast_count_segment') {
                asr_tg_handle_segment_count($pdo, $_POST);
            }

            if ($action === 'tg_tag_save') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $tagId = asr_tg_create_tag_from_post($pdo, $_POST);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                if ($subscriberId > 0) {
                    asr_tg_subscriber_tag_add($pdo, $subscriberId, $tagId);
                }
                if (($_POST['return_page'] ?? '') === 'subscriber' && $subscriberId > 0) {
                    asr_tg_redirect('Тег создан и добавлен подписчику.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
                }
                if (($_POST['return_page'] ?? '') === 'subscribers_tags') {
                    asr_tg_redirect('Тег сохранён.', '', ['page' => 'subscribers', 'bot_id' => 0, 'tags_modal' => 1]);
                }
                asr_tg_redirect('Тег сохранён.', '', ['page' => 'subscribers', 'bot_id' => $botId]);
            }

            if ($action === 'tg_tag_delete') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_delete_tag_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'subscribers_tags') {
                    asr_tg_redirect('Тег удалён.', '', ['page' => 'subscribers', 'bot_id' => 0, 'tags_modal' => 1]);
                }
                asr_tg_redirect('Тег удалён.', '', ['page' => 'subscribers', 'bot_id' => $botId]);
            }

            if ($action === 'tg_subscriber_add_tag') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                asr_tg_add_subscriber_tag_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'subscriber' && $subscriberId > 0) {
                    asr_tg_redirect('Тег добавлен подписчику.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
                }
                asr_tg_redirect('Тег добавлен подписчику.', '', ['page' => 'subscribers', 'bot_id' => $botId]);
            }

            if ($action === 'tg_subscriber_tag_add_or_create') {
                if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для назначения тегов.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                $tagName = trim((string)($_POST['tag_name'] ?? ''));
                if ($botId <= 0 || $subscriberId <= 0) throw new RuntimeException('Подписчик не найден.');
                if ($tagName === '') throw new RuntimeException('Укажите тег.');
                $tagId = asr_tg_tag_create($pdo, 0, $tagName, '#F3F4F6');
                asr_tg_subscriber_tag_add($pdo, $subscriberId, $tagId);
                if (($_POST['return_page'] ?? '') === 'messages') {
                    asr_tg_redirect('Тег добавлен подписчику.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'subscriber_id' => $subscriberId]);
                }
                asr_tg_redirect('Тег добавлен подписчику.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_subscriber_remove_tag') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                asr_tg_remove_subscriber_tag_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'messages' && $subscriberId > 0) {
                    asr_tg_redirect('Тег снят с подписчика.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'subscriber_id' => $subscriberId]);
                }
                if (($_POST['return_page'] ?? '') === 'subscriber' && $subscriberId > 0) {
                    asr_tg_redirect('Тег снят с подписчика.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
                }
                asr_tg_redirect('Тег снят с подписчика.', '', ['page' => 'subscribers', 'bot_id' => $botId]);
            }

            if ($action === 'tg_subscriber_status') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_update_subscriber_status_from_post($pdo, $_POST);
                asr_tg_redirect('Статус подписчика обновлён.', '', ['page' => 'subscribers', 'bot_id' => $botId]);
            }



            if ($action === 'tg_subscriber_profile_save') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = asr_tg_save_subscriber_profile_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'messages') {
                    asr_tg_redirect('Карточка подписчика сохранена.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'subscriber_id' => $subscriberId]);
                }
                asr_tg_redirect('Карточка подписчика сохранена.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_subscriber_custom_values_save') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = asr_tg_save_subscriber_custom_values_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'messages') {
                    $extra = ['page' => 'messages', 'bot_id' => $botId ?: null, 'subscriber_id' => $subscriberId];
                    if (!empty($_POST['dialog_view'])) $extra['dialog_view'] = (string)$_POST['dialog_view'];
                    asr_tg_redirect('Настраиваемые поля подписчика сохранены.', '', $extra);
                }
                asr_tg_redirect('Настраиваемые поля подписчика сохранены.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_subscriber_send_message') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = asr_tg_send_subscriber_message_from_post($pdo, $_POST, $_FILES ?? []);
                if (asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(asr_tg_dialog_after_action_ajax_payload($pdo, $_POST, $subscriberId, $botId));
                }
                if (($_POST['return_page'] ?? '') === 'messages') {
                    asr_tg_redirect('Сообщение отправлено.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'subscriber_id' => $subscriberId]);
                }
                asr_tg_redirect('Сообщение отправлено.', '', ['page' => 'subscriber', 'bot_id' => $botId, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_subscriber_delete') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $returnBotId = (int)($_POST['return_bot_id'] ?? $botId);
                asr_tg_delete_subscriber_from_post($pdo, $_POST);
                asr_tg_redirect('Подписчик удалён.', '', ['page' => 'subscribers', 'bot_id' => $returnBotId]);
            }

            if ($action === 'tg_subscribers_bulk_add_tag') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $returnBotId = (int)($_POST['return_bot_id'] ?? $botId);
                $count = asr_tg_bulk_add_tag_from_post($pdo, $_POST);
                asr_tg_redirect('Тег добавлен выбранным подписчикам: ' . $count . '.', '', ['page' => 'subscribers', 'bot_id' => $returnBotId]);
            }

            if ($action === 'tg_subscribers_bulk_remove_tag') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $returnBotId = (int)($_POST['return_bot_id'] ?? $botId);
                $count = asr_tg_bulk_remove_tag_from_post($pdo, $_POST);
                asr_tg_redirect('Тег снят с выбранных подписчиков: ' . $count . '.', '', ['page' => 'subscribers', 'bot_id' => $returnBotId]);
            }

            if ($action === 'tg_subscribers_bulk_delete') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $returnBotId = (int)($_POST['return_bot_id'] ?? $botId);
                $count = asr_tg_bulk_delete_subscribers_from_post($pdo, $_POST);
                asr_tg_redirect('Подписчики удалены: ' . $count . '.', '', ['page' => 'subscribers', 'bot_id' => $returnBotId]);
            }

            if ($action === 'tg_subscribers_bulk_stop_scenario') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $returnBotId = (int)($_POST['return_bot_id'] ?? $botId);
                $count = asr_tg_bulk_stop_scenario_from_post($pdo, $_POST);
                asr_tg_redirect('Заготовка остановки сценария готова. Выбрано подписчиков: ' . $count . '.', 'Полная остановка сценариев будет подключена после реализации сценариев.', ['page' => 'subscribers', 'bot_id' => $returnBotId]);
            }


            if ($action === 'tg_scenario_save') {
                $scenarioId = asr_tg_scenario_save_from_post($pdo, $_POST);
                if (($_POST['return_page'] ?? '') === 'scenario_flow') {
                    asr_tg_redirect('Сценарий сохранён.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Сценарий сохранён.', '', ['page' => 'scenarios']);
            }

            if ($action === 'tg_scenario_pause') {
                if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_set_status($pdo, $scenarioId, 'paused');
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'status' => 'paused']);
                }
                asr_tg_redirect('Сценарий поставлен на паузу.', '', ['page' => 'scenarios']);
            }

            if ($action === 'tg_scenario_resume') {
                if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для управления сценариями.');
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_set_status($pdo, $scenarioId, 'active');
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'status' => 'active']);
                }
                asr_tg_redirect('Сценарий запущен.', '', ['page' => 'scenarios']);
            }


            if ($action === 'tg_scenario_stats_reset') {
                if (!asr_tg_can('flows')) throw new RuntimeException('Недостаточно прав для сброса статистики сценария.');
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_stats_reset($pdo, $scenarioId);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Статистика сценария сброшена.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_archive') {
                asr_tg_scenario_archive_from_post($pdo, $_POST);
                asr_tg_redirect('Сценарий перенесён в архив.', '', ['page' => 'scenarios']);
            }

            if ($action === 'tg_scenario_restore') {
                $scenarioId = asr_tg_scenario_restore_from_post($pdo, $_POST);
                asr_tg_redirect('Сценарий восстановлен как черновик.', '', ['page' => 'scenarios']);
            }

            if ($action === 'tg_scenario_delete') {
                asr_tg_scenario_delete_from_post($pdo, $_POST);
                asr_tg_redirect('Сценарий удалён навсегда.', '', ['page' => 'scenarios', 'view' => 'archive']);
            }

            if ($action === 'tg_scenario_block_save') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $blockId = asr_tg_scenario_block_save_from_post($pdo, $_POST, $_FILES ?? []);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'block_id' => $blockId]);
                }
                $returnPage = (string)($_POST['return_page'] ?? 'scenario');
                $returnPage = $returnPage === 'scenario_flow' ? 'scenario_flow' : 'scenario';
                $blockType = (string)($_POST['block_type'] ?? 'message');
                $saveMessage = $blockType === 'condition' ? 'Блок условия сохранён.' : ($blockType === 'delay' ? 'Блок задержки сохранён.' : 'Блок сообщения сохранён.');
                asr_tg_redirect($saveMessage, '', ['page' => $returnPage, 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_block_delete') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_block_delete_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Блок сообщения удалён.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_block_duplicate') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $newBlockId = asr_tg_scenario_block_duplicate_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'block_id' => $newBlockId]);
                }
                asr_tg_redirect('Блок сообщения продублирован.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_block_deeplink_create') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $result = asr_tg_scenario_block_deeplink_create_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'deeplink' => $result]);
                }
                asr_tg_redirect('Диплинк создан.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_blocks_positions_save') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_blocks_positions_save_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Расположение блоков сохранено.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_link_save') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_link_save_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Связь между блоками сохранена.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_link_delete') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                asr_tg_scenario_link_delete_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Связь между блоками удалена.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_quick_message_create') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $blockId = asr_tg_scenario_quick_message_create_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'block_id' => $blockId]);
                }
                asr_tg_redirect('Новый блок создан и связан.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }

            if ($action === 'tg_scenario_quick_delay_create') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $blockId = asr_tg_scenario_quick_delay_create_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'block_id' => $blockId]);
                }
                asr_tg_redirect('Новый блок задержки создан и связан.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }


            if ($action === 'tg_scenario_quick_condition_create') {
                $scenarioId = (int)($_POST['scenario_id'] ?? 0);
                $blockId = asr_tg_scenario_quick_condition_create_from_post($pdo, $_POST);
                if (function_exists('asr_tg_ajax_requested') && asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'scenario_id' => $scenarioId, 'block_id' => $blockId]);
                }
                asr_tg_redirect('Новый блок условия создан и связан.', '', ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
            }


            if ($action === 'tg_custom_field_save') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_custom_field_save_from_post($pdo, $_POST);
                asr_tg_redirect('Настраиваемое поле сохранено.', '', ['page' => 'subscribers', 'bot_id' => $botId, 'fields_modal' => 1]);
            }

            if ($action === 'tg_custom_field_archive') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_custom_field_archive_from_post($pdo, $_POST);
                asr_tg_redirect('Настраиваемое поле отправлено в архив.', '', ['page' => 'subscribers', 'bot_id' => $botId, 'fields_modal' => 1]);
            }

            if ($action === 'tg_custom_field_restore') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_custom_field_restore_from_post($pdo, $_POST);
                asr_tg_redirect('Настраиваемое поле восстановлено.', '', ['page' => 'subscribers', 'bot_id' => $botId, 'fields_modal' => 1]);
            }


            if ($action === 'tg_custom_field_delete') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_custom_field_delete_from_post($pdo, $_POST);
                asr_tg_redirect('Настраиваемое поле удалено навсегда.', '', ['page' => 'subscribers', 'bot_id' => $botId, 'fields_modal' => 1]);
            }

            if ($action === 'tg_bot_commands_save') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $result = asr_tg_save_bot_commands_from_post($pdo, $_POST);
                $count = (int)($result['count'] ?? 0);
                $warning = (string)($result['warning'] ?? '');
                if (($_POST['return_page'] ?? '') === 'scenario_flow') {
                    $scenarioId = (int)($_POST['return_scenario_id'] ?? $_POST['scenario_id_single'] ?? 0);
                    asr_tg_redirect('Меню команд сохранено. Команд: ' . $count . '.', $warning, ['page' => 'scenario_flow', 'scenario_id' => $scenarioId]);
                }
                asr_tg_redirect('Меню команд сохранено. Команд: ' . $count . '.', $warning, ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_dialog_settings_save') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки диалогов.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $autoReplyAttachment = [];
                if (!empty($_FILES['auto_reply_attachment'] ?? null)) {
                    if (!function_exists('asr_tg_save_chat_attachment')) {
                        throw new RuntimeException('Загрузка файлов недоступна: не подключён сервис отправки файлов.');
                    }
                    $autoReplyAttachment = asr_tg_save_chat_attachment($_FILES['auto_reply_attachment']);
                }
                asr_tg_dialog_settings_save($pdo, $botId, [
                    'auto_close_incoming' => (int)($_POST['auto_close_incoming'] ?? 0),
                    'auto_reply_enabled' => (int)($_POST['auto_reply_enabled'] ?? 0),
                    'auto_reply_text' => (string)($_POST['auto_reply_text'] ?? ''),
                    'auto_reply_attachment' => $autoReplyAttachment,
                    'auto_reply_attachment_clear' => (int)($_POST['auto_reply_attachment_clear'] ?? 0),
                ]);
                asr_tg_redirect('Настройки диалогов сохранены.', '', ['page' => 'messages', 'bot_id' => $botId ?: null]);
            }

            if ($action === 'tg_dialog_status') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для изменения статуса диалога.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                $dialogStatus = (string)($_POST['dialog_status'] ?? 'new');
                asr_tg_dialog_set_status($pdo, $subscriberId, $dialogStatus, null, $botId);
                if (asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(asr_tg_dialog_after_action_ajax_payload($pdo, $_POST, $subscriberId, $botId));
                }
                asr_tg_redirect('Статус диалога обновлён.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'dialog_view' => $dialogStatus === 'mine' ? 'my' : $dialogStatus, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_dialog_read_state') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для изменения прочтения диалога.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                $dialogView = (string)($_POST['dialog_view'] ?? 'new');
                $readState = (string)($_POST['read_state'] ?? 'read');
                asr_tg_dialog_set_read_state($pdo, $subscriberId, $readState === 'read', $botId);
                if (asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(asr_tg_dialog_after_action_ajax_payload($pdo, $_POST, $subscriberId, $botId));
                }
                $params = ['page' => 'messages', 'bot_id' => $botId ?: null, 'dialog_view' => $dialogView, 'subscriber_id' => $subscriberId];
                if ($readState !== 'read') $params['no_auto_read'] = 1;
                $message = $readState === 'read' ? 'Диалог помечен прочитанным.' : 'Диалог помечен непрочитанным.';
                asr_tg_redirect($message, '', $params);
            }

            if ($action === 'tg_dialog_assign') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для назначения диалога.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
                $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
                asr_tg_dialog_assign($pdo, $subscriberId, $assignedUserId, $botId);
                $currentUserId = function_exists('asr_tg_current_user_id') ? asr_tg_current_user_id() : 0;
                $view = $assignedUserId <= 0 ? 'new' : (($currentUserId > 0 && $assignedUserId === $currentUserId) ? 'my' : 'assigned');
                if (asr_tg_ajax_requested($_POST)) {
                    $_POST['dialog_view'] = $view;
                    asr_tg_json_response(asr_tg_dialog_after_action_ajax_payload($pdo, $_POST, $subscriberId, $botId));
                }
                $message = $assignedUserId <= 0 ? 'Назначение снято.' : 'Диалог назначен.';
                asr_tg_redirect($message, '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'dialog_view' => $view, 'subscriber_id' => $subscriberId]);
            }

            if ($action === 'tg_dialog_close_all') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для закрытия диалогов.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $count = asr_tg_dialog_close_all($pdo, $botId);
                asr_tg_redirect('Диалоги закрыты: ' . $count . '.', '', ['page' => 'messages', 'bot_id' => $botId ?: null, 'dialog_view' => 'closed']);
            }

            if ($action === 'tg_bot_create') {
                $botId = asr_tg_create_bot_from_post($pdo, $_POST);
                asr_tg_redirect('Канал сохранён.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_bot_update') {
                $botId = asr_tg_update_bot_from_post($pdo, $_POST);
                asr_tg_redirect('Настройки канала сохранены.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_channel_enable') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_enable_channel($pdo, $botId);
                asr_tg_redirect('Канал включён.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_channel_disable') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_disable_channel($pdo, $botId);
                asr_tg_redirect('Канал отключён.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_bot_set_webhook') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_install_webhook($pdo, $botId);
                asr_tg_redirect('Webhook установлен.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_bot_delete_webhook') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_delete_webhook($pdo, $botId);
                asr_tg_redirect('Webhook удалён. Бот поставлен на паузу.', '', ['page' => 'bots', 'bot_id' => $botId]);
            }

            if ($action === 'tg_bot_delete') {
                if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для удаления Telegram-ботов.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                asr_tg_bot_delete($pdo, $botId);
                asr_tg_redirect('Бот и его локальные данные удалены.', '', ['page' => 'bots']);
            }


            if ($action === 'tg_broadcast_test') {
                $result = asr_tg_send_broadcast_test_from_post($pdo, $_POST, $_FILES ?? []);
                $message = 'Тестовая рассылка отправлена сотрудникам: ' . (int)($result['sent'] ?? 0) . '. Ошибок: ' . (int)($result['failed'] ?? 0) . '.';
                if (asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response(['ok' => true, 'message' => $message, 'result' => $result]);
                }
                asr_tg_redirect($message, '', ['page' => 'broadcasts', 'bot_id' => (int)($_POST['bot_id'] ?? 0), 'step' => 3]);
            }

            if ($action === 'tg_broadcast_send') {
                $result = asr_tg_create_broadcasts_from_post($pdo, $_POST, $_FILES ?? []);
                $botId = (int)($result['primary_bot_id'] ?? ($_POST['bot_id'] ?? 0));
                $broadcastId = (int)($result['primary_id'] ?? 0);
                $createdIds = array_values(array_unique(array_filter(array_map('intval', $result['ids'] ?? []), static fn($id) => $id > 0)));

                $isScheduled = !empty($result['scheduled']);
                $scheduledAt = trim((string)($result['scheduled_at'] ?? ''));

                // Мгновенные рассылки получают первый небольшой серверный проход сразу.
                // Запланированные рассылки не трогаем до scheduled_at: их активирует cron.
                $firstPassSent = 0;
                $firstPassFailed = 0;
                $firstPassProcessed = 0;
                if (!$isScheduled) {
                    foreach ($createdIds as $createdBroadcastId) {
                        $pass = asr_tg_process_broadcast_queue($pdo, 20, $createdBroadcastId);
                        $firstPassSent += (int)($pass['sent'] ?? 0);
                        $firstPassFailed += (int)($pass['failed'] ?? 0);
                        $firstPassProcessed += (int)($pass['processed'] ?? 0);
                    }
                }

                if ($isScheduled) {
                    $message = 'Рассылка запланирована на ' . ($scheduledAt !== '' ? $scheduledAt : 'указанное время') . '. Получателей: ' . (int)($result['total'] ?? 0) . '.';
                    if ((int)($result['bot_count'] ?? 1) > 1) {
                        $message = 'Создано запланированных рассылок по каналам: ' . (int)($result['bot_count'] ?? 0) . '. Запуск: ' . ($scheduledAt !== '' ? $scheduledAt : 'указанное время') . '. Получателей: ' . (int)($result['total'] ?? 0) . '.';
                        if (empty($result['allow_duplicates'])) $message .= ' Дубли по Telegram User ID исключены.';
                    }
                } else {
                    $message = 'Рассылка создана. Первый проход: обработано ' . $firstPassProcessed . ', отправлено ' . $firstPassSent . ', ошибок ' . $firstPassFailed . '. Получателей: ' . (int)($result['total'] ?? 0) . '.';
                    if ((int)($result['bot_count'] ?? 1) > 1) {
                        $message = 'Создано рассылок по каналам: ' . (int)($result['bot_count'] ?? 0) . '. Первый проход: обработано ' . $firstPassProcessed . ', отправлено ' . $firstPassSent . ', ошибок ' . $firstPassFailed . '. Получателей: ' . (int)($result['total'] ?? 0) . '.';
                        if (empty($result['allow_duplicates'])) $message .= ' Дубли по Telegram User ID исключены.';
                    }
                }
                $redirectExtra = [
                    'page' => 'broadcasts',
                    'view' => 'history',
                    'bot_id' => $botId,
                    'broadcast_id' => $broadcastId,
                ];
                if ((int)($result['bot_count'] ?? 1) > 1 && count($createdIds) > 1) {
                    // В мультиканальной отправке создаётся отдельная запись рассылки на каждый канал.
                    // Передаём все ID в отчёт, чтобы пользователь видел суммарную картину, а не только первый канал.
                    $redirectExtra['broadcast_ids'] = implode(',', $createdIds);
                }
                asr_tg_redirect($message, '', $redirectExtra);
            }

            if ($action === 'tg_broadcast_process') {
                if (!asr_tg_can('broadcast')) throw new RuntimeException('Недостаточно прав для обработки рассылок.');
                $botId = (int)($_POST['bot_id'] ?? 0);
                $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
                $limit = (int)($_POST['limit'] ?? 30);
                $result = asr_tg_process_broadcast_queue($pdo, $limit, $broadcastId);
                $stats = $broadcastId > 0 ? asr_tg_broadcast_recalc($pdo, $broadcastId) : [];
                if (asr_tg_ajax_requested($_POST)) {
                    asr_tg_json_response([
                        'ok' => true,
                        'processed' => (int)($result['processed'] ?? 0),
                        'sent' => (int)($result['sent'] ?? 0),
                        'failed' => (int)($result['failed'] ?? 0),
                        'broadcast_id' => $broadcastId,
                        'stats' => $stats,
                    ]);
                }
                asr_tg_redirect('Очередь обработана: обработано ' . (int)$result['processed'] . ', отправлено ' . (int)$result['sent'] . ', ошибок ' . (int)$result['failed'] . '.', '', ['page' => 'broadcasts', 'view' => 'history', 'bot_id' => $botId, 'broadcast_id' => $broadcastId ?: null]);
            }

            if ($action === 'tg_broadcast_cancel') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
                asr_tg_cancel_broadcast($pdo, $broadcastId);
                asr_tg_redirect('Рассылка отменена.', '', ['page' => 'broadcasts', 'bot_id' => $botId, 'broadcast_id' => $broadcastId]);
            }

            if ($action === 'tg_broadcast_cancel_scheduled') {
                $botId = (int)($_POST['bot_id'] ?? 0);
                $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
                $rawIds = $_POST['broadcast_ids'] ?? [];
                if (!is_array($rawIds)) $rawIds = preg_split('/[,;\s]+/', (string)$rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $cancelledIds = asr_tg_cancel_scheduled_broadcast_group($pdo, $broadcastId, $rawIds);

                $extra = ['page' => 'broadcasts', 'view' => 'history', 'bot_id' => $botId];
                $pageNo = max(1, (int)($_POST['page_no'] ?? 1));
                $perPage = (int)($_POST['per_page'] ?? 25);
                if (!in_array($perPage, [10,25,50,100], true)) $perPage = 25;
                $extra['page_no'] = $pageNo;
                $extra['per_page'] = $perPage;
                $dateFrom = trim((string)($_POST['date_from'] ?? ''));
                $dateTo = trim((string)($_POST['date_to'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $extra['date_from'] = $dateFrom;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $extra['date_to'] = $dateTo;
                $filterBotIds = $_POST['filter_bot_ids'] ?? [];
                if (!is_array($filterBotIds)) $filterBotIds = [$filterBotIds];
                $filterBotIds = array_values(array_unique(array_filter(array_map('intval', $filterBotIds), static fn($id) => $id > 0)));
                if ($filterBotIds) $extra['filter_bot_ids'] = $filterBotIds;
                asr_tg_redirect('Запланированная рассылка отменена. Каналов: ' . count($cancelledIds) . '.', '', $extra);
            }
        } catch (Throwable $e) {
            if (asr_tg_ajax_requested($_POST)) {
                asr_tg_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            $extra = ['page' => (string)($_POST['return_page'] ?? 'bots'), 'bot_id' => (int)($_POST['bot_id'] ?? 0) ?: null];
            if (in_array($action, ['tg_subscriber_profile_save','tg_subscriber_custom_values_save','tg_subscriber_send_message'], true)) {
                $extra['page'] = (($_POST['return_page'] ?? '') === 'messages') ? 'messages' : 'subscriber';
                $extra['subscriber_id'] = (int)($_POST['subscriber_id'] ?? 0) ?: null;
            }
            if (in_array($action, ['tg_dialog_assign','tg_dialog_status','tg_dialog_read_state'], true)) {
                $extra['page'] = 'messages';
                $extra['subscriber_id'] = (int)($_POST['subscriber_id'] ?? 0) ?: null;
                $extra['dialog_view'] = (string)($_POST['dialog_view'] ?? 'new');
            }
            if ($action === 'tg_broadcast_send') {
                // При ошибке создания рассылки возвращаем пользователя сразу на шаг «Сообщения»,
                // чтобы он не подумал, что всё сбросилось и не искал форму заново.
                $extra['page'] = 'broadcasts';
                $extra['step'] = 2;
            }
            if (in_array($action, ['tg_scenario_pause','tg_scenario_resume'], true)) {
                $extra['page'] = 'scenario_flow';
                $extra['scenario_id'] = (int)($_POST['scenario_id'] ?? 0) ?: null;
            }
            if (in_array($action, ['tg_scenario_block_save','tg_scenario_block_delete','tg_scenario_block_duplicate','tg_scenario_block_deeplink_create','tg_scenario_blocks_positions_save','tg_scenario_link_save','tg_scenario_link_delete','tg_scenario_quick_message_create','tg_scenario_quick_delay_create','tg_scenario_quick_condition_create','tg_scenario_stats_reset'], true)) {
                $extra['page'] = 'scenario_flow';
                $extra['scenario_id'] = (int)($_POST['scenario_id'] ?? 0) ?: null;
                if ($action === 'tg_scenario_block_save') {
                    $blockId = (int)($_POST['block_id'] ?? 0);
                    if ($blockId > 0) $extra['edit_block'] = $blockId;
                    else $extra['add_block'] = 'message';
                }
            }
            if (in_array($action, ['tg_custom_field_save','tg_custom_field_archive','tg_custom_field_restore','tg_custom_field_delete'], true)) {
                $extra['page'] = 'subscribers';
                $extra['fields_modal'] = 1;
            }
            if (in_array($action, ['tg_tag_save','tg_tag_delete'], true) && (($_POST['return_page'] ?? '') === 'subscribers_tags')) {
                $extra['page'] = 'subscribers';
                $extra['bot_id'] = null;
                $extra['tags_modal'] = 1;
            }
            if ((($_POST['return_page'] ?? '') === 'scenario_flow') && in_array($action, ['tg_scenario_save','tg_bot_commands_save'], true)) {
                $extra['page'] = 'scenario_flow';
                $extra['scenario_id'] = (int)($_POST['return_scenario_id'] ?? $_POST['scenario_id'] ?? $_POST['scenario_id_single'] ?? 0);
            }
            asr_tg_redirect('', $e->getMessage(), $extra);
        }
    }
}
