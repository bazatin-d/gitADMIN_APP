<?php
    $broadcastSafeJson = static function($value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        return $json === false ? 'null' : $json;
    };


    $broadcastMessageVariables = [
        ['group' => 'Системные', 'title' => 'Полное имя', 'token' => '{{full_name}}', 'icon' => 'Т'],
        ['group' => 'Системные', 'title' => 'Имя', 'token' => '{{first_name}}', 'icon' => 'Т'],
        ['group' => 'Системные', 'title' => 'Фамилия', 'token' => '{{last_name}}', 'icon' => 'Т'],
        ['group' => 'Системные', 'title' => 'Username', 'token' => '{{username}}', 'icon' => '@'],
        ['group' => 'Системные', 'title' => 'Телефон', 'token' => '{{phone}}', 'icon' => '☎'],
        ['group' => 'Системные', 'title' => 'E-mail', 'token' => '{{email}}', 'icon' => '✉'],
        ['group' => 'Системные', 'title' => 'Канал', 'token' => '{{bot_title}}', 'icon' => '#'],
        ['group' => 'Системные', 'title' => 'Username бота', 'token' => '{{bot_username}}', 'icon' => '@'],
        ['group' => 'Системные', 'title' => 'Ref / источник', 'token' => '{{ref}}', 'icon' => '↗'],
        ['group' => 'UTM', 'title' => 'utm_source', 'token' => '{{utm_source}}', 'icon' => 'U'],
        ['group' => 'UTM', 'title' => 'utm_medium', 'token' => '{{utm_medium}}', 'icon' => 'U'],
        ['group' => 'UTM', 'title' => 'utm_campaign', 'token' => '{{utm_campaign}}', 'icon' => 'U'],
        ['group' => 'UTM', 'title' => 'utm_content', 'token' => '{{utm_content}}', 'icon' => 'U'],
        ['group' => 'UTM', 'title' => 'utm_term', 'token' => '{{utm_term}}', 'icon' => 'U'],
    ];
    try {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            $code = trim((string)($field['code'] ?? ''));
            $title = trim((string)($field['title'] ?? ''));
            if ($code === '') continue;
            $broadcastMessageVariables[] = [
                'group' => 'Пользовательские',
                'title' => $title !== '' ? $title : $code,
                'token' => '{{custom.' . $code . '}}',
                'icon' => '★',
            ];
        }
    } catch (Throwable $e) {}
    $historyView = (string)($_GET['view'] ?? '') === 'history';
    $openBroadcastId = (int)($_GET['broadcast_id'] ?? 0);
    $openBroadcastGroupRaw = $_GET['broadcast_ids'] ?? [];
    if (!is_array($openBroadcastGroupRaw)) $openBroadcastGroupRaw = preg_split('/[,;\s]+/', (string)$openBroadcastGroupRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $openBroadcastGroupIds = array_values(array_unique(array_filter(array_map('intval', $openBroadcastGroupRaw), static fn($id) => $id > 0)));
    if ($openBroadcastId > 0 && !in_array($openBroadcastId, $openBroadcastGroupIds, true)) $openBroadcastGroupIds[] = $openBroadcastId;
    $openBroadcast = $openBroadcastId > 0 ? asr_tg_broadcast_find($pdo, $openBroadcastId) : null;
    $openBroadcastGroupRows = count($openBroadcastGroupIds) > 1 ? asr_tg_broadcasts_find_by_ids($pdo, $openBroadcastGroupIds) : [];
    $openBroadcastGroupEnabled = $openBroadcast && count($openBroadcastGroupRows) > 1;
    $openBroadcastReportTotal = $openBroadcast ? (int)$openBroadcast['total_recipients'] : 0;
    $openBroadcastReportSent = $openBroadcast ? (int)$openBroadcast['sent_count'] : 0;
    $openBroadcastReportFailed = $openBroadcast ? (int)$openBroadcast['failed_count'] : 0;
    $openBroadcastGroupBotTitles = [];
    if ($openBroadcastGroupEnabled) {
        $openBroadcastReportTotal = 0;
        $openBroadcastReportSent = 0;
        $openBroadcastReportFailed = 0;
        foreach ($openBroadcastGroupRows as $groupRow) {
            $openBroadcastReportTotal += (int)($groupRow['total_recipients'] ?? 0);
            $openBroadcastReportSent += (int)($groupRow['sent_count'] ?? 0);
            $openBroadcastReportFailed += (int)($groupRow['failed_count'] ?? 0);
            $botTitle = trim((string)($groupRow['bot_title'] ?? ''));
            if ($botTitle !== '') $openBroadcastGroupBotTitles[] = $botTitle;
        }
        $openBroadcastGroupBotTitles = array_values(array_unique($openBroadcastGroupBotTitles));
    }
    $openBroadcastReportIds = $openBroadcast ? ($openBroadcastGroupEnabled ? $openBroadcastGroupIds : [$openBroadcastId]) : [];
    $openBroadcastRecipientStats = $openBroadcast ? asr_tg_broadcast_recipient_status_counts($pdo, $openBroadcastReportIds) : ['pending'=>0,'processing'=>0,'sent'=>0,'failed'=>0,'total'=>0,'done'=>0,'left'=>0];
    $openBroadcastReportTimes = $openBroadcast ? asr_tg_broadcast_report_times($pdo, $openBroadcastReportIds) : ['created_at'=>'','queued_at'=>'','started_at'=>'','finished_at'=>'','first_sent_at'=>'','last_sent_at'=>'','updated_at'=>''];
    $openBroadcastErrors = $openBroadcast ? asr_tg_broadcast_error_summary_for_ids($pdo, $openBroadcastReportIds, 10) : [];
    $openBroadcastSkippedRows = [];
    if ($openBroadcastGroupEnabled) {
        foreach ($openBroadcastGroupRows as $groupRow) {
            $groupStatus = (string)($groupRow['status'] ?? '');
            $groupTotal = (int)($groupRow['total_recipients'] ?? 0);
            if ($groupTotal <= 0 && in_array($groupStatus, ['skipped', 'failed'], true)) {
                $openBroadcastSkippedRows[] = $groupRow;
            }
        }
    } elseif ($openBroadcast && (int)($openBroadcast['total_recipients'] ?? 0) <= 0 && in_array((string)($openBroadcast['status'] ?? ''), ['skipped', 'failed'], true)) {
        $openBroadcastSkippedRows[] = $openBroadcast;
    }
    $openBroadcastScheduledIds = [];
    if ($openBroadcastGroupEnabled) {
        foreach ($openBroadcastGroupRows as $groupRow) {
            if ((string)($groupRow['status'] ?? '') === 'scheduled') $openBroadcastScheduledIds[] = (int)$groupRow['id'];
        }
    } elseif ($openBroadcast && (string)($openBroadcast['status'] ?? '') === 'scheduled') {
        $openBroadcastScheduledIds = asr_tg_broadcast_scheduled_group_ids($pdo, (int)$openBroadcast['id']);
    }

    $broadcastStatusLabels = [
        'draft' => 'Черновик', 'queued' => 'В очереди', 'processing' => 'Выполняется', 'finished' => 'Завершена',
        'finished_with_errors' => 'Завершена с ошибками', 'failed' => 'Ошибка', 'cancelled' => 'Отменена', 'sending' => 'Отправляется', 'scheduled' => 'Запланирована',
        'skipped' => 'Пропущена'
    ];
    $broadcastStatusClasses = [
        'draft' => 'bg-gray-100 text-gray-700 border-gray-100',
        'queued' => 'bg-orange-50 text-orange-700 border-orange-100',
        'processing' => 'bg-orange-50 text-orange-700 border-orange-100',
        'sending' => 'bg-orange-50 text-orange-700 border-orange-100',
        'scheduled' => 'bg-orange-50 text-orange-700 border-orange-100',
        'finished' => 'bg-green-50 text-green-700 border-green-100',
        'finished_with_errors' => 'bg-red-50 text-red-700 border-red-100',
        'failed' => 'bg-red-50 text-red-700 border-red-100',
        'cancelled' => 'bg-gray-100 text-gray-600 border-gray-100',
        'skipped' => 'bg-gray-100 text-gray-600 border-gray-100',
    ];
    $broadcastStatusClass = static function(string $status) use ($broadcastStatusClasses): string {
        return $broadcastStatusClasses[$status] ?? 'bg-gray-100 text-gray-700 border-gray-100';
    };
    $broadcastFormatTime = static function($value): string {
        $value = trim((string)$value);
        return $value !== '' ? $value : '—';
    };
    $selectedBotCount = 0;
    foreach ($bots as $bot) { if ((int)$bot['id'] === $selectedBotId) { $selectedBotCount = (int)($bot['subscribers_count'] ?? 0); break; } }
    $activeBroadcastBots = array_values(array_filter($bots, static fn($bot) => (string)($bot['status'] ?? '') === 'active'));
    if (!$activeBroadcastBots) $activeBroadcastBots = $bots;

    $historyPerPage = (int)($_GET['per_page'] ?? 25);
    if (!in_array($historyPerPage, [10, 25, 50, 100], true)) $historyPerPage = 25;
    $historyPageNo = max(1, (int)($_GET['page_no'] ?? 1));
    $historyLimit = $historyView ? $historyPerPage : 30;
    $historyBotIdsRaw = $_GET['filter_bot_ids'] ?? [];
    if (!is_array($historyBotIdsRaw)) $historyBotIdsRaw = [$historyBotIdsRaw];
    $historyAllowedBotIds = array_values(array_unique(array_map(static fn($bot) => (int)$bot['id'], $activeBroadcastBots)));
    $historySelectedBotIds = array_values(array_intersect($historyAllowedBotIds, array_values(array_unique(array_filter(array_map('intval', $historyBotIdsRaw), static fn($id) => $id > 0)))));
    if (!$historySelectedBotIds) $historySelectedBotIds = $historyAllowedBotIds;
    $historyAllBotsSelected = count($historySelectedBotIds) === count($historyAllowedBotIds);
    $historyDateFrom = trim((string)($_GET['date_from'] ?? ''));
    $historyDateTo = trim((string)($_GET['date_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateFrom)) $historyDateFrom = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyDateTo)) $historyDateTo = '';
    if ($historyDateFrom !== '' && $historyDateTo !== '' && $historyDateFrom > $historyDateTo) {
        [$historyDateFrom, $historyDateTo] = [$historyDateTo, $historyDateFrom];
    }
    $historyFilters = [
        'bot_ids' => $historyAllBotsSelected ? [] : $historySelectedBotIds,
        'date_from' => $historyDateFrom,
        'date_to' => $historyDateTo,
    ];
    $historyTotalRows = ($historyView && !$openBroadcast) ? asr_tg_broadcasts_count($pdo, 0, $historyFilters) : 0;
    $historyTotalPages = max(1, (int)ceil($historyTotalRows / max(1, $historyPerPage)));
    if ($historyPageNo > $historyTotalPages) $historyPageNo = $historyTotalPages;
    $historyOffset = ($historyPageNo - 1) * $historyPerPage;
    $rows = ($historyView && !$openBroadcast) ? asr_tg_broadcasts_recent($pdo, 0, $historyLimit, $historyFilters, $historyOffset) : [];
    $historyBackParams = ['tab' => 'telegram_bots', 'page' => 'broadcasts', 'view' => 'history', 'bot_id' => $selectedBotId, 'page_no' => $historyPageNo, 'per_page' => $historyPerPage];
    foreach ($historySelectedBotIds as $historySelectedBotId) { $historyBackParams['filter_bot_ids'][] = $historySelectedBotId; }
    if ($historyDateFrom !== '') $historyBackParams['date_from'] = $historyDateFrom;
    if ($historyDateTo !== '') $historyBackParams['date_to'] = $historyDateTo;
    $historyBuildUrl = static function(array $overrides = []) use ($historyBackParams): string {
        $params = $historyBackParams;
        foreach ($overrides as $key => $value) {
            if ($value === null) unset($params[$key]);
            else $params[$key] = $value;
        }
        return 'admin.php?' . http_build_query($params);
    };
    $historyBackUrl = $historyBuildUrl();
    $historyFromRow = $historyTotalRows > 0 ? ($historyOffset + 1) : 0;
    $historyToRow = $historyTotalRows > 0 ? min($historyOffset + count($rows), $historyTotalRows) : 0;
    $historyFilterSummary = 'Все каналы';
    if (!$historyAllBotsSelected) {
        $historyFilterSummary = count($historySelectedBotIds) === 1 ? '1 канал' : ('Выбрано каналов: ' . count($historySelectedBotIds));
    }
    if ($historyDateFrom !== '' || $historyDateTo !== '') {
        $historyFilterSummary .= ' · ' . ($historyDateFrom !== '' ? $historyDateFrom : 'с начала') . ' - ' . ($historyDateTo !== '' ? $historyDateTo : 'сегодня');
    }
        $asrTgParseIniBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        if ($unit === 'g') $num *= 1024 * 1024 * 1024;
        elseif ($unit === 'm') $num *= 1024 * 1024;
        elseif ($unit === 'k') $num *= 1024;
        return (int)$num;
    };
    $tgUploadIniLimit = $asrTgParseIniBytes((string)ini_get('upload_max_filesize'));
    $tgPostIniLimit = $asrTgParseIniBytes((string)ini_get('post_max_size'));
    $tgAppUploadLimit = 45 * 1024 * 1024;
    $tgLimits = array_filter([$tgUploadIniLimit, $tgPostIniLimit, $tgAppUploadLimit]);
    $tgClientUploadLimit = max(1024 * 1024, min($tgLimits ?: [$tgAppUploadLimit]));


    $broadcastSegmentOps = [
        'text' => [['contains','Содержит'],['not_contains','Не содержит'],['equals','Равно'],['not_equals','Не равно'],['known','Заполнено'],['unknown','Не заполнено']],
        'number' => [['equals','Равно'],['greater','Больше'],['less','Меньше'],['between','Между'],['known','Заполнено'],['unknown','Не заполнено']],
        'date' => [['equals','Равно'],['after_date','После даты'],['before_date','До даты'],['between','Между'],['known','Заполнено'],['unknown','Не заполнено']],
        'datetime' => [['equals','Равно'],['after_date','После даты'],['before_date','До даты'],['between','Между'],['known','Заполнено'],['unknown','Не заполнено']],
        'enum' => [['equals','Равно'],['not_equals','Не равно'],['known','Заполнено'],['unknown','Не заполнено']],
        'tag' => [['has_tag','Есть тег'],['not_has_tag','Нет тега']],
    ];
    $broadcastSegmentCatalog = [];
    $broadcastSegmentCatalogForJs = [];
    $broadcastHumanizeColumn = static function(string $column): string {
        $map = [
            'id'=>'ID подписчика','bot_id'=>'ID канала','telegram_user_id'=>'Telegram User ID','chat_id'=>'Chat ID',
            'username'=>'Username','first_name'=>'Имя','last_name'=>'Фамилия','status'=>'Статус','language_code'=>'Язык',
            'phone'=>'Телефон','email'=>'Email','first_seen_at'=>'Дата подписки','last_seen_at'=>'Последняя активность',
            'created_at'=>'Дата создания','updated_at'=>'Дата обновления','is_bot'=>'Telegram-бот',
            'ref'=>'Источник / ref','utm_source'=>'utm_source','utm_medium'=>'utm_medium','utm_campaign'=>'utm_campaign','utm_content'=>'utm_content','utm_term'=>'utm_term',
            'admin_note'=>'Заметка','note'=>'Заметка','source'=>'Источник','source_url'=>'Ссылка-источник'
        ];
        if (isset($map[$column])) return $map[$column];
        return trim(mb_convert_case(str_replace('_', ' ', $column), MB_CASE_TITLE, 'UTF-8'));
    };
    $broadcastSqlTypeFromColumn = static function(string $dbType): string {
        $dbType = strtolower($dbType);
        if (str_contains($dbType, 'int') || str_contains($dbType, 'decimal') || str_contains($dbType, 'float') || str_contains($dbType, 'double')) return 'number';
        if (str_contains($dbType, 'datetime') || str_contains($dbType, 'timestamp')) return 'datetime';
        if (preg_match('/\bdate\b/', $dbType)) return 'date';
        return 'text';
    };
    $broadcastAddSegmentField = static function(string $key, string $title, string $type, string $source, array $extra = []) use (&$broadcastSegmentCatalog, &$broadcastSegmentCatalogForJs, $broadcastSegmentOps): void {
        if ($key === '' || isset($broadcastSegmentCatalog[$key])) return;
        if (!isset($broadcastSegmentOps[$type])) $type = 'text';
        $row = array_merge([
            'key' => $key,
            'title' => $title,
            'type' => $type,
            'source' => $source,
            'ops' => $broadcastSegmentOps[$type],
        ], $extra);
        $broadcastSegmentCatalog[$key] = $row;
        $broadcastSegmentCatalogForJs[] = [
            'key' => $key,
            'title' => $title,
            'type' => $type,
            'source' => $source,
            'ops' => $row['ops'],
            'options' => $row['options'] ?? [],
            'code' => $row['code'] ?? '',
        ];
    };

    $broadcastAllowedColumns = [];
    try {
        $columnStmt = $pdo->query('SHOW COLUMNS FROM oca_telegram_bot_subscribers');
        foreach (($columnStmt ? $columnStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $columnRow) {
            $column = (string)($columnRow['Field'] ?? '');
            if ($column !== '' && preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $broadcastAllowedColumns[$column] = strtolower((string)($columnRow['Type'] ?? 'text'));
            }
        }
    } catch (Throwable $e) {
        $broadcastAllowedColumns = [
            'id'=>'int','bot_id'=>'int','telegram_user_id'=>'bigint','chat_id'=>'bigint','username'=>'varchar','first_name'=>'varchar','last_name'=>'varchar','status'=>'varchar','language_code'=>'varchar','phone'=>'varchar','email'=>'varchar','first_seen_at'=>'datetime','last_seen_at'=>'datetime','created_at'=>'datetime','updated_at'=>'datetime'
        ];
    }
    $broadcastStatusOptions = [];
    foreach (['active'=>'Активен','inactive'=>'Неактивен','blocked'=>'Заблокирован','deleted'=>'Удалён','unsubscribed'=>'Отписан','pending'=>'Ожидает'] as $key => $label) {
        $broadcastStatusOptions[] = ['value' => $key, 'label' => $label];
    }
    foreach ($broadcastAllowedColumns as $column => $dbType) {
        if (in_array($column, ['id','bot_id','chat_id'], true)) continue;
        $fieldType = $broadcastSqlTypeFromColumn($dbType);
        if ($column === 'status') {
            $broadcastAddSegmentField('subscriber.' . $column, $broadcastHumanizeColumn($column), 'enum', 'subscriber', ['options' => $broadcastStatusOptions]);
            continue;
        }
        if ($column === 'is_bot') {
            $broadcastAddSegmentField('subscriber.' . $column, $broadcastHumanizeColumn($column), 'enum', 'subscriber', ['options' => [['value'=>'1','label'=>'Да'],['value'=>'0','label'=>'Нет']]]);
            continue;
        }
        $broadcastAddSegmentField('subscriber.' . $column, $broadcastHumanizeColumn($column), $fieldType, 'subscriber');
    }
    $broadcastAddSegmentField('bot.title', 'Канал', 'text', 'bot');
    $broadcastAddSegmentField('bot.username', 'Username канала', 'text', 'bot');
    $broadcastAddSegmentField('bot.channel_type', 'Тип канала', 'text', 'bot');

    $broadcastTagOptions = [];
    $broadcastTags = function_exists('asr_tg_tags_all_light') ? asr_tg_tags_all_light($pdo, 0) : [];
    foreach ($broadcastTags as $tag) {
        $tagId = (int)($tag['id'] ?? 0);
        if ($tagId <= 0) continue;
        $broadcastTagOptions[] = ['value' => (string)$tagId, 'label' => (string)($tag['name'] ?? ('Тег #' . $tagId))];
    }
    $broadcastAddSegmentField('tag.id', 'Тег', 'tag', 'tag', ['options' => $broadcastTagOptions]);

    try {
        if (function_exists('asr_tg_repository_ensure_custom_fields_schema')) {
            asr_tg_repository_ensure_custom_fields_schema($pdo);
        }
    } catch (Throwable $e) {}
    $broadcastCustomFieldsRaw = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, false) : [];
    $broadcastCustomFields = array_values(array_filter($broadcastCustomFieldsRaw, static fn($row) => !empty($row['is_active'])));
    foreach ($broadcastCustomFields as $field) {
        $fieldId = (int)($field['id'] ?? 0);
        if ($fieldId <= 0) continue;
        $type = (string)($field['field_type'] ?? 'text');
        if (!in_array($type, ['text','number','date','datetime'], true)) $type = 'text';
        $broadcastAddSegmentField('custom.' . $fieldId, (string)($field['title'] ?? ('custom.' . $fieldId)), $type, 'custom', [
            'field_id' => $fieldId,
            'code' => (string)($field['code'] ?? ''),
        ]);
    }

    $asrTgRenderReportText = static function (string $text) use ($h): string {
        if ($text === '') return '<span class="text-gray-400">Пустая карточка</span>';
        $parts = preg_split('/(<[^>]+>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return nl2br($h($text), false);
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part[0] !== '<') { $out .= $h($part); continue; }
            $tag = trim($part);
            $lower = strtolower($tag);
            if (preg_match('/^<\s*\/?\s*(b|strong|i|em|u|ins|s|strike|del|code|pre|blockquote)\s*>$/i', $tag, $m)) {
                $name = strtolower($m[1]);
                $map = ['strong' => 'b', 'em' => 'i', 'ins' => 'u', 'strike' => 's', 'del' => 's'];
                $htmlName = $map[$name] ?? $name;
                $out .= str_starts_with($lower, '</') ? '</' . $htmlName . '>' : '<' . $htmlName . '>';
                continue;
            }
            if (preg_match('/^<\s*\/?\s*tg-spoiler\s*>$/i', $tag)) {
                $out .= str_starts_with($lower, '</') ? '</span>' : '<span class="tg-spoiler-preview">';
                continue;
            }
            if (preg_match('/^<\s*a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>$/i', $tag, $m)) {
                $href = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $isSafe = preg_match('#^(https?://|tg://)#i', $href);
                if ($isSafe) {
                    $out .= '<a href="' . $h($href) . '" target="_blank" rel="noopener" class="text-orange-600 underline">';
                }
                continue;
            }
            if (preg_match('/^<\s*\/\s*a\s*>$/i', $tag)) { $out .= '</a>'; continue; }
            $out .= $h($part);
        }
        return nl2br($out, false);
    };
    $asrTgRenderVkReportText = static function (string $text) use ($h): string {
        $text = preg_replace_callback('/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/isu', static function($m) {
            $href = trim(html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = trim(html_entity_decode(strip_tags((string)$m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href !== '' && $label !== '' && $href !== $label) return $label . ' (' . $href . ')';
            return $href !== '' ? $href : $label;
        }, $text) ?? $text;
        $text = preg_replace('/<br\s*\/?\s*>/iu', "
", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|blockquote|pre|li)>/iu', "
", $text) ?? $text;
        $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') return '<span class="text-gray-400">Пустая карточка</span>';
        return nl2br($h($plain), false);
    };
    $asrTgRenderReportButtons = static function (array $card) use ($h): string {
        $rows = $card['buttons'] ?? [];
        if (!is_array($rows) || !$rows) return '';
        $html = '<div class="tg-report-buttons">';
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $html .= '<div class="tg-report-button-row">';
            foreach ($row as $button) {
                if (!is_array($button)) continue;
                $text = trim((string)($button['text'] ?? 'Кнопка'));
                $html .= '<span class="tg-report-button">' . $h($text !== '' ? $text : 'Кнопка') . '</span>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    };
?>
    <style>
        .tg-broadcast-shell{margin:-4px -4px 0 -4px;background:#fff;border:1px solid #eef0f4;border-radius:28px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.04)}
        .tg-broadcast-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 26px;border-bottom:1px solid #eef0f4;background:#fff}
        .tg-broadcast-steps{display:flex;align-items:center;gap:12px;padding:18px 26px;border-bottom:1px solid #eef0f4;background:#fff;overflow:auto}.tg-step{display:flex;align-items:center;gap:10px;white-space:nowrap;font-weight:600;color:#111827}.tg-step-dot{width:34px;height:34px;border-radius:999px;background:#666;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}.tg-step.is-active .tg-step-dot{background:#FFA048}.tg-step.is-done .tg-step-dot{background:#FFA048}.tg-step-line{height:1px;width:110px;background:#d8dce3;flex:0 0 110px}
        .tg-broadcast-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.9fr);min-height:560px}.tg-broadcast-left{padding:30px;border-right:1px solid #eef0f4}.tg-broadcast-right{padding:30px;background:#fff}.tg-step-side{display:none}.tg-step-side.is-active{display:block}.tg-step-side[data-step-side="2"]{height:100%;align-items:center;justify-content:center}.tg-step-side[data-step-side="2"].is-active{display:flex}.tg-step-side[data-step-side="3"]{height:100%;align-items:center;justify-content:center}.tg-step-side[data-step-side="3"].is-active{display:flex}.tg-step-panel{display:none}.tg-step-panel.is-active{display:block}.tg-card-type-btn{display:inline-flex;align-items:center;gap:9px;border:1px solid #f1e1d2;color:#FFA048;background:#fff;border-radius:8px;padding:12px 16px;font-size:12px;font-weight:600;letter-spacing:.03em}.tg-message-card{background:#f7f7f8;border:1px solid #eef0f4;border-radius:18px;padding:20px;margin-top:18px}.tg-card-toolbar{display:flex;gap:4px;flex-wrap:wrap;border:1px solid #d8dce3;border-bottom:0;border-radius:10px 10px 0 0;background:#fff;padding:8px}.tg-card-toolbar button{min-width:34px;height:30px;border-radius:8px;font-weight:600;color:#6b7280;padding:0 8px}.tg-card-toolbar button:hover{background:#f3f4f6;color:#374151}.tg-toolbar-sep{width:1px;background:#e5e7eb;margin:3px 6px}.tg-editor-wrap{border:1px solid #d8dce3;border-radius:0 0 10px 10px;background:#fff}.tg-card-editor{min-height:150px;padding:16px;font-weight:400;outline:none;white-space:pre-wrap;line-height:1.55;color:#1f2937}.tg-card-editor:empty:before{content:attr(data-placeholder);color:#9ca3af}.tg-card-bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border-top:1px solid #eef0f4}.tg-mini-btn{border:0;background:transparent;color:#6b7280;font-weight:600;padding:5px 7px;border-radius:8px}.tg-mini-btn:hover{background:#f3f4f6}.tg-macro-menu,.tg-emoji-menu{position:absolute;z-index:30;width:310px;max-height:320px;overflow:auto;background:#fff;border:1px solid #eef0f4;border-radius:12px;box-shadow:0 18px 35px rgba(15,23,42,.15);padding:8px;display:none}.tg-macro-menu.is-open,.tg-emoji-menu.is-open{display:block}.tg-macro-search{width:100%;border:0;background:#f7f7f8;border-radius:10px;padding:11px 12px;font-weight:600;margin-bottom:8px}.tg-macro-item{display:grid!important;grid-template-columns:34px 1fr auto;align-items:center;gap:8px;width:100%;padding:10px;border-radius:10px;text-align:left;color:#374151}.tg-macro-item:hover{background:#f3f4f6}.tg-macro-token{color:#9ca3af;font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis}.tg-macro-group{padding:8px 9px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af}.tg-emoji-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:4px}.tg-emoji-grid button{font-size:18px;border-radius:8px;padding:7px}.tg-help{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#eef0f4;color:#8b95a1;font-size:12px;font-weight:500;position:relative;cursor:help}.tg-help:hover:after{content:attr(data-help);position:absolute;left:24px;top:-10px;width:260px;background:#3f3f46;color:#fff;border-radius:8px;padding:10px 12px;font-size:12px;font-weight:600;line-height:1.35;z-index:40;text-align:left}.tg-card-textarea{display:none}.tg-preview-phone{width:330px;max-width:100%;border-radius:34px;background:#f4f5f7;border:1px solid #e5e7eb;padding:18px;box-shadow:0 18px 40px rgba(15,23,42,.08)}.tg-preview-bubble{background:white;border-radius:18px 18px 18px 4px;padding:13px 14px;font-size:13px;font-weight:400;color:#374151;white-space:pre-wrap;box-shadow:0 8px 22px rgba(15,23,42,.07)}.tg-preview-buttons{margin-top:8px;display:flex;flex-direction:column;gap:6px}.tg-preview-button-row{display:flex;gap:6px}.tg-preview-button{flex:1;background:#fff;border:1px solid #dbeafe;border-radius:10px;padding:8px 10px;text-align:center;color:#2563eb;font-size:12px;font-weight:600}.tg-message-buttons{display:flex;flex-direction:column;gap:8px}.tg-message-button-row{display:flex;gap:8px;align-items:center}.tg-message-button{min-height:38px;flex:1;border:1px solid #dbeafe;border-radius:8px;background:#fff;color:#2563eb;font-size:13px;font-weight:600;position:relative}.tg-message-button:hover{background:#f8fbff}.tg-message-button-add{width:38px;height:38px;border:1px solid #dbeafe;border-radius:8px;background:#fff;color:#2563eb;font-size:20px;line-height:1}.tg-button-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:100;display:none;align-items:center;justify-content:center;padding:18px}.tg-button-modal-backdrop.is-open{display:flex}.tg-button-modal{width:560px;max-width:100%;background:#fff;border-radius:18px;box-shadow:0 25px 60px rgba(15,23,42,.35);overflow:hidden}.tg-button-modal-head{padding:22px 26px;display:flex;align-items:center;justify-content:space-between}.tg-button-modal-body{padding:0 26px 22px}.tg-button-modal-foot{padding:18px 26px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:12px}.tg-button-select{width:100%;border:1px solid #d8dce3;border-radius:8px;padding:15px 16px;background:#fff;font-weight:500}.tg-button-danger{margin-right:auto;color:#ef4444;font-weight:600}.tg-button-save{background:#FFA048;color:#fff;border-radius:8px;padding:12px 24px;font-weight:600}.tg-button-muted{background:#f4f4f5;color:#52525b;border-radius:8px;padding:12px 18px;font-weight:600}.tg-card-preview-media{border-radius:14px;background:#e5e7eb;border:1px dashed #cbd5e1;padding:22px;text-align:center;font-size:12px;font-weight:600;color:#64748b;margin-bottom:8px}.tg-broadcast-footer{display:flex;align-items:center;gap:12px;padding:18px 26px;border-top:1px solid #eef0f4;background:#fff}.tg-broadcast-footer .spacer{flex:1}.tg-btn-main{background:#FFA048;color:#fff;border-radius:10px;padding:14px 28px;font-weight:600;letter-spacing:.03em}.tg-btn-ghost{color:#FFA048;border-radius:10px;padding:14px 16px;font-weight:600;letter-spacing:.03em}.tg-btn-green{background:#FFA048;color:#fff;border-radius:10px;padding:14px 28px;font-weight:600;letter-spacing:.03em}.tg-form-field{width:100%;border:1px solid #d8dce3;border-radius:8px;padding:16px 18px;font-weight:600;background:#fff}.tg-form-label{display:block;font-size:11px;font-weight:600;text-transform:;color:#9ca3af;margin-bottom:8px;letter-spacing:.12em}.tg-condition-card{background:#f7f7f8;border:1px solid #eef0f4;border-radius:16px;padding:18px;margin-top:14px}.tg-add-condition-menu{position:absolute;z-index:10;min-width:280px;background:white;border:1px solid #eef0f4;border-radius:12px;box-shadow:0 18px 35px rgba(15,23,42,.15);padding:8px;display:none}.tg-add-condition-menu.is-open{display:block}.tg-add-condition-menu button{display:flex;width:100%;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;text-align:left;font-weight:600;color:#374151}.tg-add-condition-menu button:hover{background:#f3f4f6}.tg-toggle{width:44px;height:24px;border-radius:999px;background:#d1d5db;position:relative;display:inline-block}.tg-toggle:after{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;border-radius:999px;background:white;box-shadow:0 2px 6px rgba(0,0,0,.18)}.tg-toggle-input:checked + .tg-toggle{background:#FFA048}.tg-toggle-input:checked + .tg-toggle:after{left:23px}
.tg-vk-broadcast-mode .tg-card-toolbar button:disabled{opacity:.35;cursor:not-allowed;background:#f4f4f5}.tg-vk-plain-notice{margin-top:10px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:600;line-height:1.45}
.tg-preview-phone.is-vk{background:#f3f6fb}.tg-preview-platform{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.tg-preview-platform-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#4f46e5;padding:4px 9px;font-size:10px;font-weight:700;letter-spacing:.04em}.tg-preview-platform-note{font-size:11px;font-weight:600;color:#9ca3af}.tg-preview-bubble.is-vk{border-radius:16px;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.06)}.tg-preview-vk-url{display:block;margin-top:2px;font-size:10px;color:#94a3b8;font-weight:600;word-break:break-all}.tg-card-preview-media.is-vk{background:#f8fafc;border-style:solid}.tg-card-preview-media .tg-media-kind{display:block;margin-bottom:4px;color:#9a5a17;font-size:10px;text-transform:uppercase;letter-spacing:.08em}.tg-preview-button.is-vk{border-color:#dbeafe;background:#f8fbff;color:#2563eb}

        .tg-channel-picker{position:relative;margin-top:8px}.tg-channel-picker-button{width:100%;min-height:54px;border:1px solid #d8dce3;border-radius:8px;background:#fff;padding:0 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-weight:600;color:#374151;text-align:left}.tg-channel-picker-button:after{content:'▾';font-size:13px;color:#9ca3af}.tg-channel-picker.is-open .tg-channel-picker-button:after{content:'▴'}.tg-channel-picker-menu{position:absolute;z-index:35;left:0;right:0;top:calc(100% + 8px);background:#fff;border:1px solid #eef0f4;border-radius:14px;box-shadow:0 18px 35px rgba(15,23,42,.14);padding:8px;display:none;max-height:285px;overflow:auto}.tg-channel-picker.is-open .tg-channel-picker-menu{display:block}.tg-channel-picker-item{display:flex;align-items:center;gap:10px;width:100%;padding:10px 11px;border-radius:10px;font-size:13px;font-weight:600;color:#374151;cursor:pointer}.tg-channel-picker-item:hover{background:#f7f7f8}.tg-channel-picker-item.is-disabled{opacity:.45;cursor:not-allowed}.tg-channel-picker-item.is-disabled:hover{background:transparent}.tg-channel-picker-item input{width:16px;height:16px;flex:0 0 16px;pointer-events:none}.tg-channel-picker-all{border-bottom:1px solid #eef0f4;margin-bottom:6px;padding-bottom:9px}.tg-channel-picker-count{margin-left:auto;color:#9ca3af;font-size:12px;font-weight:600}.tg-hidden-select{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;left:-9999px!important}
.tg-bc-filter{margin-top:22px}.tg-bc-filter-conditions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tg-bc-filter-condition{position:relative;display:inline-flex;align-items:center;gap:6px}.tg-bc-filter-condition.is-invalid .tg-bc-filter-chip{background:#fff1f2;border-color:#fecdd3;color:#be123c}.tg-bc-filter-chip{height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:0 8px 0 14px;display:inline-flex;align-items:center;gap:8px;color:#374151;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.03);max-width:360px}.tg-bc-filter-chip:hover{border-color:#fed7aa;background:#fffaf5}.tg-bc-filter-chip-text{display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:285px}.tg-bc-filter-chip:after{content:'▾';font-size:11px;color:#9ca3af}.tg-bc-filter-joiner{height:34px;border:0;border-radius:999px;background:#f3f4f6;color:#e98222;padding:0 12px;font-size:12px;font-weight:700;cursor:pointer}.tg-bc-filter-joiner.is-or{color:#b45309}.tg-bc-filter-remove{width:24px;height:24px;border:0;border-radius:999px;background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:15px;line-height:1;display:inline-flex;align-items:center;justify-content:center}.tg-bc-filter-remove:hover{background:#fee2e2;color:#b91c1c}.tg-bc-filter-popover{display:none;position:absolute;z-index:50;top:48px;left:0;width:360px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 18px 48px rgba(15,23,42,.16);padding:14px}.tg-bc-filter-condition.is-open .tg-bc-filter-popover{display:block}.tg-bc-filter-popover-row{display:grid;gap:6px;margin-bottom:10px}.tg-bc-filter-popover-label{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em}.tg-bc-filter-popover select,.tg-bc-filter-popover input{width:100%;height:38px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:0 10px;font-size:13px;font-weight:600;color:#374151;outline:none}.tg-bc-filter-value-box{display:grid;gap:8px}.tg-bc-filter-popover-actions{display:flex;justify-content:flex-end;gap:8px;padding-top:4px}.tg-bc-filter-done{height:34px;border:0;border-radius:12px;background:#e98222;color:#fff;padding:0 12px;font-size:12px;font-weight:700;cursor:pointer}.tg-bc-filter-warning{display:none;margin-top:8px;color:#be123c;font-size:12px;font-weight:600}.tg-bc-filter-condition.is-invalid .tg-bc-filter-warning{display:block}
        @media(max-width:1024px){.tg-broadcast-grid{grid-template-columns:1fr}.tg-broadcast-left{border-right:0;border-bottom:1px solid #eef0f4}.tg-broadcast-right{border-top:1px solid #eef0f4}.tg-step-line{width:40px;flex-basis:40px}}@media(max-width:640px){.tg-broadcast-top,.tg-broadcast-steps,.tg-broadcast-left,.tg-broadcast-footer{padding:16px}.tg-broadcast-footer{position:sticky;bottom:0;z-index:5}.tg-card-type-btn{width:100%;justify-content:center}.tg-broadcast-steps{font-size:13px}}

        .tg-card-toolbar button{font-size:16px;line-height:1}.tg-card-toolbar .tg-ico{font-size:15px;color:#6b7280}.tg-ui-icon{width:18px;height:18px;display:inline-block;vertical-align:middle}.tg-card-type-btn .aa-icon,.tg-add-condition-menu .aa-icon{width:17px;height:17px}.tg-card-toolbar button .tg-ui-icon,.tg-mini-btn .tg-ui-icon{width:16px;height:16px}.tg-card-toolbar button,.tg-mini-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px}
        .tg-emoji-menu{width:520px;max-width:calc(100vw - 36px);max-height:430px}.tg-emoji-section{margin:8px 4px 12px}.tg-emoji-title{font-size:11px;font-weight:500;color:#9ca3af;margin:8px 4px}.tg-emoji-grid{grid-template-columns:repeat(10,1fr)}.tg-emoji-grid button{font-size:22px;line-height:1.1}
        .tg-card-editor code,.tg-preview-bubble code{background:#eef0f4;border-radius:5px;padding:1px 5px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.92em}.tg-card-editor pre,.tg-preview-bubble pre{background:#eef0f4;border-radius:10px;padding:10px 12px;margin:8px 0;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.tg-card-editor blockquote,.tg-preview-bubble blockquote{border-left:3px solid #FFA048;margin:8px 0;padding:7px 10px;background:#fff7ed;border-radius:8px;color:#4b5563}.tg-card-editor a,.tg-preview-bubble a{color:#2563eb;text-decoration:underline}.tg-card-editor tg-spoiler,.tg-preview-bubble .tg-spoiler-preview{background:#6b7280;color:transparent;border-radius:4px;padding:0 3px;text-shadow:0 0 7px rgba(255,255,255,.85)}.tg-card-editor .tg-date-link,.tg-preview-date{display:inline-block;border-bottom:1px dashed #FFA048;color:#9a5a17;text-decoration:none!important}.tg-card-preview-media img{display:block;max-width:100%;border-radius:12px;margin:0 auto 8px}.tg-card-preview-media{overflow:hidden}.tg-alert-backdrop,.tg-date-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.48);z-index:110;display:none;align-items:center;justify-content:center;padding:18px}.tg-alert-backdrop.is-open,.tg-date-modal-backdrop.is-open{display:flex}.tg-alert-modal,.tg-date-modal{width:460px;max-width:100%;background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.32);overflow:hidden}.tg-alert-modal{padding:24px}.tg-draft-choice-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:22px}.tg-draft-choice-muted{background:#f4f4f5;color:#52525b;border-radius:10px;padding:12px 16px;font-weight:600}.tg-draft-choice-main{background:#FFA048;color:#fff;border-radius:10px;padding:12px 18px;font-weight:600}.tg-date-modal-head{padding:22px 26px;display:flex;align-items:center;justify-content:space-between}.tg-date-modal-body{padding:0 26px 22px}.tg-date-modal-foot{padding:18px 26px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:12px}


/* Telegram bots: local SVG icons, no global helpers */
.tg-ui-icon{width:22px;height:22px;display:inline-block;vertical-align:middle;object-fit:contain;max-width:none;opacity:.94;flex:0 0 auto}
.tg-ui-icon--toolbar{width:22px;height:22px}
.tg-ui-icon--mini{width:23px;height:23px}
.tg-ui-icon--module{width:24px;height:24px}
.tg-ui-icon--menu{width:22px;height:22px}
.tg-ui-icon--modal-close{width:24px;height:24px}
.tg-card-toolbar{gap:6px}
.tg-card-toolbar button{min-width:38px;height:34px;padding:0 8px;border-radius:9px}
.tg-card-toolbar button .tg-ui-icon{width:22px!important;height:22px!important}
.tg-mini-btn{min-width:34px;height:34px;padding:5px 8px}
.tg-mini-btn .tg-ui-icon{width:23px!important;height:23px!important}
.tg-card-type-btn .tg-ui-icon{width:24px!important;height:24px!important}
.tg-add-condition-menu .tg-ui-icon{width:22px!important;height:22px!important}
[data-card-up] .tg-ui-icon,[data-card-down] .tg-ui-icon,[data-card-delete] .tg-ui-icon{width:22px!important;height:22px!important}
@media(max-width:640px){.tg-card-toolbar button{min-width:36px;height:34px}.tg-card-toolbar button .tg-ui-icon{width:21px!important;height:21px!important}}

.tg-message-card.is-invalid{border-color:#fca5a5!important;background:#fff7f7!important;box-shadow:0 0 0 3px rgba(239,68,68,.10)}
.tg-message-card.is-invalid:after{content:attr(data-card-error);display:block;margin-top:14px;border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;border-radius:12px;padding:10px 12px;font-size:12px;font-weight:600;line-height:1.35}
.tg-message-card.is-invalid .tg-editor-wrap,.tg-message-card.is-invalid .tg-form-field{border-color:#fca5a5}

.tg-report-phone{max-width:520px;border-radius:24px;background:#eef3f7;border:1px solid #e5e7eb;padding:16px}.tg-report-bubble{background:#fff;border-radius:18px 18px 18px 5px;padding:14px 15px;font-size:14px;font-weight:400;color:#1f2937;line-height:1.5;box-shadow:0 8px 22px rgba(15,23,42,.07);overflow-wrap:anywhere}.tg-report-bubble pre{white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;background:#f4f4f5;border-radius:10px;padding:10px;margin:8px 0}.tg-report-bubble code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;background:#f4f4f5;border-radius:6px;padding:1px 5px}.tg-report-bubble blockquote{border-left:3px solid #FFA048;margin:8px 0;padding-left:10px;color:#4b5563}.tg-report-media{border-radius:14px;background:#e5e7eb;border:1px dashed #cbd5e1;padding:18px;text-align:center;font-size:12px;font-weight:600;color:#64748b;margin-bottom:10px}.tg-report-buttons{margin-top:10px;display:flex;flex-direction:column;gap:7px}.tg-report-button-row{display:flex;gap:7px}.tg-report-button{flex:1;background:#fff;border:1px solid #dbeafe;border-radius:10px;padding:8px 10px;text-align:center;color:#2563eb;font-size:12px;font-weight:600}.tg-spoiler-preview{background:#cbd5e1;color:transparent;border-radius:4px;padding:0 3px}.tg-spoiler-preview:hover{color:#1f2937;background:#e5e7eb}
    </style>
<style>
/* Telegram title row hard fix */
.tb2-title,
section .tb2-title,
div .tb2-title{
  display:flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:flex-start!important;
  gap:12px!important;
  width:100%!important;
}
.tb2-title > img.tb2-title-icon{
  width:24px!important;
  height:24px!important;
  min-width:24px!important;
  max-width:24px!important;
  flex:0 0 24px!important;
  display:inline-block!important;
  margin:0!important;
}
.tb2-title > h2,
.tb2-title > h3,
.tb2-title .tb2-title-text > h2,
.tb2-title .tb2-title-text > h3{
  display:block!important;
  margin:0!important;
  line-height:1.15!important;
}
.tb2-title .tb2-title-text{
  display:block!important;
  min-width:0!important;
}
.tb2-title .tb2-title-text p{
  margin-top:6px!important;
}
@media(max-width:768px){
  .tb2-title{gap:10px!important}
  .tb2-title > img.tb2-title-icon{width:22px!important;height:22px!important;min-width:22px!important;max-width:22px!important;flex-basis:22px!important}
}
</style>
<style>
/* Telegram title icon alignment correction */
.tb2-title{
  display:flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:flex-start!important;
  gap:12px!important;
}
.tb2-title > div{
  min-width:0!important;
}
.tb2-title-icon{
  width:26px!important;
  height:26px!important;
  flex:0 0 26px!important;
  margin:0!important;
}
.tb2-title h2,
.tb2-title h3{
  margin:0!important;
  line-height:1.15!important;
}
@media(max-width:768px){
  .tb2-title{gap:10px!important}
  .tb2-title-icon{width:24px!important;height:24px!important;flex-basis:24px!important}
}
</style>

    <?php if ($historyView): ?>
    <div class="space-y-6">
        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 sm:p-6">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div class="tb2-title" style="display:flex;flex-direction:row;align-items:center;gap:12px;width:100%;">
                    <img class="tb2-title-icon" style="width:24px;height:24px;min-width:24px;flex:0 0 24px;margin:0;display:inline-block;" src="/assets/admin/icons/tb2-history-gray.svg" alt="" aria-hidden="true">
                    <div class="tb2-title-text">
                        <h3 class="text-2xl font-semibold text-gray-800">История рассылок</h3>
                        <p class="text-sm font-semibold text-gray-500 mt-1">Все последние рассылки, статусы, прогресс и переходы в отчёт.</p>
                    </div>
                </div>
                <a href="admin.php?tab=telegram_bots&page=broadcasts&bot_id=<?php echo (int)$selectedBotId; ?>" class="tb2-btn px-4 py-3 rounded-2xl bg-[#FFA048] text-white text-xs font-semibold whitespace-nowrap"><img class="tb2-icon" src="/assets/admin/icons/tb2-plus-white.svg" alt="" aria-hidden="true">Новая рассылка</a>
            </div>


            <?php if (!$openBroadcast): ?>
            <form method="GET" class="mt-6 rounded-3xl border border-gray-100 bg-gray-50/50 p-4" id="tgHistoryFilterForm">
                <input type="hidden" name="tab" value="telegram_bots">
                <input type="hidden" name="page" value="broadcasts">
                <input type="hidden" name="view" value="history">
                <input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>">
                <input type="hidden" name="page_no" value="1">
                <input type="hidden" name="per_page" value="<?php echo (int)$historyPerPage; ?>">
                <div class="grid grid-cols-1 xl:grid-cols-[minmax(260px,1fr)_180px_180px_auto_auto] gap-3 items-end">
                    <div>
                        <label class="text-[11px] font-semibold text-gray-500">Каналы</label>
                        <div class="tg-channel-picker" id="tgHistoryBotPicker">
                            <button type="button" class="tg-channel-picker-button" id="tgHistoryBotButton" aria-haspopup="listbox" aria-expanded="false">
                                <span id="tgHistoryBotSummary"><?php echo $h($historyFilterSummary); ?></span>
                            </button>
                            <div class="tg-channel-picker-menu" id="tgHistoryBotPanel" role="listbox" aria-label="Каналы в истории рассылок">
                                <label class="tg-channel-picker-item tg-channel-picker-all">
                                    <input type="checkbox" id="tgHistoryAllBots">
                                    <span>Все каналы</span>
                                    <span class="tg-channel-picker-count"><?php echo count($activeBroadcastBots); ?></span>
                                </label>
                                <?php foreach ($activeBroadcastBots as $bot): ?>
                                    <?php $botId = (int)$bot['id']; $botLabel = $bot['title'] . (($bot['bot_username'] ?? '') ? ' (@' . $bot['bot_username'] . ')' : ''); ?>
                                    <label class="tg-channel-picker-item">
                                        <input type="checkbox" name="filter_bot_ids[]" data-history-bot-option value="<?php echo $botId; ?>" <?php echo in_array($botId, $historySelectedBotIds, true) ? 'checked' : ''; ?>>
                                        <span><?php echo $h($botLabel); ?></span>
                                        <span class="tg-channel-picker-count"><?php echo (int)($bot['subscribers_count'] ?? 0); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-gray-500" for="tgHistoryDateFrom">Дата с</label>
                        <input id="tgHistoryDateFrom" type="date" name="date_from" value="<?php echo $h($historyDateFrom); ?>" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm font-semibold text-gray-700">
                    </div>
                    <div>
                        <label class="text-[11px] font-semibold text-gray-500" for="tgHistoryDateTo">Дата по</label>
                        <input id="tgHistoryDateTo" type="date" name="date_to" value="<?php echo $h($historyDateTo); ?>" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm font-semibold text-gray-700">
                    </div>
                    <button type="submit" class="tb2-btn px-4 py-3 rounded-2xl bg-[#FFA048] text-white text-xs font-semibold whitespace-nowrap justify-center">Применить</button>
                    <a href="admin.php?tab=telegram_bots&page=broadcasts&view=history&bot_id=<?php echo (int)$selectedBotId; ?>" class="tb2-btn px-4 py-3 rounded-2xl bg-white text-gray-700 border border-gray-100 text-xs font-semibold whitespace-nowrap justify-center">Сбросить</a>
                </div>
            </form>
            <?php endif; ?>
            <?php if ($openBroadcast):
                $payload = json_decode((string)($openBroadcast['payload_json'] ?? ''), true);
                $reportCards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
                $reportPlatform = strtolower((string)($openBroadcast['channel_type'] ?? $openBroadcast['bot_channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram';
                if (!empty($payload['platform']) && strtolower((string)$payload['platform']) === 'vk') $reportPlatform = 'vk';
                $reportStatus = (string)($openBroadcast['status'] ?? '');
                if ($openBroadcastGroupEnabled) {
                    $groupStatuses = array_values(array_unique(array_map(static fn($row) => (string)($row['status'] ?? ''), $openBroadcastGroupRows)));
                    $activeGroupStatuses = [];
                    foreach ($openBroadcastGroupRows as $groupRow) {
                        $groupStatus = (string)($groupRow['status'] ?? '');
                        $groupTotal = (int)($groupRow['total_recipients'] ?? 0);
                        // Пустой канал в мультиканальной рассылке - это пропуск, а не ошибка всей группы.
                        if ($groupTotal <= 0 && in_array($groupStatus, ['skipped', 'failed'], true)) continue;
                        $activeGroupStatuses[] = $groupStatus;
                    }
                    $activeGroupStatuses = array_values(array_unique($activeGroupStatuses));
                    if (in_array('processing', $activeGroupStatuses, true) || in_array('queued', $activeGroupStatuses, true) || in_array('sending', $activeGroupStatuses, true) || (int)($openBroadcastRecipientStats['left'] ?? 0) > 0) $reportStatus = 'processing';
                    elseif (in_array('scheduled', $activeGroupStatuses, true)) $reportStatus = 'scheduled';
                    elseif ($openBroadcastReportFailed > 0 || in_array('finished_with_errors', $activeGroupStatuses, true)) $reportStatus = 'finished_with_errors';
                    elseif (in_array('failed', $activeGroupStatuses, true)) $reportStatus = 'failed';
                    elseif ($activeGroupStatuses && count(array_diff($activeGroupStatuses, ['cancelled'])) === 0) $reportStatus = 'cancelled';
                    elseif (!$activeGroupStatuses && in_array('skipped', $groupStatuses, true)) $reportStatus = 'skipped';
                    else $reportStatus = 'finished';
                }
                $openBroadcastReportDone = (int)($openBroadcastRecipientStats['done'] ?? ($openBroadcastReportSent + $openBroadcastReportFailed));
                $openBroadcastReportLeft = max(0, (int)($openBroadcastRecipientStats['left'] ?? ($openBroadcastReportTotal - $openBroadcastReportDone)));
                $openBroadcastReportPercent = $openBroadcastReportTotal > 0 ? min(100, (int)round($openBroadcastReportDone * 100 / $openBroadcastReportTotal)) : 0;
                $openBroadcastLastError = trim((string)($openBroadcast['last_error'] ?? ''));
                if ($openBroadcastGroupEnabled) {
                    foreach ($openBroadcastGroupRows as $groupRow) {
                        $groupStatus = (string)($groupRow['status'] ?? '');
                        $groupFailed = (int)($groupRow['failed_count'] ?? 0);
                        if ($groupFailed <= 0 && !in_array($groupStatus, ['failed', 'finished_with_errors'], true)) continue;
                        $groupError = trim((string)($groupRow['last_error'] ?? ''));
                        if ($groupError !== '') { $openBroadcastLastError = $groupError; break; }
                    }
                }
            ?>
                <script>
                (function(){
                    try{
                        Object.keys(localStorage).forEach(function(key){
                            if(key.indexOf('tgBroadcastDraft:')===0) localStorage.removeItem(key);
                        });
                    }catch(e){}
                })();
                </script>
                <div class="mt-6 rounded-3xl border border-orange-100 bg-orange-50/40 p-5">
                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                        <div>
                            <div class="text-xs font-semibold text-orange-700">Отчёт по рассылке</div>
                            <h4 class="mt-1 text-xl font-semibold text-gray-800"><?php echo $h($openBroadcast['title']); ?></h4>
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-500">
                                <span><?php echo $h($openBroadcast['bot_title'] ?? ''); ?></span>
                                <span class="inline-flex rounded-full border px-3 py-1 <?php echo $h($broadcastStatusClass($reportStatus)); ?>" id="tgBroadcastReportStatus"><?php echo $h($broadcastStatusLabels[$reportStatus] ?? $reportStatus); ?></span>
                                <span>создана: <?php echo $h($broadcastFormatTime($openBroadcastReportTimes['created_at'] ?? ($openBroadcast['created_at'] ?? ''))); ?></span>
                            </div>
                            <?php if ($openBroadcastGroupEnabled): ?>
                                <div class="mt-3 rounded-2xl bg-white/75 border border-orange-100 px-4 py-3 text-xs font-semibold text-gray-600">
                                    Сводный отчёт по группе: <?php echo count($openBroadcastGroupRows); ?> канала(ов). Статистика ниже суммирует все созданные рассылки: <?php echo $h(implode(', ', $openBroadcastGroupBotTitles)); ?>.
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($openBroadcastSkippedRows)): ?>
                                <div class="mt-3 rounded-2xl bg-gray-50 border border-gray-100 px-4 py-3 text-xs font-semibold text-gray-600">
                                    Пропущено каналов без подходящих получателей: <?php echo count($openBroadcastSkippedRows); ?>. Это не ошибка отправки и не влияет на доставку по остальным каналам.
                                </div>
                            <?php endif; ?>
                            <?php if (in_array($reportStatus, ['queued','processing','sending'], true)): ?><p class="mt-2 text-[11px] font-semibold text-orange-700">Рассылка ещё в очереди. Отправка продолжается через cron-обработчик.</p><?php endif; ?>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?php echo $h($historyBackUrl); ?>" class="tb2-btn px-4 py-3 rounded-2xl bg-white text-gray-700 border border-gray-100 text-xs font-semibold"><img class="tb2-icon" src="/assets/admin/icons/tb2-back-gray.svg" alt="" aria-hidden="true">К истории</a>
                            <?php if (!empty($openBroadcastScheduledIds)): ?><form method="POST" onsubmit="return confirm('Отменить запланированную рассылку до старта?');"><input type="hidden" name="action" value="tg_broadcast_cancel_scheduled"><input type="hidden" name="return_page" value="broadcasts"><input type="hidden" name="bot_id" value="<?php echo (int)$openBroadcast['bot_id']; ?>"><input type="hidden" name="broadcast_id" value="<?php echo (int)$openBroadcast['id']; ?>"><input type="hidden" name="broadcast_ids" value="<?php echo $h(implode(',', $openBroadcastScheduledIds)); ?>"><input type="hidden" name="page_no" value="<?php echo (int)$historyPageNo; ?>"><input type="hidden" name="per_page" value="<?php echo (int)$historyPerPage; ?>"><?php foreach ($historySelectedBotIds as $historySelectedBotId): ?><input type="hidden" name="filter_bot_ids[]" value="<?php echo (int)$historySelectedBotId; ?>"><?php endforeach; ?><?php if ($historyDateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo $h($historyDateFrom); ?>"><?php endif; ?><?php if ($historyDateTo !== ''): ?><input type="hidden" name="date_to" value="<?php echo $h($historyDateTo); ?>"><?php endif; ?><button class="tb2-btn px-4 py-3 rounded-2xl bg-red-50 text-red-700 border border-red-100 text-xs font-semibold"><img class="tb2-icon" src="/assets/admin/icons/tb2-cancel-gray.svg" alt="" aria-hidden="true">Отменить</button></form><?php endif; ?>
                            <?php if (in_array((string)$openBroadcast['status'], ['queued','processing'], true)): ?><form method="POST" onsubmit="return confirm('Отменить оставшуюся очередь этой рассылки?');"><input type="hidden" name="action" value="tg_broadcast_cancel"><input type="hidden" name="return_page" value="broadcasts"><input type="hidden" name="bot_id" value="<?php echo (int)$openBroadcast['bot_id']; ?>"><input type="hidden" name="broadcast_id" value="<?php echo (int)$openBroadcast['id']; ?>"><button class="tb2-btn px-4 py-3 rounded-2xl bg-red-50 text-red-700 border border-red-100 text-xs font-semibold"><img class="tb2-icon" src="/assets/admin/icons/tb2-cancel-gray.svg" alt="" aria-hidden="true">Отменить</button></form><?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-2 lg:grid-cols-5 gap-3 text-center text-xs font-semibold">
                        <div class="rounded-2xl bg-white border border-gray-100 p-4">Получателей<?php echo $openBroadcastGroupEnabled ? ' всего' : ''; ?><br><span id="tgBroadcastReportTotal" class="text-xl text-gray-800"><?php echo (int)$openBroadcastReportTotal; ?></span></div>
                        <div class="rounded-2xl bg-green-50 border border-green-100 p-4">Отправлено<?php echo $openBroadcastGroupEnabled ? ' всего' : ''; ?><br><span id="tgBroadcastReportSent" class="text-xl text-green-700"><?php echo (int)$openBroadcastReportSent; ?></span></div>
                        <div class="rounded-2xl bg-red-50 border border-red-100 p-4">Ошибок<?php echo $openBroadcastGroupEnabled ? ' всего' : ''; ?><br><span id="tgBroadcastReportFailed" class="text-xl text-red-700"><?php echo (int)$openBroadcastReportFailed; ?></span></div>
                        <div class="rounded-2xl bg-orange-50 border border-orange-100 p-4">Осталось<br><span class="text-xl text-orange-700"><?php echo (int)$openBroadcastReportLeft; ?></span></div>
                        <div class="rounded-2xl bg-white border border-gray-100 p-4">Карточек<br><span class="text-xl text-gray-800"><?php echo max(1, count($reportCards)); ?></span></div>
                    </div>
                    <div class="mt-4 rounded-2xl bg-white/80 border border-orange-100 p-4">
                        <div class="flex justify-between text-xs font-semibold text-gray-500"><span>Прогресс</span><span><?php echo (int)$openBroadcastReportPercent; ?>%</span></div>
                        <div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-[#FFA048]" style="width:<?php echo (int)$openBroadcastReportPercent; ?>%"></div></div>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs font-semibold text-gray-500">
                            <div class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2">В очереди: <span class="text-gray-800"><?php echo (int)($openBroadcastRecipientStats['pending'] ?? 0); ?></span></div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2">В обработке: <span class="text-gray-800"><?php echo (int)($openBroadcastRecipientStats['processing'] ?? 0); ?></span></div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2">Начата: <span class="text-gray-800"><?php echo $h($broadcastFormatTime($openBroadcastReportTimes['started_at'] ?? '')); ?></span></div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2">Завершена: <span class="text-gray-800"><?php echo $h($broadcastFormatTime($openBroadcastReportTimes['finished_at'] ?? '')); ?></span></div>
                        </div>
                        <div class="mt-3 text-[11px] font-semibold text-gray-400">Фактическая отправка: <?php echo $h($broadcastFormatTime($openBroadcastReportTimes['first_sent_at'] ?? '')); ?> - <?php echo $h($broadcastFormatTime($openBroadcastReportTimes['last_sent_at'] ?? '')); ?></div>
                    </div>
                    <?php if (!empty($openBroadcastErrors) || $openBroadcastLastError !== ''): ?>
                    <div class="mt-5 rounded-2xl bg-red-50 border border-red-100 p-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <div class="text-[11px] font-semibold text-red-700">Ошибки отправки</div>
                                <div class="mt-1 text-xs font-semibold text-red-700/80">Показаны последние <?php echo count($openBroadcastErrors); ?> ошибок без вывода всей базы получателей.</div>
                            </div>
                            <div class="text-xs font-semibold text-red-700">Всего ошибок: <?php echo (int)$openBroadcastReportFailed; ?></div>
                        </div>
                        <?php if ($openBroadcastLastError !== ''): ?>
                            <div class="mt-3 rounded-xl bg-white/75 border border-red-100 px-3 py-2 text-sm font-semibold text-red-800 break-words">Последняя ошибка: <?php echo $h($openBroadcastLastError); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($openBroadcastErrors)): ?>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($openBroadcastErrors as $errRow): ?>
                                    <?php $errName = trim((string)($errRow['first_name'] ?? '') . ' ' . (string)($errRow['last_name'] ?? '')); if ($errName === '') $errName = (string)($errRow['username'] ?? ''); ?>
                                    <div class="rounded-xl bg-white/75 border border-red-100 px-3 py-2 text-xs text-red-900">
                                        <div class="font-semibold"><?php echo $h($errName !== '' ? $errName : ('Подписчик #' . (int)$errRow['subscriber_id'])); ?><?php if ($openBroadcastGroupEnabled && !empty($errRow['bot_title'])): ?> · <?php echo $h((string)$errRow['bot_title']); ?><?php endif; ?> · попыток: <?php echo (int)($errRow['attempts'] ?? 0); ?> · <?php echo $h($broadcastFormatTime($errRow['updated_at'] ?? '')); ?></div>
                                        <div class="mt-1 break-words"><?php echo $h((string)($errRow['last_error'] ?? 'Ошибка без текста')); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mt-5 rounded-2xl bg-white border border-gray-100 p-4">
                        <div class="text-[10px] font-semibold text-gray-400">Сообщение</div>
                        <?php if ($reportCards): foreach ($reportCards as $idx => $card): ?>
                            <?php
                                $reportCardType = (string)($card['type'] ?? 'text');
                                $reportMediaUrl = trim((string)($card['media_url'] ?? ''));
                                $reportMediaName = trim((string)($card['media_file_name'] ?? ''));
                                $reportMediaLabel = $reportMediaName !== '' ? $reportMediaName : ($reportMediaUrl !== '' ? $reportMediaUrl : 'Медиа');
                            ?>
                            <div class="mt-3 rounded-2xl bg-gray-50 border border-gray-100 p-4"><div class="text-xs font-semibold text-gray-500">Карточка <?php echo (int)$idx + 1; ?> · <?php echo $h($reportCardType); ?><?php if (($reportPlatform ?? 'telegram') === 'vk'): ?> · VK plain text<?php endif; ?></div><div class="mt-3 tg-report-phone <?php echo (($reportPlatform ?? 'telegram') === 'vk') ? 'is-vk' : ''; ?>"><div class="tg-report-bubble"><?php if ($reportMediaUrl !== ''): ?><div class="tg-report-media">Медиа: <a class="text-orange-600 underline" target="_blank" rel="noopener" href="<?php echo $h($reportMediaUrl); ?>"><?php echo $h($reportMediaLabel); ?></a></div><?php elseif ($reportMediaName !== ''): ?><div class="tg-report-media">Медиа: <?php echo $h($reportMediaName); ?></div><?php endif; ?><?php echo (($reportPlatform ?? 'telegram') === 'vk') ? $asrTgRenderVkReportText((string)($card['text'] ?? '')) : $asrTgRenderReportText((string)($card['text'] ?? '')); ?><?php echo $asrTgRenderReportButtons(is_array($card) ? $card : []); ?></div></div></div>
                        <?php endforeach; else: ?>
                            <div class="mt-3 tg-report-phone"><div class="tg-report-bubble"><?php echo $asrTgRenderReportText((string)($openBroadcast['message_text'] ?? '')); ?></div></div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$openBroadcast): ?>
            <div class="mt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs font-semibold text-gray-500">
                <div>Показано <?php echo (int)$historyFromRow; ?>-<?php echo (int)$historyToRow; ?> из <?php echo (int)$historyTotalRows; ?></div>
                <form method="GET" class="flex items-center gap-2">
                    <?php foreach ($historyBackParams as $key => $value): if (in_array($key, ['per_page', 'page_no'], true)) continue; ?>
                        <?php if (is_array($value)): foreach ($value as $item): ?><input type="hidden" name="<?php echo $h((string)$key); ?>[]" value="<?php echo $h((string)$item); ?>"><?php endforeach; else: ?><input type="hidden" name="<?php echo $h((string)$key); ?>" value="<?php echo $h((string)$value); ?>"><?php endif; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="page_no" value="1">
                    <label for="tgHistoryPerPage">На странице</label>
                    <select id="tgHistoryPerPage" name="per_page" onchange="this.form.submit()" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700">
                        <?php foreach ([10,25,50,100] as $pp): ?><option value="<?php echo (int)$pp; ?>" <?php echo $historyPerPage === $pp ? 'selected' : ''; ?>><?php echo (int)$pp; ?></option><?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-gray-100">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-500">
                        <tr>
                            <th class="text-left px-4 py-3">Рассылка</th>
                            <th class="text-left px-4 py-3">Канал</th>
                            <th class="text-left px-4 py-3">Статус</th>
                            <th class="text-left px-4 py-3">Прогресс</th>
                            <th class="text-left px-4 py-3">Создана</th>
                            <th class="text-left px-4 py-3">Отправлена</th>
                            <th class="text-right px-4 py-3">Действие</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <?php foreach ($rows as $row):
                            $total=(int)$row['total_recipients'];
                            $done=(int)$row['sent_count']+(int)$row['failed_count'];
                            $percent=$total>0 ? min(100, round($done*100/$total)) : 0;
                            $rowStatus = (string)($row['status'] ?? '');
                            $sentLabel = '—';
                            $sentValue = '—';
                            if ($rowStatus === 'scheduled') {
                                $sentLabel = 'Запланирована';
                                $sentValue = $broadcastFormatTime($row['scheduled_at'] ?? '');
                            } elseif ($rowStatus === 'cancelled') {
                                $sentLabel = 'Отменена';
                                $sentValue = $broadcastFormatTime($row['cancelled_at'] ?? '');
                            } elseif ($rowStatus === 'skipped') {
                                $sentLabel = 'Пропущена';
                                $sentValue = $broadcastFormatTime($row['finished_at'] ?? '');
                            } elseif (in_array($rowStatus, ['finished','finished_with_errors'], true)) {
                                $sentLabel = 'Отправлена';
                                $sentValue = $broadcastFormatTime($row['finished_at'] ?? ($row['queued_at'] ?? ''));
                            } elseif (in_array($rowStatus, ['queued','processing','sending'], true)) {
                                $sentLabel = 'В очереди';
                                $sentValue = $broadcastFormatTime($row['queued_at'] ?? ($row['scheduled_at'] ?? ''));
                            }
                            $rowScheduledGroupIds = $rowStatus === 'scheduled' ? asr_tg_broadcast_scheduled_group_ids($pdo, (int)$row['id']) : [];
                        ?>
                            <tr class="hover:bg-orange-50/40">
                                <td class="px-4 py-4"><div class="font-semibold text-gray-800"><?php echo $h($row['title']); ?></div><div class="mt-1 text-xs font-semibold text-gray-400">ID <?php echo (int)$row['id']; ?></div></td>
                                <td class="px-4 py-4 text-xs font-semibold text-gray-600"><?php echo $h($row['bot_title']); ?></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?php echo $h($broadcastStatusClass((string)$row['status'])); ?>"><?php echo $h($broadcastStatusLabels[(string)$row['status']] ?? $row['status']); ?></span></td>
                                <td class="px-4 py-4 min-w-[190px]"><div class="flex justify-between text-xs font-semibold text-gray-500"><span><?php echo $done; ?> / <?php echo $total; ?></span><span><?php echo $percent; ?>%</span></div><div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-[#FFA048]" style="width:<?php echo (int)$percent; ?>%"></div></div><div class="mt-1 text-[11px] font-semibold text-gray-400">Отпр. <?php echo (int)$row['sent_count']; ?> · Ошибки <?php echo (int)$row['failed_count']; ?></div></td>
                                <td class="px-4 py-4 text-xs font-semibold text-gray-500 whitespace-nowrap"><?php echo $h($row['created_at']); ?></td>
                                <td class="px-4 py-4 text-xs font-semibold text-gray-500 whitespace-nowrap"><div class="text-gray-700"><?php echo $h($sentValue); ?></div><div class="mt-1 text-[11px] text-gray-400"><?php echo $h($sentLabel); ?></div></td>
                                <td class="px-4 py-4 text-right">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                        <?php if ($rowStatus === 'scheduled'): ?>
                                            <form method="POST" onsubmit="return confirm('Отменить эту запланированную рассылку до старта?');" class="inline-flex">
                                                <input type="hidden" name="action" value="tg_broadcast_cancel_scheduled">
                                                <input type="hidden" name="return_page" value="broadcasts">
                                                <input type="hidden" name="bot_id" value="<?php echo (int)$row['bot_id']; ?>">
                                                <input type="hidden" name="broadcast_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="broadcast_ids" value="<?php echo $h(implode(',', $rowScheduledGroupIds)); ?>">
                                                <input type="hidden" name="page_no" value="<?php echo (int)$historyPageNo; ?>">
                                                <input type="hidden" name="per_page" value="<?php echo (int)$historyPerPage; ?>">
                                                <?php foreach ($historySelectedBotIds as $historySelectedBotId): ?><input type="hidden" name="filter_bot_ids[]" value="<?php echo (int)$historySelectedBotId; ?>"><?php endforeach; ?>
                                                <?php if ($historyDateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo $h($historyDateFrom); ?>"><?php endif; ?>
                                                <?php if ($historyDateTo !== ''): ?><input type="hidden" name="date_to" value="<?php echo $h($historyDateTo); ?>"><?php endif; ?>
                                                <button class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-red-50 text-red-700 border border-red-100 text-xs font-semibold hover:bg-red-100">Отменить</button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="<?php $reportParams = $historyBackParams; $reportParams['bot_id'] = (int)$row['bot_id']; $reportParams['broadcast_id'] = (int)$row['id']; echo $h('admin.php?' . http_build_query($reportParams)); ?>" class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-orange-50">Отчёт</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="7" class="px-4 py-6 text-sm font-semibold text-gray-500">Рассылок пока нет.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($historyTotalPages > 1): ?>
                <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs font-semibold text-gray-500">
                    <div>Страница <?php echo (int)$historyPageNo; ?> из <?php echo (int)$historyTotalPages; ?></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a class="inline-flex items-center justify-center px-4 py-2 rounded-xl border border-gray-100 bg-white text-gray-700 <?php echo $historyPageNo <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-orange-50'; ?>" href="<?php echo $h($historyBuildUrl(['page_no' => 1])); ?>">В начало</a>
                        <a class="inline-flex items-center justify-center px-4 py-2 rounded-xl border border-gray-100 bg-white text-gray-700 <?php echo $historyPageNo <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-orange-50'; ?>" href="<?php echo $h($historyBuildUrl(['page_no' => max(1, $historyPageNo - 1)])); ?>">Назад</a>
                        <?php
                            $pageStart = max(1, $historyPageNo - 2);
                            $pageEnd = min($historyTotalPages, $historyPageNo + 2);
                            if ($pageEnd - $pageStart < 4) {
                                $pageStart = max(1, min($pageStart, $pageEnd - 4));
                                $pageEnd = min($historyTotalPages, max($pageEnd, $pageStart + 4));
                            }
                        ?>
                        <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
                            <a class="inline-flex items-center justify-center min-w-10 px-3 py-2 rounded-xl border <?php echo $p === $historyPageNo ? 'border-orange-200 bg-orange-50 text-orange-700' : 'border-gray-100 bg-white text-gray-700 hover:bg-orange-50'; ?>" href="<?php echo $h($historyBuildUrl(['page_no' => $p])); ?>"><?php echo (int)$p; ?></a>
                        <?php endfor; ?>
                        <a class="inline-flex items-center justify-center px-4 py-2 rounded-xl border border-gray-100 bg-white text-gray-700 <?php echo $historyPageNo >= $historyTotalPages ? 'pointer-events-none opacity-40' : 'hover:bg-orange-50'; ?>" href="<?php echo $h($historyBuildUrl(['page_no' => min($historyTotalPages, $historyPageNo + 1)])); ?>">Вперёд</a>
                        <a class="inline-flex items-center justify-center px-4 py-2 rounded-xl border border-gray-100 bg-white text-gray-700 <?php echo $historyPageNo >= $historyTotalPages ? 'pointer-events-none opacity-40' : 'hover:bg-orange-50'; ?>" href="<?php echo $h($historyBuildUrl(['page_no' => $historyTotalPages])); ?>">В конец</a>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php if (!$openBroadcast): ?>
    <script>
    (function(){
        const picker = document.getElementById('tgHistoryBotPicker');
        const button = document.getElementById('tgHistoryBotButton');
        const panel = document.getElementById('tgHistoryBotPanel');
        const summary = document.getElementById('tgHistoryBotSummary');
        const all = document.getElementById('tgHistoryAllBots');
        if (!picker || !button || !panel || !summary || !all) return;
        const options = () => Array.from(panel.querySelectorAll('[data-history-bot-option]'));
        const selected = () => options().filter(cb => cb.checked);
        function update(){
            const opts = options();
            const checked = selected();
            all.checked = opts.length > 0 && checked.length === opts.length;
            all.indeterminate = checked.length > 0 && checked.length < opts.length;
            if (!checked.length) summary.textContent = 'Выберите каналы';
            else if (checked.length === opts.length) summary.textContent = 'Все каналы';
            else if (checked.length === 1) summary.textContent = checked[0].closest('.tg-channel-picker-item')?.querySelector('span:nth-of-type(1)')?.textContent?.trim() || '1 канал';
            else summary.textContent = 'Выбрано каналов: ' + checked.length;
        }
        function setAll(value){ options().forEach(cb => { cb.checked = !!value; }); update(); }
        button.addEventListener('click', function(e){
            e.preventDefault(); e.stopPropagation();
            const open = !picker.classList.contains('is-open');
            picker.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        panel.addEventListener('click', function(e){
            const item = e.target.closest('.tg-channel-picker-item');
            if (!item) return;
            e.preventDefault(); e.stopPropagation();
            if (item.classList.contains('tg-channel-picker-all')) { setAll(!all.checked || all.indeterminate); return; }
            const cb = item.querySelector('[data-history-bot-option]');
            if (cb) { cb.checked = !cb.checked; update(); }
        });
        document.addEventListener('click', function(){ picker.classList.remove('is-open'); button.setAttribute('aria-expanded','false'); });
        update();
    })();
    </script>
    <?php endif; ?>
    <?php return; endif; ?>

    <form method="POST" enctype="multipart/form-data" class="tg-broadcast-shell" id="tgBroadcastForm" data-upload-limit="<?php echo (int)$tgClientUploadLimit; ?>">
        <input type="hidden" name="action" value="tg_broadcast_send"><input type="hidden" name="return_page" value="broadcasts">
        <div class="tg-broadcast-top">
            <div class="tb2-title" style="display:flex;flex-direction:row;align-items:center;gap:12px;width:100%;"><img class="tb2-title-icon" style="width:24px;height:24px;min-width:24px;flex:0 0 24px;margin:0;display:inline-block;" src="/assets/admin/icons/tb2-broadcast-gray.svg" alt="" aria-hidden="true"><div class="tb2-title-text"><h3 class="text-2xl font-semibold text-gray-800">Новая рассылка</h3><p class="text-sm font-semibold text-gray-500 mt-1">Выбор подписчиков, карточки сообщения, медиа и очередь для больших баз.</p></div></div>
            <a href="admin.php?tab=telegram_bots&page=broadcasts&view=history&bot_id=<?php echo (int)$selectedBotId; ?>" class="tb2-btn px-4 py-3 rounded-2xl bg-gray-100 text-gray-700 text-xs font-semibold whitespace-nowrap"><img class="tb2-icon" src="/assets/admin/icons/tb2-history-gray.svg" alt="" aria-hidden="true">История рассылок</a>
        </div>
        <div class="tg-broadcast-steps">
            <button type="button" class="tg-step is-active" data-step-goto="1"><span class="tg-step-dot">1</span><span>Сегментация</span></button><span class="tg-step-line"></span>
            <button type="button" class="tg-step" data-step-goto="2"><span class="tg-step-dot">2</span><span>Сообщения</span></button><span class="tg-step-line"></span>
            <button type="button" class="tg-step" data-step-goto="3"><span class="tg-step-dot">3</span><span>Отправка</span></button>
        </div>
        <div class="tg-broadcast-grid">
            <div class="tg-broadcast-left">
                <?php if ($bots && $canBroadcast): ?>
                <div class="tg-step-panel is-active" data-step-panel="1">
                    <h4 class="text-lg font-semibold text-gray-800">Выберите канал</h4>
                    <p class="mt-2 text-sm font-semibold text-gray-500">Сначала выберите бота, от имени которого уйдёт рассылка.</p>
                    <div class="mt-6">
                        <label class="tg-form-label mb-0">Каналы</label>
                        <input type="hidden" name="bot_id" id="tgPrimaryBotId" value="<?php echo (int)$selectedBotId; ?>">
                        <div class="tg-channel-picker" id="tgBotPicker">
                            <button type="button" class="tg-channel-picker-button" id="tgBotDropdownButton" aria-haspopup="listbox" aria-expanded="false">
                                <span id="tgBotDropdownSummary">Все каналы</span>
                            </button>
                            <div class="tg-channel-picker-menu" id="tgBotDropdownPanel" role="listbox" aria-label="Каналы для рассылки">
                                <label class="tg-channel-picker-item tg-channel-picker-all">
                                    <input type="checkbox" id="tgAllBotsToggle">
                                    <span>Все каналы</span>
                                    <span class="tg-channel-picker-count"><?php echo count($activeBroadcastBots); ?></span>
                                </label>
                                <?php foreach ($activeBroadcastBots as $bot): ?>
                                    <?php
                                        $botPlatform = strtolower((string)($bot['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram';
                                        $botPlatformLabel = $botPlatform === 'vk' ? 'ВК' : 'TG';
                                        $botHandle = $botPlatform === 'vk'
                                            ? (trim((string)($bot['vk_screen_name'] ?? '')) !== '' ? 'vk.com/' . trim((string)$bot['vk_screen_name']) : '')
                                            : (trim((string)($bot['bot_username'] ?? '')) !== '' ? '@' . ltrim((string)$bot['bot_username'], '@') : '');
                                        $botLabel = trim((string)$bot['title']) . ($botHandle !== '' ? ' (' . $botHandle . ')' : '');
                                    ?>
                                    <label class="tg-channel-picker-item">
                                        <input type="checkbox" data-bot-option data-platform="<?php echo $h($botPlatform); ?>" value="<?php echo (int)$bot['id']; ?>">
                                        <span><span class="inline-flex px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-semibold mr-2"><?php echo $h($botPlatformLabel); ?></span><?php echo $h($botLabel); ?></span>
                                        <span class="tg-channel-picker-count"><?php echo (int)($bot['subscribers_count'] ?? 0); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <select name="bot_ids[]" id="tgBotMultiSelect" multiple class="tg-hidden-select" aria-hidden="true" tabindex="-1">
                            <?php foreach ($activeBroadcastBots as $bot): ?>
                                <?php
                                    $botPlatform = strtolower((string)($bot['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram';
                                    $botPlatformLabel = $botPlatform === 'vk' ? 'ВК' : 'TG';
                                    $botHandle = $botPlatform === 'vk'
                                        ? (trim((string)($bot['vk_screen_name'] ?? '')) !== '' ? 'vk.com/' . trim((string)$bot['vk_screen_name']) : '')
                                        : (trim((string)($bot['bot_username'] ?? '')) !== '' ? '@' . ltrim((string)$bot['bot_username'], '@') : '');
                                    $botLabel = trim((string)$bot['title']) . ($botHandle !== '' ? ' (' . $botHandle . ')' : '');
                                ?>
                                <option value="<?php echo (int)$bot['id']; ?>" data-label="<?php echo $h($botPlatformLabel . ' · ' . $botLabel); ?>" data-platform="<?php echo $h($botPlatform); ?>" data-count="<?php echo (int)($bot['subscribers_count'] ?? 0); ?>" selected><?php echo $h($botPlatformLabel . ' · ' . $botLabel); ?> · <?php echo (int)($bot['subscribers_count'] ?? 0); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="mt-5 ml-1 flex items-start gap-2 text-xs font-semibold text-gray-500 cursor-pointer max-w-xl">
                            <input type="checkbox" name="broadcast_allow_duplicates" value="1" id="tgAllowDuplicates" class="w-4 h-4 mt-0.5">
                            <span><span class="block text-gray-600">Отправить дубли, если человек подписан на несколько каналов</span><span class="block mt-1 text-[11px] text-gray-400">По умолчанию один пользователь получит сообщение только один раз в пределах выбранной платформы.</span></span>
                        </label>
                    </div>
                    <div class="mt-8 rounded-2xl bg-gray-50 border border-gray-100 p-5 self-start"><div class="text-3xl font-semibold text-gray-800"><span id="tgSubscribersCount"><?php echo (int)$selectedBotCount; ?></span></div><div class="mt-1 text-sm font-semibold text-gray-600">подписчиков получат рассылку</div><p class="mt-4 text-xs font-semibold text-gray-400">Число меняется после выбора каналов и условий сегментации справа.</p><div class="mt-4 text-xs font-semibold text-gray-500">Расчётное время отправки: <span id="tgSendEstimate">от начала</span></div></div>
                </div>
                <div class="tg-step-panel" data-step-panel="2">
                    <div><div class="text-lg font-semibold text-gray-800 mb-4">Добавить карточку</div><div class="flex flex-wrap gap-3"><button type="button" class="tg-card-type-btn" data-add-card="text"><img class="tg-ui-icon tg-ui-icon--module" src="/assets/admin/icons/tg2-text-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"> <span>Текст</span></button><button type="button" class="tg-card-type-btn" data-add-card="photo"><img class="tg-ui-icon tg-ui-icon--module" src="/assets/admin/icons/tg2-image-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"> <span>Картинка</span></button><button type="button" class="tg-card-type-btn" data-add-card="document"><img class="tg-ui-icon tg-ui-icon--module" src="/assets/admin/icons/tg2-file-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"> <span>Файл</span></button><button type="button" class="tg-card-type-btn" data-add-card="audio"><img class="tg-ui-icon tg-ui-icon--module" src="/assets/admin/icons/tg2-audio-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"> <span>Аудио</span></button><button type="button" class="tg-card-type-btn" data-add-card="video"><img class="tg-ui-icon tg-ui-icon--module" src="/assets/admin/icons/tg2-video-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"> <span>Видео</span></button></div></div>
                    <div id="tgCardsBox"></div>

                </div>
                <div class="tg-step-panel" data-step-panel="3">
                    <h4 class="text-lg font-semibold text-gray-800">Отправка</h4>
                    <div class="mt-6"><label class="tg-form-label">Название рассылки</label><input name="broadcast_title" class="tg-form-field" value="New Broadcast <?php echo date('M d H:i:s'); ?>"></div>
                    <div class="mt-6 space-y-4" id="tgSendModeBox">
                        <label class="flex items-center gap-3 font-semibold text-gray-700"><input type="radio" checked name="send_mode" value="now" class="w-5 h-5"> Отправить сейчас</label>
                        <label class="flex items-center gap-3 font-semibold text-gray-700"><input type="radio" name="send_mode" value="scheduled" class="w-5 h-5"> Запланировать</label>
                    </div>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 hidden" id="tgScheduleFields">
                        <label class="block"><span class="tg-form-label">Дата отправки</span><input type="date" name="scheduled_date" id="tgScheduledDate" class="tg-form-field"></label>
                        <label class="block"><span class="tg-form-label">Время отправки</span><input type="time" name="scheduled_time" id="tgScheduledTime" class="tg-form-field" value="10:00"></label>
                    </div>
                    <p class="mt-3 text-xs font-semibold text-gray-400 hidden" id="tgScheduleHint">Запланированную рассылку отправит cron после наступления указанного времени.</p>
                    <button type="submit" class="mt-7 tg-btn-green tb2-btn"><img class="tb2-icon" src="/assets/admin/icons/tb2-send-white.svg" alt="" aria-hidden="true">Отправить сейчас</button>
                </div>
                <?php else: ?><div class="rounded-2xl bg-gray-50 border border-dashed border-gray-200 p-5 text-sm font-semibold text-gray-500">Подключите хотя бы одного бота и проверьте права на рассылки.</div><?php endif; ?>
            </div>
            <div class="tg-broadcast-right">
                <div class="tg-step-side is-active" data-step-side="1">
                    <h4 class="text-lg font-semibold text-gray-800">Выберите подписчиков, используя условия</h4>
                    <p class="mt-2 text-sm font-semibold text-gray-500">Если условий нет, рассылка уйдёт всем активным подписчикам выбранного бота.</p>
                    <input type="hidden" name="segment_filter" value="" id="tgSegmentFilterJson">
                    <div class="tg-bc-filter">
                        <div id="tgConditionsBox" class="tg-bc-filter-conditions"></div>
                        <div class="relative mt-4">
                            <button type="button" class="inline-flex items-center gap-3 px-5 py-4 rounded-lg border border-orange-200 text-gray-700 font-semibold" id="tgAddConditionBtn"><img class="tb2-icon" src="/assets/admin/icons/tb2-plus-gray.svg" alt="" aria-hidden="true">Добавить условие</button>
                        </div>
                        <p class="mt-3 text-xs font-semibold text-gray-400">Условия работают как на странице «Подписчики»: можно смешивать «и / или», использовать теги, системные и настраиваемые поля.</p>
                    </div>
                </div>
                <div class="tg-step-side" data-step-side="2"><div class="tg-preview-phone"><div id="tgPreview"><div class="text-center text-sm font-semibold text-gray-600">Предпросмотр сообщения</div><p class="mt-3 text-xs font-semibold text-gray-400 text-center">Добавьте текст или медиа слева.</p></div></div></div>
                <div class="tg-step-side" data-step-side="3"><div class="rounded-3xl bg-gray-50 border border-gray-100 p-8 text-center"><h4 class="text-lg font-semibold text-gray-800">Проверьте перед отправкой</h4><p class="mt-3 text-sm font-semibold text-gray-500">Рассылка будет поставлена в очередь и отправлена выбранным подписчикам пачками.</p><div class="mt-5 text-sm font-semibold text-gray-600">Расчётное время отправки: <span id="tgSendEstimateFinal">от начала</span></div><div id="tgVkTestBox" class="mt-6 max-w-md mx-auto text-left" style="display:none;"><label class="tg-form-label">VK ID для теста</label><input type="text" name="vk_test_peer_id" id="tgVkTestPeerId" class="tg-form-field" inputmode="numeric" placeholder="Например, 123456789"><p class="mt-2 text-[11px] font-semibold text-gray-400 leading-relaxed">Чтобы тестовая рассылка пришла в VK, этот пользователь должен быть подписан на выбранный VK-канал и хотя бы раз написать в сообщения сообщества. Вставляйте числовой VK User ID / Peer ID.</p></div><button type="button" id="tgBroadcastTestSend" class="mt-6 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-orange-50 hover:text-[#c96f2b] transition"><img class="tb2-icon" src="/assets/admin/icons/sh2-send-gray.svg" alt="" aria-hidden="true"><span id="tgBroadcastTestSendLabel">Отправить тест в тех. бот</span></button><p class="mt-3 text-[11px] font-semibold text-gray-400" id="tgBroadcastTestHint">Сначала сообщение уйдёт сотрудникам, у которых подключён тестовый бот рассылок. Потом можно отправлять подписчикам.</p><div id="tgBroadcastTestResult" class="mt-3 text-xs font-semibold text-gray-500"></div></div></div>
            </div>
        </div>
        <div class="tg-broadcast-footer"><button type="button" class="tg-btn-ghost tb2-btn" data-step-prev><img class="tb2-icon" src="/assets/admin/icons/tb2-back-gray.svg" alt="" aria-hidden="true">Назад</button><div class="spacer"></div><button type="button" class="tg-btn-main tb2-btn" data-step-next>Вперёд<img class="tb2-icon" src="/assets/admin/icons/tb2-forward-white.svg" alt="" aria-hidden="true"></button></div>
    </form>



    <div class="tg-button-modal-backdrop" id="tgButtonModal" aria-hidden="true">
        <div class="tg-button-modal">
            <div class="tg-button-modal-head"><h4 class="text-lg font-semibold text-gray-800">Кнопка</h4><button type="button" class="text-2xl text-gray-700" data-button-modal-close><img class="tg-ui-icon tg-ui-icon--modal-close" src="/assets/admin/icons/tg2-close-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"></button></div>
            <div class="tg-button-modal-body space-y-4">
                <label class="block"><span class="tg-form-label">Название кнопки</span><input type="text" maxlength="64" class="tg-form-field" id="tgButtonText" placeholder="Название кнопки"><div class="mt-1 text-right text-xs font-semibold text-gray-400"><span id="tgButtonTextCount">0</span>/64</div></label>
                <input type="hidden" id="tgButtonType" value="url">
                <label class="block" id="tgButtonUrlWrap"><span class="tg-form-label">URL-адрес</span><input type="url" class="tg-form-field" id="tgButtonUrl" placeholder="https://"><p class="mt-2 text-xs font-semibold text-gray-400">Можно использовать переменные типа {{first_name}} или {{ref}}.</p></label>
                <div id="tgButtonActionWrap" class="hidden"></div>
            </div>
            <div class="tg-button-modal-foot"><button type="button" class="tg-button-danger hidden tb2-btn" id="tgButtonDelete"><img class="tb2-icon" src="/assets/admin/icons/tb2-delete-white.svg" alt="" aria-hidden="true">Удалить</button><button type="button" class="tg-button-muted" data-button-modal-close>Отмена</button><button type="button" class="tg-button-save tb2-btn" id="tgButtonSave"><img class="tb2-icon" src="/assets/admin/icons/tb2-save-white.svg" alt="" aria-hidden="true">Сохранить</button></div>
        </div>
    </div>



    <div class="tg-date-modal-backdrop" id="tgDateModal" aria-hidden="true">
        <div class="tg-date-modal">
            <div class="tg-date-modal-head"><h4 class="text-lg font-semibold text-gray-800">Дата</h4><button type="button" class="text-2xl text-gray-700" data-date-modal-close><img class="tg-ui-icon tg-ui-icon--modal-close" src="/assets/admin/icons/tg2-close-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true"></button></div>
            <div class="tg-date-modal-body space-y-4">
                <p class="text-sm font-semibold text-gray-500">Выделенный текст станет ссылкой на календарное событие в Telegram.</p>
                <label class="block"><span class="tg-form-label">Дата</span><input type="date" class="tg-form-field" id="tgDateValue"></label>
                <label class="block"><span class="tg-form-label">Время</span><input type="time" class="tg-form-field" id="tgTimeValue" value="10:00"></label>
            </div>
            <div class="tg-date-modal-foot"><button type="button" class="tg-button-muted" data-date-modal-close>Отмена</button><button type="button" class="tg-button-save" id="tgDateApply">Готово</button></div>
        </div>
    </div>

    <div class="tg-alert-backdrop" id="tgAlertModal" aria-hidden="true">
        <div class="tg-alert-modal">
            <h4 class="text-lg font-semibold text-gray-800">Нужно поправить</h4>
            <p class="mt-3 text-sm font-semibold text-gray-600" id="tgAlertText">Проверьте поля рассылки.</p>
            <div class="mt-6 flex justify-end"><button type="button" class="tg-button-save" id="tgAlertClose">Понятно</button></div>
        </div>
    </div>

    <div class="tg-alert-backdrop" id="tgDraftChoiceModal" aria-hidden="true">
        <div class="tg-alert-modal">
            <h4 class="text-lg font-semibold text-gray-800">Есть незавершённая рассылка</h4>
            <p class="mt-3 text-sm font-semibold text-gray-600">Продолжить подготовку с сохранёнными фильтрами и текстом или начать новую рассылку с чистого листа?</p>
            <div class="tg-draft-choice-actions">
                <button type="button" class="tg-draft-choice-muted" id="tgDraftStartNew">Начать сначала</button>
                <button type="button" class="tg-draft-choice-main" id="tgDraftContinue">Продолжить</button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const form=document.getElementById('tgBroadcastForm'); if(!form) return;
        let step=1, countTimer=null, buttonContext=null, dateContext=null;
        const panels=[...form.querySelectorAll('[data-step-panel]')], sides=[...form.querySelectorAll('[data-step-side]')], steps=[...form.querySelectorAll('[data-step-goto]')];
        const next=form.querySelector('[data-step-next]'), prev=form.querySelector('[data-step-prev]'), testSendBtn=document.getElementById('tgBroadcastTestSend'), testResult=document.getElementById('tgBroadcastTestResult'), testSendLabel=document.getElementById('tgBroadcastTestSendLabel'), testHint=document.getElementById('tgBroadcastTestHint'), vkTestBox=document.getElementById('tgVkTestBox'), vkTestPeerId=document.getElementById('tgVkTestPeerId'), cardsBox=document.getElementById('tgCardsBox'), preview=document.getElementById('tgPreview');
        const botSelect=document.getElementById('tgPrimaryBotId'), botMulti=document.getElementById('tgBotMultiSelect'), botPicker=document.getElementById('tgBotPicker'), botPickerButton=document.getElementById('tgBotDropdownButton'), botPickerPanel=document.getElementById('tgBotDropdownPanel'), botPickerSummary=document.getElementById('tgBotDropdownSummary'), allBotsToggle=document.getElementById('tgAllBotsToggle'), allowDuplicates=document.getElementById('tgAllowDuplicates'), cnt=document.getElementById('tgSubscribersCount'), estimate=document.getElementById('tgSendEstimate'), estimateFinal=document.getElementById('tgSendEstimateFinal');
        const addCondBtn=document.getElementById('tgAddConditionBtn'), condBox=document.getElementById('tgConditionsBox'), segmentFilterInput=document.getElementById('tgSegmentFilterJson');
        const segmentCatalog=<?php echo $broadcastSafeJson($broadcastSegmentCatalogForJs); ?>;
        const macroCatalog=<?php echo $broadcastSafeJson($broadcastMessageVariables); ?>;
        const segmentState={conditions:[]};
        const modal=document.getElementById('tgButtonModal'), btnText=document.getElementById('tgButtonText'), btnTextCount=document.getElementById('tgButtonTextCount'), btnType=document.getElementById('tgButtonType'), btnUrl=document.getElementById('tgButtonUrl'), btnUrlWrap=document.getElementById('tgButtonUrlWrap'), btnActionWrap=document.getElementById('tgButtonActionWrap'), btnSave=document.getElementById('tgButtonSave'), btnDelete=document.getElementById('tgButtonDelete');
        const dateModal=document.getElementById('tgDateModal'), dateValue=document.getElementById('tgDateValue'), timeValue=document.getElementById('tgTimeValue'), dateApply=document.getElementById('tgDateApply');
        const alertModal=document.getElementById('tgAlertModal'), alertText=document.getElementById('tgAlertText'), alertClose=document.getElementById('tgAlertClose');
        const draftChoiceModal=document.getElementById('tgDraftChoiceModal'), draftContinue=document.getElementById('tgDraftContinue'), draftStartNew=document.getElementById('tgDraftStartNew');
        const sendModeRadios=[...form.querySelectorAll('input[name="send_mode"]')], scheduleFields=document.getElementById('tgScheduleFields'), scheduleHint=document.getElementById('tgScheduleHint'), scheduledDate=document.getElementById('tgScheduledDate'), scheduledTime=document.getElementById('tgScheduledTime');
        const uploadLimit=Number(form.dataset.uploadLimit||0)||45*1024*1024;
        const urlParams=new URLSearchParams(location.search);
        const initialStep=Math.max(1,Math.min(3,Number(urlParams.get('step')||1)||1));
        const hasOpenReport=urlParams.has('broadcast_id');
        let restoringDraft=false;
        function botOptions(){return botMulti?[...botMulti.options]:[];}
        function botCheckboxes(){return botPickerPanel?[...botPickerPanel.querySelectorAll('[data-bot-option]')]:[];}
        function selectedBotOptions(){return botOptions().filter(opt=>opt.selected);}
        function selectedBotIds(){return selectedBotOptions().map(opt=>opt.value);}
        function selectedPlatforms(){return [...new Set(selectedBotOptions().map(opt=>(opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram'))];}
        function selectedPlatform(){const p=selectedPlatforms(); return p.length===1?p[0]:(p.length>1?'mixed':'');}
        function selectedPlatformStrict(){
            const p=selectedPlatforms();
            return p.length===1?p[0]:'';
        }
        function normalizePlatformSelection(){
            const selected=selectedBotOptions();
            const platforms=[...new Set(selected.map(opt=>(opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram'))];
            if(platforms.length<=1) return;
            const primaryId=botSelect?.value||selected[0]?.value||'';
            const primaryOpt=botOptions().find(opt=>String(opt.value)===String(primaryId)&&opt.selected)||selected[0];
            const keepPlatform=(primaryOpt?.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
            botOptions().forEach(opt=>{
                const platform=(opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
                if(platform!==keepPlatform) opt.selected=false;
            });
        }
        function currentLockPlatform(){return selectedPlatformStrict();}
        function updatePlatformLocks(){
            const locked=currentLockPlatform();
            botCheckboxes().forEach(cb=>{
                const platform=(cb.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
                const disabled=!!locked && platform!==locked;
                cb.disabled=disabled;
                const item=cb.closest('.tg-channel-picker-item');
                if(item) item.classList.toggle('is-disabled', disabled);
                if(disabled) cb.checked=false;
            });
        }
        function hasVkSelected(){return selectedPlatform()==='vk';}
        function updateVkEditorMode(){
            const isVk=hasVkSelected();
            form.classList.toggle('tg-vk-broadcast-mode', isVk);
            form.querySelectorAll('[data-add-card]').forEach(btn=>{
                const t=btn.dataset.addCard||'text';
                const disabled=isVk && !['text','photo','document'].includes(t);
                btn.disabled=disabled;
                btn.style.opacity=disabled?'.45':'';
                btn.style.cursor=disabled?'not-allowed':'';
                if(!btn.dataset.originalTitle) btn.dataset.originalTitle=btn.getAttribute('title')||'';
                btn.setAttribute('title', disabled?'Для VK сейчас доступны текст, картинки и файлы. Видео лучше отправлять обложкой и кнопкой-ссылкой.':btn.dataset.originalTitle);
            });
            form.querySelectorAll('[data-card]').forEach(card=>{
                card.querySelectorAll('.tg-card-toolbar button[data-format], .tg-card-toolbar button[data-wrap], .tg-card-toolbar button[data-link]').forEach(btn=>{
                    if(!btn.dataset.originalTitle) btn.dataset.originalTitle=btn.getAttribute('title')||'';
                    btn.disabled=!!isVk;
                    btn.setAttribute('title', isVk ? 'VK не поддерживает форматирование текста в сообщениях' : btn.dataset.originalTitle);
                });
                card.querySelectorAll('[data-card-disable-preview],[data-card-protect-content]').forEach(input=>{
                    const row=input.closest('label');
                    if(row) row.style.display=isVk?'none':'';
                });
                const notice=card.querySelector('[data-vk-plain-notice]');
                if(notice) notice.style.display=isVk?'block':'none';
            });
        }
        function updateVkBroadcastNotice(){const isVk=hasVkSelected(); const notice=document.getElementById('tgVkBroadcastNotice'); const label=document.getElementById('tgBroadcastTestSendLabel'); if(notice) notice.style.display=isVk?'block':'none'; if(vkTestBox) vkTestBox.style.display=isVk?'block':'none'; if(label) label.textContent=isVk?'Отправить тест в VK':'Отправить тест в тех. бот'; if(testHint) testHint.textContent=isVk?'Тест уйдёт указанному VK-пользователю через выбранный VK-канал. Пользователь должен быть подписан на этот канал и уже написать в сообщения сообщества.':'Сначала сообщение уйдёт сотрудникам, у которых подключён тестовый бот рассылок. Потом можно отправлять подписчикам.'; updateVkEditorMode();}
        function setBotSelected(id, selected){
            const opt=botOptions().find(o=>o.value===String(id));
            if(!opt) return;
            const platform=(opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
            if(selected){
                botOptions().forEach(o=>{
                    const p=(o.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
                    if(p!==platform) o.selected=false;
                });
            }
            opt.selected=!!selected;
            syncBotCheckboxesFromSelect();
        }
        function syncBotCheckboxesFromSelect(){
            normalizePlatformSelection();
            const ids=new Set(selectedBotIds());
            botCheckboxes().forEach(cb=>{cb.checked=ids.has(cb.value);});
            updatePlatformLocks();
            updateVkBroadcastNotice();
        }
        function updateBotPickerSummary(){if(!botPickerSummary)return; const opts=botOptions(); const selected=selectedBotOptions(); const platform=currentLockPlatform(); const platformOpts=platform?opts.filter(opt=>((opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram')===platform):opts; if(platformOpts.length>0 && selected.length===platformOpts.length){botPickerSummary.textContent=platform==='vk'?'Все VK-каналы':'Все TG-каналы'; return;} if(selected.length===0){botPickerSummary.textContent='Выберите каналы'; return;} if(selected.length===1){botPickerSummary.textContent=selected[0].dataset.label||selected[0].textContent.replace(/·.*$/,'').trim(); return;} botPickerSummary.textContent='Выбрано каналов: '+selected.length;}
        function syncSelectedBots(){syncBotCheckboxesFromSelect(); const ids=selectedBotIds(); if(botSelect) botSelect.value=ids[0]||''; if(allBotsToggle){const opts=botOptions(); const platform=currentLockPlatform(); const available=platform?opts.filter(opt=>((opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram')===platform):opts; allBotsToggle.checked=available.length>0 && available.every(opt=>opt.selected); allBotsToggle.indeterminate=ids.length>0 && available.some(opt=>opt.selected) && !allBotsToggle.checked;} updateBotPickerSummary(); updateVkBroadcastNotice();}
        function currentDraftKey(){syncSelectedBots(); return 'tgBroadcastDraft:v2:'+(selectedBotIds().join('-')||'0')+':dup'+(allowDuplicates?.checked?'1':'0');}
        const emojiGroups={
            'Smileys & People':['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🫢','🫣','🤫','🤔','🫡','🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓','🧐','😕','🫤','😟','🙁','☹️','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👻','👽','🤖','🎃'],
            'Hands':['👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','✍️','💅','💪'],
            'Objects':['🎯','🚀','✅','⭐','🔥','💡','📌','📎','💬','🎁','🏆','📣','📢','🔔','🔕','📅','🗓️','⏰','⌛','⏳','📍','🧭','💰','💳','📈','📉','📊','📝','📄','📚','🔐','🔑','⚙️','🧩','🛠️','🧰','🔗','✉️','📞','💻','📱','🖥️','🖨️','📷','🎥','🎧','🎤'],
            'Nature & Food':['🌱','🌿','🍀','🌵','🌲','🌳','🌴','🌸','🌹','🌺','🌻','🌞','🌝','🌙','⭐','🌈','☀️','⛅','☁️','⚡','❄️','🔥','💧','🌊','🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🍔','🍟','🍕','☕','🍵','🍰'],
            'Travel':['🚗','🚕','🚌','🚎','🏎️','🚓','🚑','🚒','🚚','🚛','🚜','🛵','🏍️','🚲','✈️','🚀','🚁','⛵','🚢','⚓','🏠','🏢','🏫','🏦','🏨','🏪','🏭','🏰','🗼','🗽','⛰️','🏖️','🏝️','🏜️','🌋','🗺️','🧳']
        };
        function setStep(n){step=Math.max(1,Math.min(3,n)); panels.forEach(p=>p.classList.toggle('is-active', Number(p.dataset.stepPanel)===step)); sides.forEach(p=>p.classList.toggle('is-active', Number(p.dataset.stepSide)===step)); steps.forEach(b=>{const v=Number(b.dataset.stepGoto); b.classList.toggle('is-active', v===step); b.classList.toggle('is-done', v<step);}); if(next){next.textContent='Вперёд'; next.style.visibility=step===3?'hidden':'visible';} if(prev) prev.style.visibility=step===1?'hidden':'visible'; if(step===2) updatePreview();}
        function currentSendMode(){return (form.querySelector('input[name="send_mode"]:checked')?.value||'now');}
        function updateScheduleVisibility(){const scheduled=currentSendMode()==='scheduled'; if(scheduleFields) scheduleFields.classList.toggle('hidden', !scheduled); if(scheduleHint) scheduleHint.classList.toggle('hidden', !scheduled);}
        sendModeRadios.forEach(r=>r.addEventListener('change', updateScheduleVisibility));
        updateScheduleVisibility();
        function validateSchedule(){if(currentSendMode()!=='scheduled')return ''; const d=(scheduledDate?.value||'').trim(); const t=(scheduledTime?.value||'').trim(); if(!d||!t)return 'Укажите дату и время запланированной рассылки.'; const dt=new Date(d+'T'+t+':00'); if(Number.isNaN(dt.getTime()))return 'Дата или время запланированной рассылки указаны некорректно.'; if(dt.getTime()<=Date.now())return 'Нельзя запланировать рассылку на прошедшее время.'; return '';}
        function esc(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
        function showAlert(message){if(!alertModal){alert(message);return;} alertText.textContent=message; alertModal.classList.add('is-open');}
        function validatePublicHttpUrl(url){url=(url||'').trim(); if(!url)return 'Укажите ссылку.'; if(/[\x00-\x20\x7F]/.test(url))return 'Ссылка не должна содержать пробелы. Вставьте полный адрес без пробелов, например https://example.kz/page'; let u; try{u=new URL(url);}catch(e){return 'Некорректная ссылка. Вставьте полный адрес, например https://example.kz/page';} if(!['http:','https:'].includes(u.protocol))return 'Ссылка должна начинаться с http:// или https://'; const host=(u.hostname||'').trim(); if(!host)return 'Ссылка должна содержать домен, например https://example.kz/page'; const isIp=/^\d{1,3}(?:\.\d{1,3}){3}$/.test(host)||host.includes(':'); if(!isIp){if(host.includes('_'))return 'В домене ссылки нельзя использовать подчёркивание.'; if(!host.includes('.'))return 'Telegram не принимает такой адрес. Укажите полноценный домен, например https://example.kz/page'; const bad=host.split('.').some(part=>!part||part.length>63||!/^[a-z0-9-]+$/i.test(part)||part.startsWith('-')||part.endsWith('-')); if(bad)return 'Некорректный домен в ссылке. Проверьте адрес.';} return '';}
        function hideAlert(){alertModal?.classList.remove('is-open');}
        alertClose?.addEventListener('click',hideAlert); alertModal?.addEventListener('click',e=>{if(e.target===alertModal)hideAlert();});
        function formatBytes(bytes){bytes=Number(bytes)||0; if(bytes>=1024*1024) return (bytes/1024/1024).toFixed(bytes>=10*1024*1024?0:1)+' МБ'; if(bytes>=1024) return Math.round(bytes/1024)+' КБ'; return bytes+' Б';}
        function updateUploadHints(){form.querySelectorAll('[data-upload-hint]').forEach(el=>{el.textContent='Лимит загрузки на сервере: до '+formatBytes(uploadLimit)+'. Если файл больше — вставьте ссылку на медиа.';});}
        function selectedFiles(){return [...form.querySelectorAll('[data-media-file]')].map(inp=>inp.files&&inp.files[0]?inp.files[0]:null).filter(Boolean);}
        function validateUploadSizes(){const files=selectedFiles(); for(const file of files){if(file.size>uploadLimit){return 'Файл «'+file.name+'» весит '+formatBytes(file.size)+'. Сейчас сервер принимает файл до '+formatBytes(uploadLimit)+'. Текст и настройки сохранены как черновик; выберите файл меньше или вставьте ссылку на медиа.';}} const total=files.reduce((sum,f)=>sum+f.size,0); if(total&&total>uploadLimit){return 'Общий размер файлов '+formatBytes(total)+' больше лимита сервера '+formatBytes(uploadLimit)+'. Текст и настройки сохранены как черновик; отправляйте медиа ссылкой или увеличим лимит на сервере.';} return '';}
        function mediaAccept(type){return {photo:'image/*',video:'video/mp4,video/quicktime,video/webm',audio:'audio/*',document:'.pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/*,video/*,audio/*'}[type]||'';}
        function cardTitle(type){return {text:'Текст',photo:'Картинка',document:'Файл',audio:'Аудио',video:'Видео'}[type]||'Текст';}
        function serializeDraft(){syncSegmentInput(); const cards=[...cardsBox.querySelectorAll('[data-card]')].map(card=>{syncCard(card);return {type:card.querySelector('input[name="card_type[]"]')?.value||'text',text:card.querySelector('textarea[name="card_text[]"]')?.value||'',media_url:card.querySelector('[data-media-url]')?.value||'',disable_preview:!!card.querySelector('[data-card-disable-preview]')?.checked,protect_content:!!card.querySelector('[data-card-protect-content]')?.checked,buttons_json:card.querySelector('input[name="card_buttons_json[]"]')?.value||'[]'};}); return {bot_id:botSelect?.value||'',bot_ids:selectedBotIds(),allow_duplicates:!!allowDuplicates?.checked,title:form.querySelector('input[name="broadcast_title"]')?.value||'',segment:segmentState.conditions,cards,updated_at:Date.now()};}
        function isMeaningfulDraft(data){
            if(!data||!Array.isArray(data.cards))return false;
            if(Array.isArray(data.segment)&&data.segment.length>0)return true;
            return data.cards.some(card=>{
                const text=String(card?.text||'').replace(/<[^>]+>/g,'').trim();
                const media=String(card?.media_url||'').trim();
                let buttons=[]; try{buttons=JSON.parse(card?.buttons_json||'[]');}catch(e){}
                return text!==''||media!==''||(Array.isArray(buttons)&&buttons.length>0)||card?.type&&card.type!=='text';
            });
        }
        function hasDraft(){try{const raw=localStorage.getItem(currentDraftKey())||''; if(!raw)return false; const data=JSON.parse(raw); return isMeaningfulDraft(data);}catch(e){return false;}}
        function removeCurrentDraft(){try{localStorage.removeItem(currentDraftKey());}catch(e){}}
        function showDraftChoice(){draftChoiceModal?.classList.add('is-open');}
        function hideDraftChoice(){draftChoiceModal?.classList.remove('is-open');}
        function saveDraft(){if(restoringDraft||hasOpenReport)return; try{const data=serializeDraft(); if(isMeaningfulDraft(data)) localStorage.setItem(currentDraftKey(),JSON.stringify(data)); else localStorage.removeItem(currentDraftKey());}catch(e){} }
        function restoreDraft(){if(hasOpenReport)return false; let raw=''; try{raw=localStorage.getItem(currentDraftKey())||'';}catch(e){} if(!raw)return false; let data=null; try{data=JSON.parse(raw);}catch(e){return false;} if(!data||!Array.isArray(data.cards)||!data.cards.length)return false; restoringDraft=true; cardsBox.innerHTML=''; if(Array.isArray(data.segment)){segmentState.conditions=data.segment.map(c=>({field:String(c.field||''),op:String(c.op||''),value:String(c.value||''),value2:String(c.value2||''),joiner:String(c.joiner||'and')==='or'?'or':'and'})).filter(c=>segmentCatalog.some(f=>f.key===c.field)); renderSegmentConditions();} data.cards.forEach(item=>{addCard(item.type||'text'); const card=cardsBox.lastElementChild; const editor=card?.querySelector('[data-editor]'); if(editor) editor.innerHTML=item.text||''; const ta=card?.querySelector('textarea[name="card_text[]"]'); if(ta) ta.value=item.text||''; const url=card?.querySelector('[data-media-url]'); if(url) url.value=item.media_url||''; const dp=card?.querySelector('[data-card-disable-preview]'); if(dp) dp.checked=!!item.disable_preview; const pc=card?.querySelector('[data-card-protect-content]'); if(pc) pc.checked=!!item.protect_content; const bj=card?.querySelector('input[name="card_buttons_json[]"]'); if(bj) bj.value=item.buttons_json||'[]'; renderButtons(card);}); const title=form.querySelector('input[name="broadcast_title"]'); if(title&&data.title) title.value=data.title; restoringDraft=false; updateUploadHints(); updatePreview(); return true;}
        function segmentField(key){return segmentCatalog.find(f=>f.key===key)||segmentCatalog[0]||null;}
        function segmentOp(field, op){const ops=(field&&Array.isArray(field.ops))?field.ops:[]; return ops.find(o=>o[0]===op)||ops[0]||['contains','Содержит'];}
        function segmentNeedsValue(op){return !['known','unknown','has_value','empty'].includes(op);}
        function segmentNeedsValue2(op){return op==='between';}
        function segmentOptionLabel(field, value){const opts=(field&&Array.isArray(field.options))?field.options:[]; const found=opts.find(o=>String(o.value)===String(value)); return found?found.label:value;}
        function segmentSummary(condition, index){const field=segmentField(condition.field); if(!field)return 'Некорректное условие'; const op=segmentOp(field, condition.op); let text=field.title+' · '+op[1]; if(segmentNeedsValue(op[0])){text+=' · '+(condition.value?segmentOptionLabel(field, condition.value):'укажите значение');} if(segmentNeedsValue2(op[0])){text+=' - '+(condition.value2?segmentOptionLabel(field, condition.value2):'укажите второе значение');} return text;}
        function syncSegmentInput(){if(!segmentFilterInput)return; const normalized=segmentState.conditions.map((c,i)=>({field:c.field,op:c.op,value:c.value||'',value2:c.value2||'',joiner:i>0&&c.joiner==='or'?'or':'and'})); segmentFilterInput.value=JSON.stringify({conditions:normalized});}
        function validateSegmentCondition(condition){const field=segmentField(condition.field); if(!field)return false; const op=segmentOp(field, condition.op)[0]; if(segmentNeedsValue(op)&&String(condition.value||'').trim()==='')return false; if(segmentNeedsValue2(op)&&String(condition.value2||'').trim()==='')return false; return true;}
        function invalidSegmentCount(){return segmentState.conditions.filter(c=>!validateSegmentCondition(c)).length;}
        function updateSegmentCondition(index, patch){if(!segmentState.conditions[index])return; segmentState.conditions[index]=Object.assign({},segmentState.conditions[index],patch); renderSegmentConditions(); scheduleCount(); saveDraft();}
        function refreshSegmentConditionView(index, div){const condition=segmentState.conditions[index]; if(!condition||!div)return; div.classList.toggle('is-invalid', !validateSegmentCondition(condition)); const chipText=div.querySelector('.tg-bc-filter-chip-text'); if(chipText)chipText.textContent=segmentSummary(condition,index); syncSegmentInput(); scheduleCount(); saveDraft();}
        function updateSegmentConditionValue(index, key, value, div){if(!segmentState.conditions[index])return; segmentState.conditions[index]=Object.assign({},segmentState.conditions[index],{[key]:value}); refreshSegmentConditionView(index, div);}
        function addSegmentCondition(fieldKey){const field=segmentField(fieldKey)||segmentCatalog[0]; if(!field)return; const op=(field.ops&&field.ops[0]?field.ops[0][0]:'contains'); segmentState.conditions.push({field:field.key,op:op,value:'',value2:'',joiner:segmentState.conditions.length?'and':'and'}); renderSegmentConditions(); const items=condBox?condBox.querySelectorAll('.tg-bc-filter-condition'):[]; const last=items&&items.length?items[items.length-1]:null; if(last)last.classList.add('is-open'); scheduleCount(); saveDraft();}
        function removeSegmentCondition(index){segmentState.conditions.splice(index,1); renderSegmentConditions(); scheduleCount(); saveDraft();}
        function valueInputHtml(field, condition, valueKey){const val=esc(condition[valueKey]||''); const name=valueKey==='value2'?'value2':'value'; if(field.type==='enum'||field.type==='tag'){const opts=(field.options||[]).map(o=>`<option value="${esc(o.value)}" ${String(o.value)===String(condition[name]||'')?'selected':''}>${esc(o.label)}</option>`).join(''); return `<select data-segment-${name}><option value="">Выберите</option>${opts}</select>`;} if(field.type==='number')return `<input type="number" step="any" value="${val}" data-segment-${name}>`; if(field.type==='date')return `<input type="date" value="${val}" data-segment-${name}>`; if(field.type==='datetime')return `<input type="datetime-local" value="${val}" data-segment-${name}>`; return `<input type="text" value="${val}" data-segment-${name} placeholder="Значение">`;}
        function renderSegmentConditions(){if(!condBox)return; condBox.innerHTML=''; segmentState.conditions.forEach((condition,index)=>{const field=segmentField(condition.field); const invalid=!validateSegmentCondition(condition); const div=document.createElement('div'); div.className='tg-bc-filter-condition'+(invalid?' is-invalid':''); const joiner=index>0?`<button type="button" class="tg-bc-filter-joiner ${condition.joiner==='or'?'is-or':''}" data-segment-joiner>${condition.joiner==='or'?'или':'и'}</button>`:''; const fieldOptions=segmentCatalog.map(f=>`<option value="${esc(f.key)}" ${f.key===condition.field?'selected':''}>${esc(f.title)}</option>`).join(''); const currentOp=segmentOp(field,condition.op)[0]; const opOptions=((field&&field.ops)||[]).map(o=>`<option value="${esc(o[0])}" ${o[0]===currentOp?'selected':''}>${esc(o[1])}</option>`).join(''); const values=segmentNeedsValue(currentOp)?`<div class="tg-bc-filter-popover-row"><div class="tg-bc-filter-popover-label">Значение</div><div class="tg-bc-filter-value-box">${valueInputHtml(field,condition,'value')}${segmentNeedsValue2(currentOp)?valueInputHtml(field,condition,'value2'):''}</div></div>`:''; div.innerHTML=`${joiner}<button type="button" class="tg-bc-filter-chip"><span class="tg-bc-filter-chip-text">${esc(segmentSummary(condition,index))}</span></button><button type="button" class="tg-bc-filter-remove" title="Удалить">×</button><div class="tg-bc-filter-popover"><div class="tg-bc-filter-popover-row"><div class="tg-bc-filter-popover-label">Поле</div><select data-segment-field>${fieldOptions}</select></div><div class="tg-bc-filter-popover-row"><div class="tg-bc-filter-popover-label">Условие</div><select data-segment-op>${opOptions}</select></div>${values}<div class="tg-bc-filter-warning">Заполните условие или удалите его.</div><div class="tg-bc-filter-popover-actions"><button type="button" class="tg-bc-filter-done">Готово</button></div></div>`; condBox.appendChild(div); div.querySelector('.tg-bc-filter-chip')?.addEventListener('click',e=>{e.stopPropagation(); condBox.querySelectorAll('.tg-bc-filter-condition.is-open').forEach(x=>{if(x!==div)x.classList.remove('is-open');}); div.classList.toggle('is-open');}); div.querySelector('.tg-bc-filter-done')?.addEventListener('click',()=>div.classList.remove('is-open')); div.querySelector('.tg-bc-filter-remove')?.addEventListener('click',()=>removeSegmentCondition(index)); div.querySelector('[data-segment-joiner]')?.addEventListener('click',()=>updateSegmentCondition(index,{joiner:condition.joiner==='or'?'and':'or'})); div.querySelector('[data-segment-field]')?.addEventListener('change',e=>{const f=segmentField(e.target.value); updateSegmentCondition(index,{field:f.key,op:(f.ops&&f.ops[0]?f.ops[0][0]:'contains'),value:'',value2:''});}); div.querySelector('[data-segment-op]')?.addEventListener('change',e=>updateSegmentCondition(index,{op:e.target.value,value:'',value2:''})); div.querySelector('[data-segment-value]')?.addEventListener('input',e=>updateSegmentConditionValue(index,'value',e.target.value,div)); div.querySelector('[data-segment-value]')?.addEventListener('change',e=>updateSegmentConditionValue(index,'value',e.target.value,div)); div.querySelector('[data-segment-value2]')?.addEventListener('input',e=>updateSegmentConditionValue(index,'value2',e.target.value,div)); div.querySelector('[data-segment-value2]')?.addEventListener('change',e=>updateSegmentConditionValue(index,'value2',e.target.value,div)); }); syncSegmentInput();}
        function updateEstimate(n){const minutes=Math.max(1, Math.ceil((Number(n)||0)/30)); const text=minutes<=1?'примерно 1 минута':`примерно ${minutes} мин.`; if(estimate) estimate.textContent=text; if(estimateFinal) estimateFinal.textContent=text;}
        function fallbackSelectedCount(){return selectedBotOptions().reduce((sum,opt)=>sum+(Number(opt.dataset.count)||0),0);}
        function updateCount(n){const fallback=fallbackSelectedCount(); const val=Number(n ?? fallback) || 0; if(cnt) cnt.textContent=val; updateEstimate(val);}

function adminIcon(name){
    const map={
        'bold':'bold','italic':'italic','underline':'underline','strike':'strikethrough','strikethrough':'strikethrough',
        'mono':'mono','code':'code','spoiler':'eye-off','quote':'quote','event-date':'date','date':'date','link':'link',
        'emoji':'emoji','variables':'variables','clear':'clear','delete':'delete','arrow-up':'arrow-up','arrow-down':'arrow-down',
        'text':'text','image':'image','file':'file','audio':'audio','video':'video','user':'user','username':'at','at':'at',
        'user-plus':'user-plus','dialog':'chat','chat':'chat','language':'globe','globe':'globe','button':'button','close':'close'
    };
    const file=map[name]||name;
    return '<img class="tg-ui-icon tg-ui-icon--toolbar" src="/assets/admin/icons/tg2-'+file+'-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true">';
}
        function scheduleCount(){syncSelectedBots(); syncSegmentInput(); clearTimeout(countTimer); if(invalidSegmentCount()>0){if(cnt)cnt.textContent='—'; if(estimate)estimate.textContent='после заполнения условий'; if(estimateFinal)estimateFinal.textContent='после заполнения условий'; return;} countTimer=setTimeout(()=>{const fd=new FormData(form); fd.set('action','tg_broadcast_count_segment'); fetch('admin.php?tab=telegram_bots&page=broadcasts',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{if(j&&j.ok) updateCount(j.count); else updateCount();}).catch(()=>updateCount());},250);}
        function emojiHtml(){return Object.entries(emojiGroups).map(([title,items])=>`<div class="tg-emoji-section"><div class="tg-emoji-title">${esc(title)}</div><div class="tg-emoji-grid">${items.map(e=>`<button type="button" data-emoji-insert="${e}">${e}</button>`).join('')}</div></div>`).join('');}
        function macroMenuHtml(){
            const grouped={};
            (macroCatalog||[]).forEach(item=>{const group=item.group||'Переменные'; (grouped[group]=grouped[group]||[]).push(item);});
            let html='<input class="tg-macro-search" placeholder="Найти переменную" data-macro-search>';
            Object.keys(grouped).forEach(group=>{
                html+='<div class="tg-macro-group">'+esc(group)+'</div>';
                grouped[group].forEach(item=>{
                    const token=String(item.token||''); if(!token) return;
                    html+='<button type="button" class="tg-macro-item" data-macro-insert="'+esc(token)+'" data-macro-search-text="'+esc((item.title||'')+' '+token+' '+group)+'"><span>'+esc(item.icon||'Т')+'</span><span>'+esc(item.title||token)+'</span><span class="tg-macro-token">'+esc(token)+'</span></button>';
                });
            });
            return html;
        }
        function editorTemplate(type){const limit=type==='text'?4096:1024; const placeholder=type==='text'?'Текст сообщения':'Подпись к медиа'; return `<div class="tg-card-toolbar"><button type="button" title="Жирный" data-format="bold">${adminIcon('bold')}</button><button type="button" title="Курсив" data-format="italic">${adminIcon('italic')}</button><button type="button" title="Подчёркнутый" data-format="underline">${adminIcon('underline')}</button><button type="button" title="Зачёркнутый" data-format="strikeThrough">${adminIcon('strike')}</button><button type="button" title="Моноширинный текст" data-wrap="<code>|</code>">${adminIcon('mono')}</button><button type="button" title="Скрытый текст" data-wrap="<tg-spoiler>|</tg-spoiler>">${adminIcon('spoiler')}</button><button type="button" title="Код" data-wrap="<pre>|</pre>">${adminIcon('code')}</button><button type="button" title="Цитата" data-wrap="<blockquote>|</blockquote>">${adminIcon('quote')}</button><button type="button" title="Дата" data-date>${adminIcon('event-date')}</button><span class="tg-toolbar-sep"></span><button type="button" title="Ссылка" data-link>${adminIcon('link')}</button></div><div class="tg-editor-wrap"><div class="tg-card-editor" contenteditable="true" data-editor data-placeholder="${placeholder}" spellcheck="true"></div><textarea name="card_text[]" class="tg-card-textarea" maxlength="${limit}"></textarea><input type="hidden" name="card_buttons_json[]" value="[]" data-buttons-json><div class="tg-card-bottom"><div class="relative"><button type="button" class="tg-mini-btn" data-emoji>${adminIcon('emoji')}</button><button type="button" class="tg-mini-btn" data-macro>${adminIcon('variables')}</button><button type="button" class="tg-mini-btn" data-clear-format>${adminIcon('clear')}</button><div class="tg-emoji-menu">${emojiHtml()}</div><div class="tg-macro-menu">${macroMenuHtml()}</div></div><div class="text-xs font-bold text-gray-400"><span data-char-count>0</span> / ${limit}</div></div></div><div class="mt-4 space-y-3"><label class="flex items-center gap-3 font-semibold text-gray-700"><input type="checkbox" name="card_disable_preview[]" value="0" data-card-disable-preview class="w-5 h-5"> Отключить предпросмотр ссылок <span class="tg-help" data-help="При включении к сообщению не будут прикреплены иллюстрации ссылок из текста сообщения, в том числе обложка статьи.">?</span></label><label class="flex items-center gap-3 font-semibold text-gray-700"><input type="checkbox" name="card_protect_content[]" value="0" data-card-protect-content class="w-5 h-5"> Защищать контент <span class="tg-help" data-help="Защищает содержимое отправленного сообщения от пересылки и сохранения.">?</span></label></div><div class="mt-5 tg-message-buttons" data-buttons-box></div><div class="mt-3 flex justify-end"><button type="button" class="tg-btn-ghost" data-add-button>+ Добавить кнопку</button></div>`;}
        function addCard(type='text'){const div=document.createElement('div');div.className='tg-message-card';div.dataset.card='1';div.innerHTML=`<input type="hidden" name="card_type[]" value="${type}"><input type="hidden" name="card_parse_mode[]" value="HTML"><div class="flex items-center justify-between gap-3"><h5 class="text-lg font-semibold text-gray-800">${cardTitle(type)}</h5><div class="flex gap-2"><button type="button" class="text-gray-400 font-semibold" data-card-up>${adminIcon('arrow-up')}</button><button type="button" class="text-gray-400 font-semibold" data-card-down>${adminIcon('arrow-down')}</button><button type="button" class="text-gray-400 font-semibold" data-card-delete>${adminIcon('delete')}</button></div></div>${type==='text'?'':`<div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"><label><span class="tg-form-label">Файл</span><input type="file" name="card_media_file[]" class="tg-form-field bg-white" data-media-file accept="${mediaAccept(type)}"><span class="block mt-2 text-xs font-semibold text-gray-400" data-upload-hint></span></label><label><span class="tg-form-label">Или ссылка на медиа</span><input name="card_media_url[]" placeholder="https://..." class="tg-form-field" data-media-url></label></div>`}${editorTemplate(type)}`;cardsBox.appendChild(div);bindCard(div);updatePreview();}
        function sanitizeEditorHtml(html){const box=document.createElement('div'); box.innerHTML=html||''; const allowed=new Set(['BR','P','B','I']); const aliases={STRONG:'B',EM:'I'}; function clean(node){if(node.nodeType===Node.TEXT_NODE) return document.createTextNode(node.nodeValue.replace(/​/g,'')); if(node.nodeType!==Node.ELEMENT_NODE) return document.createTextNode(''); const tag=aliases[node.tagName]||node.tagName; const frag=document.createDocumentFragment(); if(allowed.has(tag)){const el=document.createElement(tag.toLowerCase()); [...node.childNodes].forEach(ch=>el.appendChild(clean(ch))); return el;} [...node.childNodes].forEach(ch=>frag.appendChild(clean(ch))); if(['DIV','SECTION','ARTICLE','LI','H1','H2','H3','H4','H5','H6'].includes(node.tagName)) frag.appendChild(document.createElement('br')); return frag;} const out=document.createDocumentFragment(); [...box.childNodes].forEach(ch=>out.appendChild(clean(ch))); const wrap=document.createElement('div'); wrap.appendChild(out); return wrap.innerHTML.replace(/(<br\s*\/?>\s*){3,}/gi,'<br><br>');}
        function normalizeEditorHtml(html){html=(html||'').replace(/​/g,''); html=html.replace(/<div><br><\/div>/gi,'\n').replace(/<div>/gi,'\n').replace(/<\/div>/gi,'').replace(/<p>/gi,'\n').replace(/<\/p>/gi,'').replace(/<br\s*\/?>/gi,'\n'); html=html.replace(/<strong>/gi,'<b>').replace(/<\/strong>/gi,'</b>').replace(/<em>/gi,'<i>').replace(/<\/em>/gi,'</i>').replace(/<strike>/gi,'<s>').replace(/<\/strike>/gi,'</s>'); html=html.replace(/<tg-spoiler[^>]*>/gi,'<tg-spoiler>').replace(/<\/tg-spoiler>/gi,'</tg-spoiler>'); html=html.replace(/<blockquote[^>]*>/gi,'<blockquote>').replace(/<\/blockquote>/gi,'</blockquote>'); html=html.replace(/<pre[^>]*>/gi,'<pre>').replace(/<\/pre>/gi,'</pre>'); html=html.replace(/<code[^>]*>/gi,'<code>').replace(/<\/code>/gi,'</code>'); html=html.replace(/<a\s+[^>]*href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi,(m,href,body)=>`<a href="${href.replace(/"/g,'%22')}">${body}</a>`); html=html.replace(/<span[^>]*>(.*?)<\/span>/gi,'$1'); html=html.replace(/&nbsp;/g,' '); html=html.replace(/<(?!\/?(?:b|i|u|s|code|pre|blockquote|a|tg-spoiler)(?=\s|>|\/))[^>]+>/gi,''); return html.trim();}
        function serializeEditor(editor){return normalizeEditorHtml(editor.innerHTML||'');}
        function insertHtmlAtSelection(editor, html){editor.focus(); const sel=window.getSelection(); if(!sel||!sel.rangeCount){editor.insertAdjacentHTML('beforeend',html); return;} const range=sel.getRangeAt(0); if(!editor.contains(range.commonAncestorContainer)){editor.insertAdjacentHTML('beforeend',html); return;} range.deleteContents(); const temp=document.createElement('div'); temp.innerHTML=html; const frag=document.createDocumentFragment(); let node,last; while((node=temp.firstChild)){last=frag.appendChild(node);} range.insertNode(frag); if(last){range.setStartAfter(last);range.collapse(true);sel.removeAllRanges();sel.addRange(range);}}
        function placeCaretIn(node){const sel=window.getSelection(); const range=document.createRange(); range.setStart(node, node.nodeType===Node.TEXT_NODE ? node.length : node.childNodes.length); range.collapse(true); sel.removeAllRanges(); sel.addRange(range);}
        function insertPlainBreakWithoutFormat(editor){editor.focus(); const sel=window.getSelection(); if(!sel||!sel.rangeCount){editor.appendChild(document.createElement('br')); return;} const range=sel.getRangeAt(0); if(!editor.contains(range.commonAncestorContainer)){editor.appendChild(document.createElement('br')); return;} range.deleteContents(); const marker=document.createElement('span'); marker.setAttribute('data-plain-break','1'); marker.appendChild(document.createTextNode('​')); range.insertNode(marker); let top=marker; while(top.parentNode && top.parentNode!==editor){top=top.parentNode;} if(top!==marker && top.parentNode===editor){editor.insertBefore(document.createElement('br'), top.nextSibling); editor.insertBefore(marker, top.nextSibling?.nextSibling || top.nextSibling);} else {marker.parentNode.insertBefore(document.createElement('br'), marker);} placeCaretIn(marker.firstChild);}
        function wrapSelection(editor, before, after){editor.focus(); const sel=window.getSelection(); let selected=''; if(sel&&sel.rangeCount&&editor.contains(sel.getRangeAt(0).commonAncestorContainer)){selected=sel.toString();} insertHtmlAtSelection(editor, before + esc(selected || '') + after);}
        function countTechnicalChars(editor){return serializeEditor(editor).length;}
        function syncCard(card){const editor=card.querySelector('[data-editor]'); const hidden=card.querySelector('textarea[name="card_text[]"]'); if(!editor||!hidden)return ''; const text=serializeEditor(editor); hidden.value=text; const count=card.querySelector('[data-char-count]'); if(count) count.textContent=countTechnicalChars(editor); return text;}
        function readButtons(card){try{return JSON.parse(card.querySelector('[data-buttons-json]')?.value||'[]')||[]}catch(e){return[]}}
        function writeButtons(card, rows){card.querySelector('[data-buttons-json]').value=JSON.stringify(rows); renderButtons(card); updatePreview();}
        function renderButtons(card){const box=card.querySelector('[data-buttons-box]'); if(!box)return; const rows=readButtons(card); box.innerHTML=''; rows.forEach((row,ri)=>{const line=document.createElement('div'); line.className='tg-message-button-row'; row.forEach((btn,bi)=>{const b=document.createElement('button'); b.type='button'; b.className='tg-message-button'; b.textContent=btn.text||'Кнопка'; b.onclick=()=>openButtonModal(card,ri,bi); line.appendChild(b); const plus=document.createElement('button'); plus.type='button'; plus.className='tg-message-button-add'; plus.textContent='+'; plus.title='Добавить рядом'; plus.onclick=()=>openButtonModal(card,ri,bi+1,true); line.appendChild(plus);}); box.appendChild(line); const below=document.createElement('button'); below.type='button'; below.className='tg-btn-ghost self-start'; below.textContent='+ Добавить ниже'; below.onclick=()=>openButtonModal(card,ri+1,0,true); box.appendChild(below);});}
        function openButtonModal(card,rowIndex=null,buttonIndex=0,isNew=false){buttonContext={card,rowIndex,buttonIndex,isNew}; const rows=readButtons(card); const existing=!isNew&&rowIndex!==null&&rows[rowIndex]&&rows[rowIndex][buttonIndex]?rows[rowIndex][buttonIndex]:{text:'',type:'url',url:''}; btnText.value=existing.text||''; btnUrl.value=existing.url||''; btnType.value='url'; btnDelete.classList.toggle('hidden', !!isNew || rowIndex===null); updateButtonModalType(); btnTextCount.textContent=btnText.value.length; modal.classList.add('is-open'); btnText.focus();}
        function closeButtonModal(){modal.classList.remove('is-open'); buttonContext=null;}
        function updateButtonModalType(){const isUrl=btnType.value==='url'; btnUrlWrap.classList.toggle('hidden', !isUrl); btnActionWrap.classList.toggle('hidden', isUrl);}
        btnType?.addEventListener('change',updateButtonModalType); btnText?.addEventListener('input',()=>btnTextCount.textContent=btnText.value.length); modal?.querySelectorAll('[data-button-modal-close]').forEach(b=>b.addEventListener('click',closeButtonModal)); modal?.addEventListener('click',e=>{if(e.target===modal) closeButtonModal();});
        function countButtons(rows){let total=0;(rows||[]).forEach(row=>(row||[]).forEach(btn=>{if(btn&&String(btn.text||'').trim())total++;}));return total;}
        btnSave?.addEventListener('click',()=>{if(!buttonContext)return; const text=(btnText.value||'').trim(); if(!text){showAlert('Введите название кнопки.');return;} const type='url'; const url=(btnUrl.value||'').trim(); if(selectedPlatform()==='vk'){if(type!=='url'){showAlert('В VK-рассылке сейчас доступны только кнопки-ссылки.');return;} if(text.length>40){showAlert('Для VK текст кнопки должен быть не длиннее 40 символов.');return;}} if(type==='url'){if(!url){showAlert('Укажите ссылку кнопки.');return;} const urlError=validatePublicHttpUrl(url,'Ссылка кнопки'); if(urlError){showAlert(urlError);return;}} const rows=readButtons(buttonContext.card); if(selectedPlatform()==='vk' && buttonContext.isNew && countButtons(rows)>=5){showAlert('В VK-рассылке можно добавить максимум 5 кнопок-ссылок.');return;} let ri=buttonContext.rowIndex; if(ri===null || ri===undefined){ri=rows.length; rows[ri]=[];} if(!rows[ri]) rows[ri]=[]; const item={text:text,type:type,url:type==='url'?url:'',color:'primary'}; if(buttonContext.isNew){rows[ri].splice(buttonContext.buttonIndex||0,0,item);} else {rows[ri][buttonContext.buttonIndex]=item;} writeButtons(buttonContext.card, rows.filter(r=>r.length)); buttonContext.card.classList.remove('is-invalid'); buttonContext.card.removeAttribute('data-card-error'); closeButtonModal();});
        btnDelete?.addEventListener('click',()=>{if(!buttonContext)return; const rows=readButtons(buttonContext.card); if(rows[buttonContext.rowIndex]) rows[buttonContext.rowIndex].splice(buttonContext.buttonIndex,1); writeButtons(buttonContext.card, rows.filter(r=>r.length)); closeButtonModal();});
        function closeFloatingMenus(except=null){form.querySelectorAll('.tg-emoji-menu.is-open,.tg-macro-menu.is-open').forEach(m=>{if(m!==except) m.classList.remove('is-open');});}
        function openDateModal(editor){const sel=window.getSelection(); if(!sel||!sel.rangeCount||!editor.contains(sel.getRangeAt(0).commonAncestorContainer)||sel.toString().trim()===''){showAlert('Сначала выделите слово или фразу, к которой нужно привязать дату.'); return;} dateContext={editor, text:sel.toString()}; const now=new Date(); const yyyy=now.getFullYear(); const mm=String(now.getMonth()+1).padStart(2,'0'); const dd=String(now.getDate()).padStart(2,'0'); if(dateValue) dateValue.value=`${yyyy}-${mm}-${dd}`; if(timeValue) timeValue.value='10:00'; dateModal.classList.add('is-open');}
        function closeDateModal(){dateModal.classList.remove('is-open'); dateContext=null;}
        dateModal?.querySelectorAll('[data-date-modal-close]').forEach(b=>b.addEventListener('click',closeDateModal)); dateModal?.addEventListener('click',e=>{if(e.target===dateModal) closeDateModal();});
        dateApply?.addEventListener('click',()=>{if(!dateContext)return; const d=dateValue.value; const t=timeValue.value||'00:00'; if(!d){showAlert('Выберите дату.');return;} const ts=Math.floor(new Date(`${d}T${t}:00`).getTime()/1000); wrapSelection(dateContext.editor,`<a class="tg-date-link" href="tg://msg?timestamp=${ts}">`,'</a>'); const card=dateContext.editor.closest('[data-card]'); if(card){syncCard(card);updatePreview();} closeDateModal();});
        function bindCard(card){card.addEventListener('input',()=>{syncCard(card);updatePreview();});card.addEventListener('change',()=>{syncCard(card);updatePreview();});card.querySelector('[data-card-delete]')?.addEventListener('click',()=>{card.remove();if(!cardsBox.querySelector('[data-card]')) addCard('text'); updatePreview();});card.querySelector('[data-card-up]')?.addEventListener('click',()=>{if(card.previousElementSibling) cardsBox.insertBefore(card,card.previousElementSibling);updatePreview();});card.querySelector('[data-card-down]')?.addEventListener('click',()=>{if(card.nextElementSibling) cardsBox.insertBefore(card.nextElementSibling,card);updatePreview();});const editor=card.querySelector('[data-editor]'); editor.addEventListener('paste',e=>{e.preventDefault(); const data=e.clipboardData||window.clipboardData; const html=data?.getData('text/html')||''; const text=data?.getData('text/plain')||''; const clean=html?sanitizeEditorHtml(html):esc(text).replace(/\n/g,'<br>'); insertHtmlAtSelection(editor, clean); syncCard(card); updatePreview();}); editor.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault(); insertPlainBreakWithoutFormat(editor); syncCard(card); updatePreview();}});card.querySelectorAll('[data-format]').forEach(btn=>btn.addEventListener('click',()=>{editor.focus(); document.execCommand(btn.dataset.format,false,null);syncCard(card);updatePreview();}));card.querySelectorAll('[data-wrap]').forEach(btn=>btn.addEventListener('click',()=>{const tpl=btn.dataset.wrap||''; const [a,b]=tpl.split('|'); wrapSelection(editor,a,b);syncCard(card);updatePreview();}));card.querySelector('[data-date]')?.addEventListener('click',()=>openDateModal(editor));card.querySelector('[data-link]')?.addEventListener('click',()=>{const url=prompt('Вставьте ссылку','https://'); if(url){wrapSelection(editor,`<a href="${esc(url)}">`,'</a>');syncCard(card);updatePreview();}});card.querySelector('[data-clear-format]')?.addEventListener('click',()=>{editor.focus(); document.execCommand('removeFormat',false,null);syncCard(card);updatePreview();});const emojiBtn=card.querySelector('[data-emoji]'), emojiMenu=card.querySelector('.tg-emoji-menu'), macroBtn=card.querySelector('[data-macro]'), macroMenu=card.querySelector('.tg-macro-menu');emojiBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!emojiMenu.classList.contains('is-open'); closeFloatingMenus(); emojiMenu.classList.toggle('is-open',open);});macroBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!macroMenu.classList.contains('is-open'); closeFloatingMenus(); macroMenu.classList.toggle('is-open',open);});emojiMenu?.addEventListener('click',e=>e.stopPropagation()); macroMenu?.addEventListener('click',e=>e.stopPropagation());card.querySelector('[data-macro-search]')?.addEventListener('input',e=>{const q=String(e.target.value||'').toLowerCase().trim(); card.querySelectorAll('[data-macro-search-text]').forEach(item=>{item.style.display=!q||String(item.dataset.macroSearchText||'').toLowerCase().includes(q)?'grid':'none';});});card.querySelectorAll('[data-emoji-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.emojiInsert||'');emojiMenu.classList.remove('is-open');syncCard(card);updatePreview();}));card.querySelectorAll('[data-macro-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.macroInsert||'');macroMenu.classList.remove('is-open');syncCard(card);updatePreview();}));card.querySelector('[data-add-button]')?.addEventListener('click',()=>openButtonModal(card,null,0,true));card.querySelectorAll('[data-media-file],[data-media-url]').forEach(el=>el.addEventListener('change',()=>{const err=validateUploadSizes(); if(err) showAlert(err); updatePreview(); saveDraft();})); updateUploadHints(); updateVkEditorMode();}
        function htmlPreview(text){let out=esc(text); out=out.replace(/&lt;b&gt;([\s\S]*?)&lt;\/b&gt;/g,'<b>$1</b>').replace(/&lt;i&gt;([\s\S]*?)&lt;\/i&gt;/g,'<i>$1</i>').replace(/&lt;u&gt;([\s\S]*?)&lt;\/u&gt;/g,'<u>$1</u>').replace(/&lt;s&gt;([\s\S]*?)&lt;\/s&gt;/g,'<s>$1</s>').replace(/&lt;code&gt;([\s\S]*?)&lt;\/code&gt;/g,'<code>$1</code>').replace(/&lt;pre&gt;([\s\S]*?)&lt;\/pre&gt;/g,'<pre>$1</pre>').replace(/&lt;blockquote&gt;([\s\S]*?)&lt;\/blockquote&gt;/g,'<blockquote>$1</blockquote>').replace(/&lt;tg-spoiler&gt;([\s\S]*?)&lt;\/tg-spoiler&gt;/g,'<span class="tg-spoiler-preview">$1</span>').replace(/&lt;a href=&quot;tg:\/\/msg\?timestamp=([^&]+)&quot;&gt;([\s\S]*?)&lt;\/a&gt;/g,'<span class="tg-preview-date">$2</span>').replace(/&lt;a href=&quot;([^&]+)&quot;&gt;([\s\S]*?)&lt;\/a&gt;/g,'<a href="#" onclick="return false;">$2</a>').replace(/\n/g,'<br>'); return out;}
        function vkPlainTextPreview(html){
            const root=document.createElement('div');
            root.innerHTML=html||'';
            function walk(node){
                if(node.nodeType===Node.TEXT_NODE)return node.nodeValue||'';
                if(node.nodeType!==Node.ELEMENT_NODE)return '';
                const tag=(node.tagName||'').toLowerCase();
                if(tag==='br')return '\n';
                if(tag==='a'){
                    const href=(node.getAttribute('href')||'').trim();
                    const label=(node.textContent||'').trim();
                    if(href && label && href!==label)return `${label} (${href})`;
                    return href||label;
                }
                let out='';
                node.childNodes.forEach(child=>{out+=walk(child);});
                if(['div','p','blockquote','pre','li'].includes(tag))out+='\n';
                return out;
            }
            return walk(root).replace(/\n{3,}/g,'\n\n').trim();
        }
        function mediaPreviewHtml(card,type,platform='telegram'){
            if(type==='text')return'';
            const isVk=platform==='vk';
            const file=card.querySelector('[data-media-file]')?.files?.[0];
            const url=(card.querySelector('[data-media-url]')?.value||'').trim();
            const cls='tg-card-preview-media'+(isVk?' is-vk':'');
            const kind={photo:'Картинка',document:'Файл',audio:'Аудио',video:'Видео'}[type]||cardTitle(type);
            const vkKind=isVk?`<span class="tg-media-kind">VK · ${esc(kind)}</span>`:'';
            if(file && file.type && file.type.startsWith('image/')){
                if(card.dataset.mediaPreviewUrl) URL.revokeObjectURL(card.dataset.mediaPreviewUrl);
                card.dataset.mediaPreviewUrl=URL.createObjectURL(file);
                return `<div class="${cls}">${vkKind}<img src="${card.dataset.mediaPreviewUrl}" alt="Картинка"><div>${esc(file.name)}</div></div>`;
            }
            if(url && /\.(png|jpe?g|gif|webp)(\?|#|$)/i.test(url)){
                return `<div class="${cls}">${vkKind}<img src="${esc(url)}" alt="Картинка"><div>Картинка по ссылке</div></div>`;
            }
            const label=file?.name || (url?url:cardTitle(type));
            return `<div class="${cls}">${vkKind}<div>${esc(label)}</div>${isVk&&type==='video'?'<div class="mt-1 text-[11px] text-orange-700">Прямое VK-видео отключено: используйте обложку + кнопку-ссылку.</div>':''}</div>`;
        }
        function renderPreviewButtons(rows, platform='telegram'){
            if(!rows.length)return'';
            let html='<div class="tg-preview-buttons">';
            if(platform==='vk'){
                let flat=[]; rows.forEach(row=>{(row||[]).forEach(btn=>flat.push(btn));});
                flat.slice(0,5).forEach(btn=>{html+='<div class="tg-preview-button-row">'; html+=`<div class="tg-preview-button is-vk">${esc(btn.text||'Кнопка')}${btn.url?`<span class="tg-preview-vk-url">${esc(btn.url)}</span>`:''}</div>`; html+='</div>';});
                if(flat.length>5) html+='<div class="text-[11px] font-semibold text-red-500 mt-1">В VK уйдут только первые 5 кнопок после исправления.</div>';
            } else {
                rows.forEach(row=>{html+='<div class="tg-preview-button-row">'; row.forEach(btn=>{html+=`<div class="tg-preview-button">${esc(btn.text||'Кнопка')}</div>`;}); html+='</div>';});
            }
            return html+'</div>';
        }
        function updatePreview(){
            const platform=selectedPlatform()==='vk'?'vk':'telegram';
            preview?.closest('.tg-preview-phone')?.classList.toggle('is-vk', platform==='vk');
            let html='<div class="tg-preview-platform"><div class="text-xs font-semibold text-gray-500">Предпросмотр</div><span class="tg-preview-platform-badge">'+(platform==='vk'?'VK plain text':'Telegram')+'</span></div>';
            if(platform==='vk')html+='<div class="tg-preview-platform-note mb-3">Так сообщение будет выглядеть в VK: без Markdown/HTML, с обычными ссылками и кнопками-ссылками.</div>';
            cardsBox.querySelectorAll('[data-card]').forEach(card=>{const type=card.querySelector('input[name="card_type[]"]').value; const text=syncCard(card); html+='<div class="tg-preview-bubble '+(platform==='vk'?'is-vk ':'')+'mb-3">'; html+=mediaPreviewHtml(card,type,platform); const previewText=platform==='vk'?vkPlainTextPreview(text):text; html+=previewText?(platform==='vk'?esc(previewText):htmlPreview(previewText)):'<span class="text-gray-400">Пустая карточка</span>'; html+=renderPreviewButtons(readButtons(card), platform); html+='</div>';});preview.innerHTML=html; saveDraft();}
        function reindexCardOptionCheckboxes(){cardsBox.querySelectorAll('[data-card]').forEach((card,i)=>{const dp=card.querySelector('[data-card-disable-preview]'); if(dp) dp.value=String(i); const pc=card.querySelector('[data-card-protect-content]'); if(pc) pc.value=String(i);});}
        function validatePublicHttpUrl(url, label='Ссылка'){url=String(url||'').trim(); if(!url)return ''; if(/[\x00-\x20\x7F]/.test(url))return `${label} содержит пробелы или служебные символы. Вставьте полный адрес без пробелов, например https://example.kz/file.jpg`; let parsed; try{parsed=new URL(url);}catch(e){return `${label} указана некорректно. Вставьте полный адрес, например https://example.kz/file.jpg`;} if(parsed.protocol!=='http:'&&parsed.protocol!=='https:')return `${label} должна начинаться с http:// или https://`; const host=(parsed.hostname||'').replace(/^\[|\]$/g,''); if(!host)return `${label} должна содержать домен, например https://example.kz/file.jpg`; const isIp=/^(\d{1,3}\.){3}\d{1,3}$/.test(host)||host.includes(':'); if(!isIp){if(host.includes('_'))return `${label} содержит некорректный домен. В домене нельзя использовать подчёркивание.`; if(!host.includes('.'))return `${label} должна быть полноценным адресом с доменом, например https://example.kz/file.jpg. Адрес «${url}» Telegram не принимает.`; const labels=host.split('.'); for(const part of labels){if(!part||part.length>63||!/^[a-z0-9-]+$/i.test(part)||part.startsWith('-')||part.endsWith('-'))return `${label} содержит некорректный домен. Проверьте адрес и вставьте полную ссылку.`;}} return '';}
        function clearCardErrors(){cardsBox.querySelectorAll('[data-card].is-invalid').forEach(card=>{card.classList.remove('is-invalid'); card.removeAttribute('data-card-error');});}
        function markCardError(card,message){if(!card)return; card.classList.add('is-invalid'); card.dataset.cardError=message||'Проверьте эту карточку.';}
        function firstInvalidCard(){return cardsBox.querySelector('[data-card].is-invalid');}
        function showValidationProblem(message){const bad=firstInvalidCard(); if(bad){setStep(2); setTimeout(()=>bad.scrollIntoView({behavior:'smooth',block:'center'}),80);} showAlert(message);}
        function validateBroadcast(){clearCardErrors(); syncSelectedBots(); if(selectedBotIds().length<1)return 'Выберите хотя бы один канал для рассылки.'; const platform=selectedPlatform(); if(platform==='mixed')return 'Пока нельзя запускать одну рассылку одновременно по Telegram и VK. Выберите каналы одной платформы.'; const scheduleErr=validateSchedule(); if(scheduleErr)return scheduleErr; const badSegments=invalidSegmentCount(); if(badSegments>0)return badSegments===1?'Заполните условие сегментации или удалите его.':'Заполните условия сегментации или удалите лишние.'; reindexCardOptionCheckboxes(); const uploadErr=validateUploadSizes(); if(uploadErr)return uploadErr; const cards=[...cardsBox.querySelectorAll('[data-card]')]; if(!cards.length)return 'Добавьте хотя бы одну карточку сообщения.'; let firstError=''; let badCards=0; for(const card of cards){const type=card.querySelector('input[name="card_type[]"]')?.value||'text'; const title=cardTitle(type); const text=syncCard(card).replace(/<[^>]+>/g,'').trim(); let cardError=''; if(platform==='vk' && type==='video') cardError='VK-видео через прямую загрузку временно отключено. Используйте картинку/обложку + кнопку-ссылку на видео.'; if(platform==='vk' && !['text','photo','document'].includes(type) && !cardError) cardError='Для VK сейчас доступны текстовые карточки, картинки и файлы.'; if(type==='text' && text==='') cardError='Заполните текст этой карточки или удалите её.'; if(!cardError && type!=='text'){const file=card.querySelector('[data-media-file]')?.files?.[0]; const url=(card.querySelector('[data-media-url]')?.value||'').trim(); if(!file && !url) cardError=`В карточке «${title}» выберите файл или укажите ссылку на медиа.`; if(!cardError && platform==='vk' && type==='photo' && file && file.type && !file.type.startsWith('image/')) cardError='Для VK-картинки загрузите изображение JPG, PNG, WEBP или GIF.'; if(!cardError && platform==='vk' && type==='photo' && url && !/\.(png|jpe?g|gif|webp)(\?|#|$)/i.test(url)) cardError='Для VK ссылка должна вести прямо на изображение JPG, PNG, WEBP или GIF.'; if(!cardError && url){const urlErr=validatePublicHttpUrl(url,'Ссылка на медиа'); if(urlErr)cardError=urlErr;}} if(!cardError){const rows=readButtons(card); let vkButtonCount=0; rows.forEach((row,ri)=>{(row||[]).forEach((btn,bi)=>{if(cardError)return; const btnTitle=(btn&&btn.text?String(btn.text).trim():''); const btnType=(btn&&btn.type)==='action'?'action':'url'; const btnUrl=(btn&&btn.url?String(btn.url).trim():''); if(btnTitle && platform==='vk')vkButtonCount++; if(!btnTitle)cardError=`В карточке «${title}» есть кнопка без названия.`; else if(platform==='vk'&&vkButtonCount>5)cardError='В VK-рассылке можно добавить максимум 5 кнопок-ссылок.'; else if(platform==='vk'&&btnTitle.length>40)cardError=`VK-кнопка «${btnTitle}» слишком длинная. Лимит: 40 символов.`; else if(platform==='vk'&&btnType!=='url')cardError=`VK сейчас поддерживает в рассылках только кнопки-ссылки. Кнопка «${btnTitle}» должна быть типа «Ссылка».`; else if(btnType==='url'&&!btnUrl)cardError=`В карточке «${title}» у кнопки «${btnTitle}» не указана ссылка.`; else if(btnType==='url'){const urlErr=validatePublicHttpUrl(btnUrl,'Ссылка кнопки'); if(urlErr)cardError=`Кнопка «${btnTitle}»: ${urlErr}`;}});});} if(cardError){badCards++; markCardError(card,cardError); if(!firstError)firstError=cardError;}}
            if(badCards>0){return badCards===1?firstError:`Проверьте карточки, выделенные красной обводкой. Проблемных карточек: ${badCards}.`;}
            return '';}
                form.addEventListener('submit',e=>{syncSegmentInput(); const invalid=segmentState.conditions.some(c=>!validateSegmentCondition(c)); if(invalid){e.preventDefault(); renderSegmentConditions(); showAlert('Заполните условия сегментации или удалите пустые условия.'); return;} const err=validateBroadcast(); if(err){e.preventDefault(); saveDraft(); showValidationProblem(err); return;} const confirmText=currentSendMode()==='scheduled'?'Запланировать рассылку выбранным подписчикам?':'Поставить рассылку в очередь выбранным подписчикам?'; if(!confirm(confirmText)){e.preventDefault(); return;} cardsBox.querySelectorAll('[data-card]').forEach(syncCard); saveDraft();});
        async function sendTestBroadcast(){
            const err=validateBroadcast();
            if(err){showValidationProblem(err);return;}
            cardsBox.querySelectorAll('[data-card]').forEach(syncCard);
            syncSegmentInput();
            const platform=selectedPlatform();
            if(platform==='vk'){
                const vkId=(vkTestPeerId?.value||'').trim();
                if(!vkId){showAlert('Укажите VK ID для тестовой отправки. Пользователь должен быть подписан на выбранный VK-канал.'); vkTestPeerId?.focus(); return;}
            }
            const fd=new FormData(form);
            fd.set('action','tg_broadcast_test');
            fd.set('tg_ajax','1');
            if(testSendBtn){testSendBtn.disabled=true; testSendBtn.innerHTML='Отправляю тест...';}
            if(testResult){testResult.textContent=platform==='vk'?'Отправляю тестовую рассылку в VK.':'Отправляю тестовую рассылку в технический бот.'; testResult.className='mt-3 text-xs font-semibold text-gray-500';}
            try{
                const res=await fetch(form.getAttribute('action')||location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
                const data=await res.json().catch(()=>({ok:false,error:'Сервер вернул некорректный ответ.'}));
                if(!res.ok||!data.ok) throw new Error(data.error||'Не удалось отправить тестовую рассылку.');
                if(testResult){testResult.textContent=data.message||'Тестовая рассылка отправлена.'; testResult.className='mt-3 text-xs font-semibold text-green-700';}
            }catch(e){
                if(testResult){testResult.textContent=e.message||'Не удалось отправить тестовую рассылку.'; testResult.className='mt-3 text-xs font-semibold text-red-600';}
                showAlert(e.message||'Не удалось отправить тестовую рассылку.');
            }finally{
                if(testSendBtn){testSendBtn.disabled=false; testSendBtn.innerHTML='<img class="tb2-icon" src="/assets/admin/icons/sh2-send-gray.svg" alt="" aria-hidden="true"><span id="tgBroadcastTestSendLabel">'+(selectedPlatform()==='vk'?'Отправить тест в VK':'Отправить тест в тех. бот')+'</span>';}
                updateVkBroadcastNotice();
            }
        }
        testSendBtn?.addEventListener('click',sendTestBroadcast);
        renderSegmentConditions(); addCondBtn?.addEventListener('click',e=>{e.stopPropagation(); closeFloatingMenus(); addSegmentCondition('');}); document.addEventListener('click',()=>{closeFloatingMenus(); condBox?.querySelectorAll('.tg-bc-filter-condition.is-open').forEach(x=>x.classList.remove('is-open'));}); condBox?.addEventListener('click',e=>e.stopPropagation()); form.querySelectorAll('[data-add-card]').forEach(btn=>btn.addEventListener('click',()=>addCard(btn.dataset.addCard||'text'))); steps.forEach(b=>b.addEventListener('click',()=>setStep(Number(b.dataset.stepGoto)))); next?.addEventListener('click',()=>{const err=step===2?validateBroadcast():''; if(err){showValidationProblem(err);return;} setStep(step+1);}); prev?.addEventListener('click',()=>setStep(step-1)); botMulti?.addEventListener('change',()=>{syncSelectedBots(); scheduleCount(); saveDraft();});
        function commitBotPickerChange(){syncSelectedBots(); scheduleCount(); saveDraft();}
        function allBotOptionsSelected(){const opts=botOptions(); return opts.length>0 && opts.every(opt=>opt.selected);}
        function setAllBotOptions(checked){
            const opts=botOptions();
            let platform=currentLockPlatform();
            if(!platform && checked){
                const first=opts[0];
                platform=(first?.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
            }
            opts.forEach(opt=>{
                const p=(opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram';
                opt.selected=!!checked && (!platform || p===platform);
            });
            commitBotPickerChange();
        }
        function toggleAllBotOptions(){const opts=botOptions(); if(!opts.length)return; const platform=currentLockPlatform(); const available=platform?opts.filter(opt=>((opt.dataset.platform||'telegram').toLowerCase()==='vk'?'vk':'telegram')===platform):opts; const next=!available.length || !available.every(opt=>opt.selected); setAllBotOptions(next);}
        function toggleOneBotOption(cb){if(!cb||cb.disabled)return; const opt=botOptions().find(o=>o.value===String(cb.value)); const next=!(opt&&opt.selected); setBotSelected(cb.value,next); commitBotPickerChange();}
        botPickerButton?.addEventListener('click',e=>{e.preventDefault(); e.stopPropagation(); const open=!botPicker?.classList.contains('is-open'); botPicker?.classList.toggle('is-open',open); botPickerButton.setAttribute('aria-expanded',open?'true':'false');});
        botPickerPanel?.addEventListener('click',e=>{const item=e.target.closest('.tg-channel-picker-item'); if(!item)return; e.preventDefault(); e.stopPropagation(); if(item.classList.contains('tg-channel-picker-all')){toggleAllBotOptions(); return;} const cb=item.querySelector('[data-bot-option]'); if(cb)toggleOneBotOption(cb);});
        botPickerPanel?.addEventListener('keydown',e=>{if(e.key!==' '&&e.key!=='Enter')return; const item=e.target.closest('.tg-channel-picker-item'); if(!item)return; e.preventDefault(); e.stopPropagation(); if(item.classList.contains('tg-channel-picker-all')){toggleAllBotOptions(); return;} const cb=item.querySelector('[data-bot-option]'); if(cb)toggleOneBotOption(cb);});
        document.addEventListener('click',()=>{botPicker?.classList.remove('is-open'); botPickerButton?.setAttribute('aria-expanded','false');});
        allowDuplicates?.addEventListener('change',()=>{scheduleCount(); saveDraft();}); form.addEventListener('input',()=>{saveDraft();}); form.addEventListener('change',()=>{saveDraft();});
        draftContinue?.addEventListener('click',()=>{hideDraftChoice(); if(!restoreDraft()&&!cardsBox.querySelector('[data-card]')) addCard('text'); updatePreview(); scheduleCount();});
        draftStartNew?.addEventListener('click',()=>{hideDraftChoice(); removeCurrentDraft(); segmentState.conditions=[]; renderSegmentConditions(); cardsBox.innerHTML=''; addCard('text'); updatePreview(); scheduleCount(); saveDraft();});
        syncSelectedBots(); setStep(initialStep); updateCount(); updateUploadHints();
        if(hasDraft()) showDraftChoice(); else addCard('text');
        scheduleCount();
    })();
    </script>
