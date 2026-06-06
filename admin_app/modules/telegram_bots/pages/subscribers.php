<?php
defined('ASR_ADMIN') || exit;

// Clean subscribers page v3.4.40.
// Adds row checkboxes and bulk actions dropdown; keeps independent AND/OR operators between filter conditions.
// New custom fields from oca_telegram_bot_custom_fields appear in the filter catalog automatically.
// Do not add message COUNT/MAX, heavy message JOINs or runtime ALTER TABLE here.

$safeFilterRaw = trim((string)($_GET['filter'] ?? ''));
$safeBotId = max(0, (int)($_GET['bot_id'] ?? 0));
$safePerPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($safePerPage, [10,25,50,100], true)) $safePerPage = 25;
$safePageNum = max(1, (int)($_GET['p'] ?? 1));
$safeOffset = ($safePageNum - 1) * $safePerPage;
$safeStatuses = ['active' => 'Активен', 'inactive' => 'Неактивен', 'unsubscribed' => 'Отписан', 'blocked' => 'Заблокирован'];

$safeFilterLogic = 'and';
$safeFilterConditionsForUi = [];
$safeFilterErrors = [];
$safeFilterCatalog = [];
$safeFilterCatalogForJs = [];
$safeFilterActive = false;
$safeAllowedColumns = [];

$safeHumanizeColumn = static function(string $code): string {
    $labels = [
        'id' => 'ID подписчика',
        'bot_id' => 'ID канала',
        'telegram_user_id' => 'Telegram User ID',
        'chat_id' => 'Chat ID',
        'username' => 'Username',
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'phone' => 'Телефон',
        'email' => 'Email',
        'language_code' => 'Язык',
        'is_bot' => 'Аккаунт бота',
        'status' => 'Статус',
        'admin_note' => 'Заметка',
        'ref' => 'Источник / ref',
        'utm_source' => 'utmSource',
        'utm_medium' => 'utmMedium',
        'utm_campaign' => 'utmCampaign',
        'utm_content' => 'utmContent',
        'utm_term' => 'utmTerm',
        'first_seen_at' => 'Дата подписки',
        'last_seen_at' => 'Последний контакт',
        'created_at' => 'Создан',
        'updated_at' => 'Обновлён',
    ];
    if (isset($labels[$code])) return $labels[$code];
    return trim(str_replace('  ', ' ', str_replace('_', ' ', $code)));
};
$safeSqlTypeFromColumn = static function(string $dbType): string {
    $type = strtolower($dbType);
    if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) return (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) ? 'datetime' : 'date';
    if (preg_match('/int|decimal|float|double|numeric|bigint|tinyint/', $type)) return 'number';
    return 'text';
};
$safeFilterOps = [
    'text' => ['eq','neq','contains','not_contains','known','unknown'],
    'number' => ['eq','neq','gt','lt','gte','lte','known','unknown'],
    'date' => ['eq','before','after','between','known','unknown'],
    'datetime' => ['eq','before','after','between','known','unknown'],
    'enum' => ['eq','neq','known','unknown'],
    'tag' => ['tag_has','tag_not_has'],
];
$safeFilterOpLabels = [
    'eq' => 'соответствует',
    'neq' => 'не соответствует',
    'contains' => 'содержит',
    'not_contains' => 'не содержит',
    'gt' => 'больше',
    'lt' => 'меньше',
    'gte' => 'больше или равно',
    'lte' => 'меньше или равно',
    'before' => 'до',
    'after' => 'после',
    'between' => 'между',
    'known' => 'известно',
    'unknown' => 'неизвестно',
    'tag_has' => 'есть',
    'tag_not_has' => 'нет',
];
$safeJson = static function($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: 'null';
};

$safeNormalizeDateValue = static function($value, string $type): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if ($type === 'datetime') {
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], isset($m[6]) ? (int)$m[6] : 0);
        }
        return '';
    }
    if ($type === 'date') {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return '';
    }
    return $value;
};

try {
    $safeBots = [];
    $safeBotStmt = $pdo->query("SELECT id,title,bot_username,channel_type,status FROM oca_telegram_bots ORDER BY id ASC LIMIT 200");
    if ($safeBotStmt) $safeBots = $safeBotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $safeBotIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $safeBots);
    if ($safeBotId > 0 && !in_array($safeBotId, $safeBotIds, true)) $safeBotId = 0;

    $safeTags = [];
    $safeTagSql = 'SELECT t.id,t.bot_id,t.name,t.color,b.title AS bot_title FROM oca_telegram_bot_tags t LEFT JOIN oca_telegram_bots b ON b.id=t.bot_id ORDER BY t.name ASC LIMIT 500';
    $safeTagStmt = $pdo->prepare($safeTagSql);
    $safeTagStmt->execute([]);
    if ($safeTagStmt) $safeTags = $safeTagStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    try {
        if (function_exists('asr_tg_repository_ensure_custom_fields_schema')) {
            asr_tg_repository_ensure_custom_fields_schema($pdo);
        }
    } catch (Throwable $e) {}
    $safeCustomFields = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, false) : [];
    $safeActiveCustomFields = array_values(array_filter($safeCustomFields, static fn($row) => !empty($row['is_active'])));
    $safeVariablesCatalog = function_exists('asr_tg_variables_catalog') ? asr_tg_variables_catalog($pdo, 0) : [];

    try {
        $columnStmt = $pdo->query('SHOW COLUMNS FROM oca_telegram_bot_subscribers');
        foreach (($columnStmt ? $columnStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $columnRow) {
            $column = (string)($columnRow['Field'] ?? '');
            if ($column !== '' && preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $safeAllowedColumns[$column] = strtolower((string)($columnRow['Type'] ?? 'text'));
            }
        }
    } catch (Throwable $e) {
        $safeAllowedColumns = [
            'id'=>'int','bot_id'=>'int','telegram_user_id'=>'bigint','chat_id'=>'bigint','username'=>'varchar',
            'first_name'=>'varchar','last_name'=>'varchar','status'=>'varchar','first_seen_at'=>'datetime','last_seen_at'=>'datetime',
            'created_at'=>'datetime','updated_at'=>'datetime'
        ];
    }

    $safeAddField = static function(string $key, string $title, string $type, string $source, string $expr = '', array $extra = []) use (&$safeFilterCatalog, &$safeFilterCatalogForJs, $safeFilterOps): void {
        if ($key === '' || isset($safeFilterCatalog[$key])) return;
        if (!isset($safeFilterOps[$type])) $type = 'text';
        $row = array_merge([
            'key' => $key,
            'title' => $title,
            'type' => $type,
            'source' => $source,
            'expr' => $expr,
            'ops' => $safeFilterOps[$type] ?? $safeFilterOps['text'],
        ], $extra);
        $safeFilterCatalog[$key] = $row;
        $safeFilterCatalogForJs[] = [
            'key' => $key,
            'title' => $title,
            'type' => $type,
            'source' => $source,
            'ops' => $row['ops'],
            'options' => $row['options'] ?? [],
            'code' => $row['code'] ?? '',
        ];
    };

    if (isset($safeAllowedColumns['first_name']) || isset($safeAllowedColumns['last_name'])) {
        $safeAddField('system.full_name', 'Полное имя', 'text', 'system', "TRIM(CONCAT_WS(' ', s.first_name, s.last_name))");
    }
    foreach ($safeAllowedColumns as $column => $dbType) {
        $fieldType = $safeSqlTypeFromColumn($dbType);
        if ($column === 'status') {
            $statusOptions = [];
            foreach ($safeStatuses as $key => $label) $statusOptions[] = ['value' => $key, 'label' => $label];
            $safeAddField('subscriber.' . $column, $safeHumanizeColumn($column), 'enum', 'subscriber', 's.`' . $column . '`', ['options' => $statusOptions]);
            continue;
        }
        if ($column === 'is_bot') {
            $safeAddField('subscriber.' . $column, $safeHumanizeColumn($column), 'enum', 'subscriber', 's.`' . $column . '`', ['options' => [['value'=>'1','label'=>'Да'],['value'=>'0','label'=>'Нет']]]);
            continue;
        }
        $safeAddField('subscriber.' . $column, $safeHumanizeColumn($column), $fieldType, 'subscriber', 's.`' . $column . '`');
    }
    $safeAddField('bot.title', 'Канал', 'text', 'bot', 'b.title');
    $safeAddField('bot.username', 'Username канала', 'text', 'bot', 'b.bot_username');
    $safeAddField('bot.channel_type', 'Тип канала', 'text', 'bot', 'b.channel_type');

    $safeTagOptions = [];
    foreach ($safeTags as $tag) {
        $tagId = (int)($tag['id'] ?? 0);
        if ($tagId <= 0) continue;
        $safeTagOptions[] = ['value' => (string)$tagId, 'label' => (string)($tag['name'] ?? ('Тег #' . $tagId))];
    }
    $safeAddField('tag.id', 'Тег', 'tag', 'tag', '', ['options' => $safeTagOptions]);

    foreach ($safeActiveCustomFields as $field) {
        $fieldId = (int)($field['id'] ?? 0);
        if ($fieldId <= 0) continue;
        $type = (string)($field['field_type'] ?? 'text');
        if (!in_array($type, ['text','number','date','datetime'], true)) $type = 'text';
        $safeAddField('custom.' . $fieldId, (string)($field['title'] ?? ('custom.' . $fieldId)), $type, 'custom', '', [
            'field_id' => $fieldId,
            'code' => (string)($field['code'] ?? ''),
        ]);
    }

    $decodedFilter = null;
    if ($safeFilterRaw !== '') {
        $decodedFilter = json_decode($safeFilterRaw, true);
        if (!is_array($decodedFilter)) {
            $safeFilterErrors[] = 'Фильтр повреждён. Сбросьте его и настройте заново.';
        }
    }
    if (is_array($decodedFilter)) {
        $logic = strtolower((string)($decodedFilter['logic'] ?? 'and'));
        $safeFilterLogic = $logic === 'or' ? 'or' : 'and';
        $rawConditions = $decodedFilter['conditions'] ?? [];
        if (!is_array($rawConditions)) $rawConditions = [];
        $rawConditions = array_slice($rawConditions, 0, 10);
        foreach ($rawConditions as $idx => $rawCondition) {
            if (!is_array($rawCondition)) continue;
            $fieldKey = trim((string)($rawCondition['field'] ?? ''));
            $op = trim((string)($rawCondition['op'] ?? ''));
            $value = $rawCondition['value'] ?? '';
            $value2 = $rawCondition['value2'] ?? '';
            $joiner = strtolower((string)($rawCondition['joiner'] ?? ($idx > 0 ? $safeFilterLogic : 'and')));
            $joiner = $joiner === 'or' ? 'or' : 'and';
            $conditionForUi = ['field' => $fieldKey, 'op' => $op, 'value' => $value, 'value2' => $value2, 'joiner' => $idx > 0 ? $joiner : 'and', 'invalid' => false];
            if (!isset($safeFilterCatalog[$fieldKey])) {
                $conditionForUi['invalid'] = true;
                $safeFilterErrors[] = 'Одно из полей фильтра больше не существует.';
                $safeFilterConditionsForUi[] = $conditionForUi;
                continue;
            }
            $fieldDef = $safeFilterCatalog[$fieldKey];
            if (!in_array($op, $fieldDef['ops'], true)) {
                $conditionForUi['invalid'] = true;
                $safeFilterErrors[] = 'Для одного из полей выбран неподходящий оператор.';
                $safeFilterConditionsForUi[] = $conditionForUi;
                continue;
            }
            if ($fieldDef['type'] === 'tag' && empty($fieldDef['options'])) {
                $conditionForUi['invalid'] = true;
                $safeFilterErrors[] = 'Сначала создайте хотя бы один тег.';
            }
            if (!in_array($op, ['known','unknown'], true)) {
                if ($op === 'between') {
                    if (trim((string)$value) === '' || trim((string)$value2) === '') {
                        $conditionForUi['invalid'] = true;
                        $safeFilterErrors[] = 'Для условия «между» нужны два значения.';
                    }
                } elseif (trim((string)$value) === '') {
                    $conditionForUi['invalid'] = true;
                    $safeFilterErrors[] = 'В одном из условий не указано значение.';
                }
                if (!$conditionForUi['invalid'] && $fieldDef['type'] === 'tag') {
                    $allowedTagIds = array_map(static fn($option) => (string)($option['value'] ?? ''), $fieldDef['options'] ?? []);
                    if (!in_array((string)$value, $allowedTagIds, true)) {
                        $conditionForUi['invalid'] = true;
                        $safeFilterErrors[] = 'В одном из условий выбран несуществующий тег.';
                    }
                }
                if (!$conditionForUi['invalid'] && $fieldDef['type'] === 'number' && !is_numeric((string)$value)) {
                    $conditionForUi['invalid'] = true;
                    $safeFilterErrors[] = 'Для числового поля нужно указать число.';
                }
                if (!$conditionForUi['invalid'] && $fieldDef['type'] === 'number' && $op === 'between' && !is_numeric((string)$value2)) {
                    $conditionForUi['invalid'] = true;
                    $safeFilterErrors[] = 'Для числового диапазона нужны два числа.';
                }
                if (!$conditionForUi['invalid'] && in_array($fieldDef['type'], ['date','datetime'], true)) {
                    $normalizedValue = $safeNormalizeDateValue($value, (string)$fieldDef['type']);
                    $normalizedValue2 = $op === 'between' ? $safeNormalizeDateValue($value2, (string)$fieldDef['type']) : '';
                    if ($normalizedValue === '' || ($op === 'between' && $normalizedValue2 === '')) {
                        $conditionForUi['invalid'] = true;
                        $safeFilterErrors[] = $fieldDef['type'] === 'datetime' ? 'Для поля «дата и время» нужно указать корректное значение.' : 'Для поля «дата» нужно указать корректное значение.';
                    } else {
                        $conditionForUi['value'] = $normalizedValue;
                        if ($op === 'between') $conditionForUi['value2'] = $normalizedValue2;
                    }
                }
            }
            $safeFilterConditionsForUi[] = $conditionForUi;
        }
        $safeFilterActive = count($safeFilterConditionsForUi) > 0;
    }

    $where = [];
    $params = [];
    if ($safeBotId > 0) { $where[] = 's.bot_id = ?'; $params[] = $safeBotId; }

    $conditionSqlParts = [];
    $conditionParams = [];
    $conditionJoiners = [];
    $canApplyFilter = $safeFilterActive && empty($safeFilterErrors);
    $safeBuildKnownSql = static function(string $expr, string $type, bool $unknown = false): string {
        if ($type === 'number' || $type === 'date' || $type === 'datetime') {
            return $unknown ? '(' . $expr . ' IS NULL)' : '(' . $expr . ' IS NOT NULL)';
        }
        return $unknown ? '(' . $expr . ' IS NULL OR TRIM(CAST(' . $expr . ' AS CHAR)) = \'\')' : '(' . $expr . ' IS NOT NULL AND TRIM(CAST(' . $expr . ' AS CHAR)) <> \'\')';
    };
    $safeAddSqlCondition = static function(array $fieldDef, string $op, $value, $value2) use (&$conditionSqlParts, &$conditionParams, $safeBuildKnownSql, $safeNormalizeDateValue): void {
        $type = (string)($fieldDef['type'] ?? 'text');
        if ($type === 'date' || $type === 'datetime') { $value = $safeNormalizeDateValue($value, $type); $value2 = $safeNormalizeDateValue($value2, $type); }
        $source = (string)($fieldDef['source'] ?? 'subscriber');
        if ($source === 'tag') {
            $tagId = (int)$value;
            if ($tagId <= 0) return;
            $tagSql = 'SELECT 1 FROM oca_telegram_bot_subscriber_tags st WHERE st.subscriber_id = s.id AND st.tag_id = ?';
            if ($op === 'tag_has') {
                $conditionSqlParts[] = 'EXISTS (' . $tagSql . ')';
                $conditionParams[] = $tagId;
            } elseif ($op === 'tag_not_has') {
                $conditionSqlParts[] = 'NOT EXISTS (' . $tagSql . ')';
                $conditionParams[] = $tagId;
            }
            return;
        }
        if ($source === 'custom') {
            $fieldId = (int)($fieldDef['field_id'] ?? 0);
            if ($fieldId <= 0) return;
            $valueColumn = $type === 'number' ? 'value_number' : ($type === 'date' ? 'value_date' : ($type === 'datetime' ? 'value_datetime' : 'value_text'));
            $base = 'SELECT 1 FROM oca_telegram_bot_subscriber_custom_values cfv JOIN oca_telegram_bot_subscribers sx ON sx.id = cfv.subscriber_id WHERE cfv.field_id = ? AND (sx.id = s.id OR (s.telegram_user_id > 0 AND sx.telegram_user_id = s.telegram_user_id))';
            if ($op === 'known') {
                $conditionSqlParts[] = 'EXISTS (' . $base . ' AND cfv.`' . $valueColumn . '` IS NOT NULL' . ($type === 'text' ? " AND TRIM(cfv.`{$valueColumn}`) <> ''" : '') . ')';
                $conditionParams[] = $fieldId;
                return;
            }
            if ($op === 'unknown') {
                $conditionSqlParts[] = 'NOT EXISTS (' . $base . ' AND cfv.`' . $valueColumn . '` IS NOT NULL' . ($type === 'text' ? " AND TRIM(cfv.`{$valueColumn}`) <> ''" : '') . ')';
                $conditionParams[] = $fieldId;
                return;
            }
            $operatorSql = '=';
            $paramValue = $value;
            if ($op === 'neq') $operatorSql = '<>';
            if ($op === 'gt') $operatorSql = '>';
            if ($op === 'lt') $operatorSql = '<';
            if ($op === 'gte') $operatorSql = '>=';
            if ($op === 'lte') $operatorSql = '<=';
            if ($op === 'before') $operatorSql = '<';
            if ($op === 'after') $operatorSql = '>';
            if ($op === 'contains' || $op === 'not_contains') {
                $operatorSql = $op === 'contains' ? 'LIKE' : 'NOT LIKE';
                $paramValue = '%' . (string)$value . '%';
            }
            if ($op === 'between') {
                $conditionSqlParts[] = 'EXISTS (' . $base . ' AND cfv.`' . $valueColumn . '` BETWEEN ? AND ?)';
                array_push($conditionParams, $fieldId, $value, $value2);
                return;
            }
            $conditionSqlParts[] = 'EXISTS (' . $base . ' AND cfv.`' . $valueColumn . '` ' . $operatorSql . ' ?)';
            array_push($conditionParams, $fieldId, $paramValue);
            return;
        }
        $expr = (string)($fieldDef['expr'] ?? '');
        if ($expr === '') return;
        if ($op === 'known') { $conditionSqlParts[] = $safeBuildKnownSql($expr, $type, false); return; }
        if ($op === 'unknown') { $conditionSqlParts[] = $safeBuildKnownSql($expr, $type, true); return; }
        if ($op === 'contains' || $op === 'not_contains') {
            $conditionSqlParts[] = '(' . $expr . ($op === 'contains' ? ' LIKE ?' : ' NOT LIKE ?') . ')';
            $conditionParams[] = '%' . (string)$value . '%';
            return;
        }
        if ($op === 'between') {
            $conditionSqlParts[] = '(' . $expr . ' BETWEEN ? AND ?)';
            array_push($conditionParams, $value, $value2);
            return;
        }
        $map = ['eq'=>'=','neq'=>'<>','gt'=>'>','lt'=>'<','gte'=>'>=','lte'=>'<=','before'=>'<','after'=>'>'];
        if (!isset($map[$op])) return;
        $conditionSqlParts[] = '(' . $expr . ' ' . $map[$op] . ' ?)';
        $conditionParams[] = $value;
    };

    if ($canApplyFilter) {
        foreach ($safeFilterConditionsForUi as $conditionIndex => $condition) {
            $fieldKey = (string)($condition['field'] ?? '');
            if (!isset($safeFilterCatalog[$fieldKey])) continue;
            $beforeParts = count($conditionSqlParts);
            $safeAddSqlCondition($safeFilterCatalog[$fieldKey], (string)$condition['op'], $condition['value'] ?? '', $condition['value2'] ?? '');
            if (count($conditionSqlParts) > $beforeParts) {
                $joiner = strtolower((string)($condition['joiner'] ?? ($conditionIndex > 0 ? $safeFilterLogic : 'and')));
                $conditionJoiners[count($conditionSqlParts) - 1] = $joiner === 'or' ? 'or' : 'and';
            }
        }
        if ($conditionSqlParts) {
            $combinedSql = (string)$conditionSqlParts[0];
            for ($i = 1, $n = count($conditionSqlParts); $i < $n; $i++) {
                $joiner = (($conditionJoiners[$i] ?? 'and') === 'or') ? ' OR ' : ' AND ';
                $combinedSql = '(' . $combinedSql . $joiner . (string)$conditionSqlParts[$i] . ')';
            }
            $where[] = '(' . $combinedSql . ')';
            array_push($params, ...$conditionParams);
        }
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM oca_telegram_bot_subscribers s LEFT JOIN oca_telegram_bots b ON b.id = s.bot_id' . $whereSql);
    $countStmt->execute($params);
    $safeTotal = (int)$countStmt->fetchColumn();
    $sql = "SELECT s.id,s.bot_id,s.telegram_user_id,s.chat_id,s.username,s.first_name,s.last_name,s.status,s.first_seen_at,s.last_seen_at,b.title AS bot_title,b.bot_username,b.channel_type
        FROM oca_telegram_bot_subscribers s
        LEFT JOIN oca_telegram_bots b ON b.id = s.bot_id" . $whereSql . "
        ORDER BY COALESCE(s.last_seen_at, s.first_seen_at) DESC, s.id DESC
        LIMIT {$safePerPage} OFFSET {$safeOffset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $safeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $safeRowsTags = [];
    $safeIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $safeRows), static fn($id) => $id > 0));
    if ($safeIds) {
        $ph = implode(',', array_fill(0, count($safeIds), '?'));
        $tagRowsStmt = $pdo->prepare('SELECT st.subscriber_id,t.id,t.name,t.color FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_tags t ON t.id=st.tag_id WHERE st.subscriber_id IN (' . $ph . ') ORDER BY t.name ASC');
        $tagRowsStmt->execute($safeIds);
        foreach (($tagRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $tagRow) {
            $sid = (int)($tagRow['subscriber_id'] ?? 0);
            if ($sid > 0) $safeRowsTags[$sid][] = $tagRow;
        }
    }
} catch (Throwable $e) {
    ?>
    <section class="bg-white rounded-3xl border border-red-100 p-8 text-left">
        <h3 class="text-xl font-bold text-red-700">Страница «Подписчики» не смогла загрузиться</h3>
        <p class="mt-3 text-sm font-semibold text-gray-600">Это уже не похоже на SQL-lock: страница падает с ошибкой PHP/SQL. Ниже диагностическое сообщение.</p>
        <pre class="mt-4 whitespace-pre-wrap rounded-2xl bg-red-50 border border-red-100 p-4 text-xs font-semibold text-red-800"><?php echo $h($e->getMessage()); ?></pre>
        <p class="mt-4 text-sm font-semibold text-gray-500">Пришлите этот текст ошибки, и я сделаю точечный фикс.</p>
    </section>
    <?php return;
}
$safeBuildUrl = static function(array $extra = []) use ($safeBotId, $safeFilterRaw, $safePerPage): string {
    $query = ['tab' => 'telegram_bots', 'page' => 'subscribers', 'bot_id' => $safeBotId, 'filter' => $safeFilterRaw, 'per_page' => $safePerPage];
    foreach ($extra as $k => $v) $query[$k] = $v;
    return 'admin.php?' . http_build_query($query);
};
$safeFormatDate = static function($value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    $ts = strtotime($value);
    if (!$ts) return $value;
    return date('d.m.Y', $ts);
};
?>
<style>
    .tg-subs-emergency{background:#fff;border:1px solid #edf0f2;border-radius:24px;box-shadow:0 10px 28px rgba(15,23,42,.04);overflow:hidden}.tg-subs-emergency-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #edf0f2}.tg-subs-emergency-head h3{margin:0;font-size:22px;font-weight:600;color:#1f2937}.tg-subs-emergency-note{font-size:12px;font-weight:600;color:#9ca3af;margin-top:4px}.tg-subs-emergency-filter{display:flex;flex-wrap:wrap;gap:10px;padding:14px 18px;border-bottom:1px solid #edf0f2;background:#fbfbfc}.tg-subs-emergency-filter input,.tg-subs-emergency-filter select{height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;padding:0 12px;font-size:13px;font-weight:500;color:#374151}.tg-subs-emergency-filter button,.tg-subs-emergency-filter a{height:42px;border:0;background:#111827;color:#fff;border-radius:14px;padding:0 15px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;text-decoration:none}.tg-subs-emergency-filter a{background:#f3f4f6;color:#6b7280}.tg-subs-emergency-list{display:grid}.tg-subs-emergency-row{display:grid;grid-template-columns:32px 42px minmax(160px,1fr) minmax(180px,1fr) 120px 140px;gap:12px;align-items:center;padding:14px 18px;border-bottom:1px solid #f1f3f5}.tg-subs-emergency-avatar{width:38px;height:38px;border-radius:14px;background:#fbf2e8;display:flex;align-items:center;justify-content:center;flex:0 0 38px}.tg-subs-emergency-avatar img{width:21px;height:21px;display:block;opacity:.82}.tg-subs-emergency-name{font-size:14px;font-weight:600;color:#1f2937}.tg-subs-emergency-meta{font-size:12px;font-weight:500;color:#9ca3af;margin-top:3px}.tg-subs-emergency-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}.tg-subs-emergency-tag{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:10px;font-weight:600;padding:4px 7px}.tg-subs-emergency-tag i{width:7px;height:7px;border-radius:999px;display:inline-block}.tg-subs-emergency-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:11px;font-weight:600;padding:6px 9px}.tg-subs-emergency-open{justify-self:end;color:#e98222;font-size:12px;font-weight:500;text-decoration:none}.tg-subs-emergency-date{justify-self:end;text-align:right;font-size:12px;font-weight:500;color:#6b7280}.tg-subs-emergency-date span{display:block;color:#a8b0b8;font-size:11px;margin-top:2px}.tg-subs-emergency-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px}.tg-subs-emergency-pager a{border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:10px 12px;color:#374151;text-decoration:none;font-size:12px;font-weight:600}.tg-subs-emergency-pager span{font-size:12px;font-weight:600;color:#9ca3af}@media(max-width:820px){.tg-subs-emergency-row{grid-template-columns:32px 42px 1fr}.tg-subs-emergency-row>*:nth-child(n+5){grid-column:3}.tg-subs-emergency-open{justify-self:start}.tg-subs-emergency-filter input,.tg-subs-emergency-filter select{width:100%}}
    .tg-subs-toolbar{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.tg-subs-btn{height:40px;border:1px solid #e5e7eb;background:#fff;color:#374151;border-radius:14px;padding:0 14px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer}.tg-subs-btn-primary{border-color:#f1b06d;background:#e98222;color:#fff}.tg-subs-vars{padding:14px 18px;border-bottom:1px solid #edf0f2;background:#fff7ed}.tg-subs-vars-title{font-size:13px;font-weight:600;color:#7c2d12;margin-bottom:6px}.tg-subs-vars-list{display:flex;flex-wrap:wrap;gap:7px}.tg-subs-var-token{border-radius:999px;background:#fff;border:1px solid #fed7aa;color:#9a3412;padding:6px 9px;font-size:11px;font-weight:600}.tg-subs-modal{position:fixed;inset:0;background:rgba(17,24,39,.38);z-index:9999;display:none;align-items:center;justify-content:center;padding:18px}.tg-subs-modal.is-open{display:flex}.tg-subs-modal-card{width:min(760px,100%);max-height:90dvh;overflow:auto;background:#fff;border-radius:24px;box-shadow:0 24px 80px rgba(15,23,42,.24);border:1px solid #e5e7eb}.tg-subs-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:20px 22px;border-bottom:1px solid #edf0f2}.tg-subs-modal-head h4{margin:0;font-size:20px;font-weight:600;color:#1f2937}.tg-subs-modal-head p{margin:5px 0 0;font-size:12px;font-weight:500;color:#9ca3af}.tg-subs-modal-close{border:0;background:#f3f4f6;width:34px;height:34px;border-radius:12px;cursor:pointer;font-size:20px;color:#374151}.tg-subs-field-form{display:grid;grid-template-columns:1.3fr .9fr .8fr 90px;gap:10px;padding:16px 22px;border-bottom:1px solid #edf0f2;background:#fbfbfc}.tg-subs-field-form input,.tg-subs-field-form select{height:42px;border:1px solid #e5e7eb;border-radius:13px;padding:0 12px;font-size:13px;font-weight:500}.tg-subs-field-form button{height:42px;border:0;background:#e98222;color:#fff;border-radius:13px;font-size:12px;font-weight:600}.tg-subs-fields-table{padding:0 22px 20px}.tg-subs-field-row{display:grid;grid-template-columns:1.2fr 1fr .55fr .45fr;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid #f1f3f5}.tg-subs-field-name{font-size:14px;font-weight:600;color:#1f2937}.tg-subs-field-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;font-weight:600;color:#7c2d12;background:#fff7ed;border-radius:999px;padding:6px 9px;display:inline-flex}.tg-subs-field-type{font-size:12px;font-weight:600;color:#6b7280}.tg-subs-field-actions{display:flex;justify-content:flex-end;gap:6px}.tg-subs-field-actions button{border:1px solid #e5e7eb;background:#fff;border-radius:10px;padding:8px 10px;color:#6b7280;font-size:11px;font-weight:600;cursor:pointer}.tg-subs-field-actions .danger{color:#b91c1c;background:#fef2f2;border-color:#fecaca}.tg-subs-field-actions .icon-btn{width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1}.tg-subs-field-actions .restore{color:#0f766e;background:#ecfdf5;border-color:#bbf7d0}.tg-subs-field-actions .edit{color:#374151;background:#f9fafb;border-color:#e5e7eb}@media(max-width:820px){.tg-subs-toolbar{justify-content:flex-start}.tg-subs-field-form,.tg-subs-field-row{grid-template-columns:1fr}.tg-subs-field-actions{justify-content:flex-start}}
.tg-subs-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.tg-subs-btn-soft{background:#f3f4f6!important;color:#374151!important}.tg-subs-page-right{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.tg-subs-page-size{margin:0}.tg-subs-page-size select{height:38px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:0 10px;font-size:12px;font-weight:600;color:#374151;outline:none}.tg-subs-tag-form{grid-template-columns:minmax(220px,1fr) auto}.tg-subs-tag-row{grid-template-columns:minmax(220px,1fr) auto}@media(max-width:820px){.tg-subs-emergency-pager{align-items:stretch;flex-direction:column}.tg-subs-page-right{justify-content:space-between}.tg-subs-tag-form{grid-template-columns:1fr}.tg-subs-tag-row{grid-template-columns:1fr auto}}
.tg-filter-server-error{padding:12px 18px;background:#fff1f2;border-bottom:1px solid #fecdd3;color:#9f1239;font-size:13px;font-weight:600}.tg-filter-builder{padding:14px 18px;border-bottom:1px solid #edf0f2;background:#fbfbfc}.tg-filter-mainline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.tg-filter-builder select,.tg-filter-builder input{height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;padding:0 12px;font-size:13px;font-weight:500;color:#374151;outline:none}.tg-filter-channel{min-width:210px}.tg-filter-logic-wrap{display:none;align-items:center;gap:4px;border-radius:999px;background:#f3f4f6;padding:3px}.tg-filter-logic-wrap.is-visible{display:inline-flex}.tg-filter-logic{height:30px;border:0;border-radius:999px;background:transparent;color:#6b7280;padding:0 10px;font-size:12px;font-weight:600;cursor:pointer}.tg-filter-logic.is-active{background:#fff;color:#e98222;box-shadow:0 1px 4px rgba(15,23,42,.08)}.tg-filter-conditions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tg-filter-condition{display:inline-flex;align-items:center;gap:7px;min-height:44px;border:1px solid #e5e7eb;background:#fff;border-radius:18px;padding:6px 8px}.tg-filter-condition.is-invalid{background:#fff1f2;border-color:#fecdd3}.tg-filter-condition select,.tg-filter-condition input{height:34px;border-radius:11px;font-size:12px}.tg-filter-condition .tg-filter-value{width:170px}.tg-filter-condition .tg-filter-value2{width:140px}.tg-filter-remove{width:28px;height:28px;border:0;border-radius:999px;background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:16px;line-height:1}.tg-filter-add,.tg-filter-apply,.tg-filter-reset{height:42px;border-radius:14px;padding:0 14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;text-decoration:none}.tg-filter-add{border:1px solid #fed7aa;background:#fff7ed;color:#c2410c;cursor:pointer}.tg-filter-apply{border:0;background:#e98222;color:#fff;cursor:pointer}.tg-filter-apply:disabled{background:#e5e7eb;color:#9ca3af;cursor:not-allowed}.tg-filter-reset{background:#f3f4f6;color:#6b7280}.tg-filter-help{margin-top:8px;font-size:12px;font-weight:500;color:#9ca3af}@media(max-width:820px){.tg-filter-mainline{align-items:stretch;flex-direction:column}.tg-filter-channel,.tg-filter-builder select,.tg-filter-builder input,.tg-filter-add,.tg-filter-apply,.tg-filter-reset{width:100%;justify-content:center}.tg-filter-conditions{display:grid;width:100%;gap:8px}.tg-filter-condition{display:grid;grid-template-columns:1fr;align-items:stretch;width:100%}.tg-filter-condition .tg-filter-value,.tg-filter-condition .tg-filter-value2{width:100%}.tg-filter-remove{justify-self:end}}

/* v3.4.34: dropdown-style filter chips */
.tg-filter-conditions{position:relative}.tg-filter-condition{position:relative;display:inline-flex;align-items:center;gap:6px;min-height:42px;border:0;background:transparent;border-radius:0;padding:0}.tg-filter-condition.is-invalid .tg-filter-chip{background:#fff1f2;border-color:#fecdd3;color:#be123c}.tg-filter-chip{height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:0 8px 0 14px;display:inline-flex;align-items:center;gap:8px;color:#374151;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.03);max-width:360px}.tg-filter-chip:hover{border-color:#fed7aa;background:#fffaf5}.tg-filter-chip-text{display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:285px}.tg-filter-chip:after{content:'▾';font-size:11px;color:#9ca3af}.tg-filter-remove{width:24px;height:24px;border:0;border-radius:999px;background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:15px;line-height:1;display:inline-flex;align-items:center;justify-content:center}.tg-filter-remove:hover{background:#fee2e2;color:#b91c1c}.tg-filter-popover{display:none;position:absolute;z-index:50;top:48px;left:0;width:360px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 18px 48px rgba(15,23,42,.16);padding:14px}.tg-filter-condition.is-open .tg-filter-popover{display:block}.tg-filter-joiner{height:34px;border:0;border-radius:999px;background:#f3f4f6;color:#e98222;padding:0 12px;font-size:12px;font-weight:700;cursor:pointer}.tg-filter-joiner:hover{background:#fff7ed}.tg-filter-joiner.is-or{color:#b45309}.tg-filter-popover-row{display:grid;gap:6px;margin-bottom:10px}.tg-filter-popover-label{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em}.tg-filter-popover-actions{display:flex;justify-content:flex-end;gap:8px;padding-top:4px}.tg-filter-done{height:34px;border:0;border-radius:12px;background:#e98222;color:#fff;padding:0 12px;font-size:12px;font-weight:700;cursor:pointer}.tg-filter-builder .tg-filter-popover select,.tg-filter-builder .tg-filter-popover input{width:100%;height:38px;border-radius:12px}.tg-filter-value-box{display:grid;gap:8px}.tg-filter-warning{display:none;margin-top:8px;color:#be123c;font-size:12px;font-weight:600}.tg-filter-condition.is-invalid .tg-filter-warning{display:block}.tg-filter-add{border-radius:999px}.tg-filter-logic-wrap{height:42px}@media(max-width:820px){.tg-filter-condition{width:100%;display:block}.tg-filter-chip{width:100%;max-width:none;justify-content:space-between}.tg-filter-chip-text{max-width:calc(100vw - 120px)}.tg-filter-popover{position:static;width:100%;box-shadow:none;margin-top:8px}.tg-filter-condition.is-open .tg-filter-popover{display:block}}



/* v3.4.40: bulk actions */
.tg-filter-mainline{position:relative}.tg-bulk-actions{margin-left:auto;position:relative;display:inline-flex;align-items:center}.tg-bulk-actions-btn{height:42px;border:1px solid #e5e7eb;background:#f3f4f6;color:#374151;border-radius:14px;padding:0 14px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:8px;cursor:pointer}.tg-bulk-actions-btn:disabled{opacity:.55;cursor:not-allowed}.tg-bulk-actions-btn:after{content:'▾';font-size:11px;color:#9ca3af}.tg-bulk-menu{display:none;position:absolute;right:0;top:48px;z-index:70;width:300px;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 18px 48px rgba(15,23,42,.16);padding:10px}.tg-bulk-actions.is-open .tg-bulk-menu{display:block}.tg-bulk-menu-title{font-size:12px;font-weight:700;color:#9ca3af;padding:6px 8px 10px}.tg-bulk-menu-item{width:100%;min-height:38px;border:0;background:#fff;color:#374151;border-radius:12px;padding:9px 10px;font-size:13px;font-weight:600;text-align:left;display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer}.tg-bulk-menu-item:hover{background:#fff7ed;color:#c2410c}.tg-bulk-menu-item[disabled]{color:#9ca3af;background:#f9fafb;cursor:not-allowed}.tg-bulk-menu-item.danger{color:#b91c1c}.tg-bulk-panel{display:none;border-top:1px solid #edf0f2;margin-top:8px;padding:10px 8px 4px}.tg-bulk-panel.is-open{display:grid;gap:8px}.tg-bulk-panel label{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em}.tg-bulk-panel select{height:40px;border:1px solid #e5e7eb;border-radius:12px;padding:0 10px;color:#374151;font-size:13px;font-weight:600;background:#fff}.tg-bulk-panel button{height:38px;border:0;border-radius:12px;background:#e98222;color:#fff;font-size:12px;font-weight:700;cursor:pointer}.tg-row-check{display:flex;align-items:center;justify-content:center}.tg-row-check input{width:18px;height:18px;accent-color:#e98222;cursor:pointer}.tg-delete-confirm-card{width:min(780px,100%)}.tg-delete-alert{margin:16px 22px 0;border:1px solid #fed7aa;background:#fff7ed;color:#1f2937;border-radius:8px;padding:16px 20px;display:flex;gap:14px;align-items:flex-start;font-size:15px;font-weight:500;line-height:1.55}.tg-delete-alert-icon{width:28px;height:28px;border:2px solid #f59e0b;color:#f59e0b;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 auto}.tg-delete-confirm-body{padding:22px}.tg-delete-confirm-body input{width:100%;height:72px;border:1px solid #d1d5db;border-radius:6px;padding:0 22px;font-size:18px;font-weight:500;color:#111827;outline:none}.tg-delete-confirm-body input:focus{border-color:#e98222;box-shadow:0 0 0 3px rgba(233,130,34,.12)}.tg-delete-confirm-actions{display:flex;justify-content:flex-end;gap:12px;padding:0 22px 20px}.tg-delete-confirm-actions button{height:54px;border:0;border-radius:6px;padding:0 22px;font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}.tg-delete-cancel{background:#fff;color:#1a73e8}.tg-delete-ok{background:#e5e7eb;color:#9ca3af}.tg-delete-ok.is-ready{background:#e98222;color:#fff}@media(max-width:820px){.tg-bulk-actions{margin-left:0;width:100%}.tg-bulk-actions-btn{width:100%;justify-content:center}.tg-bulk-menu{position:static;width:100%;margin-top:8px;box-shadow:none}.tg-delete-confirm-actions{flex-direction:column-reverse}.tg-delete-confirm-actions button{width:100%}}

</style>
<section class="tg-subs-emergency">
    <div class="tg-subs-emergency-head">
        <div><h3>Подписчики <span style="color:#9ca3af"><?php echo (int)$safeTotal; ?></span></h3></div>
        <div class="tg-subs-toolbar"><button type="button" class="tg-subs-btn tg-subs-btn-soft" data-tg-tags-open>Теги</button><button type="button" class="tg-subs-btn tg-subs-btn-primary" data-tg-fields-open>Настраиваемые поля</button></div>
    </div>
    <?php if (!empty($safeVariablesCatalog)): ?>
    <?php endif; ?>
    <?php if (!empty($safeFilterErrors)): ?>
        <div class="tg-filter-server-error"><?php echo $h(implode(' ', array_unique($safeFilterErrors))); ?></div>
    <?php endif; ?>
    <form class="tg-filter-builder" method="GET" data-tg-filter-form>
        <input type="hidden" name="tab" value="telegram_bots">
        <input type="hidden" name="page" value="subscribers">
        <input type="hidden" name="filter" value="<?php echo $h($safeFilterRaw); ?>" data-tg-filter-json>
        <div class="tg-filter-mainline">
            <select name="bot_id" class="tg-filter-channel" data-tg-filter-channel>
                <option value="0">Все каналы</option>
                <?php foreach ($safeBots as $bot): ?>
                    <option value="<?php echo (int)$bot['id']; ?>" <?php echo (int)$bot['id'] === $safeBotId ? 'selected' : ''; ?>><?php echo $h((string)$bot['title'] . (!empty($bot['bot_username']) ? ' (@' . (string)$bot['bot_username'] . ')' : '')); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="tg-filter-logic-wrap" data-tg-filter-logic-wrap>
                <button type="button" class="tg-filter-logic is-active" data-tg-filter-logic="and">и</button>
                <button type="button" class="tg-filter-logic" data-tg-filter-logic="or">или</button>
            </div>
            <div class="tg-filter-conditions" data-tg-filter-conditions></div>
            <button type="button" class="tg-filter-add" data-tg-filter-add>＋ Добавить условие</button>
            <button type="submit" class="tg-filter-apply" data-tg-filter-apply>Применить фильтр</button>
            <a class="tg-filter-reset" href="admin.php?tab=telegram_bots&page=subscribers">Отменить фильтр</a>
            <div class="tg-bulk-actions" data-tg-bulk-actions>
                <button type="button" class="tg-bulk-actions-btn" data-tg-bulk-toggle disabled>Действия <span data-tg-bulk-count>0</span></button>
                <div class="tg-bulk-menu" data-tg-bulk-menu>
                    <div class="tg-bulk-menu-title">Выбрано: <span data-tg-bulk-count>0</span></div>
                    <button type="button" class="tg-bulk-menu-item" disabled>Добавить в сценарий <span>позже</span></button>
                    <button type="button" class="tg-bulk-menu-item" disabled>Остановить сценарий <span>позже</span></button>
                    <button type="button" class="tg-bulk-menu-item" data-tg-bulk-panel-toggle="add-tag">Установить тег <span>›</span></button>
                    <div class="tg-bulk-panel" data-tg-bulk-panel="add-tag">
                        <label>Выберите тег</label>
                        <select data-tg-bulk-tag-select="add-tag" required><option value="">Тег</option><?php foreach ($safeTags as $tag): ?><option value="<?php echo (int)$tag['id']; ?>"><?php echo $h((string)$tag['name']); ?></option><?php endforeach; ?></select>
                        <button type="button" data-tg-bulk-submit="add-tag">Установить</button>
                    </div>
                    <button type="button" class="tg-bulk-menu-item" data-tg-bulk-panel-toggle="remove-tag">Снять тег <span>›</span></button>
                    <div class="tg-bulk-panel" data-tg-bulk-panel="remove-tag">
                        <label>Выберите тег</label>
                        <select data-tg-bulk-tag-select="remove-tag" required><option value="">Тег</option><?php foreach ($safeTags as $tag): ?><option value="<?php echo (int)$tag['id']; ?>"><?php echo $h((string)$tag['name']); ?></option><?php endforeach; ?></select>
                        <button type="button" data-tg-bulk-submit="remove-tag">Снять</button>
                    </div>
                    <button type="button" class="tg-bulk-menu-item danger" data-tg-bulk-delete-open>Удалить</button>
                </div>
            </div>        </div>
    </form>

    <div class="tg-subs-emergency-list">
        <?php foreach ($safeRows as $row):
            $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            if ($name === '' && !empty($row['username'])) $name = '@' . ltrim((string)$row['username'], '@');
            if ($name === '') $name = 'Подписчик #' . (int)$row['id'];
            $openUrl = 'admin.php?tab=telegram_bots&page=subscriber&bot_id=' . (int)$row['bot_id'] . '&subscriber_id=' . (int)$row['id'];
            ?>
            <div class="tg-subs-emergency-row" data-subscriber-row="<?php echo (int)$row['id']; ?>">
                <label class="tg-row-check" title="Выбрать подписчика"><input type="checkbox" value="<?php echo (int)$row['id']; ?>" data-subscriber-checkbox></label>
                <div class="tg-subs-emergency-avatar"><img src="/assets/admin/icons/tb2-telegram-gray.svg" alt="" aria-hidden="true"></div>
                <div><a class="tg-subs-emergency-name tg-subs-emergency-open" href="<?php echo $h($openUrl); ?>"><?php echo $h($name); ?></a><div class="tg-subs-emergency-meta"><?php echo !empty($row['username']) ? '@' . $h(ltrim((string)$row['username'], '@')) . ' · ' : ''; ?>ID <?php echo $h((string)($row['telegram_user_id'] ?? '')); ?></div><?php $rowTags = $safeRowsTags[(int)$row['id']] ?? []; if ($rowTags): ?><div class="tg-subs-emergency-tags"><?php foreach (array_slice($rowTags, 0, 4) as $tag): ?><span class="tg-subs-emergency-tag"><?php echo $h((string)$tag['name']); ?></span><?php endforeach; ?><?php if (count($rowTags) > 4): ?><span class="tg-subs-emergency-tag">+<?php echo count($rowTags) - 4; ?></span><?php endif; ?></div><?php endif; ?></div>
                <div><div class="tg-subs-emergency-name"><?php echo $h((string)($row['bot_title'] ?? 'Канал')); ?></div><div class="tg-subs-emergency-meta"><?php echo !empty($row['bot_username']) ? '@' . $h((string)$row['bot_username']) : ''; ?></div></div>
                <div><span class="tg-subs-emergency-pill"><?php echo $h($safeStatuses[(string)($row['status'] ?? '')] ?? (string)($row['status'] ?? '')); ?></span></div>
                <div class="tg-subs-emergency-date"><?php echo $h($safeFormatDate($row['first_seen_at'] ?? '')); ?><span>дата подписки</span></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$safeRows): ?><div class="p-8 text-center text-gray-400 font-bold">Подписчиков по этим условиям не найдено.</div><?php endif; ?>
    </div>
    <div class="tg-subs-emergency-pager">
        <div class="tg-subs-page-nav"><?php if ($safePageNum > 1): ?><a href="<?php echo $h($safeBuildUrl(['p' => $safePageNum - 1])); ?>">← Назад</a><?php endif; ?></div>
        <span>Страница <?php echo (int)$safePageNum; ?> · показано <?php echo count($safeRows); ?> из <?php echo (int)$safeTotal; ?></span>
        <div class="tg-subs-page-right">
            <?php if ($safeOffset + $safePerPage < $safeTotal): ?><a href="<?php echo $h($safeBuildUrl(['p' => $safePageNum + 1])); ?>">Вперёд →</a><?php endif; ?>
            <form class="tg-subs-page-size" method="GET">
                <input type="hidden" name="tab" value="telegram_bots"><input type="hidden" name="page" value="subscribers">
                <input type="hidden" name="bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="filter" value="<?php echo $h($safeFilterRaw); ?>">
                <select name="per_page" onchange="this.form.submit()" aria-label="Количество подписчиков на странице"><?php foreach ([10,25,50,100] as $pp): ?><option value="<?php echo $pp; ?>" <?php echo $safePerPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?> на странице</option><?php endforeach; ?></select>
            </form>
        </div>
    </div>
</section>
<form method="POST" action="admin.php?tab=telegram_bots&page=subscribers" data-tg-bulk-form="add-tag">
    <input type="hidden" name="action" value="tg_subscribers_bulk_add_tag"><input type="hidden" name="bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="return_bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="tag_id" value="" data-tg-bulk-tag-hidden="add-tag"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
</form>
<form method="POST" action="admin.php?tab=telegram_bots&page=subscribers" data-tg-bulk-form="remove-tag">
    <input type="hidden" name="action" value="tg_subscribers_bulk_remove_tag"><input type="hidden" name="bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="return_bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="tag_id" value="" data-tg-bulk-tag-hidden="remove-tag"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
</form>
<form method="POST" action="admin.php?tab=telegram_bots&page=subscribers" data-tg-bulk-delete-form>
    <input type="hidden" name="action" value="tg_subscribers_bulk_delete"><input type="hidden" name="bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="return_bot_id" value="<?php echo (int)$safeBotId; ?>"><input type="hidden" name="confirm_word" value="" data-tg-delete-confirm-hidden><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
</form>
<div class="tg-subs-modal" data-tg-delete-modal aria-hidden="true">
    <div class="tg-subs-modal-card tg-delete-confirm-card">
        <div class="tg-subs-modal-head"><div><h4>Удалить <span data-tg-delete-count>0</span> человек</h4></div><button type="button" class="tg-subs-modal-close" data-tg-delete-close>×</button></div>
        <div class="tg-delete-alert"><span class="tg-delete-alert-icon">i</span><div>Если вы действительно хотите удалить людей, напишите «удалить» в поле ниже. Эти люди будут удалены из всех рассылок, ботов и авторассылок. Мы не сможем их вернуть.</div></div>
        <div class="tg-delete-confirm-body"><input type="text" placeholder="Напишите слово" autocomplete="off" data-tg-delete-confirm-input></div>
        <div class="tg-delete-confirm-actions"><button type="button" class="tg-delete-cancel" data-tg-delete-cancel>Отмена</button><button type="button" class="tg-delete-ok" data-tg-delete-submit disabled>ОК</button></div>
    </div>
</div>
<div class="tg-subs-modal" data-tg-tags-modal aria-hidden="true">
    <div class="tg-subs-modal-card">
        <div class="tg-subs-modal-head"><div><h4>Теги</h4><p>Глобальные теги доступны во всех каналах, сценариях и рассылках.</p></div><button type="button" class="tg-subs-modal-close" data-tg-tags-close>×</button></div>
        <form class="tg-subs-field-form tg-subs-tag-form" method="POST" action="admin.php?tab=telegram_bots&page=subscribers&tags_modal=1" data-tg-tag-form>
            <input type="hidden" name="action" value="tg_tag_save"><input type="hidden" name="return_page" value="subscribers_tags"><input type="hidden" name="tags_modal" value="1"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
            <input type="hidden" name="bot_id" value="0"><input type="hidden" name="tag_id" value="0" data-tg-tag-id>
            <input name="tag_name" placeholder="Название тега, например: автовебинар" required data-tg-tag-name>
            <button type="submit" class="tg-subs-field-add" data-tg-tag-submit>Добавить</button>
        </form>
        <div class="tg-subs-fields-table">
            <?php foreach ($safeTags as $tag): ?>
                <div class="tg-subs-field-row tg-subs-tag-row">
                    <div><div class="tg-subs-field-name"><?php echo $h((string)$tag['name']); ?></div></div>
                    <div class="tg-subs-field-actions">
                        <button type="button" class="icon-btn edit" title="Редактировать тег" aria-label="Редактировать тег" data-tg-tag-edit data-tag-id="<?php echo (int)$tag['id']; ?>" data-tag-name="<?php echo $h((string)$tag['name']); ?>">✎</button>
                        <form method="POST" action="admin.php?tab=telegram_bots&page=subscribers&tags_modal=1"><input type="hidden" name="action" value="tg_tag_delete"><input type="hidden" name="bot_id" value="0"><input type="hidden" name="return_page" value="subscribers_tags"><input type="hidden" name="tags_modal" value="1"><input type="hidden" name="tag_id" value="<?php echo (int)$tag['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?><button class="icon-btn danger" title="Удалить тег" aria-label="Удалить тег" onclick="return confirm('Удалить тег? Он будет снят со всех подписчиков.')">🗑</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($safeTags)): ?><div class="p-6 text-center text-gray-400 font-bold">Пока нет тегов. Добавьте первый тег выше.</div><?php endif; ?>
        </div>
    </div>
</div>
<div class="tg-subs-modal" data-tg-fields-modal aria-hidden="true">
    <div class="tg-subs-modal-card">
        <div class="tg-subs-modal-head"><div><h4>Настраиваемые поля</h4><p>Эти поля станут переменными вида {{custom.code}} и позже будут доступны в фильтрах, сценариях и рассылках.</p></div><button type="button" class="tg-subs-modal-close" data-tg-fields-close>×</button></div>
        <form class="tg-subs-field-form" method="POST" action="admin.php?tab=telegram_bots&page=subscribers&fields_modal=1" data-tg-field-form>
            <input type="hidden" name="action" value="tg_custom_field_save"><input type="hidden" name="tab" value="telegram_bots"><input type="hidden" name="page" value="subscribers"><input type="hidden" name="bot_id" value="0"><input type="hidden" name="return_page" value="subscribers"><input type="hidden" name="fields_modal" value="1"><input type="hidden" name="field_id" value="0" data-tg-field-id><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
            <input name="title" placeholder="Название поля, например: Сотрудников" required data-tg-field-title>
            <input name="code" placeholder="Код переменной: nickname" data-tg-field-code>
            <select name="field_type" data-tg-field-type><option value="text">Текст</option><option value="number">Число</option><option value="date">Дата</option><option value="datetime">Дата и время</option></select>
            <button data-tg-field-submit>Добавить</button>
        </form>
        <div class="tg-subs-fields-table">
            <?php $typeLabels = ['text'=>'Текст','number'=>'Число','date'=>'Дата','datetime'=>'Дата и время']; ?>
            <?php foreach ($safeCustomFields as $field): ?>
                <div class="tg-subs-field-row" style="opacity:<?php echo !empty($field['is_active']) ? '1' : '.55'; ?>">
                    <div><div class="tg-subs-field-name"><?php echo $h((string)$field['title']); ?></div><?php if (empty($field['is_active'])): ?><div class="tg-subs-emergency-meta">В архиве</div><?php endif; ?></div>
                    <div><span class="tg-subs-field-code">{{custom.<?php echo $h((string)$field['code']); ?>}}</span></div>
                    <div class="tg-subs-field-type"><?php echo $h($typeLabels[(string)$field['field_type']] ?? (string)$field['field_type']); ?></div>
                    <div class="tg-subs-field-actions">
                        <button type="button" class="icon-btn edit" title="Редактировать поле" aria-label="Редактировать поле" data-tg-field-edit data-field-id="<?php echo (int)$field['id']; ?>" data-field-title="<?php echo $h((string)$field['title']); ?>" data-field-code="<?php echo $h((string)$field['code']); ?>" data-field-type="<?php echo $h((string)$field['field_type']); ?>">✎</button>
                        <?php if (!empty($field['is_active'])): ?><form method="POST" action="admin.php?tab=telegram_bots&page=subscribers&fields_modal=1"><input type="hidden" name="action" value="tg_custom_field_archive"><input type="hidden" name="bot_id" value="0"><input type="hidden" name="return_page" value="subscribers"><input type="hidden" name="fields_modal" value="1"><input type="hidden" name="field_id" value="<?php echo (int)$field['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?><button class="danger" onclick="return confirm('Отправить поле в архив? Значения подписчиков не удаляются.')">В архив</button></form><?php else: ?><form method="POST" action="admin.php?tab=telegram_bots&page=subscribers&fields_modal=1"><input type="hidden" name="action" value="tg_custom_field_restore"><input type="hidden" name="bot_id" value="0"><input type="hidden" name="return_page" value="subscribers"><input type="hidden" name="fields_modal" value="1"><input type="hidden" name="field_id" value="<?php echo (int)$field['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?><button class="icon-btn restore" title="Восстановить" aria-label="Восстановить">↻</button></form><form method="POST" action="admin.php?tab=telegram_bots&page=subscribers&fields_modal=1"><input type="hidden" name="action" value="tg_custom_field_delete"><input type="hidden" name="bot_id" value="0"><input type="hidden" name="return_page" value="subscribers"><input type="hidden" name="fields_modal" value="1"><input type="hidden" name="field_id" value="<?php echo (int)$field['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?><button class="icon-btn danger" title="Удалить навсегда" aria-label="Удалить навсегда" onclick="return confirm('Удалить поле навсегда? Значения этого поля у подписчиков тоже будут удалены.')">🗑</button></form><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($safeCustomFields)): ?><div class="p-6 text-center text-gray-400 font-bold">Пока нет настраиваемых полей. Добавьте первое поле выше.</div><?php endif; ?>
        </div>
    </div>
</div>
<script>
(function(){
    var filterCatalog = <?php echo $safeJson($safeFilterCatalogForJs); ?>;
    var filterState = {logic: <?php echo $safeJson($safeFilterLogic); ?>, conditions: <?php echo $safeJson($safeFilterConditionsForUi); ?>};
    var opLabels = <?php echo $safeJson($safeFilterOpLabels); ?>;
    function initFilterBuilder(){
        var form=document.querySelector('[data-tg-filter-form]');
        if(!form || !filterCatalog || !filterCatalog.length) return;
        var list=form.querySelector('[data-tg-filter-conditions]');
        var hidden=form.querySelector('[data-tg-filter-json]');
        var addBtn=form.querySelector('[data-tg-filter-add]');
        var applyBtn=form.querySelector('[data-tg-filter-apply]');
        var logicWrap=form.querySelector('[data-tg-filter-logic-wrap]');
        var logicBtns=form.querySelectorAll('[data-tg-filter-logic]');
        var fieldsByKey={};
        filterCatalog.forEach(function(f){ fieldsByKey[f.key]=f; });
        if(!filterState || !Array.isArray(filterState.conditions)) filterState={logic:'and',conditions:[]};
        filterState.logic = filterState.logic === 'or' ? 'or' : 'and';
        function makeOption(value,label,selected){var o=document.createElement('option');o.value=value;o.textContent=label;if(selected)o.selected=true;return o;}
        function firstField(){return filterCatalog[0] ? filterCatalog[0].key : '';}
        function defaultOp(field){var def=fieldsByKey[field]||filterCatalog[0];return def && def.ops && def.ops.length ? (def.type==='text' ? (def.ops.indexOf('contains')>=0?'contains':def.ops[0]) : def.ops[0]) : 'eq';}
        function createCondition(data){
            var fieldKey=data && data.field && fieldsByKey[data.field] ? data.field : firstField();
            var def=fieldsByKey[fieldKey] || filterCatalog[0];
            var op=data && data.op && def.ops.indexOf(data.op)>=0 ? data.op : defaultOp(fieldKey);
            var row=document.createElement('div');row.className='tg-filter-condition';row.dataset.condition='1';
            row.dataset.joiner=(data && data.joiner==='or')?'or':'and';

            var chip=document.createElement('button');chip.type='button';chip.className='tg-filter-chip';
            var chipText=document.createElement('span');chipText.className='tg-filter-chip-text';
            var remove=document.createElement('button');remove.type='button';remove.className='tg-filter-remove';remove.innerHTML='×';remove.title='Удалить условие';
            chip.appendChild(chipText);chip.appendChild(remove);

            var pop=document.createElement('div');pop.className='tg-filter-popover';
            var fieldWrap=document.createElement('div');fieldWrap.className='tg-filter-popover-row';
            var fieldLabel=document.createElement('div');fieldLabel.className='tg-filter-popover-label';fieldLabel.textContent='Поле';
            var fieldSel=document.createElement('select');fieldSel.className='tg-filter-field';
            filterCatalog.forEach(function(f){
                var label = (f.source==='custom'?'✦ ':'') + f.title;
                fieldSel.appendChild(makeOption(f.key,label,f.key===fieldKey));
            });
            fieldWrap.appendChild(fieldLabel);fieldWrap.appendChild(fieldSel);

            var opWrap=document.createElement('div');opWrap.className='tg-filter-popover-row';
            var opLabel=document.createElement('div');opLabel.className='tg-filter-popover-label';opLabel.textContent='Условие';
            var opSel=document.createElement('select');opSel.className='tg-filter-op';
            opWrap.appendChild(opLabel);opWrap.appendChild(opSel);

            var valueWrap=document.createElement('div');valueWrap.className='tg-filter-popover-row';
            var valueLabel=document.createElement('div');valueLabel.className='tg-filter-popover-label';valueLabel.textContent='Значение';
            var valueBox=document.createElement('span');valueBox.className='tg-filter-value-box';
            valueWrap.appendChild(valueLabel);valueWrap.appendChild(valueBox);

            var warning=document.createElement('div');warning.className='tg-filter-warning';warning.textContent='Заполните условие корректно.';
            var actions=document.createElement('div');actions.className='tg-filter-popover-actions';
            var done=document.createElement('button');done.type='button';done.className='tg-filter-done';done.textContent='Готово';
            actions.appendChild(done);
            pop.appendChild(fieldWrap);pop.appendChild(opWrap);pop.appendChild(valueWrap);pop.appendChild(warning);pop.appendChild(actions);
            row.appendChild(chip);row.appendChild(pop);

            function renderOps(){
                var d=fieldsByKey[fieldSel.value] || filterCatalog[0];
                opSel.innerHTML='';
                (d.ops||[]).forEach(function(op){opSel.appendChild(makeOption(op,opLabels[op]||op,op===opSel.dataset.current));});
                if(!opSel.value) opSel.value=defaultOp(fieldSel.value);
            }
            function buildInput(name, cls, val){
                var d=fieldsByKey[fieldSel.value] || filterCatalog[0];
                if((d.type==='enum'||d.type==='tag') && Array.isArray(d.options) && d.options.length){
                    var sel=document.createElement('select');sel.className=cls;sel.dataset.valueInput=name;
                    var empty=document.createElement('option');empty.value='';empty.textContent='Выберите';sel.appendChild(empty);
                    d.options.forEach(function(opt){sel.appendChild(makeOption(String(opt.value),String(opt.label),String(opt.value)===String(val||'')));});
                    return sel;
                }
                var inp=document.createElement('input');inp.className=cls;inp.dataset.valueInput=name;inp.value=val||'';
                if(d.type==='number') inp.type='number'; else if(d.type==='date') inp.type='date'; else if(d.type==='datetime') inp.type='datetime-local'; else inp.type='text';
                inp.placeholder='значение';
                return inp;
            }
            function renderValue(){
                var currentValue = row.querySelector('[data-value-input="value"]') ? row.querySelector('[data-value-input="value"]').value : (data && data.value ? data.value : '');
                var currentValue2 = row.querySelector('[data-value-input="value2"]') ? row.querySelector('[data-value-input="value2"]').value : (data && data.value2 ? data.value2 : '');
                valueBox.innerHTML='';
                var op=opSel.value;
                if(op==='known'||op==='unknown'){valueWrap.style.display='none';return;}
                valueWrap.style.display='grid';
                valueBox.appendChild(buildInput('value','tg-filter-value',currentValue));
                if(op==='between') valueBox.appendChild(buildInput('value2','tg-filter-value2',currentValue2));
            }
            function selectedText(input){
                if(!input) return '';
                if(input.tagName && input.tagName.toLowerCase()==='select'){
                    var opt=input.options[input.selectedIndex];return opt ? opt.textContent : '';
                }
                return input.value || '';
            }
            function updateSummary(){
                var d=fieldsByKey[fieldSel.value] || filterCatalog[0];
                var opText=opLabels[opSel.value] || opSel.value || '';
                var v=row.querySelector('[data-value-input="value"]');
                var v2=row.querySelector('[data-value-input="value2"]');
                var title=d ? d.title : 'Условие';
                var text=title + (opText ? ' ' + opText : '');
                if(opSel.value!=='known' && opSel.value!=='unknown'){
                    var valueText=selectedText(v);
                    if(valueText) text += ' ' + valueText;
                    if(v2 && selectedText(v2)) text += ' - ' + selectedText(v2);
                }
                chipText.textContent=text;
                chip.title=text;
            }
            function open(){
                list.querySelectorAll('.tg-filter-condition.is-open').forEach(function(item){if(item!==row)item.classList.remove('is-open');});
                row.classList.add('is-open');
            }
            function close(){row.classList.remove('is-open');}
            function changed(){ updateSummary(); validateAndSerialize(); }
            chip.addEventListener('click',function(e){ if(e.target===remove) return; e.preventDefault(); row.classList.toggle('is-open'); });
            pop.addEventListener('click',function(e){e.stopPropagation();});
            fieldSel.addEventListener('change',function(){opSel.dataset.current='';renderOps();renderValue();changed();});
            opSel.addEventListener('change',function(){opSel.dataset.current=opSel.value;renderValue();changed();});
            valueBox.addEventListener('input',changed);valueBox.addEventListener('change',changed);
            remove.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();row.remove();validateAndSerialize();});
            done.addEventListener('click',function(){close();});
            opSel.dataset.current=op;renderOps();opSel.value=op;renderValue();updateSummary();
            return row;
        }
        function readCondition(row, idx){
            var field=row.querySelector('.tg-filter-field').value;
            var op=row.querySelector('.tg-filter-op').value;
            var v=row.querySelector('[data-value-input="value"]');
            var v2=row.querySelector('[data-value-input="value2"]');
            var joiner=(row.dataset.joiner==='or')?'or':'and';
            return {field:field,op:op,value:v?v.value:'',value2:v2?v2.value:'',joiner:idx>0?joiner:'and'};
        }
        function isValidTypedDate(value,type){
            value=String(value||'').trim();
            if(!value) return false;
            var m;
            if(type==='date'){
                m=value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            }else if(type==='datetime'){
                m=value.replace('T',' ').match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/);
            }
            if(!m) return false;
            var y=Number(m[1]), mo=Number(m[2]), d=Number(m[3]);
            var dt=new Date(y,mo-1,d);
            return dt.getFullYear()===y && dt.getMonth()===(mo-1) && dt.getDate()===d;
        }
        function isInvalid(c){
            var d=fieldsByKey[c.field]; if(!d || !c.op || (d.ops||[]).indexOf(c.op)<0) return true;
            if(c.op==='known'||c.op==='unknown') return false;
            if(c.op==='between' && (!String(c.value||'').trim() || !String(c.value2||'').trim())) return true;
            if(c.op!=='between' && !String(c.value||'').trim()) return true;
            if(d.type==='number' && c.value!=='' && isNaN(Number(c.value))) return true;
            if(d.type==='number' && c.op==='between' && c.value2!=='' && isNaN(Number(c.value2))) return true;
            if((d.type==='date'||d.type==='datetime') && !isValidTypedDate(c.value,d.type)) return true;
            if((d.type==='date'||d.type==='datetime') && c.op==='between' && !isValidTypedDate(c.value2,d.type)) return true;
            return false;
        }
        function syncInlineLogic(rows){
            list.querySelectorAll('[data-tg-filter-joiner]').forEach(function(item){item.remove();});
            rows.forEach(function(row,idx){
                if(idx===0) return;
                if(row.dataset.joiner!=='or') row.dataset.joiner='and';
                var joiner=document.createElement('button');
                joiner.type='button';
                joiner.className='tg-filter-joiner' + (row.dataset.joiner==='or' ? ' is-or' : '');
                joiner.dataset.tgFilterJoiner='1';
                joiner.textContent=row.dataset.joiner==='or' ? 'или' : 'и';
                joiner.title='Нажмите, чтобы сменить только этот оператор';
                joiner.addEventListener('click',function(e){
                    e.preventDefault();
                    row.dataset.joiner=row.dataset.joiner==='or'?'and':'or';
                    validateAndSerialize();
                });
                list.insertBefore(joiner,row);
            });
        }
        function validateAndSerialize(){
            var rows=[].slice.call(list.querySelectorAll('[data-condition="1"]'));
            var conditions=[];var hasInvalid=false;
            rows.forEach(function(row,idx){var c=readCondition(row,idx);var bad=isInvalid(c);row.classList.toggle('is-invalid',bad);if(bad)hasInvalid=true;conditions.push(c);});
            if(logicWrap) logicWrap.classList.remove('is-visible');
            logicBtns.forEach(function(btn){btn.classList.toggle('is-active',btn.dataset.tgFilterLogic===filterState.logic);});
            syncInlineLogic(rows);
            if(applyBtn) applyBtn.disabled=hasInvalid;
            hidden.value=conditions.length?JSON.stringify({logic:filterState.logic,conditions:conditions}):'';
        }
        logicBtns.forEach(function(btn){btn.addEventListener('click',function(){filterState.logic=btn.dataset.tgFilterLogic==='or'?'or':'and';validateAndSerialize();});});
        document.addEventListener('click',function(e){if(!e.target.closest('[data-condition="1"]')){list.querySelectorAll('.tg-filter-condition.is-open').forEach(function(row){row.classList.remove('is-open');});}});
        addBtn.addEventListener('click',function(){list.appendChild(createCondition({field:firstField(),op:defaultOp(firstField()),value:'',value2:''}));validateAndSerialize();});
        filterState.conditions.forEach(function(c){list.appendChild(createCondition(c));});
        validateAndSerialize();
        form.addEventListener('submit',function(e){validateAndSerialize(); if(applyBtn && applyBtn.disabled){e.preventDefault();}});
    }
    initFilterBuilder();
    function initBulkActions(){
        var checkboxes=[].slice.call(document.querySelectorAll('[data-subscriber-checkbox]'));
        var wrap=document.querySelector('[data-tg-bulk-actions]');
        if(!wrap) return;
        var toggle=wrap.querySelector('[data-tg-bulk-toggle]');
        var counters=[].slice.call(document.querySelectorAll('[data-tg-bulk-count]'));
        var forms=[].slice.call(document.querySelectorAll('[data-tg-bulk-form]'));
        var deleteForm=document.querySelector('[data-tg-bulk-delete-form]');
        var deleteModal=document.querySelector('[data-tg-delete-modal]');
        var deleteInput=document.querySelector('[data-tg-delete-confirm-input]');
        var deleteHidden=document.querySelector('[data-tg-delete-confirm-hidden]');
        var deleteSubmit=document.querySelector('[data-tg-delete-submit]');
        var deleteCount=document.querySelector('[data-tg-delete-count]');
        function selected(){return checkboxes.filter(function(cb){return cb.checked;}).map(function(cb){return cb.value;});}
        function fillForm(form, ids){
            form.querySelectorAll('input[name="subscriber_ids[]"]').forEach(function(el){el.remove();});
            ids.forEach(function(id){var input=document.createElement('input');input.type='hidden';input.name='subscriber_ids[]';input.value=id;form.appendChild(input);});
        }
        function sync(){
            var ids=selected();
            counters.forEach(function(el){el.textContent=String(ids.length);});
            if(toggle) toggle.disabled=ids.length===0;
            forms.forEach(function(form){fillForm(form,ids);});
            if(deleteForm) fillForm(deleteForm,ids);
            if(deleteCount) deleteCount.textContent=String(ids.length);
            if(!ids.length) wrap.classList.remove('is-open');
        }
        checkboxes.forEach(function(cb){cb.addEventListener('change',sync);});
        if(toggle){toggle.addEventListener('click',function(e){e.preventDefault();if(toggle.disabled)return;wrap.classList.toggle('is-open');});}
        document.querySelectorAll('[data-tg-bulk-panel-toggle]').forEach(function(btn){
            btn.addEventListener('click',function(e){e.preventDefault();var name=btn.getAttribute('data-tg-bulk-panel-toggle');document.querySelectorAll('[data-tg-bulk-panel]').forEach(function(panel){panel.classList.toggle('is-open',panel.getAttribute('data-tg-bulk-panel')===name && !panel.classList.contains('is-open'));});});
        });
        document.addEventListener('click',function(e){if(wrap && !e.target.closest('[data-tg-bulk-actions]'))wrap.classList.remove('is-open');});
        forms.forEach(function(form){form.addEventListener('submit',function(e){sync();if(!selected().length){e.preventDefault();}});});
        document.querySelectorAll('[data-tg-bulk-submit]').forEach(function(btn){
            btn.addEventListener('click',function(e){
                e.preventDefault();
                sync();
                if(!selected().length) return;
                var key=btn.getAttribute('data-tg-bulk-submit');
                var select=document.querySelector('[data-tg-bulk-tag-select="'+key+'"]');
                var form=document.querySelector('[data-tg-bulk-form="'+key+'"]');
                var hidden=document.querySelector('[data-tg-bulk-tag-hidden="'+key+'"]');
                if(!select || !form || !hidden || !select.value){ if(select) select.focus(); return; }
                hidden.value=select.value;
                form.submit();
            });
        });
        function showDelete(){
            sync();
            if(!selected().length || !deleteModal) return;
            deleteModal.classList.add('is-open');deleteModal.setAttribute('aria-hidden','false');wrap.classList.remove('is-open');
            if(deleteInput){deleteInput.value='';setTimeout(function(){deleteInput.focus();},30);}validateDelete();
        }
        function hideDelete(){if(deleteModal){deleteModal.classList.remove('is-open');deleteModal.setAttribute('aria-hidden','true');}}
        function validateDelete(){
            var ok=(deleteInput && deleteInput.value.trim().toLowerCase()==='удалить');
            if(deleteHidden) deleteHidden.value=deleteInput ? deleteInput.value.trim().toLowerCase() : '';
            if(deleteSubmit){deleteSubmit.disabled=!ok;deleteSubmit.classList.toggle('is-ready',ok);}
        }
        var delOpen=document.querySelector('[data-tg-bulk-delete-open]');
        if(delOpen)delOpen.addEventListener('click',function(e){e.preventDefault();showDelete();});
        document.querySelectorAll('[data-tg-delete-close],[data-tg-delete-cancel]').forEach(function(btn){btn.addEventListener('click',function(e){e.preventDefault();hideDelete();});});
        if(deleteModal)deleteModal.addEventListener('click',function(e){if(e.target===deleteModal)hideDelete();});
        if(deleteInput)deleteInput.addEventListener('input',validateDelete);
        if(deleteSubmit)deleteSubmit.addEventListener('click',function(e){e.preventDefault();sync();validateDelete();if(!deleteSubmit.disabled && deleteForm)deleteForm.submit();});
        sync();
    }
    initBulkActions();
    function bindModal(modalSel, openSel, closeSel, queryKey){
        var modal=document.querySelector(modalSel);
        var open=document.querySelector(openSel);
        var close=document.querySelector(closeSel);
        var card=modal ? modal.querySelector('.tg-subs-modal-card') : null;
        if(!modal||!open)return;
        function show(){modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');}
        function hide(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');}
        open.addEventListener('click',show);
        if(close)close.addEventListener('click',hide);
        if(card)card.addEventListener('click',function(e){e.stopPropagation();});
        modal.addEventListener('click',function(e){if(e.target===modal)hide();});
        if (new URLSearchParams(window.location.search).get(queryKey) === '1') show();
        document.addEventListener('keydown',function(e){if(e.key==='Escape')hide();});
    }
    bindModal('[data-tg-fields-modal]','[data-tg-fields-open]','[data-tg-fields-close]','fields_modal');
    bindModal('[data-tg-tags-modal]','[data-tg-tags-open]','[data-tg-tags-close]','tags_modal');

    var tagForm=document.querySelector('[data-tg-tag-form]');
    var tagId=tagForm ? tagForm.querySelector('[data-tg-tag-id]') : null;
    var tagName=tagForm ? tagForm.querySelector('[data-tg-tag-name]') : null;
    var tagSubmit=tagForm ? tagForm.querySelector('[data-tg-tag-submit]') : null;
    document.querySelectorAll('[data-tg-tag-edit]').forEach(function(btn){
        btn.addEventListener('click',function(){
            if(tagId) tagId.value=btn.getAttribute('data-tag-id')||'0';
            if(tagName){ tagName.value=btn.getAttribute('data-tag-name')||''; tagName.focus(); }
            if(tagSubmit) tagSubmit.textContent='Сохранить';
        });
    });

    var fieldForm=document.querySelector('[data-tg-field-form]');
    var fieldId=fieldForm ? fieldForm.querySelector('[data-tg-field-id]') : null;
    var fieldTitle=fieldForm ? fieldForm.querySelector('[data-tg-field-title]') : null;
    var fieldCode=fieldForm ? fieldForm.querySelector('[data-tg-field-code]') : null;
    var fieldType=fieldForm ? fieldForm.querySelector('[data-tg-field-type]') : null;
    var fieldSubmit=fieldForm ? fieldForm.querySelector('[data-tg-field-submit]') : null;
    document.querySelectorAll('[data-tg-field-edit]').forEach(function(btn){
        btn.addEventListener('click',function(){
            if(fieldId) fieldId.value=btn.getAttribute('data-field-id')||'0';
            if(fieldTitle){ fieldTitle.value=btn.getAttribute('data-field-title')||''; fieldTitle.focus(); }
            if(fieldCode) fieldCode.value=btn.getAttribute('data-field-code')||'';
            if(fieldType) fieldType.value=btn.getAttribute('data-field-type')||'text';
            if(fieldSubmit) fieldSubmit.textContent='Сохранить';
        });
    });
})();
</script>
<?php return;
