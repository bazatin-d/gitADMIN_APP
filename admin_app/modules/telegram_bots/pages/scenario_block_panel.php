<?php
defined('ASR_ADMIN') || exit;

asr_tg_repository_ensure_scenario_schema($pdo);

$h = $h ?? static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$safeJson = static function($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? '[]' : $json;
};
$mediaUrl = static function(array $card): string {
    $url = trim((string)($card['media_file_path'] ?? '')) ?: trim((string)($card['media_url'] ?? ''));
    if ($url === '') return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    return '/' . ltrim($url, '/');
};

$scenarioId = (int)($_GET['scenario_id'] ?? 0);
$blockId = (int)($_GET['block_id'] ?? ($_GET['id'] ?? 0));
$scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
$block = null;
if ($blockId > 0) {
    $block = $scenario ? asr_tg_scenario_block_find($pdo, $blockId, $scenarioId) : asr_tg_scenario_block_find($pdo, $blockId);
    if (!$block) {
        // Safety fallback: if an old or cached URL lost the scenario_id, recover it by block id.
        $block = asr_tg_scenario_block_find($pdo, $blockId);
    }
    if ($block && (!$scenario || (int)($block['scenario_id'] ?? 0) !== $scenarioId)) {
        $scenarioId = (int)($block['scenario_id'] ?? 0);
        $scenario = $scenarioId > 0 ? asr_tg_scenario_find($pdo, $scenarioId) : null;
    }
}
if (!$scenario || !$block) {
    echo '<section id="tg-flow-panel" class="tg-flow-panel"><div class="tg-flow-panel-head"><div class="tg-flow-panel-title">Блок не найден</div><button type="button" class="tg-flow-drawer-close">×</button></div><div class="tg-flow-panel-body"><div class="tg-flow-panel-alert is-open">Не удалось найти блок сценария.</div></div></section>';
    return;
}

$scenarioTimezone = function_exists('asr_tg_scenario_timezone') ? asr_tg_scenario_timezone($pdo, $scenarioId) : (string)($scenario['timezone'] ?? 'Asia/Almaty');
try { new DateTimeZone($scenarioTimezone); } catch (Throwable $e) { $scenarioTimezone = 'Asia/Almaty'; }

$type = (string)($block['type'] ?? 'message');
if ($type === 'start') {
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <div class="tg-flow-panel-head">
            <div><div class="tg-flow-panel-title">Старт сценария</div><div class="tg-flow-panel-subtitle">Пока запускаем сценарий по кнопке «Начать».</div></div>
            <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
        </div>
        <div class="tg-flow-panel-body">
            <div class="tg-flow-card">
                <div class="tg-flow-card-title">Запуск по кнопке «Начать»</div>
                <div class="tg-flow-card-sub" style="font-size:13px;line-height:1.6;margin-top:10px;color:#6b7280">Бот будет запускать сценарий при нажатии кнопки «Начать» или команде /start. Диплинки и ключевые слова добавим отдельным шагом.</div>
            </div>
        </div>
        <div class="tg-flow-panel-footer"><button type="button" class="tg-flow-panel-secondary tg-flow-drawer-close">Закрыть</button></div>
    </section>
    <?php
    return;
}


if ($type === 'actions') {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $actions = isset($settings['actions']) && is_array($settings['actions']) ? array_values($settings['actions']) : [];
    $tags = function_exists('asr_tg_tags_all_light') ? asr_tg_tags_all_light($pdo, 0) : [];
    $fields = function_exists('asr_tg_scenario_condition_parameter_catalog') ? array_values(array_filter(asr_tg_scenario_condition_parameter_catalog($pdo), static function($item) {
        $type = (string)($item['param_type'] ?? 'text');
        $key = (string)($item['key'] ?? '');
        $fieldCode = (string)($item['field_code'] ?? '');
        if (!in_array($type, ['text','number','date','datetime'], true) || $key === '') return false;
        if (strpos($key, 'custom:') === 0) return true;
        if (strpos($key, 'field:') === 0) return in_array($fieldCode, ['first_name','last_name','phone','email'], true);
        return false;
    })) : [];
    $staff = [];
    try {
        if (function_exists('asr_tg_broadcast_test_ensure_user_schema')) asr_tg_broadcast_test_ensure_user_schema($pdo);
        if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_chat_id') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_notify_dialogs')) {
            $selectParts = ['id', 'telegram_broadcast_test_chat_id'];
            foreach (['full_name','name','username','email','telegram_broadcast_test_username'] as $col) {
                if (asr_table_column_exists($pdo, 'oca_users', $col)) $selectParts[] = $col;
            }
            $where = ["COALESCE(telegram_broadcast_test_chat_id, '') <> ''", 'COALESCE(telegram_broadcast_test_notify_dialogs, 0) = 1'];
            if (asr_table_column_exists($pdo, 'oca_users', 'is_active')) $where[] = 'COALESCE(is_active, 1) = 1';
            if (asr_table_column_exists($pdo, 'oca_users', 'archived_at')) $where[] = 'archived_at IS NULL';
            $sql = 'SELECT ' . implode(', ', array_values(array_unique($selectParts))) . ' FROM oca_users WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 200';
            $stmt = $pdo->query($sql);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $row) {
                $label = trim((string)($row['full_name'] ?? ''));
                if ($label === '') $label = trim((string)($row['name'] ?? ''));
                if ($label === '') $label = trim((string)($row['telegram_broadcast_test_username'] ?? ''));
                if ($label === '') $label = trim((string)($row['username'] ?? ''));
                if ($label === '') $label = trim((string)($row['email'] ?? ''));
                if ($label === '') $label = 'Сотрудник #' . (int)($row['id'] ?? 0);
                $staff[] = ['id' => (int)($row['id'] ?? 0), 'title' => $label];
            }
        }
    } catch (Throwable $e) { $staff = []; }
    try {
        $messageBlocks = function_exists('asr_tg_scenario_message_blocks_for_delete_action') ? asr_tg_scenario_message_blocks_for_delete_action($pdo, $scenarioId) : [];
    } catch (Throwable $e) { $messageBlocks = []; }
    $notifyVariables = [
        ['title' => 'Имя', 'token' => '{{first_name}}'],
        ['title' => 'Фамилия', 'token' => '{{last_name}}'],
        ['title' => 'Username', 'token' => '{{username}}'],
        ['title' => 'Телефон', 'token' => '{{phone}}'],
        ['title' => 'Email', 'token' => '{{email}}'],
        ['title' => 'Название канала', 'token' => '{{bot_title}}'],
    ];
    try {
        if (function_exists('asr_tg_custom_fields_all')) {
            foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
                $code = trim((string)($field['code'] ?? ''));
                if ($code === '') continue;
                $title = trim((string)($field['title'] ?? $code));
                $notifyVariables[] = ['title' => $title, 'token' => '{{custom.' . $code . '}}'];
            }
        }
    } catch (Throwable $e) {}
    $actionLabels = function_exists('asr_tg_scenario_action_type_labels') ? asr_tg_scenario_action_type_labels() : [
        'add_tag' => 'Добавить тег',
        'remove_tag' => 'Удалить тег',
        'set_field' => 'Изменить поле / переменную',
        'stop_scenario' => 'Остановить сценарий',
        'notify_staff' => 'Отправить уведомление',
        'webhook_subscriber' => 'Отправить данные подписчика через Webhook',
        'unsubscribe_bot' => 'Отписать от бота',
        'telegram_unban_user' => 'Разблокировать пользователя',
        'telegram_kick_user' => 'Исключить из группы/канала',
        'telegram_approve_join' => 'Подтвердить заявку на вступление',
        'telegram_decline_join' => 'Отклонить заявку на вступление',
        'delete_step_message' => 'Удалить шаг-сообщение',
        'external_request' => 'Внешний запрос',
        'yandex_metrika' => 'Передать данные о событии в Яндекс.Метрику',
    ];
    $csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
    $blockMeta = ['scenarioId' => $scenarioId, 'blockId' => $blockId, 'hasDeeplink' => false];
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <form method="POST" id="scenario-message-form" class="tg-flow-panel-form" data-actions-form>
            <div class="tg-flow-panel-head tg-actions-panel-head">
                <div>
                    <div class="tg-flow-panel-title">Редактировать блок «Действия»</div>
                    <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
                </div>
                <div class="tg-flow-panel-actions">
                    <div class="tg-flow-panel-menu-wrap">
                        <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                        <div class="tg-flow-panel-dropdown" data-panel-menu>
                            <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                            <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                        </div>
                    </div>
                    <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
                </div>
            </div>
            <div class="tg-flow-panel-body">
                <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
                <input type="hidden" name="action" value="tg_scenario_block_save">
                <input type="hidden" name="return_page" value="scenario_flow">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
                <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
                <input type="hidden" name="block_type" value="actions">
                <input type="hidden" name="scenario_cards_json" value="[]">
                <input type="hidden" name="action_cards_json" id="scenario-actions-json" value="">
                <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

                <label class="tg-flow-panel-field">
                    <span class="tg-flow-panel-label">Название блока</span>
                    <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Действия')); ?>" maxlength="190">
                    <span class="tg-flow-panel-block-id">Блок #<?php echo (int)$blockId; ?></span>
                </label>

                <div class="tg-actions-box" data-actions-box>
                    <div class="tg-actions-section-title">Действия</div>
                    <p class="tg-actions-note">Добавь действия, которые должны выполниться на этом шаге. Они идут сверху вниз.</p>
                    <div class="tg-actions-list" data-actions-list></div>
                    <div class="tg-actions-toolbar">
                        <button type="button" class="tg-flow-panel-secondary" data-action-add>+ Добавить действие</button>
                        <span data-actions-counter></span>
                    </div>
                </div>
            </div>
            <div class="tg-flow-panel-footer"><button type="submit" class="tg-flow-panel-primary">Сохранить блок</button></div>
        </form>
    </section>
    <style data-flow-panel-style="scenario-actions-panel-v3.5.174">
    .tg-actions-panel-head{border-bottom-color:#ead5ff}.tg-actions-box{border:1px solid #ead5ff;background:linear-gradient(180deg,#fff 0%,#fdfaff 100%);border-radius:20px;padding:20px;margin-top:12px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.tg-actions-section-title{font-size:18px;font-weight:650;color:#3f315c;margin:0 0 8px}.tg-actions-note{font-size:13px;color:#6b7280;line-height:1.5;margin:0 0 18px}.tg-actions-list{display:flex;flex-direction:column;gap:14px}.tg-action-card{background:#fff;border:1px solid #eadff8;border-radius:16px;padding:16px;position:relative;box-shadow:0 8px 22px rgba(63,49,92,.06);overflow:hidden}.tg-action-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:#b99cff}.tg-action-card.is-invalid{border-color:#ff9aa1;box-shadow:0 0 0 1px rgba(255,107,115,.28),0 8px 22px rgba(63,49,92,.05);background:#fffafa}.tg-action-card.is-invalid:before{background:#ff6b73}.tg-action-head{display:grid;grid-template-columns:minmax(0,1fr) 34px;gap:12px;align-items:start;margin-bottom:12px}.tg-action-title{font-size:16px;font-weight:650;color:#2f3437;line-height:1.3}.tg-action-remove{border:0;background:transparent;color:#a8aaa6;font-size:18px;line-height:1;cursor:pointer;padding:8px 6px;border-radius:10px}.tg-action-remove:hover{color:#dc2626;background:#fff1f1}.tg-action-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tg-action-card .tg-flow-panel-field{margin:0}.tg-action-card .tg-flow-panel-label{font-size:12px;font-weight:600;color:#777085;background:transparent;letter-spacing:.01em}.tg-action-card .tg-flow-panel-input{background:#fff;border-color:#ddd3ec;border-radius:12px;min-height:44px;font-size:15px;color:#2f3437}.tg-action-card .tg-flow-panel-input:focus{border-color:#b99cff;box-shadow:0 0 0 3px rgba(185,156,255,.18)}.tg-action-card.is-invalid .tg-flow-panel-input[data-invalid="1"]{border-color:#ff6b73!important;box-shadow:0 0 0 3px rgba(255,107,115,.12)!important}.tg-action-error{display:none;margin-top:8px;color:#dc2626;font-size:12px;font-weight:600;line-height:1.35}.tg-action-card.is-invalid .tg-action-error{display:block}.tg-actions-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}.tg-actions-toolbar span{font-size:12px;font-weight:600;color:#8b8497}.tg-action-field{display:none}.tg-action-card[data-type="add_tag"] [data-action-field="tag"],.tg-action-card[data-type="remove_tag"] [data-action-field="tag"]{display:block}.tg-action-card[data-type="set_field"] [data-action-field="field"],.tg-action-card[data-type="set_field"] [data-action-field="operation"],.tg-action-card[data-type="set_field"] [data-action-field="value"]{display:block}.tg-action-card[data-type="notify_staff"] [data-action-field="staff"],.tg-action-card[data-type="notify_staff"] [data-action-field="message"],.tg-action-card[data-type="notify_staff"] [data-action-field="dialog_link"]{display:block}.tg-action-card[data-type="webhook_subscriber"] [data-action-field="url"],.tg-action-card[data-type="webhook_subscriber"] [data-action-field="webhook_flags"]{display:block}.tg-action-card[data-type="delete_step_message"] [data-action-field="step_messages"]{display:block}.tg-action-card[data-type="external_request"] [data-action-field="external_request"]{display:block}.tg-action-card[data-type="yandex_metrika"] [data-action-field="yandex_metrika"]{display:block}.tg-action-card[data-type="telegram_unban_user"] [data-action-field="telegram_group"],.tg-action-card[data-type="telegram_kick_user"] [data-action-field="telegram_group"],.tg-action-card[data-type="telegram_approve_join"] [data-action-field="telegram_group"],.tg-action-card[data-type="telegram_decline_join"] [data-action-field="telegram_group"]{display:block}.tg-action-card[data-type="telegram_kick_user"] [data-action-field="telegram_delete_messages"],.tg-action-card[data-type="telegram_kick_user"] [data-action-field="telegram_unban_after_kick"]{display:block}.tg-action-external-preview{border:1px solid #e5d8f6;background:#fff;border-radius:12px;padding:10px 11px;color:#4b5563;font-size:13px;font-weight:600;line-height:1.45}.tg-action-external-preview.is-empty{color:#ef4444;background:#fff7f7;border-color:#fecaca}.tg-action-edit{height:38px;border:1px solid #e5d8f6;background:#fff;border-radius:12px;color:#6f54bd;font-size:13px;font-weight:600;cursor:pointer;padding:0 12px}.tg-action-edit:hover{background:#f2eaff}.tg-external-modal-backdrop{position:fixed;inset:0;z-index:7000;background:rgba(15,23,42,.35);display:none;align-items:center;justify-content:center;padding:20px}.tg-external-modal-backdrop.is-open{display:flex}.tg-external-modal{width:min(980px,calc(100vw - 40px));max-height:calc(100vh - 40px);background:#fff;border:1px solid #e5e7eb;border-radius:22px;box-shadow:0 28px 80px rgba(15,23,42,.24);display:flex;flex-direction:column;overflow:hidden}.tg-external-head{display:flex;align-items:center;justify-content:space-between;padding:20px 26px;border-bottom:1px solid #edf0f2}.tg-external-title{font-size:20px;font-weight:650;color:#1f2937}.tg-external-close{width:38px;height:38px;border:0;background:#fff;border-radius:12px;color:#6b7280;font-size:24px;cursor:pointer}.tg-external-close:hover{background:#f3f4f6}.tg-external-body{padding:20px 26px;overflow:auto}.tg-external-top{display:grid;grid-template-columns:180px 1fr;gap:14px;margin-bottom:18px}.tg-external-tabs{display:flex;gap:16px;border-bottom:1px solid #edf0f2;margin:8px -26px 18px;padding:0 26px}.tg-external-tab{border:0;background:#fff;padding:13px 0 14px;color:#8b929e;font-size:14px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent}.tg-external-tab.is-active{color:#6f54bd;border-bottom-color:#b99cff}.tg-external-pane{display:none;min-height:220px}.tg-external-pane.is-active{display:block}.tg-external-row{display:grid;grid-template-columns:1fr 1fr 42px;gap:12px;align-items:end;margin-bottom:10px}.tg-external-del{height:42px;border:0;background:#fff;color:#9ca3af;border-radius:12px;font-size:20px;cursor:pointer}.tg-external-del:hover{background:#fef2f2;color:#dc2626}.tg-external-add{height:42px;border:1px solid #ead5ff;background:#fff;color:#6f54bd;border-radius:12px;padding:0 14px;font-size:13px;font-weight:600;cursor:pointer}.tg-external-help{border:1px solid #f1e7c7;background:#fff8e8;border-radius:12px;padding:12px;color:#705d2d;font-size:13px;line-height:1.45}.tg-external-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 26px;border-top:1px solid #edf0f2;background:#fff}.tg-external-secondary{border:0;background:#f3f4f6;color:#6b7280;border-radius:14px;min-height:42px;padding:0 18px;font-size:13px;font-weight:600;cursor:pointer}.tg-external-primary{border:0;background:#FFA048;color:#fff;border-radius:14px;min-height:42px;padding:0 18px;font-size:13px;font-weight:700;cursor:pointer}.tg-external-result{white-space:pre-wrap;border:1px dashed #d1d5db;background:#f9fafb;border-radius:12px;padding:14px;color:#6b7280;font-size:13px;line-height:1.45}.tg-external-mapping-list{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}.tg-external-mapping-row{display:grid;grid-template-columns:1fr 1fr 42px;gap:12px;align-items:end}.tg-external-mapping-note{font-size:12px;color:#6b7280;line-height:1.45;margin-top:8px}.tg-action-group-warning,.tg-action-help{margin-top:8px;border:1px solid #efe6d2;background:#fffaf0;border-radius:12px;padding:9px 11px;color:#75613a;font-size:12px;line-height:1.45}.tg-action-card[data-type="stop_scenario"] .tg-action-grid,.tg-action-card[data-type="unsubscribe_bot"] .tg-action-grid{display:none}.tg-action-card[data-type="set_field"][data-action-no-value="1"] [data-action-field="value"]{display:none}.tg-action-check{display:flex;align-items:flex-start;gap:8px;font-size:13px;font-weight:500;color:#4b5563;line-height:1.35}.tg-action-check input{margin-top:2px}.tg-action-step-search{margin-bottom:8px}.tg-action-step-list{max-height:180px;overflow:auto;border:1px solid #e5d8f6;background:#fff;border-radius:12px;padding:8px;display:flex;flex-direction:column;gap:6px}.tg-action-step-row{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:8px;font-size:13px;color:#374151;cursor:pointer}.tg-action-step-row:hover{background:#f7f4fb}.tg-action-step-row small{margin-left:auto;color:#9ca3af;font-size:11px}.tg-action-empty{border:1px dashed #e5d8f6;background:#fff;border-radius:14px;padding:16px;color:#6b7280;font-size:13px;font-weight:600;line-height:1.4}@media(max-width:680px){.tg-actions-box{padding:16px}.tg-action-grid,.tg-action-head{grid-template-columns:1fr}.tg-action-remove{justify-self:end}.tg-actions-toolbar{align-items:stretch;flex-direction:column}.tg-actions-toolbar button{width:100%}}
    </style>
    <script data-flow-panel-script>
    (function(){
      var panel = document.getElementById('tg-flow-panel');
      var form = document.getElementById('scenario-message-form');
      var box = document.querySelector('[data-actions-box]');
      if (!panel || !form || !box || box.dataset.bound === '1') return;
      box.dataset.bound = '1';
      var list = box.querySelector('[data-actions-list]');
      var hidden = document.getElementById('scenario-actions-json');
      var counter = box.querySelector('[data-actions-counter]');
      var actionLabels = <?php echo $safeJson($actionLabels); ?>;
      var initialActions = <?php echo $safeJson($actions); ?>;
      var tags = <?php echo $safeJson($tags); ?>;
      var fields = <?php echo $safeJson($fields); ?>;
      var fieldMap = {};
      (Array.isArray(fields) ? fields : []).forEach(function(field){ if (field && field.key) fieldMap[String(field.key)] = field; });
      var staff = <?php echo $safeJson($staff); ?>;
      var messageBlocks = <?php echo $safeJson($messageBlocks ?? []); ?>;
      var notifyVariables = <?php echo $safeJson($notifyVariables); ?>;
      var actions = Array.isArray(initialActions) ? initialActions.map(function(a){ return Object.assign({}, a); }) : [];
      var actionGroups = [
        {title:'Теги', items:['add_tag','remove_tag']},
        {title:'Поля и переменные', items:['set_field']},
        {title:'Сценарий', items:['stop_scenario','unsubscribe_bot','delete_step_message']},
        {title:'Уведомления и интеграции', items:['notify_staff','webhook_subscriber','external_request','yandex_metrika']},
        {title:'Управление группами', items:['telegram_unban_user','telegram_kick_user','telegram_approve_join','telegram_decline_join']}
      ];
      var actionTypes = [];
      actionGroups.forEach(function(group){ (group.items || []).forEach(function(type){ if (actionTypes.indexOf(type) === -1) actionTypes.push(type); }); });
      function esc(value){ return String(value == null ? '' : value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];}); }
      function optList(items, selected, valueKey, labelKey, emptyText){
        var html = '<option value="">'+esc(emptyText || 'Выберите')+'</option>';
        (Array.isArray(items)?items:[]).forEach(function(item){
          var value = String(item[valueKey] == null ? '' : item[valueKey]);
          var label = String(item[labelKey] || item.title || item.name || item.email || value);
          html += '<option value="'+esc(value)+'" '+(String(selected)===value?'selected':'')+'>'+esc(label)+'</option>';
        });
        return html;
      }
      function fieldOptions(selected){
        var html = '<option value="">Выберите поле</option>';
        fields.forEach(function(item){ var value=String(item.key||''); var label=String(item.title||value); html += '<option value="'+esc(value)+'" data-type="'+esc(item.param_type||'text')+'" '+(String(selected)===value?'selected':'')+'>'+esc(label)+'</option>'; });
        return html;
      }
      function fieldTypeFor(key){ var f = fieldMap[String(key || '')] || {}; return String(f.param_type || f.field_type || 'text'); }
      function operationOptions(selected, fieldType){
        selected = selected || 'set';
        fieldType = fieldType || 'text';
        var ops = [{v:'set',t:'Установить значение'},{v:'clear',t:'Очистить значение'}];
        if (fieldType === 'number') ops.push({v:'inc',t:'Прибавить число'},{v:'dec',t:'Вычесть число'});
        if (!ops.some(function(op){ return op.v === selected; })) selected = 'set';
        return ops.map(function(op){ return '<option value="'+esc(op.v)+'" '+(op.v===selected?'selected':'')+'>'+esc(op.t)+'</option>'; }).join('');
      }
      function valueInputHtml(action, fieldType, operation){
        var value = action.value || '';
        var type = 'text';
        var placeholder = 'Введите значение';
        if (fieldType === 'number') { type = 'number'; placeholder = 'Введите число'; }
        if (fieldType === 'date') { type = 'date'; placeholder = ''; }
        if (fieldType === 'datetime') { type = 'datetime-local'; placeholder = ''; value = String(value || '').replace(' ', 'T').slice(0, 16); }
        return '<input class="tg-flow-panel-input" type="'+type+'" data-action-value value="'+esc(value)+'" placeholder="'+esc(placeholder)+'">';
      }
      function typeOptions(selected){
        var html = '';
        actionGroups.forEach(function(group){
          var items = (group.items || []).filter(function(type){ return actionTypes.indexOf(type) !== -1; });
          if (!items.length) return;
          html += '<optgroup label="'+esc(group.title || 'Действия')+'">';
          items.forEach(function(type){
            html += '<option value="'+esc(type)+'" '+(selected===type?'selected':'')+'>'+esc(actionLabels[type]||type)+'</option>';
          });
          html += '</optgroup>';
        });
        return html;
      }
      function notifyVariableOptions(){
        var html = '<option value="">Вставить переменную</option>';
        (Array.isArray(notifyVariables)?notifyVariables:[]).forEach(function(item){ var token=String(item.token||''); if(!token)return; html += '<option value="'+esc(token)+'">'+esc((item.title||token)+' — '+token)+'</option>'; });
        return html;
      }
      function normalizeExternal(action){
        action = action || {};
        var method = String(action.method || 'GET').toUpperCase();
        if (['GET','POST','PUT','PATCH','DELETE'].indexOf(method) === -1) method = 'GET';
        var headers = Array.isArray(action.headers) ? action.headers : [];
        var mappings = Array.isArray(action.mappings) ? action.mappings : [];
        return {
          method: method,
          url: String(action.url || ''),
          headers: headers.map(function(h){ return {key:String((h||{}).key||''), value:String((h||{}).value||'')}; }),
          body: String(action.body || ''),
          mappings: mappings.map(function(m){ return {response_path:String((m||{}).response_path || (m||{}).path || ''), target_param_key:String((m||{}).target_param_key || (m||{}).param_key || '')}; }),
          last_test: action.last_test || null
        };
      }
      function externalMappingOptions(selected){
        var html = '<option value="">Выберите поле</option>';
        (Array.isArray(fields) ? fields : []).forEach(function(field){
          var key = String(field.key || ''); if(!key) return;
          html += '<option value="'+esc(key)+'" '+(String(selected||'')===key?'selected':'')+'>'+esc(field.title || key)+'</option>';
        });
        return html;
      }
      function yandexClientFieldOptions(selected){
        var html = '<option value="">Выберите поле с Yandex Client ID</option>';
        (Array.isArray(fields) ? fields : []).forEach(function(field){
          var key = String(field.key || ''); if(!key) return;
          if (String(field.source || '') !== 'custom') return;
          if (String(field.param_type || field.field_type || 'text') !== 'text') return;
          html += '<option value="'+esc(key)+'" '+(String(selected||'')===key?'selected':'')+'>'+esc(field.title || key)+'</option>';
        });
        return html;
      }
      function externalSummary(action){
        var ext = normalizeExternal(action);
        if (!ext.url) return 'Настройте внешний запрос';
        return ext.method + ' ' + ext.url;
      }
      function selectedBlockIds(action){
        var raw = action.block_ids || action.step_ids || action.block_id || [];
        if (!Array.isArray(raw)) raw = raw ? [raw] : [];
        var seen = {};
        return raw.map(function(v){ return String(parseInt(v,10)||0); }).filter(function(v){ if (v === '0' || seen[v]) return false; seen[v]=true; return true; });
      }
      function stepMessagePickerHtml(action){
        var selected = selectedBlockIds(action);
        var selectedMap = {}; selected.forEach(function(id){ selectedMap[id]=true; });
        var rows = (Array.isArray(messageBlocks)?messageBlocks:[]).map(function(item){
          var id = String(item.id || ''); if (!id) return '';
          var label = String(item.label || ((item.title || 'Сообщение') + ' — Сообщение #' + id));
          var title = String(item.title || 'Сообщение');
          var ref = String(item.ref || ('Сообщение #' + id));
          return '<label class="tg-action-step-row" data-step-row data-step-search-text="'+esc((label + ' ' + title + ' ' + ref).toLowerCase())+'"><input type="checkbox" data-action-step-id value="'+esc(id)+'" '+(selectedMap[id]?'checked':'')+'><span>'+esc(label)+'</span><small>'+esc(ref)+'</small></label>';
        }).join('');
        if (!rows) rows = '<div class="tg-action-empty">В сценарии пока нет блоков «Сообщение» для удаления.</div>';
        return '<input class="tg-flow-panel-input tg-action-step-search" data-action-step-search placeholder="Найти по названию или номеру блока">'
          + '<div class="tg-action-step-list" data-action-step-list>'+rows+'</div>'
          + '<div class="tg-action-help">Будут удалены все сообщения, которые выбранный шаг отправил подписчику. Если прошло больше 48 часов, Telegram может не разрешить удаление.</div>';
      }
      function actionTitle(type){ return actionLabels[type] || 'Действие'; }
      function isTelegramGroupAction(type){ return ['telegram_unban_user','telegram_kick_user','telegram_approve_join','telegram_decline_join'].indexOf(String(type||'')) !== -1; }
      function telegramGroupHelp(type){
        if (type === 'telegram_unban_user') return 'Снимает блокировку. После этого пользователь сможет вступить снова по ссылке.';
        if (type === 'telegram_kick_user') return 'Исключает пользователя из указанной группы или канала.';
        if (type === 'telegram_approve_join') return 'Подтверждает уже поданную пользователем заявку на вступление.';
        if (type === 'telegram_decline_join') return 'Отклоняет уже поданную пользователем заявку на вступление.';
        return 'Укажите @username или ID группы/канала.';
      }
      function render(){
        if (!list) return;
        if (!actions.length) {
          list.innerHTML = '<div class="tg-action-empty">Добавьте первое действие.</div>';
        } else {
          list.innerHTML = actions.map(function(action, index){
            var type = action.type || 'add_tag';
            var tagSelected = String(action.tag_id || '');
            var staffSelected = String(action.staff_user_id || '');
            var fieldType = fieldTypeFor(action.param_key || '');
            var operation = action.operation || 'set';
            if (type === 'set_field' && fieldType !== 'number' && (operation === 'inc' || operation === 'dec')) operation = 'set';
            var noValue = operation === 'clear';
            return '<div class="tg-action-card" data-action-index="'+index+'" data-type="'+esc(type)+'" data-action-field-type="'+esc(fieldType)+'" data-action-no-value="'+(noValue ? '1' : '0')+'">'
              + '<div class="tg-action-head"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Действие</span><select class="tg-flow-panel-input" data-action-type>'+typeOptions(type)+'</select></label><button type="button" class="tg-action-remove" data-action-remove title="Удалить действие">🗑</button></div>'
              + '<div class="tg-action-grid">'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="tag"><span class="tg-flow-panel-label">Тег</span><select class="tg-flow-panel-input" data-action-tag>'+optList(tags, tagSelected, 'id', 'title', 'Выберите тег')+'</select></label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="field"><span class="tg-flow-panel-label">Поле / переменная</span><select class="tg-flow-panel-input" data-action-param>'+fieldOptions(action.param_key || '')+'</select></label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="operation"><span class="tg-flow-panel-label">Операция</span><select class="tg-flow-panel-input" data-action-operation>'+operationOptions(operation, fieldType)+'</select></label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="value"><span class="tg-flow-panel-label">Значение</span>'+valueInputHtml(action, fieldType, operation)+'</label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="staff"><span class="tg-flow-panel-label">Сотрудник</span><select class="tg-flow-panel-input" data-action-staff>'+optList(staff, staffSelected, 'id', 'title', staff.length ? 'Выберите сотрудника' : 'Нет сотрудников с уведомлениями')+'</select></label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="message"><span class="tg-flow-panel-label">Текст уведомления</span><textarea class="tg-flow-panel-input" data-action-message rows="4" maxlength="4000" placeholder="Введите текст уведомления">'+esc(action.message || '')+'</textarea><select class="tg-flow-panel-input" data-action-message-var style="margin-top:8px;min-height:38px;font-size:13px">'+notifyVariableOptions()+'</select></label>'
              + '<label class="tg-flow-panel-field tg-action-field" data-action-field="url"><span class="tg-flow-panel-label">URL webhook</span><input class="tg-flow-panel-input" data-action-url value="'+esc(action.url || '')+'" placeholder="https://..."></label>'
              + '<div class="tg-action-field" data-action-field="telegram_group"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Группа / канал</span><input class="tg-flow-panel-input" data-action-telegram-chat value="'+esc(action.chat_id || action.group_ref || '')+'" placeholder="@groupname или -100..."></label><div class="tg-action-group-warning">'+esc(telegramGroupHelp(type))+'</div></div>'
              + '<div class="tg-action-field" data-action-field="step_messages"><span class="tg-flow-panel-label">Выберите шаг(и)</span>'+stepMessagePickerHtml(action)+'</div>'
              + '<div class="tg-action-field" data-action-field="external_request"><div class="tg-action-external-preview '+(action.url?'':'is-empty')+'" data-external-preview>'+esc(externalSummary(action))+'</div><button type="button" class="tg-action-edit" data-external-edit style="margin-top:10px">✎ Настроить внешний запрос</button><div class="tg-action-help">Настройте метод, URL, заголовки, тело запроса и запись ответа в поля подписчика.</div></div>'
              + '<div class="tg-action-field" data-action-field="yandex_metrika"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Номер счётчика</span><input class="tg-flow-panel-input" data-action-yandex-counter value="'+esc(action.counter_id || '')+'" placeholder="Например: 12345678"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">OAuth-токен</span><input class="tg-flow-panel-input" data-action-yandex-token value="'+esc(action.token || '')+'" placeholder="Токен Яндекс.Метрики"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Идентификатор цели</span><input class="tg-flow-panel-input" data-action-yandex-target value="'+esc(action.target || '')+'" placeholder="Например: order_confirmed"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Поле с Yandex Client ID</span><select class="tg-flow-panel-input" data-action-yandex-client-field>'+yandexClientFieldOptions(action.client_id_param_key || '')+'</select></label><div class="tg-action-help">Client ID берётся из выбранного пользовательского поля.</div></div>'
              + '<div class="tg-action-field" data-action-field="dialog_link"><label class="tg-action-check"><input type="checkbox" data-action-dialog-link '+(action.add_dialog_link ? 'checked' : '')+'> Добавить ссылку на диалог</label></div>'
              + '<div class="tg-action-field" data-action-field="webhook_flags"><label class="tg-action-check"><input type="checkbox" data-action-custom-fields '+(action.include_custom_fields !== false ? 'checked' : '')+'> Передавать пользовательские поля</label><label class="tg-action-check"><input type="checkbox" data-action-tags '+(action.include_tags !== false ? 'checked' : '')+'> Передавать теги</label></div>'
              + '<div class="tg-action-field" data-action-field="telegram_delete_messages"><label class="tg-action-check"><input type="checkbox" data-action-telegram-revoke '+(action.revoke_messages ? 'checked' : '')+'> Удалить сообщения пользователя в чате</label></div>'
              + '<div class="tg-action-field" data-action-field="telegram_unban_after_kick"><label class="tg-action-check"><input type="checkbox" data-action-telegram-unban-after-kick '+(action.unban_after_kick === true ? 'checked' : '')+'> Разрешить повторное вступление после исключения</label></div>'
              + '</div><div class="tg-action-error">Заполните обязательные поля этого действия.</div></div>';
          }).join('');
        }
        if (counter) { var n = actions.length; counter.textContent = n === 1 ? '1 действие' : (n > 1 && n < 5 ? n + ' действия' : n + ' действий'); }
        validate(false);
        syncHidden();
      }
      function collect(){
        var next = [];
        Array.prototype.slice.call(list.querySelectorAll('[data-action-index]')).forEach(function(card){
          var type = (card.querySelector('[data-action-type]')||{}).value || 'add_tag';
          var item = {type:type};
          if (type === 'add_tag' || type === 'remove_tag') item.tag_id = (card.querySelector('[data-action-tag]')||{}).value || '';
          if (type === 'set_field') { item.param_key = (card.querySelector('[data-action-param]')||{}).value || ''; item.param_type = fieldTypeFor(item.param_key); item.operation = (card.querySelector('[data-action-operation]')||{}).value || 'set'; item.value = (card.querySelector('[data-action-value]')||{}).value || ''; }
          if (type === 'notify_staff') { item.staff_user_id = (card.querySelector('[data-action-staff]')||{}).value || ''; item.message = (card.querySelector('[data-action-message]')||{}).value || ''; item.add_dialog_link = !!(card.querySelector('[data-action-dialog-link]')||{}).checked; }
          if (type === 'webhook_subscriber') { item.url = (card.querySelector('[data-action-url]')||{}).value || ''; item.include_custom_fields = !!(card.querySelector('[data-action-custom-fields]')||{}).checked; item.include_tags = !!(card.querySelector('[data-action-tags]')||{}).checked; }
          if (isTelegramGroupAction(type)) { item.chat_id = (card.querySelector('[data-action-telegram-chat]')||{}).value || ''; if (type === 'telegram_kick_user') { item.revoke_messages = !!(card.querySelector('[data-action-telegram-revoke]')||{}).checked; item.unban_after_kick = !!(card.querySelector('[data-action-telegram-unban-after-kick]')||{}).checked; } }
          if (type === 'delete_step_message') { item.block_ids = Array.prototype.slice.call(card.querySelectorAll('[data-action-step-id]:checked')).map(function(el){ return el.value || ''; }).filter(Boolean); }
          if (type === 'external_request') { var idx = parseInt(card.getAttribute('data-action-index')||'-1',10); var src = actions[idx] || {}; var ext = normalizeExternal(src); item.method = ext.method; item.url = ext.url; item.headers = ext.headers; item.body = ext.body; item.mappings = ext.mappings; item.last_test = ext.last_test || null; }
          if (type === 'yandex_metrika') { item.counter_id = (card.querySelector('[data-action-yandex-counter]')||{}).value || ''; item.token = (card.querySelector('[data-action-yandex-token]')||{}).value || ''; item.target = (card.querySelector('[data-action-yandex-target]')||{}).value || ''; item.client_id_param_key = (card.querySelector('[data-action-yandex-client-field]')||{}).value || ''; }
          next.push(item);
        });
        actions = next;
        return next;
      }
      function syncHidden(){ if (hidden) hidden.value = JSON.stringify(collect()); }
      function validate(mark){
        var ok = true;
        if (!actions.length) ok = false;
        Array.prototype.slice.call(list.querySelectorAll('[data-action-index]')).forEach(function(card){
          var type = (card.querySelector('[data-action-type]')||{}).value || '';
          var valid = true;
          Array.prototype.slice.call(card.querySelectorAll('[data-invalid]')).forEach(function(el){ el.removeAttribute('data-invalid'); });
          function req(selector){ var el = card.querySelector(selector); var filled = !!(el && String(el.value || '').trim() !== ''); if (!filled && el) el.setAttribute('data-invalid','1'); return filled; }
          if (type === 'add_tag' || type === 'remove_tag') valid = req('[data-action-tag]');
          if (type === 'set_field') { valid = req('[data-action-param]'); var op = (card.querySelector('[data-action-operation]')||{}).value || 'set'; if (op !== 'clear') valid = req('[data-action-value]') && valid; }
          if (type === 'notify_staff') valid = req('[data-action-staff]') && req('[data-action-message]');
          if (type === 'webhook_subscriber') { valid = req('[data-action-url]'); var url = (card.querySelector('[data-action-url]')||{}).value || ''; if (url && !/^https?:\/\//i.test(url)) valid = false; }
          if (isTelegramGroupAction(type)) valid = req('[data-action-telegram-chat]');
          if (type === 'delete_step_message') { var checked = card.querySelectorAll('[data-action-step-id]:checked').length; if (!checked) { valid = false; var listEl = card.querySelector('[data-action-step-list]'); if (listEl) listEl.setAttribute('data-invalid','1'); } }
          if (type === 'external_request') { var idx = parseInt(card.getAttribute('data-action-index')||'-1',10); var ext = normalizeExternal(actions[idx] || {}); valid = !!(ext.method && /^https:\/\//i.test(ext.url)); var prev = card.querySelector('[data-external-preview]'); if (!valid && prev) prev.setAttribute('data-invalid','1'); }
          if (type === 'yandex_metrika') { valid = req('[data-action-yandex-counter]') && req('[data-action-yandex-token]') && req('[data-action-yandex-target]') && req('[data-action-yandex-client-field]'); }
          card.classList.toggle('is-invalid', mark && !valid);
          if (!valid) ok = false;
        });
        return ok;
      }
      function openExternalModal(index){
        var action = actions[index] || {type:'external_request'};
        var ext = normalizeExternal(action);
        var backdrop = document.createElement('div');
        backdrop.className = 'tg-external-modal-backdrop is-open';
        var headerRows = ext.headers.length ? ext.headers : [{key:'', value:''}];
        var mappingRows = ext.mappings.length ? ext.mappings : [];
        function rowsHtml(){
          return headerRows.map(function(row){ return '<div class="tg-external-row" data-external-header-row><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Key</span><input class="tg-flow-panel-input" data-external-header-key value="'+esc(row.key||'')+'" placeholder="Authorization"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Value</span><input class="tg-flow-panel-input" data-external-header-value value="'+esc(row.value||'')+'" placeholder="Bearer ..."></label><button type="button" class="tg-external-del" data-external-header-del>🗑</button></div>'; }).join('');
        }
        function mappingRowHtml(row){
          row = row || {};
          return '<div class="tg-external-mapping-row" data-external-mapping-row><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Путь в JSON-ответе</span><input class="tg-flow-panel-input" data-external-map-path value="'+esc(row.response_path||'')+'" placeholder="data.order_id или $.status"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Записать в поле</span><select class="tg-flow-panel-input" data-external-map-target>'+externalMappingOptions(row.target_param_key||'')+'</select></label><button type="button" class="tg-external-del" data-external-map-del>🗑</button></div>';
        }
        function mappingRowsHtml(){
          return mappingRows.map(mappingRowHtml).join('');
        }
        backdrop.innerHTML = '<div class="tg-external-modal" role="dialog" aria-modal="true"><div class="tg-external-head"><div><div class="tg-external-title">Внешний запрос</div><div class="tg-flow-panel-subtitle">Настройка HTTP-запроса для этого действия</div></div><button type="button" class="tg-external-close" data-external-close>×</button></div><div class="tg-external-body"><div class="tg-external-top"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Тип запроса</span><select class="tg-flow-panel-input" data-external-method>'+['GET','POST','PUT','PATCH','DELETE'].map(function(m){return '<option value="'+m+'" '+(ext.method===m?'selected':'')+'>'+m+'</option>';}).join('')+'</select></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">URL-адрес</span><input class="tg-flow-panel-input" data-external-url value="'+esc(ext.url)+'" placeholder="https://example.com/api"></label></div><div class="tg-external-help" style="margin-bottom:14px">Разрешены только HTTPS-ссылки. В URL, заголовках и теле можно использовать переменные подписчика.</div><div class="tg-external-tabs"><button type="button" class="tg-external-tab is-active" data-external-tab="headers">Заголовки</button><button type="button" class="tg-external-tab" data-external-tab="body">Тело</button><button type="button" class="tg-external-tab" data-external-tab="response">Ответ</button><button type="button" class="tg-external-tab" data-external-tab="mapping">Сопоставление ответов</button></div><div class="tg-external-pane is-active" data-external-pane="headers"><div data-external-headers>'+rowsHtml()+'</div><button type="button" class="tg-external-add" data-external-header-add>+ Добавить заголовок</button></div><div class="tg-external-pane" data-external-pane="body"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Тело запроса</span><textarea class="tg-flow-panel-input" data-external-body rows="9" placeholder=\'{\n  "phone": "{{phone}}"\n}\'>'+esc(ext.body)+'</textarea></label><div class="tg-external-help">Для GET тело запроса не используется. Для GET тело запроса не используется.</div></div><div class="tg-external-pane" data-external-pane="response"><div class="tg-external-result">Здесь будет результат тестирования запроса. Кнопку теста подключим отдельным шагом.</div></div><div class="tg-external-pane" data-external-pane="mapping"><div class="tg-external-mapping-list" data-external-mappings>'+mappingRowsHtml()+'</div><button type="button" class="tg-external-add" data-external-map-add>+ Добавить сопоставление</button><div class="tg-external-help" style="margin-top:12px">Укажите путь в JSON-ответе и поле подписчика, куда записать значение. Пример пути: <b>data.order_id</b> или <b>$.status</b>. Сопоставление выполняется после успешного внешнего запроса.</div></div></div><div class="tg-external-footer"><button type="button" class="tg-external-secondary" data-external-close>Отмена</button><button type="button" class="tg-external-secondary" data-external-test>Протестировать запрос</button><button type="button" class="tg-external-primary" data-external-save>Сохранить</button></div></div>';
        document.body.appendChild(backdrop);
        function close(){ backdrop.remove(); }
        function activate(tab){ Array.prototype.slice.call(backdrop.querySelectorAll('[data-external-tab]')).forEach(function(b){ b.classList.toggle('is-active', b.getAttribute('data-external-tab')===tab); }); Array.prototype.slice.call(backdrop.querySelectorAll('[data-external-pane]')).forEach(function(p){ p.classList.toggle('is-active', p.getAttribute('data-external-pane')===tab); }); }
        backdrop.addEventListener('click', function(e){ if(e.target === backdrop || e.target.closest('[data-external-close]')) { e.preventDefault(); close(); return; } var tabBtn=e.target.closest('[data-external-tab]'); if(tabBtn){ e.preventDefault(); activate(tabBtn.getAttribute('data-external-tab')||'headers'); return; } if(e.target.closest('[data-external-header-add]')){ e.preventDefault(); var wrap=backdrop.querySelector('[data-external-headers]'); if(wrap){ wrap.insertAdjacentHTML('beforeend', '<div class="tg-external-row" data-external-header-row><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Key</span><input class="tg-flow-panel-input" data-external-header-key placeholder="Authorization"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Value</span><input class="tg-flow-panel-input" data-external-header-value placeholder="Bearer ..."></label><button type="button" class="tg-external-del" data-external-header-del>🗑</button></div>'); } return; } if(e.target.closest('[data-external-header-del]')){ e.preventDefault(); var row=e.target.closest('[data-external-header-row]'); if(row) row.remove(); return; } if(e.target.closest('[data-external-map-add]')){ e.preventDefault(); var maps=backdrop.querySelector('[data-external-mappings]'); if(maps){ maps.insertAdjacentHTML('beforeend', mappingRowHtml({})); } return; } if(e.target.closest('[data-external-map-del]')){ e.preventDefault(); var mapRow=e.target.closest('[data-external-mapping-row]'); if(mapRow) mapRow.remove(); return; } function readExternalForm(){ var method=(backdrop.querySelector('[data-external-method]')||{}).value||'GET'; var url=(backdrop.querySelector('[data-external-url]')||{}).value||''; var headers=[]; Array.prototype.slice.call(backdrop.querySelectorAll('[data-external-header-row]')).forEach(function(r){ var key=(r.querySelector('[data-external-header-key]')||{}).value||''; var value=(r.querySelector('[data-external-header-value]')||{}).value||''; if(String(key).trim()!=='' || String(value).trim()!=='') headers.push({key:key,value:value}); }); var mappings=[]; Array.prototype.slice.call(backdrop.querySelectorAll('[data-external-mapping-row]')).forEach(function(r){ var path=(r.querySelector('[data-external-map-path]')||{}).value||''; var target=(r.querySelector('[data-external-map-target]')||{}).value||''; if(String(path).trim()!=='' || String(target).trim()!=='') mappings.push({response_path:String(path).trim(), target_param_key:String(target).trim()}); }); return {method:method, url:String(url).trim(), headers:headers, body:(backdrop.querySelector('[data-external-body]')||{}).value||'', mappings:mappings}; }
          if(e.target.closest('[data-external-test]')){ e.preventDefault(); var cfg=readExternalForm(); if(!/^https:\/\//i.test(String(cfg.url).trim())){ if(typeof window.tgScenarioFlowToast==='function') window.tgScenarioFlowToast('Укажите HTTPS URL для внешнего запроса.', true); return; } var resultBox=backdrop.querySelector('[data-external-pane="response"] .tg-external-result'); if(resultBox){ resultBox.textContent='Выполняю тестовый запрос...'; } activate('response'); var fd=new FormData(); fd.set('tg_ajax','scenario_external_request_test'); fd.set('return_page','scenario_flow'); var csrfEl = form ? form.querySelector('input[name="csrf_token"]') : null; if (csrfEl && csrfEl.value) fd.set('csrf_token', csrfEl.value); fd.set('method', cfg.method); fd.set('url', cfg.url); fd.set('headers_json', JSON.stringify(cfg.headers||[])); fd.set('body', cfg.body||''); fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){ return r.text().then(function(text){ var data=null; try { data = text ? JSON.parse(text) : null; } catch(parseErr) { var sample = String(text || '').replace(/\s+/g, ' ').slice(0, 500); return { ok:false, http_code:r.status || 0, error:'Сервер вернул HTML вместо JSON. Обычно это значит, что запрос ушёл не в ajax-обработчик или actions.php не обновился. Фрагмент ответа: '+sample, response_headers:'', response_body:'' }; } return data || {ok:false, http_code:r.status || 0, error:'Пустой ответ сервера.', response_headers:'', response_body:''}; }); }).then(function(data){ var out=''; if(data && data.ok){ out+='Статус: успешно\n'; } else { out+='Статус: ошибка\n'; } if(data && typeof data.http_code!=='undefined') out+='HTTP-код: '+data.http_code+'\n'; if(data && data.duration_ms) out+='Время: '+data.duration_ms+' мс\n'; if(data && data.error) out+='Ошибка: '+data.error+'\n'; if(data && data.response_headers) out+='\nЗаголовки ответа:\n'+data.response_headers+'\n'; if(data && typeof data.response_body!=='undefined' && data.response_body !== '') out+='\nТело ответа:\n'+data.response_body; if(!out) out='Не удалось получить ответ.'; if(resultBox) resultBox.textContent=out; }).catch(function(err){ if(resultBox) resultBox.textContent='Ошибка тестового запроса: '+(err && err.message ? err.message : err); }); return; }
          if(e.target.closest('[data-external-save]')){ e.preventDefault(); var cfg=readExternalForm(); if(!/^https:\/\//i.test(String(cfg.url).trim())){ if(typeof window.tgScenarioFlowToast==='function') window.tgScenarioFlowToast('Укажите HTTPS URL для внешнего запроса.', true); return; } actions[index] = Object.assign({}, actions[index] || {}, {type:'external_request', method:cfg.method, url:cfg.url, headers:cfg.headers, body:cfg.body, mappings:cfg.mappings || []}); close(); render(); } });
      }
      box.addEventListener('click', function(event){
        var add = event.target.closest('[data-action-add]');
        if (add) { event.preventDefault(); actions.push({type:'add_tag'}); render(); return; }
        var remove = event.target.closest('[data-action-remove]');
        if (remove) { event.preventDefault(); var card = remove.closest('[data-action-index]'); var idx = card ? parseInt(card.getAttribute('data-action-index')||'-1',10) : -1; if (idx >= 0) { actions.splice(idx,1); render(); } return; }
        var extEdit = event.target.closest('[data-external-edit]');
        if (extEdit) { event.preventDefault(); var extCard = extEdit.closest('[data-action-index]'); var extIdx = extCard ? parseInt(extCard.getAttribute('data-action-index')||'-1',10) : -1; if (extIdx >= 0) openExternalModal(extIdx); return; }
      });
      box.addEventListener('input', function(event){
        if (event.target && event.target.matches('[data-action-step-search]')) {
          var card = event.target.closest('[data-action-index]');
          var q = String(event.target.value || '').toLowerCase().trim();
          Array.prototype.slice.call((card || box).querySelectorAll('[data-step-row]')).forEach(function(row){
            var text = row.getAttribute('data-step-search-text') || '';
            row.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
          });
        }
        syncHidden(); validate(false);
      });
      box.addEventListener('change', function(event){
        var card = event.target.closest('[data-action-index]');
        if (card && event.target.matches('[data-action-type]')) { var idx = parseInt(card.getAttribute('data-action-index')||'-1',10); if (idx >= 0) { actions[idx] = {type:event.target.value || 'add_tag'}; render(); return; } }
        if (card && event.target.matches('[data-action-param]')) { var pidx = parseInt(card.getAttribute('data-action-index')||'-1',10); if (pidx >= 0) { collect(); actions[pidx].param_key = event.target.value || ''; actions[pidx].param_type = fieldTypeFor(actions[pidx].param_key); actions[pidx].operation = 'set'; actions[pidx].value = ''; render(); return; } }
        if (card && event.target.matches('[data-action-operation]')) { var oidx = parseInt(card.getAttribute('data-action-index')||'-1',10); if (oidx >= 0) { collect(); actions[oidx].operation = event.target.value || 'set'; if (actions[oidx].operation === 'clear') actions[oidx].value = ''; render(); return; } }
        if (card && event.target.matches('[data-action-message-var]')) { var token = event.target.value || ''; if (token) { var textarea = card.querySelector('[data-action-message]'); if (textarea) { var start = textarea.selectionStart || textarea.value.length; var end = textarea.selectionEnd || start; textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end); textarea.focus(); textarea.selectionStart = textarea.selectionEnd = start + token.length; } event.target.value = ''; syncHidden(); validate(false); return; } }
        syncHidden(); validate(false);
      });
      form.addEventListener('submit', function(event){
        syncHidden();
        if (!validate(true)) {
          event.preventDefault();
          var alertBox = document.getElementById('scenario-message-alert');
          if (alertBox) { alertBox.textContent = 'Заполните обязательные поля в действиях.'; alertBox.classList.add('is-open'); alertBox.scrollIntoView({block:'nearest', behavior:'smooth'}); }
        }
      });
      var panelMenu = panel.querySelector('[data-panel-menu]');
      var panelMenuToggle = panel.querySelector('[data-panel-menu-toggle]');
      function closePanelMenu(){ if (panelMenu) panelMenu.classList.remove('is-open'); }
      async function postBlockAction(action, payload){
        payload = payload || {};
        if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
        var fd = new FormData(); fd.set('action', action === 'tg_scenario_duplicate_block' ? 'tg_scenario_block_duplicate' : action); fd.set('return_page','scenario_flow'); fd.set('tg_ajax','1'); fd.set('scenario_id', String(<?php echo (int)$scenarioId; ?>));
        Object.keys(payload).forEach(function(key){ fd.set(key, String(payload[key] == null ? '' : payload[key])); });
        var csrf = form.querySelector('input[name="csrf_token"]'); if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
        var response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        var text = await response.text(); var json = null; try { json = text ? JSON.parse(text) : null; } catch(e) {}
        if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.'); return json;
      }
      function showPanelAlert(message){ var alertBox = document.getElementById('scenario-message-alert'); if (alertBox) { alertBox.textContent = message || 'Ошибка'; alertBox.classList.add('is-open'); } }
      if (panelMenuToggle) panelMenuToggle.addEventListener('click', function(event){ event.preventDefault(); event.stopPropagation(); if (panelMenu) panelMenu.classList.toggle('is-open'); });
      if (panelMenu) panelMenu.addEventListener('click', function(event){ event.stopPropagation(); });
      document.addEventListener('click', closePanelMenu);
      var duplicateBtn = panel.querySelector('[data-panel-duplicate]');
      if (duplicateBtn) duplicateBtn.addEventListener('click', async function(event){ event.preventDefault(); closePanelMenu(); try { await postBlockAction('tg_scenario_duplicate_block', {block_id: <?php echo (int)$blockId; ?>}); if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh(); if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer(); } catch(error) { showPanelAlert(error.message || 'Не удалось дублировать блок.'); } });
      var deleteBtn = panel.querySelector('[data-panel-delete]');
      if (deleteBtn) deleteBtn.addEventListener('click', async function(event){ event.preventDefault(); closePanelMenu(); var ok = true; if (typeof window.tgScenarioFlowConfirm === 'function') ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок?', dangerText:'Удалить', cancelText:'Отмена'}); if (!ok) return; try { await postBlockAction('tg_scenario_block_delete', {block_id: <?php echo (int)$blockId; ?>}); if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh(); if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer(); } catch(error) { showPanelAlert(error.message || 'Не удалось удалить блок.'); } });
      render();
    })();
    </script>
    <?php
    return;
}


if ($type === 'condition') {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $matchMode = (string)($settings['condition_match_mode'] ?? 'all');
    if (!in_array($matchMode, ['all','any'], true)) $matchMode = 'all';
    $conditions = isset($settings['conditions']) && is_array($settings['conditions']) ? array_values($settings['conditions']) : [];
    if (!$conditions) $conditions = [[]];
    $tags = function_exists('asr_tg_tags_all_light') ? asr_tg_tags_all_light($pdo, 0) : [];
    $channels = function_exists('asr_tg_bots_all_light') ? asr_tg_bots_all_light($pdo) : [];
    $parameters = function_exists('asr_tg_scenario_condition_parameter_catalog') ? array_values(asr_tg_scenario_condition_parameter_catalog($pdo)) : [];
    if (!$parameters) {
        $parameters = [
            ['key' => 'special:tag', 'title' => 'Тег', 'param_type' => 'tag'],
            ['key' => 'special:current_date', 'title' => 'Текущая дата', 'param_type' => 'current_date'],
            ['key' => 'special:current_time', 'title' => 'Текущее время', 'param_type' => 'current_time'],
            ['key' => 'special:weekday', 'title' => 'День недели', 'param_type' => 'weekday'],
            ['key' => 'field:first_name', 'title' => 'Имя', 'param_type' => 'text', 'field_code' => 'first_name'],
            ['key' => 'field:email', 'title' => 'Email', 'param_type' => 'text', 'field_code' => 'email'],
        ];
    }
    $paramByKey = [];
    foreach ($parameters as $param) {
        $key = (string)($param['key'] ?? '');
        if ($key !== '') $paramByKey[$key] = $param;
    }
    $weekdays = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
    $conditionView = static function(array $rule) use ($paramByKey): array {
        $paramKey = trim((string)($rule['param_key'] ?? ''));
        $operator = trim((string)($rule['operator'] ?? ''));
        $value = trim((string)($rule['value'] ?? ''));
        $value2 = trim((string)($rule['value2'] ?? ''));
        $legacyType = (string)($rule['type'] ?? '');
        if ($paramKey === '') {
            if ($legacyType === 'tag') {
                $paramKey = 'special:tag';
                $value = (string)((int)($rule['tag_id'] ?? $value));
            } elseif ($legacyType === 'current_date') {
                $paramKey = 'special:current_date';
                $value = trim((string)($rule['date'] ?? $value));
            } elseif ($legacyType === 'current_time') {
                $paramKey = 'special:current_time';
                $value = trim((string)($rule['time'] ?? $value));
            } elseif ($legacyType === 'weekday') {
                $paramKey = 'special:weekday';
                $value = (string)((int)($rule['weekday'] ?? $value ?: 1));
            } elseif ($legacyType === 'channel') {
                $paramKey = 'special:channel';
                $value = (string)((int)($rule['bot_id'] ?? $value));
            } else {
                $fieldCode = trim((string)($rule['field_code'] ?? ''));
                $fieldType = (string)($rule['field_type'] ?? 'text');
                $prefix = (string)($rule['field_source'] ?? '') === 'custom' ? 'custom:' : 'field:';
                $paramKey = $fieldCode !== '' ? ($prefix . $fieldCode) : '';
                if ($paramKey !== '' && !isset($paramByKey[$paramKey])) {
                    $paramKey = 'custom:' . $fieldCode;
                    if (!isset($paramByKey[$paramKey])) $paramKey = 'field:' . $fieldCode;
                }
            }
        }
        return [
            'param_key' => $paramKey,
            'operator' => $operator,
            'value' => $value,
            'value2' => $value2,
        ];
    };
    $csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <form method="POST" id="scenario-message-form" class="tg-flow-panel-form">
            <div class="tg-flow-panel-head tg-condition-panel-head">
                <div>
                    <div class="tg-flow-panel-title">Редактировать блок «Условие»</div>
                    <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?> · ветки «Да» и «Нет»</div>
                </div>
                <div class="tg-flow-panel-actions">
                    <div class="tg-flow-panel-menu-wrap">
                        <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                        <div class="tg-flow-panel-dropdown" data-panel-menu>
                            <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                            <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                        </div>
                    </div>
                    <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
                </div>
            </div>
            <div class="tg-flow-panel-body">
                <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
                <input type="hidden" name="action" value="tg_scenario_block_save">
                <input type="hidden" name="return_page" value="scenario_flow">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
                <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
                <input type="hidden" name="block_type" value="condition">
                <input type="hidden" name="scenario_cards_json" id="scenario-cards-json" value="[]">
                <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

                <label class="tg-flow-panel-field">
                    <span class="tg-flow-panel-label">Название блока</span>
                    <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Условие')); ?>" maxlength="190">
                    <span class="tg-flow-panel-block-id">Блок #<?php echo (int)$blockId; ?></span>
                </label>

                <div class="tg-condition-box" data-condition-box data-max="15">
                    <div class="tg-condition-section-title">Условие</div>
                    <label class="tg-flow-panel-field tg-condition-match-field">
                        <span class="tg-flow-panel-label">Подписчик соответствует</span>
                        <select class="tg-flow-panel-input" name="condition_match_mode">
                            <option value="all" <?php echo $matchMode === 'all' ? 'selected' : ''; ?>>Каждому из этих условий</option>
                            <option value="any" <?php echo $matchMode === 'any' ? 'selected' : ''; ?>>Любому из этих условий</option>
                        </select>
                    </label>

                    <div class="tg-condition-rules" data-condition-rules>
                        <?php foreach ($conditions as $rule): ?>
                            <?php
                            $view = $conditionView(is_array($rule) ? $rule : []);
                            $rParamKey = (string)$view['param_key'];
                            if ($rParamKey === '' || !isset($paramByKey[$rParamKey])) $rParamKey = (string)($parameters[0]['key'] ?? '');
                            $rOperator = (string)$view['operator'];
                            $rValue = (string)$view['value'];
                            $rValue2 = (string)$view['value2'];
                            ?>
                            <div class="tg-condition-rule" data-condition-rule>
                                <div class="tg-condition-rule-top">
                                    <label class="tg-flow-panel-field tg-condition-param-field">
                                        <span class="tg-flow-panel-label">Поле / параметр</span>
                                        <select class="tg-flow-panel-input" name="condition_param_key[]" data-condition-param>
                                            <?php foreach ($parameters as $param): ?>
                                                <?php $pKey = (string)($param['key'] ?? ''); if ($pKey === '') continue; $pType = (string)($param['param_type'] ?? 'text'); ?>
                                                <option value="<?php echo $h($pKey); ?>" data-param-type="<?php echo $h($pType); ?>" <?php echo $rParamKey === $pKey ? 'selected' : ''; ?>><?php echo $h((string)($param['title'] ?? $pKey)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="button" class="tg-condition-remove" data-condition-remove title="Удалить условие" aria-label="Удалить условие">🗑</button>
                                </div>

                                <div class="tg-condition-rule-grid">
                                    <label class="tg-flow-panel-field" data-cond-op-wrap>
                                        <span class="tg-flow-panel-label">Условие</span>
                                        <select class="tg-flow-panel-input" name="condition_operator[]" data-condition-operator data-value="<?php echo $h($rOperator); ?>"></select>
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-text-wrap>
                                        <span class="tg-flow-panel-label">Значение</span>
                                        <input class="tg-flow-panel-input" type="text" name="condition_value_text[]" value="<?php echo $h($rValue); ?>" placeholder="Например: Москва">
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-number-wrap>
                                        <span class="tg-flow-panel-label">Значение</span>
                                        <input class="tg-flow-panel-input" type="number" step="any" name="condition_value_number[]" value="<?php echo $h($rValue); ?>" placeholder="Например: 1000">
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-date-wrap>
                                        <span class="tg-flow-panel-label">Дата</span>
                                        <input class="tg-flow-panel-input" type="date" name="condition_value_date[]" value="<?php echo $h(preg_match('~^\d{4}-\d{2}-\d{2}$~', $rValue) ? $rValue : date('Y-m-d')); ?>">
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-datetime-wrap>
                                        <span class="tg-flow-panel-label">Дата и время</span>
                                        <input class="tg-flow-panel-input" type="datetime-local" name="condition_value_datetime[]" value="<?php echo $h(preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$~', $rValue) ? $rValue : ''); ?>">
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-time-wrap>
                                        <span class="tg-flow-panel-label">Время</span>
                                        <input class="tg-flow-panel-input" type="time" name="condition_value_time[]" value="<?php echo $h(preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $rValue) ? $rValue : '12:00'); ?>">
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-weekday-wrap>
                                        <span class="tg-flow-panel-label">День недели</span>
                                        <select class="tg-flow-panel-input" name="condition_value_weekday[]">
                                            <?php foreach ($weekdays as $dayNum => $dayTitle): ?>
                                                <option value="<?php echo (int)$dayNum; ?>" <?php echo (int)$rValue === (int)$dayNum ? 'selected' : ''; ?>><?php echo $h($dayTitle); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-tag-wrap>
                                        <span class="tg-flow-panel-label">Тег</span>
                                        <select class="tg-flow-panel-input" name="condition_value_tag[]">
                                            <option value="0">Выберите тег</option>
                                            <?php foreach ($tags as $tag): ?>
                                                <?php $tagId = (int)($tag['id'] ?? 0); if ($tagId <= 0) continue; ?>
                                                <option value="<?php echo $tagId; ?>" <?php echo (int)$rValue === $tagId ? 'selected' : ''; ?>><?php echo $h((string)($tag['name'] ?? ('Тег #' . $tagId))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="tg-flow-panel-field" data-cond-value-channel-wrap>
                                        <span class="tg-flow-panel-label">Канал</span>
                                        <select class="tg-flow-panel-input" name="condition_value_channel[]">
                                            <option value="0">Выберите канал</option>
                                            <?php foreach ($channels as $channel): ?>
                                                <?php $channelId = (int)($channel['id'] ?? 0); if ($channelId <= 0) continue; ?>
                                                <option value="<?php echo $channelId; ?>" <?php echo (int)$rValue === $channelId ? 'selected' : ''; ?>><?php echo $h((string)($channel['title'] ?? $channel['name'] ?? ('Канал #' . $channelId))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <input type="hidden" name="condition_value2[]" value="<?php echo $h($rValue2); ?>">
                                </div>
                                <div class="tg-condition-rule-error" data-condition-rule-error>Заполните условие полностью.</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="tg-condition-actions"><button type="button" class="tg-flow-panel-secondary" data-condition-add>+ Добавить условие</button><span data-condition-counter></span></div>
                    <div class="tg-condition-note">Сначала выбираем поле или параметр. Тип данных определяется автоматически, поэтому лишнего выбора «текст/число/дата» больше нет.</div>
                </div>
            </div>
            <div class="tg-flow-panel-footer"><button type="submit" class="tg-flow-panel-primary">Сохранить блок</button></div>
        </form>
    </section>
    <style data-flow-panel-style="scenario-condition-panel-v3.5.141">
    .tg-condition-panel-head{border-bottom-color:#d9edc8}.tg-condition-box{border:1px solid #d9edc8;background:#fbfff7;border-radius:18px;padding:20px;margin-top:12px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.tg-condition-section-title{font-size:18px;font-weight:650;color:#2f3a2b;margin:0 0 14px}.tg-condition-match-field{margin-bottom:22px}.tg-condition-rules{display:flex;flex-direction:column;gap:14px;margin-top:10px}.tg-condition-rule{background:#f6f7f4;border:1px solid #edf0e8;border-radius:8px;padding:18px 16px 16px;position:relative}.tg-condition-rule.is-invalid{border-color:#ff6b73;box-shadow:0 0 0 1px rgba(255,107,115,.55);background:#fffafa}.tg-condition-rule-error{display:none;margin-top:8px;color:#dc2626;font-size:12px;font-weight:650;line-height:1.35}.tg-condition-rule.is-invalid .tg-condition-rule-error{display:block}.tg-condition-rule.is-invalid .tg-flow-panel-input[data-invalid="1"]{border-color:#ff6b73!important;box-shadow:0 0 0 3px rgba(255,107,115,.12)!important}.tg-condition-rule-top{display:grid;grid-template-columns:minmax(0,1fr) 34px;align-items:start;gap:12px;margin-bottom:12px}.tg-condition-param-field{margin:0}.tg-condition-rule-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tg-condition-rule .tg-flow-panel-field{margin:0}.tg-condition-rule .tg-flow-panel-label,.tg-condition-match-field .tg-flow-panel-label{font-size:12px;font-weight:650;color:#7b8176;background:transparent}.tg-condition-rule .tg-flow-panel-input,.tg-condition-match-field .tg-flow-panel-input{background:#fff;border-color:#cfd6cb;border-radius:4px;min-height:48px;font-size:15px;color:#2f3437}.tg-condition-rule .tg-flow-panel-input:focus,.tg-condition-match-field .tg-flow-panel-input:focus{border-color:#8db85f;box-shadow:0 0 0 3px rgba(141,184,95,.16)}.tg-condition-remove{border:0;background:transparent;color:#a8aaa6;font-size:18px;line-height:1;cursor:pointer;padding:10px 6px;border-radius:8px}.tg-condition-remove:hover{color:#dc2626;background:#fff1f1}.tg-condition-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}.tg-condition-actions span{font-size:12px;font-weight:650;color:#6b7280}.tg-condition-note{margin-top:14px;border:1px solid #e5e7eb;background:#fff;border-radius:14px;padding:11px 12px;color:#64748b;font-size:12px;line-height:1.45}.tg-condition-rule-grid [data-cond-value-text-wrap],.tg-condition-rule-grid [data-cond-value-number-wrap],.tg-condition-rule-grid [data-cond-value-date-wrap],.tg-condition-rule-grid [data-cond-value-datetime-wrap],.tg-condition-rule-grid [data-cond-value-time-wrap],.tg-condition-rule-grid [data-cond-value-weekday-wrap],.tg-condition-rule-grid [data-cond-value-tag-wrap],.tg-condition-rule-grid [data-cond-value-channel-wrap]{display:none}.tg-condition-rule[data-param-type="text"] [data-cond-value-text-wrap],.tg-condition-rule[data-param-type="number"] [data-cond-value-number-wrap],.tg-condition-rule[data-param-type="date"] [data-cond-value-date-wrap],.tg-condition-rule[data-param-type="datetime"] [data-cond-value-datetime-wrap],.tg-condition-rule[data-param-type="current_date"] [data-cond-value-date-wrap],.tg-condition-rule[data-param-type="current_time"] [data-cond-value-time-wrap],.tg-condition-rule[data-param-type="weekday"] [data-cond-value-weekday-wrap],.tg-condition-rule[data-param-type="tag"] [data-cond-value-tag-wrap],.tg-condition-rule[data-param-type="channel"] [data-cond-value-channel-wrap]{display:block}.tg-condition-rule[data-op-no-value="1"] [data-cond-value-text-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-number-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-date-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-datetime-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-time-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-weekday-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-tag-wrap],.tg-condition-rule[data-op-no-value="1"] [data-cond-value-channel-wrap]{display:none}@media(max-width:680px){.tg-condition-box{padding:16px}.tg-condition-rule-grid,.tg-condition-rule-top{grid-template-columns:1fr}.tg-condition-remove{justify-self:end}.tg-condition-actions{align-items:stretch;flex-direction:column}.tg-condition-actions button{width:100%}}
    </style>
    <script data-flow-panel-script>
    (function(){
      var box = document.querySelector('[data-condition-box]');
      if (!box || box.dataset.bound === '1') return;
      box.dataset.bound = '1';
      var rulesBox = box.querySelector('[data-condition-rules]');
      var addBtn = box.querySelector('[data-condition-add]');
      var counter = box.querySelector('[data-condition-counter]');
      var max = parseInt(box.getAttribute('data-max') || '15', 10) || 15;
      var panel = document.getElementById('tg-flow-panel');
      var form = document.getElementById('scenario-message-form');
      var blockMeta = {scenarioId: <?php echo (int)$scenarioId; ?>, blockId: <?php echo (int)$blockId; ?>};
      var panelMenu = panel ? panel.querySelector('[data-panel-menu]') : null;
      var panelMenuToggle = panel ? panel.querySelector('[data-panel-menu-toggle]') : null;
      function closePanelMenu(){ if (panelMenu) panelMenu.classList.remove('is-open'); }
      function showPanelAlert(message){
        var alertBox = panel ? panel.querySelector('#scenario-message-alert') : null;
        if (alertBox) {
          alertBox.textContent = String(message || 'Ошибка');
          alertBox.classList.add('is-open');
          if (alertBox.scrollIntoView) alertBox.scrollIntoView({block:'nearest', behavior:'smooth'});
        } else if (typeof window.tgScenarioFlowToast === 'function') {
          window.tgScenarioFlowToast(String(message || 'Ошибка'), 'error');
        }
      }
      async function postBlockAction(action, payload){
        payload = payload || {};
        if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
        var actionMap = {tg_scenario_duplicate_block: 'tg_scenario_block_duplicate'};
        var fd = new FormData();
        fd.set('action', actionMap[action] || action);
        fd.set('return_page', 'scenario_flow');
        fd.set('tg_ajax', '1');
        fd.set('scenario_id', String(blockMeta.scenarioId || ''));
        Object.keys(payload).forEach(function(key){ fd.set(key, String(payload[key] == null ? '' : payload[key])); });
        var csrf = form ? form.querySelector('input[name="csrf_token"]') : null;
        if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
        var response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        var text = await response.text();
        var json = null;
        try { json = text ? JSON.parse(text) : null; } catch(e) { json = null; }
        if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.');
        return json;
      }
      if (panelMenuToggle) {
        panelMenuToggle.addEventListener('click', function(event){
          event.preventDefault();
          event.stopPropagation();
          if (panelMenu) panelMenu.classList.toggle('is-open');
        });
      }
      if (panelMenu) panelMenu.addEventListener('click', function(event){ event.stopPropagation(); });
      document.addEventListener('click', closePanelMenu);
      var duplicateBtn = panel ? panel.querySelector('[data-panel-duplicate]') : null;
      if (duplicateBtn) duplicateBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        try {
          await postBlockAction('tg_scenario_duplicate_block', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось дублировать блок.'); }
      });
      var deleteBtn = panel ? panel.querySelector('[data-panel-delete]') : null;
      if (deleteBtn) deleteBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        var ok = true;
        if (typeof window.tgScenarioFlowConfirm === 'function') {
          ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText:'Удалить', cancelText:'Отмена'});
        }
        if (!ok) return;
        try {
          await postBlockAction('tg_scenario_block_delete', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось удалить блок.'); }
      });
      var operatorGroups = {
        text: [['equals','Соответствует'], ['not_equals','Не соответствует'], ['contains','Содержит'], ['not_contains','Не содержит'], ['is_empty','Неизвестно'], ['is_filled','Имеет какое-то значение']],
        number: [['eq','Равно'], ['ne','Не равно'], ['gt','Больше'], ['gte','Больше либо равно'], ['lt','Меньше'], ['lte','Меньше либо равно'], ['is_empty','Не заполнено'], ['is_filled','Заполнено']],
        date: [['eq','Равно'], ['ne','Не равно'], ['gt','Позже'], ['gte','Позже или равно'], ['lt','Раньше'], ['lte','Раньше или равно'], ['is_empty','Не заполнено'], ['is_filled','Заполнено']],
        datetime: [['eq','Равно'], ['ne','Не равно'], ['gt','Позже'], ['gte','Позже или равно'], ['lt','Раньше'], ['lte','Раньше или равно'], ['is_empty','Не заполнено'], ['is_filled','Заполнено']],
        current_date: [['eq','Равно'], ['gte','Больше либо равно'], ['lte','Меньше либо равно']],
        current_time: [['gte','Больше либо равно'], ['lte','Меньше либо равно']],
        weekday: [['eq','Соответствует'], ['ne','Не соответствует']],
        tag: [['has_tag','Есть тег'], ['not_has_tag','Нет тега']],
        channel: [['has_channel','Подписан'], ['not_has_channel','Не подписан']]
      };
      var noValueOps = {is_empty:1,is_filled:1};
      function paramType(rule){
        var param = rule.querySelector('[data-condition-param]');
        var selected = param && param.options ? param.options[param.selectedIndex] : null;
        return (selected && selected.getAttribute('data-param-type')) || 'text';
      }
      function syncRule(rule){
        var type = paramType(rule);
        var opEl = rule.querySelector('[data-condition-operator]');
        rule.setAttribute('data-param-type', type);
        if (opEl) {
          var selected = opEl.getAttribute('data-value') || opEl.value || '';
          var ops = operatorGroups[type] || operatorGroups.text;
          opEl.innerHTML = '';
          ops.forEach(function(pair){ var opt = document.createElement('option'); opt.value = pair[0]; opt.textContent = pair[1]; if (pair[0] === selected) opt.selected = true; opEl.appendChild(opt); });
          if (!opEl.value && ops[0]) opEl.value = ops[0][0];
          opEl.setAttribute('data-value', opEl.value);
          rule.setAttribute('data-op-no-value', noValueOps[opEl.value] ? '1' : '0');
        }
      }
      function allRules(){ return Array.prototype.slice.call(rulesBox ? rulesBox.querySelectorAll('[data-condition-rule]') : []); }
      function visibleValueInput(rule, type){
        var map = {text:'[data-cond-value-text-wrap] input', number:'[data-cond-value-number-wrap] input', date:'[data-cond-value-date-wrap] input', datetime:'[data-cond-value-datetime-wrap] input', current_date:'[data-cond-value-date-wrap] input', current_time:'[data-cond-value-time-wrap] input', weekday:'[data-cond-value-weekday-wrap] select', tag:'[data-cond-value-tag-wrap] select', channel:'[data-cond-value-channel-wrap] select'};
        return rule.querySelector(map[type] || '[data-cond-value-text-wrap] input');
      }
      function validateRule(rule){
        if (!rule) return false;
        rule.classList.remove('is-invalid');
        rule.querySelectorAll('[data-invalid]').forEach(function(el){ el.removeAttribute('data-invalid'); });
        var error = rule.querySelector('[data-condition-rule-error]');
        if (error) error.textContent = 'Заполните условие полностью.';
        var param = rule.querySelector('[data-condition-param]');
        var op = rule.querySelector('[data-condition-operator]');
        var type = paramType(rule);
        var ok = true;
        if (!param || !param.value) { ok = false; if (param) param.setAttribute('data-invalid','1'); }
        if (!op || !op.value) { ok = false; if (op) op.setAttribute('data-invalid','1'); }
        if (op && noValueOps[op.value]) return ok;
        var input = visibleValueInput(rule, type);
        var value = input ? String(input.value || '').trim() : '';
        if (!input || value === '' || ((type === 'tag' || type === 'channel' || type === 'weekday') && value === '0')) {
          ok = false;
          if (input) input.setAttribute('data-invalid','1');
        }
        if (!ok) rule.classList.add('is-invalid');
        return ok;
      }
      function validateAll(){
        var rules = allRules();
        var validCount = 0;
        rules.forEach(function(rule){ if (validateRule(rule)) validCount++; });
        return {ok: validCount > 0 && validCount === rules.length, total: rules.length, valid: validCount};
      }
      function syncAll(){
        allRules().forEach(syncRule);
        var count = allRules().length;
        if (counter) counter.textContent = count + ' из ' + max + ' условий';
        if (addBtn) addBtn.disabled = count >= max;
        validateAll();
      }
      function resetValue(el){
        if (!el) return;
        if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else if (el.type === 'time') el.value = '12:00';
        else if (el.type === 'date') el.value = new Date().toISOString().slice(0,10);
        else el.value = '';
        if (el.matches('[data-condition-operator]')) el.setAttribute('data-value','');
      }
      if (rulesBox) {
        rulesBox.addEventListener('change', function(e){
          var rule = e.target.closest ? e.target.closest('[data-condition-rule]') : null;
          if (!rule) return;
          if (e.target.matches('[data-condition-operator]')) e.target.setAttribute('data-value', e.target.value || '');
          if (e.target.matches('[data-condition-param]')) {
            var op = rule.querySelector('[data-condition-operator]');
            if (op) op.setAttribute('data-value','');
          }
          syncRule(rule);
        });
        rulesBox.addEventListener('click', function(e){
          var btn = e.target.closest ? e.target.closest('[data-condition-remove]') : null;
          if (!btn) return;
          var rules = allRules();
          if (rules.length <= 1) {
            var rule = rules[0];
            if (rule) rule.querySelectorAll('input,select').forEach(resetValue);
          } else {
            btn.closest('[data-condition-rule]').remove();
          }
          syncAll();
        });
      }
      if (rulesBox) rulesBox.addEventListener('input', function(e){
        var rule = e.target.closest ? e.target.closest('[data-condition-rule]') : null;
        if (rule) validateRule(rule);
      });
      if (addBtn && rulesBox) addBtn.addEventListener('click', function(){
        var rules = allRules();
        if (rules.length >= max || !rules[0]) return;
        var clone = rules[0].cloneNode(true);
        clone.querySelectorAll('input,select').forEach(resetValue);
        rulesBox.appendChild(clone);
        syncAll();
      });
      var form = box.closest ? box.closest('form') : null;
      if (form) form.tgFlowPrepareSave = function(){
        var result = validateAll();
        if (result.ok) return {ok:true};
        var alertBox = form.querySelector('#scenario-message-alert') || form.querySelector('.tg-flow-panel-alert');
        if (alertBox) {
          alertBox.textContent = 'Заполните условие полностью: выберите поле, оператор и значение. Некорректные строки подсвечены красным.';
          alertBox.classList.add('is-open');
        }
        var firstBad = box.querySelector('.tg-condition-rule.is-invalid');
        if (firstBad && firstBad.scrollIntoView) firstBad.scrollIntoView({behavior:'smooth', block:'center'});
        return {ok:false, message:'Заполните условие полностью.'};
      };
      syncAll();
    })();
    </script>
    <?php
    return;
}


if ($type === 'schedule') {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $scheduleMode = (string)($settings['schedule_mode'] ?? 'fixed');
    if (!in_array($scheduleMode, ['fixed','field'], true)) $scheduleMode = 'fixed';
    $scheduleDate = trim((string)($settings['schedule_date'] ?? ''));
    $scheduleTime = trim((string)($settings['schedule_time'] ?? '12:00'));
    if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $scheduleTime)) $scheduleTime = '12:00';
    $scheduleFieldCode = trim((string)($settings['schedule_field_code'] ?? ''));
    $scheduleFieldFallbackDate = trim((string)($settings['schedule_field_fallback_date'] ?? ''));
    $scheduleFieldFallbackTime = trim((string)($settings['schedule_field_fallback_time'] ?? '12:00'));
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $scheduleFieldFallbackDate)) $scheduleFieldFallbackDate = '';
    if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $scheduleFieldFallbackTime)) $scheduleFieldFallbackTime = '12:00';
    $timezone = trim((string)($settings['timezone'] ?? $scenarioTimezone)) ?: $scenarioTimezone;
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = $scenarioTimezone; }
    $dateFields = [];
    try {
        if (function_exists('asr_tg_custom_fields_all')) {
            foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
                $code = trim((string)($field['code'] ?? ''));
                $fieldType = trim((string)($field['field_type'] ?? ''));
                if ($code === '' || !in_array($fieldType, ['date','datetime'], true)) continue;
                $dateFields[] = [
                    'code' => $code,
                    'title' => trim((string)($field['title'] ?? $code)) ?: $code,
                    'type' => $fieldType,
                    'id' => (int)($field['id'] ?? 0),
                ];
            }
        }
    } catch (Throwable $e) { $dateFields = []; }
    $timezonePriorityLabels = [
        'Asia/Almaty' => 'Asia/Almaty — Алматы, Астана',
        'Europe/Moscow' => 'Europe/Moscow — Москва',
        'Asia/Yekaterinburg' => 'Asia/Yekaterinburg — Екатеринбург',
        'Asia/Tashkent' => 'Asia/Tashkent — Ташкент',
        'Asia/Dubai' => 'Asia/Dubai — Дубай',
        'Europe/Istanbul' => 'Europe/Istanbul — Стамбул',
        'UTC' => 'UTC — всемирное время',
    ];
    $timezoneIds = [];
    try {
        $timezoneIds = timezone_identifiers_list(DateTimeZone::ALL);
    } catch (Throwable $e) {
        $timezoneIds = timezone_identifiers_list();
    }
    if (!in_array('UTC', $timezoneIds, true)) $timezoneIds[] = 'UTC';
    if (!in_array($timezone, $timezoneIds, true)) $timezoneIds[] = $timezone;
    $timezoneChoices = [];
    foreach ($timezonePriorityLabels as $tzCode => $tzLabel) {
        if (in_array($tzCode, $timezoneIds, true)) $timezoneChoices[$tzCode] = ['label' => $tzLabel, 'popular' => true];
    }
    sort($timezoneIds, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($timezoneIds as $tzCode) {
        $tzCode = trim((string)$tzCode);
        if ($tzCode === '' || isset($timezoneChoices[$tzCode])) continue;
        $timezoneChoices[$tzCode] = ['label' => $tzCode, 'popular' => false];
    }
    $tzOffset = static function(string $tz): string {
        try {
            $zone = new DateTimeZone($tz);
            $offset = $zone->getOffset(new DateTimeImmutable('now', $zone));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            return 'GMT' . $sign . sprintf('%02d:%02d', intdiv($offset, 3600), intdiv($offset % 3600, 60));
        } catch (Throwable $e) { return 'GMT+00:00'; }
    };
    $timezoneTitle = '(' . $tzOffset($timezone) . ') ' . $timezone;
    $lower = static function(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <form method="POST" id="scenario-message-form" class="tg-flow-panel-form">
            <div class="tg-flow-panel-head">
                <div>
                    <div class="tg-flow-panel-title">Редактировать блок «Расписание»</div>
                    <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
                </div>
                <div class="tg-flow-panel-actions">
                    <div class="tg-flow-panel-menu-wrap">
                        <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                        <div class="tg-flow-panel-dropdown" data-panel-menu>
                            <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                            <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                        </div>
                    </div>
                    <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
                </div>
            </div>
            <div class="tg-flow-panel-body">
                <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
                <input type="hidden" name="action" value="tg_scenario_block_save">
                <input type="hidden" name="return_page" value="scenario_flow">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
                <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
                <input type="hidden" name="block_type" value="schedule">
                <input type="hidden" name="scenario_cards_json" value="[]">
                <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>
                <label class="tg-flow-panel-field">
                    <span class="tg-flow-panel-label">Название блока</span>
                    <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Расписание')); ?>" maxlength="190">
                    <span class="tg-flow-panel-block-id">Блок #<?php echo (int)$blockId; ?></span>
                </label>
                <div class="tg-flow-schedule-box" data-schedule-settings>
                    <div class="tg-flow-schedule-hero">
                        <div class="tg-flow-schedule-icon">🗓</div>
                        <div>
                            <h3>Расписание</h3>
                            <p>Удержит подписчика до нужной даты. Если дата уже прошла, сценарий уйдёт по отдельной ветке.</p>
                        </div>
                    </div>

                    <div class="tg-flow-schedule-section">
                        <div class="tg-flow-schedule-section-head">
                            <span>Источник даты</span>
                            <small>фиксированная дата или поле подписчика</small>
                        </div>
                        <label class="tg-flow-panel-field tg-flow-schedule-field">
                            <span class="tg-flow-panel-label">Дата и время</span>
                            <select class="tg-flow-panel-input" name="schedule_mode" data-schedule-mode>
                                <option value="fixed" <?php echo $scheduleMode === 'fixed' ? 'selected' : ''; ?>>Указать дату и время</option>
                                <option value="field" <?php echo $scheduleMode === 'field' ? 'selected' : ''; ?>>Взять из пользовательского поля</option>
                            </select>
                        </label>

                        <div class="tg-flow-schedule-grid" data-schedule-fixed>
                            <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Дата</span><input class="tg-flow-panel-input" type="date" name="schedule_date" value="<?php echo $h($scheduleDate); ?>"></label>
                            <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Время</span><input class="tg-flow-panel-input" type="time" name="schedule_time" value="<?php echo $h($scheduleTime); ?>"></label>
                        </div>

                        <div class="tg-flow-schedule-field-mode" data-schedule-field>
                            <label class="tg-flow-panel-field tg-flow-schedule-field">
                                <span class="tg-flow-panel-label">Пользовательское поле</span>
                                <select class="tg-flow-panel-input" name="schedule_field_code" data-schedule-field-select>
                                    <option value="">Выберите поле типа дата/дата-время</option>
                                    <option value="__create__" <?php echo $scheduleFieldCode === '__create__' ? 'selected' : ''; ?>>＋ Создать новое поле</option>
                                    <?php foreach ($dateFields as $field): ?>
                                        <option value="<?php echo $h($field['code']); ?>" data-field-type="<?php echo $h($field['type']); ?>" <?php echo $scheduleFieldCode === $field['code'] ? 'selected' : ''; ?>><?php echo $h($field['title'] . ' · ' . ($field['type'] === 'datetime' ? 'дата и время' : 'дата')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="tg-flow-schedule-new-field" data-schedule-new-field>
                                <div class="tg-flow-schedule-new-title">Новое пользовательское поле</div>
                                <div class="tg-flow-schedule-grid">
                                    <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Название</span><input class="tg-flow-panel-input" type="text" name="schedule_new_field_title" placeholder="Например: Дата вебинара" maxlength="190"></label>
                                    <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Тип</span><select class="tg-flow-panel-input" name="schedule_new_field_type"><option value="datetime">Дата и время</option><option value="date">Дата</option></select></label>
                                </div>
                                <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Код поля — необязательно</span><input class="tg-flow-panel-input" type="text" name="schedule_new_field_code" placeholder="webinar_date" maxlength="80"></label>
                            </div>
                            <div class="tg-flow-schedule-field-value" data-schedule-field-value>
                                <div class="tg-flow-schedule-new-title">Дата по умолчанию</div>
                                <div class="tg-flow-schedule-grid">
                                    <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Дата</span><input class="tg-flow-panel-input" type="date" name="schedule_field_fallback_date" value="<?php echo $h($scheduleFieldFallbackDate); ?>"></label>
                                    <label class="tg-flow-panel-field tg-flow-schedule-field"><span class="tg-flow-panel-label">Время</span><input class="tg-flow-panel-input" type="time" name="schedule_field_fallback_time" value="<?php echo $h($scheduleFieldFallbackTime); ?>"></label>
                                </div>
                                <span class="tg-flow-schedule-note">Если у подписчика поле уже заполнено, сценарий возьмёт его дату. Если пустое — использует дату по умолчанию.</span>
                            </div>
                            <?php if (!$dateFields): ?><div class="tg-flow-schedule-empty-note">Пока нет полей типа дата. Создайте новое поле прямо здесь — оно появится в списке пользовательских полей.</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="tg-flow-schedule-section tg-flow-schedule-tz-section">
                        <div class="tg-flow-schedule-section-head">
                            <span>Часовой пояс</span>
                            <small>расписание считается по времени сценария</small>
                        </div>
                        <div class="tg-flow-schedule-tz">
                            <div>
                                <strong data-tz-current><?php echo $h($timezoneTitle); ?></strong>
                                <small>У подписчика может быть другой часовой пояс — этот блок его не учитывает.</small>
                            </div>
                            <input type="hidden" name="timezone" data-tz-value value="<?php echo $h($timezone); ?>">
                            <button type="button" data-tz-open>Изменить часовой пояс</button>
                        </div>
                    </div>
                </div>
                <div class="tg-schedule-tz-modal" data-tz-modal aria-hidden="true">
                    <div class="tg-schedule-tz-card" role="dialog" aria-modal="true" aria-label="Часовой пояс расписания">
                        <div class="tg-schedule-tz-head"><h3>Часовой пояс</h3><button type="button" data-tz-close aria-label="Закрыть">×</button></div>
                        <p>Выберите, по какому времени считать дату и время в блоке «Расписание».</p>
                        <label class="tg-flow-panel-field tg-schedule-tz-search-field">
                            <span class="tg-flow-panel-label">Поиск</span>
                            <input class="tg-flow-panel-input" type="search" data-tz-search placeholder="Например: Алматы, Moscow, GMT+05 или Asia/Almaty" autocomplete="off">
                        </label>
                        <div class="tg-schedule-tz-list" data-tz-list>
                            <?php foreach ($timezoneChoices as $tzCode => $tzInfo): ?>
                                <?php
                                $tzLabel = is_array($tzInfo) ? (string)($tzInfo['label'] ?? $tzCode) : (string)$tzInfo;
                                $tzPopular = is_array($tzInfo) && !empty($tzInfo['popular']);
                                $tzTitle = '(' . $tzOffset($tzCode) . ') ' . $tzCode;
                                $tzSearch = $lower($tzCode . ' ' . $tzLabel . ' ' . $tzTitle);
                                ?>
                                <button type="button" class="tg-schedule-tz-option<?php echo $tzCode === $timezone ? ' is-selected' : ''; ?><?php echo $tzPopular ? ' is-popular' : ''; ?>" data-tz-option value="<?php echo $h($tzCode); ?>" data-title="<?php echo $h($tzTitle); ?>" data-search="<?php echo $h($tzSearch); ?>">
                                    <span><?php echo $h($tzTitle); ?></span>
                                    <small><?php echo $h($tzLabel); ?><?php echo $tzPopular ? ' · часто используется' : ''; ?></small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tg-schedule-tz-empty" data-tz-empty>Ничего не найдено. Попробуйте город, регион или GMT-смещение.</div>
                        <div class="tg-schedule-tz-actions"><button type="button" class="tg-flow-panel-secondary" data-tz-close>Отмена</button><button type="button" class="tg-flow-panel-primary" data-tz-apply>Применить</button></div>
                    </div>
                </div>
            </div>
            <div class="tg-flow-panel-footer"><button type="button" class="tg-flow-panel-secondary tg-flow-drawer-close">Отмена</button><button type="submit" class="tg-flow-panel-primary">Сохранить и закрыть</button></div>
        </form>
    </section>
    <style data-flow-panel-style="scenario-schedule-panel-v3.5.180">
    .tg-flow-schedule-box{border:1px solid #f0dbe7;background:linear-gradient(180deg,#fff 0%,#fff8fb 100%);border-radius:22px;padding:18px;margin-top:14px;box-shadow:0 14px 34px rgba(120,70,95,.08)}
    .tg-flow-schedule-hero{display:flex;gap:13px;align-items:flex-start;margin-bottom:16px}.tg-flow-schedule-icon{width:42px;height:42px;border-radius:16px;background:#fff1f7;border:1px solid #f6dbe8;display:flex;align-items:center;justify-content:center;font-size:21px;flex:0 0 auto}.tg-flow-schedule-box h3{margin:1px 0 5px;color:#2f343b;font-size:18px;font-weight:720;line-height:1.25}.tg-flow-schedule-box p{margin:0;color:#6b7280;font-size:13px;line-height:1.5}.tg-flow-schedule-section{background:#fff;border:1px solid #edf0f4;border-radius:18px;padding:16px;margin-top:12px}.tg-flow-schedule-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:13px}.tg-flow-schedule-section-head span{color:#2f343b;font-size:14px;font-weight:720;line-height:1.25}.tg-flow-schedule-section-head small{color:#8b929d;font-size:12px;line-height:1.35;text-align:right}.tg-flow-schedule-field{margin:0 0 12px}.tg-flow-schedule-field:last-child{margin-bottom:0}.tg-flow-schedule-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tg-flow-schedule-box .tg-flow-panel-input{min-height:44px;border-radius:13px}.tg-flow-schedule-note{display:block;margin-top:8px;color:#8b929d;font-size:12px;line-height:1.45}.tg-flow-schedule-empty-note{margin-top:12px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;color:#6b4a22;padding:11px 12px;font-size:12px;line-height:1.45}.tg-flow-schedule-new-field,.tg-flow-schedule-field-value{display:none;margin-top:12px;padding:14px;border:1px solid #f1e7ef;background:#fff8fb;border-radius:17px}.tg-flow-schedule-new-title{margin:0 0 10px;color:#4b5563;font-size:13px;font-weight:720}.tg-flow-schedule-tz-section{background:linear-gradient(180deg,#fff,#fffafc)}.tg-flow-schedule-tz{display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #f1e7ef;background:#fff8fb;border-radius:16px;padding:13px 14px}.tg-flow-schedule-tz strong{display:block;color:#374151;font-size:13px;font-weight:720;line-height:1.35;overflow-wrap:anywhere}.tg-flow-schedule-tz small{display:block;margin-top:3px;color:#8b929d;font-size:12px;line-height:1.35}.tg-flow-schedule-tz button{border:0;background:transparent;color:#d97706;padding:2px 0;font-size:13px;font-weight:720;cursor:pointer;white-space:nowrap}.tg-flow-schedule-tz button:hover{text-decoration:underline}.tg-flow-schedule-box[data-mode="fixed"] [data-schedule-field],.tg-flow-schedule-box[data-mode="field"] [data-schedule-fixed]{display:none}.tg-flow-schedule-box[data-mode="field"] [data-schedule-field-value]{display:block}.tg-flow-schedule-box[data-mode="field"][data-create-field="1"] [data-schedule-new-field]{display:block}.tg-schedule-tz-modal{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.36);padding:18px}.tg-schedule-tz-modal.is-open{display:flex}.tg-schedule-tz-card{width:min(520px,100%);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.24);padding:18px}.tg-schedule-tz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.tg-schedule-tz-head h3{margin:0;font-size:17px;font-weight:720;color:#1f2937}.tg-schedule-tz-head button{border:0;background:#f3f4f6;color:#6b7280;border-radius:12px;width:34px;height:34px;font-size:22px;line-height:1;cursor:pointer}.tg-schedule-tz-card p{margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.45}.tg-schedule-tz-search-field{margin-bottom:10px}.tg-schedule-tz-list{max-height:min(52vh,430px);overflow-y:auto;overflow-x:hidden;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc;padding:6px;scrollbar-gutter:stable}.tg-schedule-tz-option{width:100%;max-width:100%;box-sizing:border-box;border:0;background:transparent;border-radius:12px;padding:10px 12px;text-align:left;cursor:pointer;display:block;color:#1f2937;white-space:normal;overflow:hidden}.tg-schedule-tz-option:hover{background:#fff7ed}.tg-schedule-tz-option.is-selected{background:#ffedd5;box-shadow:inset 0 0 0 1px rgba(249,115,22,.24)}.tg-schedule-tz-option span{display:block;font-size:13px;font-weight:700;color:#1f2937;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-schedule-tz-option small{display:block;margin-top:3px;font-size:12px;color:#6b7280;line-height:1.35;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-schedule-tz-option.is-popular small{color:#9a5a09}.tg-schedule-tz-empty{display:none;margin-top:10px;border-radius:14px;background:#f8fafc;border:1px dashed #d1d5db;padding:12px;color:#6b7280;font-size:13px;line-height:1.4}.tg-schedule-tz-empty.is-visible{display:block}.tg-schedule-tz-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
    @media(max-width:620px){.tg-flow-schedule-box{padding:15px}.tg-flow-schedule-grid{grid-template-columns:1fr}.tg-flow-schedule-section-head{display:block}.tg-flow-schedule-section-head small{display:block;text-align:left;margin-top:3px}.tg-flow-schedule-tz{align-items:flex-start;flex-direction:column}.tg-schedule-tz-actions{flex-direction:column-reverse}.tg-schedule-tz-actions button{width:100%}}
    </style>
    <script data-flow-panel-script>
    (function(){
      var box=document.querySelector('[data-schedule-settings]'); if(!box||box.dataset.bound==='1')return; box.dataset.bound='1';
      var mode=box.querySelector('[data-schedule-mode]');
      var fieldSelect=box.querySelector('[data-schedule-field-select]');
      function sync(){
        box.dataset.mode=mode?mode.value:'fixed';
        box.dataset.createField=(fieldSelect&&fieldSelect.value==='__create__')?'1':'0';
      }
      if(mode)mode.addEventListener('change',sync);
      if(fieldSelect)fieldSelect.addEventListener('change',sync);
      sync();

      var tzModal=document.querySelector('[data-tz-modal]');
      var tzSearch=tzModal?tzModal.querySelector('[data-tz-search]'):null;
      var tzList=tzModal?tzModal.querySelector('[data-tz-list]'):null;
      var tzEmpty=tzModal?tzModal.querySelector('[data-tz-empty]'):null;
      var tzInput=box.querySelector('[data-tz-value]');
      var selectedTz=tzInput&&tzInput.value?tzInput.value:<?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      function tzOptions(){return tzModal?Array.prototype.slice.call(tzModal.querySelectorAll('[data-tz-option]')):[];}
      function markSelected(value){
        selectedTz=value||selectedTz||<?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        tzOptions().forEach(function(option){option.classList.toggle('is-selected',option.getAttribute('value')===selectedTz);});
      }
      function filterTz(){
        var q=tzSearch?(tzSearch.value||'').trim().toLowerCase():'';
        var visible=0;
        tzOptions().forEach(function(option){
          var haystack=(option.getAttribute('data-search')||option.textContent||'').toLowerCase();
          var show=!q||haystack.indexOf(q)!==-1;
          option.style.display=show?'':'none';
          if(show)visible++;
        });
        if(tzEmpty)tzEmpty.classList.toggle('is-visible',visible===0);
        if(tzList)tzList.scrollLeft=0;
      }
      function openTz(){
        if(!tzModal||!tzList)return;
        if(tzInput&&tzInput.value)selectedTz=tzInput.value;
        if(tzSearch)tzSearch.value='';
        markSelected(selectedTz);filterTz();
        tzModal.classList.add('is-open');tzModal.setAttribute('aria-hidden','false');
        setTimeout(function(){try{if(tzSearch)tzSearch.focus();var selected=tzModal.querySelector('[data-tz-option].is-selected');if(selected)selected.scrollIntoView({block:'center',inline:'nearest'});if(tzList)tzList.scrollLeft=0;}catch(e){}},0);
      }
      function closeTz(){if(!tzModal)return;tzModal.classList.remove('is-open');tzModal.setAttribute('aria-hidden','true');}
      function applyTz(){
        if(!tzInput){closeTz();return;}
        var option=tzModal?tzModal.querySelector('[data-tz-option].is-selected'):null;
        var value=option?(option.getAttribute('value')||selectedTz):selectedTz;
        var title=option?(option.getAttribute('data-title')||option.textContent||value):value;
        tzInput.value=value||<?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        box.querySelectorAll('[data-tz-current]').forEach(function(el){el.textContent=title;});
        closeTz();
      }
      box.querySelectorAll('[data-tz-open]').forEach(function(btn){btn.addEventListener('click',openTz);});
      if(tzModal){
        tzOptions().forEach(function(option){option.addEventListener('click',function(){markSelected(option.getAttribute('value')||<?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);});});
        if(tzSearch)tzSearch.addEventListener('input',filterTz);
        tzModal.querySelectorAll('[data-tz-close]').forEach(function(btn){btn.addEventListener('click',closeTz);});
        var applyBtn=tzModal.querySelector('[data-tz-apply]'); if(applyBtn)applyBtn.addEventListener('click',applyTz);
        tzModal.addEventListener('click',function(event){if(event.target===tzModal)closeTz();});
      }
      document.addEventListener('keydown',function(event){if(event.key==='Escape')closeTz();});

      var form=box.closest('form');
      if(form)form.tgFlowPrepareSave=function(){
        var m=mode?mode.value:'fixed';
        var alertBox=form.querySelector('#scenario-message-alert');
        var ok=true,msg='';
        if(m==='fixed'){
          var d=(form.querySelector('[name="schedule_date"]')||{}).value||''; var t=(form.querySelector('[name="schedule_time"]')||{}).value||'';
          if(!d||!t){ok=false;msg='Укажите дату и время расписания.';}
        }else{
          var f=(form.querySelector('[name="schedule_field_code"]')||{}).value||'';
          if(!f){ok=false;msg='Выберите пользовательское поле с датой или создайте новое.';}
          if(f==='__create__'){
            var title=(form.querySelector('[name="schedule_new_field_title"]')||{}).value||'';
            if(!title.trim()){ok=false;msg='Укажите название нового пользовательского поля.';}
          }
        }
        if(!ok&&alertBox){alertBox.textContent=msg;alertBox.classList.add('is-open');}
        return ok?{ok:true}:{ok:false,message:msg};
      };
    })();
    </script>
    <?php
    return;
}

if ($type === 'delay') {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $delayMode = (string)($settings['delay_mode'] ?? $settings['mode'] ?? 'after');
    if (!in_array($delayMode, ['after', 'tomorrow', 'at'], true)) $delayMode = 'after';
    $delayValue = max(1, min(999, (int)($settings['delay_value'] ?? $settings['value'] ?? 1)));
    $delayUnit = (string)($settings['delay_unit'] ?? $settings['unit'] ?? 'days');
    if (!in_array($delayUnit, ['seconds', 'minutes', 'hours', 'days'], true)) $delayUnit = 'days';
    $sendTimeMode = (string)($settings['send_time_mode'] ?? 'any');
    if (!in_array($sendTimeMode, ['any', 'exact', 'interval'], true)) $sendTimeMode = 'any';
    $normalizeTime = static function($value, string $fallback = '00:00'): string {
        $time = trim((string)$value);
        if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) return $fallback;
        [$h, $m] = array_map('intval', explode(':', $time));
        return sprintf('%02d:%02d', $h, $m);
    };
    $sendTimeExact = $normalizeTime($settings['send_time_exact'] ?? '00:00');
    $sendTimeFrom = $normalizeTime($settings['send_time_from'] ?? '00:00');
    $sendTimeTo = $normalizeTime($settings['send_time_to'] ?? '00:00');
    $timezone = trim((string)($settings['timezone'] ?? $scenarioTimezone)) ?: $scenarioTimezone;
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = $scenarioTimezone; }
    $timezonePriorityLabels = [
        'Asia/Almaty' => 'Asia/Almaty — Алматы, Астана',
        'Europe/Moscow' => 'Europe/Moscow — Москва',
        'Asia/Yekaterinburg' => 'Asia/Yekaterinburg — Екатеринбург',
        'Asia/Tashkent' => 'Asia/Tashkent — Ташкент',
        'Asia/Dubai' => 'Asia/Dubai — Дубай',
        'Europe/Istanbul' => 'Europe/Istanbul — Стамбул',
        'UTC' => 'UTC — всемирное время',
    ];
    $timezoneIds = [];
    try {
        $timezoneIds = timezone_identifiers_list(DateTimeZone::ALL);
    } catch (Throwable $e) {
        $timezoneIds = timezone_identifiers_list();
    }
    if (!in_array('UTC', $timezoneIds, true)) $timezoneIds[] = 'UTC';
    if (!in_array($timezone, $timezoneIds, true)) $timezoneIds[] = $timezone;
    $timezoneChoices = [];
    foreach ($timezonePriorityLabels as $tzCode => $tzLabel) {
        if (in_array($tzCode, $timezoneIds, true)) $timezoneChoices[$tzCode] = ['label' => $tzLabel, 'popular' => true];
    }
    sort($timezoneIds, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($timezoneIds as $tzCode) {
        $tzCode = trim((string)$tzCode);
        if ($tzCode === '' || isset($timezoneChoices[$tzCode])) continue;
        $timezoneChoices[$tzCode] = ['label' => $tzCode, 'popular' => false];
    }
    $tzOffset = static function(string $tz): string {
        try {
            $zone = new DateTimeZone($tz);
            $offset = $zone->getOffset(new DateTimeImmutable('now', $zone));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            return 'GMT' . $sign . sprintf('%02d:%02d', intdiv($offset, 3600), intdiv($offset % 3600, 60));
        } catch (Throwable $e) {
            return 'GMT+00:00';
        }
    };
    $timezoneTitle = '(' . $tzOffset($timezone) . ') ' . $timezone;
    $lower = static function(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $allowedWeekdays = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $weekdays = $settings['weekdays'] ?? array_keys($allowedWeekdays);
    if (!is_array($weekdays) || !$weekdays) $weekdays = array_keys($allowedWeekdays);
    $weekdays = array_values(array_intersect(array_keys($allowedWeekdays), array_map('strval', $weekdays)));
    if (!$weekdays) $weekdays = array_keys($allowedWeekdays);
    $deeplink = null;
    try {
        if (function_exists('asr_tg_scenario_block_deeplink_find')) {
            $deeplink = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
            if ($deeplink && function_exists('asr_tg_scenario_deeplink_url')) {
                $deeplink['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $deeplink);
            }
        }
    } catch (Throwable $e) {
        $deeplink = null;
    }
    $deeplinkCode = is_array($deeplink) ? trim((string)($deeplink['code'] ?? $deeplink['token'] ?? '')) : '';
    $deeplinkUrl = is_array($deeplink) ? trim((string)($deeplink['url'] ?? '')) : '';
    $csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
    $blockMeta = [
        'scenarioId' => $scenarioId,
        'blockId' => $blockId,
        'hasDeeplink' => ($deeplinkCode !== '' || $deeplinkUrl !== ''),
    ];
    ?>
    <section id="tg-flow-panel" class="tg-flow-panel">
        <form method="POST" id="scenario-message-form" class="tg-flow-panel-form">
            <div class="tg-flow-panel-head">
                <div>
                    <div class="tg-flow-panel-title">Редактировать блок «Задержка»</div>
                    <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
                </div>
                <div class="tg-flow-panel-actions">
                    <div class="tg-flow-panel-menu-wrap">
                        <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                        <div class="tg-flow-panel-dropdown" data-panel-menu>
                            <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                            <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                        </div>
                    </div>
                    <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
                </div>
            </div>
            <div class="tg-flow-panel-body">
                <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
                <input type="hidden" name="action" value="tg_scenario_block_save">
                <input type="hidden" name="return_page" value="scenario_flow">
                <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
                <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
                <input type="hidden" name="block_type" value="delay">
                <input type="hidden" name="scenario_cards_json" id="scenario-cards-json" value="[]">
                <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

                <label class="tg-flow-panel-field">
                    <span class="tg-flow-panel-label">Название блока</span>
                    <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Задержка')); ?>" maxlength="190">
                    <span class="tg-flow-panel-block-id">Блок #<?php echo (int)$blockId; ?></span>
                </label>

                <div class="tg-flow-delay-box" data-delay-settings>
                    <h3>Задержка</h3>
                    <p>Через какое время отправить следующее сообщение?</p>
                    <label class="tg-flow-panel-field">
                        <span class="tg-flow-panel-label">Тип задержки</span>
                        <select class="tg-flow-panel-input" name="delay_mode" data-delay-mode>
                            <option value="after" <?php echo $delayMode === 'after' ? 'selected' : ''; ?>>Отправить через</option>
                            <option value="tomorrow" <?php echo $delayMode === 'tomorrow' ? 'selected' : ''; ?>>Отправить завтра</option>
                            <option value="at" <?php echo $delayMode === 'at' ? 'selected' : ''; ?>>Отправить в</option>
                        </select>
                    </label>

                    <div class="tg-flow-delay-row" data-delay-after>
                        <label class="tg-flow-panel-field tg-flow-delay-value">
                            <span class="tg-flow-panel-label">Значение</span>
                            <input class="tg-flow-panel-input" type="number" name="delay_value" min="1" max="999" step="1" value="<?php echo (int)$delayValue; ?>">
                        </label>
                        <label class="tg-flow-panel-field tg-flow-delay-unit">
                            <span class="tg-flow-panel-label">Единица времени</span>
                            <select class="tg-flow-panel-input" name="delay_unit">
                                <option value="seconds" <?php echo $delayUnit === 'seconds' ? 'selected' : ''; ?>>секунд</option>
                                <option value="minutes" <?php echo $delayUnit === 'minutes' ? 'selected' : ''; ?>>минут</option>
                                <option value="hours" <?php echo $delayUnit === 'hours' ? 'selected' : ''; ?>>часов</option>
                                <option value="days" <?php echo $delayUnit === 'days' ? 'selected' : ''; ?>>дней</option>
                            </select>
                        </label>
                    </div>

                    <div class="tg-flow-delay-section" data-delay-time-section>
                        <h3>Время отправки</h3>
                        <p>Выберите удобное время для получателя</p>
                        <label class="tg-flow-panel-field" data-delay-time-mode-wrap>
                            <span class="tg-flow-panel-label">Время отправки</span>
                            <select class="tg-flow-panel-input" name="send_time_mode" data-delay-time-mode>
                                <option value="any" <?php echo $sendTimeMode === 'any' ? 'selected' : ''; ?>>Любое</option>
                                <option value="exact" <?php echo $sendTimeMode === 'exact' ? 'selected' : ''; ?>>Точное время</option>
                                <option value="interval" <?php echo $sendTimeMode === 'interval' ? 'selected' : ''; ?>>Временной интервал</option>
                            </select>
                        </label>
                        <div class="tg-flow-delay-time-grid" data-delay-exact>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_exact" value="<?php echo $h($sendTimeExact); ?>">
                            <div class="tg-flow-delay-tz"><span data-tz-current><?php echo $h($timezoneTitle); ?></span><input type="hidden" name="timezone" data-tz-value value="<?php echo $h($timezone); ?>"><button type="button" data-tz-open>Изменить часовой пояс</button></div>
                        </div>
                        <div class="tg-flow-delay-time-grid is-interval" data-delay-interval>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_from" value="<?php echo $h($sendTimeFrom); ?>">
                            <span class="tg-flow-delay-dash">—</span>
                            <input class="tg-flow-panel-input tg-flow-delay-time" type="time" name="send_time_to" value="<?php echo $h($sendTimeTo); ?>">
                            <div class="tg-flow-delay-tz"><span data-tz-current><?php echo $h($timezoneTitle); ?></span><button type="button" data-tz-open>Изменить часовой пояс</button></div>
                        </div>
                        <div class="tg-flow-delay-warn" data-delay-interval-warn>Будет произведена задержка как минимум на выбранный срок от предыдущего шага и затем ожидание указанного интервала.</div>
                    </div>

                    <div class="tg-flow-delay-section">
                        <h3>Дни отправки</h3>
                        <div class="tg-flow-delay-weekdays">
                            <?php foreach ($allowedWeekdays as $code => $label): ?>
                                <label><input type="checkbox" name="weekdays[]" value="<?php echo $h($code); ?>" <?php echo in_array($code, $weekdays, true) ? 'checked' : ''; ?>> <span><?php echo $h($label); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="tg-flow-delay-help">Важно! Этот шаг будет отправлен только в выбранные дни недели.</div>
                    </div>
                    <div class="tg-flow-delay-runtime-note">Runtime-подсказка: блок требует следующий шаг. Пока это используется для подсветки на холсте, позже — для runner задержек.</div>
                </div>
                <div class="tg-delay-tz-modal" data-tz-modal aria-hidden="true">
                    <div class="tg-delay-tz-card" role="dialog" aria-modal="true" aria-label="Часовой пояс задержки">
                        <div class="tg-delay-tz-head"><h3>Часовой пояс</h3><button type="button" data-tz-close aria-label="Закрыть">×</button></div>
                        <p>Выберите, по какому времени считать точное время и интервалы отправки.</p>
                        <label class="tg-flow-panel-field tg-delay-tz-search-field">
                            <span class="tg-flow-panel-label">Поиск</span>
                            <input class="tg-flow-panel-input" type="search" data-tz-search placeholder="Например: Алматы, Moscow, GMT+05 или Asia/Almaty" autocomplete="off">
                        </label>
                        <div class="tg-delay-tz-list" data-tz-list>
                            <?php foreach ($timezoneChoices as $tzCode => $tzInfo): ?>
                                <?php
                                $tzLabel = is_array($tzInfo) ? (string)($tzInfo['label'] ?? $tzCode) : (string)$tzInfo;
                                $tzPopular = is_array($tzInfo) && !empty($tzInfo['popular']);
                                $tzTitle = '(' . $tzOffset($tzCode) . ') ' . $tzCode;
                                $tzSearch = $lower($tzCode . ' ' . $tzLabel . ' ' . $tzTitle);
                                ?>
                                <button type="button" class="tg-delay-tz-option<?php echo $tzCode === $timezone ? ' is-selected' : ''; ?><?php echo $tzPopular ? ' is-popular' : ''; ?>" data-tz-option value="<?php echo $h($tzCode); ?>" data-title="<?php echo $h($tzTitle); ?>" data-search="<?php echo $h($tzSearch); ?>">
                                    <span><?php echo $h($tzTitle); ?></span>
                                    <small><?php echo $h($tzLabel); ?><?php echo $tzPopular ? ' · часто используется' : ''; ?></small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tg-delay-tz-empty" data-tz-empty>Ничего не найдено. Попробуйте город, регион или GMT-смещение.</div>
                        <div class="tg-delay-tz-actions"><button type="button" class="tg-flow-panel-secondary" data-tz-close>Отмена</button><button type="button" class="tg-flow-panel-primary" data-tz-apply>Применить</button></div>
                    </div>
                </div>
            </div>
            <div class="tg-flow-panel-footer">
                <button type="submit" class="tg-flow-panel-primary">Сохранить блок</button>
            </div>
        </form>
    </section>
    <style data-flow-panel-style="scenario-delay-panel-v3.5.110">
    .tg-flow-delay-box{border:1px solid #e7ebf1;background:#fff;border-radius:18px;padding:20px;margin-top:12px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
    .tg-flow-delay-box h3,.tg-flow-delay-section h3{font-size:16px;font-weight:720;color:#1f2937;margin:0 0 10px;line-height:1.3}.tg-flow-delay-box p,.tg-flow-delay-section p{font-size:13px;color:#6b7280;margin:0 0 18px;line-height:1.5}.tg-flow-delay-box .tg-flow-panel-field{display:flex;flex-direction:column;gap:8px;margin:0 0 18px}.tg-flow-delay-box .tg-flow-panel-label{display:block;margin:0!important;line-height:1.25!important}.tg-flow-delay-box .tg-flow-panel-input{min-height:44px}.tg-flow-delay-row{display:grid;grid-template-columns:minmax(110px,176px) minmax(0,1fr);gap:16px;align-items:start;margin:2px 0 24px}.tg-flow-delay-row .tg-flow-panel-field{margin-bottom:0}.tg-flow-delay-section{margin-top:28px;padding-top:4px}.tg-flow-delay-section:first-of-type{margin-top:26px}.tg-flow-delay-time-grid{display:grid;grid-template-columns:112px minmax(0,1fr);gap:14px;align-items:center;margin-top:10px}.tg-flow-delay-time-grid.is-interval{grid-template-columns:112px 18px 112px minmax(0,1fr)}.tg-flow-delay-time{max-width:120px}.tg-flow-delay-dash{color:#9ca3af;text-align:center}.tg-flow-delay-tz{font-size:13px;color:#6b7280;line-height:1.45;min-width:0}.tg-flow-delay-tz button{display:block;border:0;background:transparent;color:#d97706;padding:3px 0 0;font-size:12px;font-weight:700;cursor:pointer;text-align:left}.tg-flow-delay-weekdays{display:flex;flex-wrap:wrap;gap:14px 16px;margin-top:10px}.tg-flow-delay-weekdays label{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:650;color:#374151}.tg-flow-delay-weekdays input{width:18px;height:18px;accent-color:#FFA048}.tg-flow-delay-help{margin-top:14px;font-size:12px;color:#6b7280;line-height:1.45}.tg-flow-delay-runtime-note{margin-top:18px;border-radius:14px;background:#f8fafc;color:#64748b;border:1px solid #e5e7eb;padding:10px 12px;font-size:12px;line-height:1.45}.tg-delay-tz-modal{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.36);padding:18px}.tg-delay-tz-modal.is-open{display:flex}.tg-delay-tz-card{width:min(520px,100%);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.24);padding:18px}.tg-delay-tz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.tg-delay-tz-head h3{margin:0;font-size:17px;font-weight:720;color:#1f2937}.tg-delay-tz-head button{border:0;background:#f3f4f6;color:#6b7280;border-radius:12px;width:34px;height:34px;font-size:22px;line-height:1;cursor:pointer}.tg-delay-tz-card p{margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.45}.tg-delay-tz-search-field{margin-bottom:10px}.tg-delay-tz-list{max-height:min(52vh,430px);overflow-y:auto;overflow-x:hidden;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc;padding:6px;scrollbar-gutter:stable}.tg-delay-tz-option{width:100%;max-width:100%;box-sizing:border-box;border:0;background:transparent;border-radius:12px;padding:10px 12px;text-align:left;cursor:pointer;display:block;color:#1f2937;white-space:normal;overflow:hidden}.tg-delay-tz-option:hover{background:#fff7ed}.tg-delay-tz-option.is-selected{background:#ffedd5;box-shadow:inset 0 0 0 1px rgba(249,115,22,.24)}.tg-delay-tz-option span{display:block;font-size:13px;font-weight:700;color:#1f2937;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-delay-tz-option small{display:block;margin-top:3px;font-size:12px;color:#6b7280;line-height:1.35;text-align:left;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.tg-delay-tz-option.is-popular small{color:#9a5a09}.tg-delay-tz-empty{display:none;margin-top:10px;border-radius:14px;background:#f8fafc;border:1px dashed #d1d5db;padding:12px;color:#6b7280;font-size:13px;line-height:1.4}.tg-delay-tz-empty.is-visible{display:block}.tg-delay-tz-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}.tg-flow-delay-warn{display:none;margin-top:16px;border-radius:14px;background:#fff7ed;color:#5f4630;padding:12px 14px;font-size:13px;line-height:1.5}.tg-flow-delay-warn::before{content:'ⓘ';color:#d97706;font-weight:800;margin-right:8px}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-after],.tg-flow-delay-box[data-mode="at"] [data-delay-after]{display:none}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-time-mode-wrap],.tg-flow-delay-box[data-mode="at"] [data-delay-time-mode-wrap]{display:none}.tg-flow-delay-box[data-time-mode="any"] [data-delay-exact],.tg-flow-delay-box[data-time-mode="any"] [data-delay-interval],.tg-flow-delay-box[data-time-mode="exact"] [data-delay-interval],.tg-flow-delay-box[data-time-mode="interval"] [data-delay-exact]{display:none}.tg-flow-delay-box[data-time-mode="interval"] [data-delay-interval-warn]{display:block}.tg-flow-delay-box[data-mode="tomorrow"] [data-delay-exact],.tg-flow-delay-box[data-mode="at"] [data-delay-exact]{display:grid}
    @media(max-width:620px){.tg-flow-delay-box{padding:16px}.tg-flow-delay-row,.tg-flow-delay-time-grid,.tg-flow-delay-time-grid.is-interval{grid-template-columns:1fr}.tg-flow-delay-dash{text-align:left}.tg-flow-delay-time{max-width:none}.tg-delay-tz-actions{flex-direction:column-reverse}.tg-delay-tz-actions button{width:100%}}
    </style>
    <script data-flow-panel-script>
    (function(){
      var box = document.querySelector('[data-delay-settings]');
      if (!box || box.dataset.bound === '1') return;
      box.dataset.bound = '1';
      var mode = box.querySelector('[data-delay-mode]');
      var timeMode = box.querySelector('[data-delay-time-mode]');
      function sync(){
        var m = mode ? mode.value : 'after';
        var tm = timeMode ? timeMode.value : 'any';
        box.dataset.mode = m;
        box.dataset.timeMode = (m === 'after') ? tm : 'exact';
      }
      if (mode) mode.addEventListener('change', sync);
      if (timeMode) timeMode.addEventListener('change', sync);
      var tzModal = document.querySelector('[data-tz-modal]');
      var tzSearch = tzModal ? tzModal.querySelector('[data-tz-search]') : null;
      var tzList = tzModal ? tzModal.querySelector('[data-tz-list]') : null;
      var tzEmpty = tzModal ? tzModal.querySelector('[data-tz-empty]') : null;
      var tzInput = box.querySelector('[data-tz-value]');
      var selectedTz = tzInput && tzInput.value ? tzInput.value : <?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      function tzOptions(){ return tzModal ? Array.prototype.slice.call(tzModal.querySelectorAll('[data-tz-option]')) : []; }
      function markSelected(value){
        selectedTz = value || selectedTz || <?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        tzOptions().forEach(function(option){ option.classList.toggle('is-selected', option.getAttribute('value') === selectedTz); });
      }
      function filterTz(){
        var q = tzSearch ? (tzSearch.value || '').trim().toLowerCase() : '';
        var visible = 0;
        tzOptions().forEach(function(option){
          var haystack = (option.getAttribute('data-search') || option.textContent || '').toLowerCase();
          var show = !q || haystack.indexOf(q) !== -1;
          option.style.display = show ? '' : 'none';
          if (show) visible++;
        });
        if (tzEmpty) tzEmpty.classList.toggle('is-visible', visible === 0);
        if (tzList) tzList.scrollLeft = 0;
      }
      function openTz(){
        if (!tzModal || !tzList) return;
        if (tzInput && tzInput.value) selectedTz = tzInput.value;
        if (tzSearch) tzSearch.value = '';
        markSelected(selectedTz);
        filterTz();
        tzModal.classList.add('is-open');
        tzModal.setAttribute('aria-hidden', 'false');
        setTimeout(function(){
          try {
            if (tzSearch) tzSearch.focus();
            var selected = tzModal.querySelector('[data-tz-option].is-selected');
            if (selected) selected.scrollIntoView({ block: 'center', inline: 'nearest' });
            if (tzList) tzList.scrollLeft = 0;
          } catch(e){}
        }, 0);
      }
      function closeTz(){
        if (!tzModal) return;
        tzModal.classList.remove('is-open');
        tzModal.setAttribute('aria-hidden', 'true');
      }
      function applyTz(){
        if (!tzInput) { closeTz(); return; }
        var option = tzModal ? tzModal.querySelector('[data-tz-option].is-selected') : null;
        var value = option ? (option.getAttribute('value') || selectedTz) : selectedTz;
        var title = option ? (option.getAttribute('data-title') || option.textContent || value) : value;
        tzInput.value = value || <?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        box.querySelectorAll('[data-tz-current]').forEach(function(el){ el.textContent = title; });
        closeTz();
      }
      box.querySelectorAll('[data-tz-open]').forEach(function(btn){ btn.addEventListener('click', openTz); });
      if (tzModal) {
        tzOptions().forEach(function(option){ option.addEventListener('click', function(){ markSelected(option.getAttribute('value') || <?php echo json_encode($scenarioTimezone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>); }); });
        if (tzSearch) tzSearch.addEventListener('input', filterTz);
        tzModal.querySelectorAll('[data-tz-close]').forEach(function(btn){ btn.addEventListener('click', closeTz); });
        var applyBtn = tzModal.querySelector('[data-tz-apply]');
        if (applyBtn) applyBtn.addEventListener('click', applyTz);
        tzModal.addEventListener('click', function(event){ if (event.target === tzModal) closeTz(); });
      }
      document.addEventListener('keydown', function(event){ if (event.key === 'Escape') closeTz(); });

      var blockMeta = <?php echo $safeJson($blockMeta); ?>;
      var panel = document.getElementById('tg-flow-panel');
      var form = document.getElementById('scenario-message-form');
      var panelMenu = panel ? panel.querySelector('[data-panel-menu]') : null;
      var panelMenuToggle = panel ? panel.querySelector('[data-panel-menu-toggle]') : null;
      function closePanelMenu(){ if (panelMenu) panelMenu.classList.remove('is-open'); }
      function showPanelAlert(message){
        var alertBox = panel ? panel.querySelector('#scenario-message-alert') : null;
        if (alertBox) {
          alertBox.textContent = String(message || 'Ошибка');
          alertBox.classList.add('is-open');
          alertBox.scrollIntoView({block:'nearest', behavior:'smooth'});
        } else if (typeof window.tgScenarioFlowToast === 'function') {
          window.tgScenarioFlowToast(String(message || 'Ошибка'), 'error');
        }
      }
      async function postBlockAction(action, payload){
        payload = payload || {};
        if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
        var actionMap = {tg_scenario_duplicate_block: 'tg_scenario_block_duplicate'};
        var fd = new FormData();
        fd.set('action', actionMap[action] || action);
        fd.set('return_page', 'scenario_flow');
        fd.set('tg_ajax', '1');
        fd.set('scenario_id', String(blockMeta.scenarioId || ''));
        Object.keys(payload).forEach(function(key){ fd.set(key, String(payload[key] == null ? '' : payload[key])); });
        var csrf = form ? form.querySelector('input[name="csrf_token"]') : null;
        if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
        var response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        var text = await response.text();
        var json = null;
        try { json = text ? JSON.parse(text) : null; } catch(e) { json = null; }
        if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.');
        return json;
      }
      if (panelMenuToggle) {
        panelMenuToggle.addEventListener('click', function(event){ event.preventDefault(); event.stopPropagation(); if (panelMenu) panelMenu.classList.toggle('is-open'); });
      }
      if (panelMenu) panelMenu.addEventListener('click', function(event){ event.stopPropagation(); });
      document.addEventListener('click', closePanelMenu);
      var deeplinkBtn = panel ? panel.querySelector('[data-panel-deeplink]') : null;
      if (deeplinkBtn) deeplinkBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        if (blockMeta.hasDeeplink) return;
        try {
          await postBlockAction('tg_scenario_block_deeplink_create', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowToast === 'function') window.tgScenarioFlowToast('Диплинк создан. Ссылка появилась под блоком.');
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось создать диплинк.'); }
      });
      var duplicateBtn = panel ? panel.querySelector('[data-panel-duplicate]') : null;
      if (duplicateBtn) duplicateBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        try {
          await postBlockAction('tg_scenario_duplicate_block', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось дублировать блок.'); }
      });
      var deleteBtn = panel ? panel.querySelector('[data-panel-delete]') : null;
      if (deleteBtn) deleteBtn.addEventListener('click', async function(event){
        event.preventDefault(); event.stopPropagation(); closePanelMenu();
        var ok = true;
        if (typeof window.tgScenarioFlowConfirm === 'function') {
          ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText:'Удалить', cancelText:'Отмена'});
        }
        if (!ok) return;
        try {
          await postBlockAction('tg_scenario_block_delete', {block_id: blockMeta.blockId || ''});
          if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
          if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
        } catch(error) { showPanelAlert(error && error.message ? error.message : 'Не удалось удалить блок.'); }
      });
      sync();
    })();
    </script>
    <?php
    return;
}

$settings = json_decode((string)($block['settings_json'] ?? ''), true);
$cards = [];
if (is_array($settings) && isset($settings['cards']) && is_array($settings['cards'])) $cards = array_values($settings['cards']);
if (!$cards) $cards = [['type' => 'text', 'text' => (string)($block['message_text'] ?? ''), 'buttons' => []]];
$blocks = function_exists('asr_tg_scenario_blocks_all') ? asr_tg_scenario_blocks_all($pdo, $scenarioId) : [];
$questionFieldOptions = [];
$messageVariables = [
    ['group' => 'Системные', 'title' => 'Полное имя', 'token' => '{{full_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Имя', 'token' => '{{first_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Фамилия', 'token' => '{{last_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Username', 'token' => '{{username}}', 'icon' => '@'],
    ['group' => 'Системные', 'title' => 'Телефон', 'token' => '{{phone}}', 'icon' => '☎'],
    ['group' => 'Системные', 'title' => 'E-mail', 'token' => '{{email}}', 'icon' => '✉'],
    ['group' => 'Системные', 'title' => 'Канал', 'token' => '{{bot_title}}', 'icon' => '#'],
    ['group' => 'Системные', 'title' => 'Username бота', 'token' => '{{bot_username}}', 'icon' => '@'],
];
try {
    if (function_exists('asr_tg_custom_fields_all')) {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            $code = trim((string)($field['code'] ?? ''));
            if ($code === '') continue;
            $title = trim((string)($field['title'] ?? '')) ?: $code;
            $messageVariables[] = ['group' => 'Пользовательские', 'title' => $title, 'token' => '{{custom.' . $code . '}}', 'icon' => '★'];
            $fieldType = trim((string)($field['field_type'] ?? 'text'));
            if (!in_array($fieldType, ['text','number','date','datetime'], true)) $fieldType = 'text';
            if (in_array($fieldType, ['text','number'], true)) {
                $questionFieldOptions[] = ['code' => $code, 'title' => $title, 'field_type' => $fieldType];
            }
        }
    }
} catch (Throwable $e) {}

$transitionOptions = [];
foreach ($blocks as $b) {
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0 || $id === $blockId || (string)($b['type'] ?? '') === 'start') continue;
    $title = trim((string)($b['title'] ?? '')) ?: ('Блок #' . $id);
    $transitionOptions[] = ['id' => $id, 'title' => $title];
}

$deeplink = null;
try {
    if (function_exists('asr_tg_scenario_block_deeplink_find')) {
        $deeplink = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
        if ($deeplink && function_exists('asr_tg_scenario_deeplink_url')) {
            $deeplink['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $deeplink);
        }
    }
} catch (Throwable $e) {
    $deeplink = null;
}
$deeplinkCode = is_array($deeplink) ? trim((string)($deeplink['code'] ?? $deeplink['token'] ?? '')) : '';
$deeplinkUrl = is_array($deeplink) ? trim((string)($deeplink['url'] ?? '')) : '';
$blockMeta = [
    'scenarioId' => $scenarioId,
    'blockId' => $blockId,
    'hasDeeplink' => $deeplinkCode !== '' || $deeplinkUrl !== '',
    'deeplinkCode' => $deeplinkCode,
    'deeplinkUrl' => $deeplinkUrl,
];

$csrf = function_exists('asr_csrf_token') ? asr_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '');
?>
<section id="tg-flow-panel" class="tg-flow-panel">
    <form method="POST" enctype="multipart/form-data" id="scenario-message-form" class="tg-flow-panel-form">
        <div class="tg-flow-panel-head">
            <div>
                <div class="tg-flow-panel-title">Редактировать блок «Сообщение»</div>
                <div class="tg-flow-panel-subtitle">Блок #<?php echo (int)$blockId; ?></div>
            </div>
            <div class="tg-flow-panel-actions">
                <div class="tg-flow-panel-menu-wrap">
                    <button type="button" class="tg-flow-panel-more" data-panel-menu-toggle aria-label="Действия блока">⋯</button>
                    <div class="tg-flow-panel-dropdown" data-panel-menu>
                        <button type="button" data-panel-deeplink <?php echo ($deeplinkCode !== '' || $deeplinkUrl !== '') ? 'disabled' : ''; ?>>
                            <span class="tg-flow-panel-dropdown-ico">🔗</span><span><span class="tg-flow-panel-menu-main"><?php echo ($deeplinkCode !== '' || $deeplinkUrl !== '') ? 'Диплинк уже создан' : 'Добавить диплинк'; ?></span><?php if ($deeplinkCode !== '' || $deeplinkUrl !== ''): ?><span class="tg-flow-panel-menu-note">Ссылка уже есть под блоком</span><?php endif; ?></span>
                        </button>
                        <button type="button" data-panel-duplicate><span class="tg-flow-panel-dropdown-ico">⧉</span><span class="tg-flow-panel-menu-main">Дублировать</span></button>
                        <button type="button" class="is-danger" data-panel-delete><span class="tg-flow-panel-dropdown-ico">🗑</span><span class="tg-flow-panel-menu-main">Удалить</span></button>
                    </div>
                </div>
                <button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>
            </div>
        </div>
        <div class="tg-flow-panel-body">
            <div id="scenario-message-alert" class="tg-flow-panel-alert"></div>
            <input type="hidden" name="action" value="tg_scenario_block_save">
            <input type="hidden" name="return_page" value="scenario_flow">
            <input type="hidden" name="scenario_id" value="<?php echo (int)$scenarioId; ?>">
            <input type="hidden" name="block_id" value="<?php echo (int)$blockId; ?>">
            <input type="hidden" name="block_type" value="message">
            <input type="hidden" name="scenario_cards_json" id="scenario-cards-json" value="">
            <?php if ($csrf !== ''): ?><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><?php endif; ?>

            <label class="tg-flow-panel-field">
                <span class="tg-flow-panel-label">Название блока</span>
                <input class="tg-flow-panel-input" type="text" name="block_title" value="<?php echo $h((string)($block['title'] ?? 'Сообщение')); ?>" maxlength="190">
                <span class="tg-flow-panel-block-id">Блок #<?php echo (int)$blockId; ?></span>
            </label>

            <div class="tg-flow-card-toolbar" aria-label="Добавить карточку">
                <button type="button" class="tg-flow-card-add" data-add-card="text">T Текст</button>
                <button type="button" class="tg-flow-card-add" data-add-card="image">▣ Картинка</button>
                <button type="button" class="tg-flow-card-add" data-add-card="file">▤ Файл</button>
                <button type="button" class="tg-flow-card-add" data-add-card="audio">♫ Аудио</button>
                <button type="button" class="tg-flow-card-add" data-add-card="video">▶ Видео</button>
                <button type="button" class="tg-flow-card-add" data-add-card="video_note">◉ Видео-заметка</button>
                <button type="button" class="tg-flow-card-add" data-add-card="gallery">▣ Галерея</button>
                <button type="button" class="tg-flow-card-add" data-add-card="question">? Вопрос</button>
            </div>
            <div id="scenario-cards-box"></div>
        </div>
        <div class="tg-flow-panel-footer">
            <button type="submit" class="tg-flow-panel-primary">Сохранить блок</button>
        </div>
    </form>
    <div class="tg-date-modal-backdrop" id="tgScenarioDateModal" aria-hidden="true">
        <div class="tg-date-modal">
            <div class="tg-date-modal-head">
                <h4>Дата</h4>
                <button type="button" class="tg-date-modal-close" data-date-modal-close aria-label="Закрыть">×</button>
            </div>
            <div class="tg-date-modal-body">
                <p class="tg-date-modal-note">Выделенный текст станет ссылкой на календарное событие в Telegram.</p>
                <label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Дата</span><input type="date" class="tg-flow-panel-input" id="tgScenarioDateValue"></label>
                <label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Время</span><input type="time" class="tg-flow-panel-input" id="tgScenarioTimeValue" value="10:00"></label>
            </div>
            <div class="tg-date-modal-foot"><button type="button" class="tg-button-muted" data-date-modal-close>Отмена</button><button type="button" class="tg-button-save" id="tgScenarioDateApply">Готово</button></div>
        </div>
    </div>
</section>
<style data-flow-panel-style="scenario-block-panel-v3.5.89">
/* scenario block panel styles v3.5.89 */
.tg-video-note-status{display:none;margin:10px 0 0;padding:10px 12px;border-radius:12px;font-size:13px;line-height:1.45;font-weight:650}
.tg-video-note-status.is-open{display:block}
.tg-video-note-status.is-ok{background:#ecfdf3;color:#267044;border:1px solid #bbf7d0}
.tg-video-note-status.is-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.tg-video-note-status.is-error{background:#fff1f2;color:#b91c1c;border:1px solid #fecdd3}

.tg-message-card[data-type="question"]{padding:22px 24px;background:#f7f8fa;border-color:#edf0f5}
.tg-message-card[data-type="question"] .tg-flow-card-head{margin-bottom:16px}
.tg-question-editor-wrap{margin:0 0 22px 0;background:#fff;border:1px solid #dce2ea;border-radius:14px;overflow:hidden}
.tg-question-editor-wrap .tg-caption-editor{border:0;border-radius:0;background:#fff;margin:0}
.tg-question-editor-wrap .tg-card-toolbar{margin-top:0;border-top:0;border-left:0;border-right:0;border-bottom:1px solid #edf0f5;border-radius:0;background:#fff}
.tg-question-editor-wrap .tg-editor-wrap{border:0;border-radius:0;background:#fff}
.tg-question-editor-wrap .tg-card-editor{min-height:118px;padding:18px 20px;font-size:15px;line-height:1.55}
.tg-question-box{display:flex;flex-direction:column;gap:20px;margin-top:0;padding-top:0;border-top:0}
.tg-question-option-stack{display:flex;flex-direction:column;gap:12px}
.tg-question-option-line{display:flex;align-items:center;gap:12px;min-height:46px;padding:0;color:#4b5563;font-size:14px;font-weight:600;line-height:1.35;background:transparent;border:0;border-radius:0}
.tg-question-option-line input{width:18px;height:18px;accent-color:#FFA048;flex:0 0 auto;margin:0}
.tg-question-option-line .tg-help-dot,.tg-question-option-line .tg-protect-help{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#e9edf3;color:#8b95a4;font-size:12px;font-weight:800;margin-left:2px;position:relative;cursor:help}
.tg-question-option-line .tg-help-dot:hover::after,.tg-question-option-line .tg-protect-help:hover::after{content:attr(data-tip);position:absolute;left:50%;top:26px;transform:translateX(-50%);width:max-content;max-width:260px;background:#333;color:#fff;padding:9px 11px;border-radius:8px;font-size:12px;font-weight:600;line-height:1.35;z-index:1000;box-shadow:0 10px 24px rgba(15,23,42,.18)}
.tg-question-save-field{position:relative;display:block;margin:0;border:1px solid #dce2ea;border-radius:14px;background:#fff;padding:0 12px 12px}
.tg-question-save-field .tg-question-field-title{display:inline-flex;position:relative;top:-10px;margin:0 0 -2px;padding:0 8px;background:#fff;color:#8b95a4;font-size:12px;font-weight:750;letter-spacing:.01em}
.tg-question-save-field .tg-button-select{height:50px;background:#fff;border:0;border-radius:10px;font-size:15px;border-color:transparent;color:#1f2937;width:100%;padding:0 8px;box-shadow:none}
.tg-question-field-hint{margin:6px 8px 0;color:#8b95a4;font-size:12px;font-weight:600;line-height:1.35}
.tg-question-answers-panel{border:0;background:#f1f3f6;border-radius:16px;padding:16px 18px;margin:0}
.tg-question-answers-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.tg-question-answers-title{font-size:13px;font-weight:800;color:#1f2937;line-height:1.25;text-transform:none}
.tg-question-answers-hint{font-size:12px;color:#8b95a4;font-weight:600;line-height:1.35;margin-top:4px}
.tg-question-answers{display:flex;flex-wrap:wrap;gap:9px;margin:10px 0 2px;min-height:36px}
.tg-question-answer-chip{display:inline-flex;align-items:center;gap:8px;min-height:34px;border:0;background:#e1e4e8;border-radius:999px;padding:8px 12px;font-size:13px;font-weight:650;color:#374151;cursor:pointer;max-width:100%}
.tg-question-answer-chip:hover{background:#fff1e6;color:#c45b12}
.tg-question-answer-chip button{border:0;background:transparent;color:#6b7280;font-size:17px;line-height:1;cursor:pointer;padding:0;margin-left:2px}
.tg-question-answer-rows{display:flex;flex-direction:column;gap:10px}
.tg-question-answer-row{display:flex;align-items:center;gap:10px}
.tg-question-answer-row .tg-flow-panel-input{flex:1}
.tg-question-row-remove{width:36px;height:36px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:20px;line-height:1;cursor:pointer}
.tg-question-row-remove:hover{background:#fee2e2;color:#b91c1c}
.tg-question-answer-bulk .tg-question-settings-meta{margin-top:10px}
.tg-question-add-row{margin-top:4px}
.tg-question-empty{font-size:12px;color:#8b95a4;font-weight:600;line-height:1.45;width:100%;padding:8px 0;border:0;background:transparent;border-radius:0}
.tg-question-actions{display:flex;flex-wrap:wrap;gap:16px;align-items:center;border-top:1px solid #e5e8ee;padding-top:16px;margin-top:14px}
.tg-question-actions .tg-btn-ghost{margin:0;padding:0;border:0;background:transparent;color:#f28b36;font-size:13px;letter-spacing:.02em;font-weight:800;text-transform:none;box-shadow:none;min-height:32px}
.tg-question-actions .tg-btn-ghost:hover{color:#d76f1f;background:transparent}
.tg-question-wait{display:inline-flex;align-items:center;min-height:30px;color:#6b7280;font-size:13px;font-weight:650;margin-left:auto;white-space:nowrap;background:#fff;border:1px solid #e5e8ee;border-radius:999px;padding:5px 11px}
.tg-question-noanswer{border:0;background:#fff;border-radius:14px;padding:16px 18px;font-size:13px;color:#6b7280;line-height:1.55;display:flex;flex-direction:column;gap:6px;box-shadow:inset 0 0 0 1px #edf0f5}
.tg-question-noanswer strong{display:block;color:#1f2937;font-size:13px;font-weight:800;margin:0}
.tg-question-settings-modal .tg-button-modal{max-width:640px}
.tg-question-settings-modal .tg-button-modal-body{max-height:70vh;overflow:auto;padding-right:22px}
.tg-question-settings-modal .tg-question-settings-answer-box{border:1px solid #d9dee7;border-radius:12px;padding:10px 12px;background:#fff}
.tg-question-settings-modal .tg-question-settings-chips{display:flex;align-items:center;flex-wrap:wrap;gap:8px;min-height:38px}
.tg-question-settings-modal .tg-question-settings-chip{display:inline-flex;align-items:center;gap:8px;min-height:32px;border-radius:999px;background:#e5e7eb;color:#374151;padding:7px 10px;font-size:13px;font-weight:650}
.tg-question-settings-modal .tg-question-settings-chip button{border:0;background:transparent;color:#6b7280;font-size:16px;line-height:1;cursor:pointer;padding:0}
.tg-question-settings-modal .tg-question-settings-add{border:0;background:transparent;color:#8b95a4;font-size:13px;font-weight:750;cursor:pointer;padding:5px 2px}
.tg-question-settings-modal .tg-question-settings-meta{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.35}
.tg-question-settings-modal .tg-question-settings-row{display:grid;grid-template-columns:120px 150px;gap:14px;align-items:center;margin-top:10px}
.tg-question-settings-modal .tg-question-switches{display:flex;flex-direction:column;gap:16px;margin-top:20px}
.tg-question-settings-modal .tg-switch-line{display:flex;align-items:center;gap:12px;color:#374151;font-size:14px;font-weight:600}
.tg-question-settings-modal .tg-switch-line input{width:18px;height:18px;accent-color:#FFA048}
.tg-question-settings-modal .tg-reminder-box{display:none;margin-top:12px;border:1px solid #d9dee7;border-radius:12px;padding:12px;background:#fff}
.tg-question-settings-modal .tg-reminder-box.is-open{display:block}
.tg-question-settings-modal .tg-reminder-box textarea{min-height:110px;resize:vertical}
@media(max-width:720px){.tg-message-card[data-type="question"]{padding:18px 16px}.tg-question-actions .tg-btn-ghost{flex:0 0 auto}.tg-question-wait{width:100%;margin-left:0;justify-content:center}.tg-question-settings-modal .tg-question-settings-row{grid-template-columns:1fr}}

</style>
<script data-flow-panel-script>
(function(){
  const panel = document.getElementById('tg-flow-panel');
  const box = panel ? panel.querySelector('#scenario-cards-box') : document.getElementById('scenario-cards-box');
  const form = panel ? panel.querySelector('#scenario-message-form') : document.getElementById('scenario-message-form');
  const jsonInput = panel ? panel.querySelector('#scenario-cards-json') : document.getElementById('scenario-cards-json');
  if (!box || !form || !jsonInput || form.dataset.scenarioMessageEditorBound === '1') return;
  form.dataset.scenarioMessageEditorBound = '1';

  const initialCards = <?php echo $safeJson($cards); ?>;
  const transitionOptions = <?php echo $safeJson($transitionOptions); ?>;
  const macroCatalog = <?php echo $safeJson($messageVariables); ?>;
  const questionFieldOptions = <?php echo $safeJson($questionFieldOptions); ?>;
  const blockMeta = <?php echo $safeJson($blockMeta); ?>;
  const emojiGroups = {
    'Частые': ['😀','😁','🙂','😉','😍','🔥','👍','👏','🙏','✅','❗','💡','🚀','🎯','📌','❤️'],
    'Работа': ['📅','📍','📎','📞','✉️','💬','📣','📊','🧩','⚙️','🔔','⭐','🏁','⏱️','📝','💰']
  };
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
  function showPanelAlert(message){
    const alertBox = panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
    if (alertBox) {
      alertBox.textContent = String(message || 'Ошибка');
      alertBox.classList.add('is-open');
      alertBox.scrollIntoView({block:'nearest', behavior:'smooth'});
    } else if (typeof window.tgScenarioFlowToast === 'function') {
      window.tgScenarioFlowToast(String(message || 'Ошибка'), 'error');
    }
  }
  const postBlockAction = async (action, payload = {}) => {
    const actionMap = {tg_scenario_duplicate_block: 'tg_scenario_block_duplicate'};
    const realAction = actionMap[action] || action;
    if (typeof window.tgScenarioFlowPostAction === 'function') return window.tgScenarioFlowPostAction(action, payload);
    const fd = new FormData();
    fd.set('action', realAction);
    fd.set('return_page', 'scenario_flow');
    fd.set('tg_ajax', '1');
    fd.set('scenario_id', String(blockMeta.scenarioId || ''));
    Object.entries(payload || {}).forEach(([key, value]) => fd.set(key, String(value ?? '')));
    const csrf = form.querySelector('input[name="csrf_token"]');
    if (csrf && csrf.value) fd.set('csrf_token', csrf.value);
    const response = await fetch('admin.php?tab=telegram_bots', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    const text = await response.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
    if (!response.ok || !json || json.ok === false) throw new Error((json && json.error) || 'Сервер не вернул JSON.');
    return json;
  };
  const panelMenu = panel ? panel.querySelector('[data-panel-menu]') : null;
  const panelMenuToggle = panel ? panel.querySelector('[data-panel-menu-toggle]') : null;
  const closePanelMenu = () => panelMenu && panelMenu.classList.remove('is-open');
  panelMenuToggle?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); panelMenu?.classList.toggle('is-open'); });
  panelMenu?.addEventListener('click', (event) => event.stopPropagation());
  document.addEventListener('click', closePanelMenu);
  panel?.querySelector('[data-panel-deeplink]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    if (blockMeta.hasDeeplink) return;
    try {
      await postBlockAction('tg_scenario_block_deeplink_create', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowToast === 'function') window.tgScenarioFlowToast('Диплинк создан. Ссылка появилась под блоком.');
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось создать диплинк.');
    }
  });
  panel?.querySelector('[data-panel-duplicate]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    try {
      await postBlockAction('tg_scenario_duplicate_block', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось дублировать блок.');
    }
  });
  panel?.querySelector('[data-panel-delete]')?.addEventListener('click', async (event) => {
    event.preventDefault(); event.stopPropagation(); closePanelMenu();
    let ok = true;
    if (typeof window.tgScenarioFlowConfirm === 'function') {
      ok = await window.tgScenarioFlowConfirm({title:'Удалить блок?', text:'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText:'Удалить', cancelText:'Отмена'});
    }
    if (!ok) return;
    try {
      await postBlockAction('tg_scenario_block_delete', {block_id: blockMeta.blockId || ''});
      if (typeof window.tgScenarioFlowRefresh === 'function') await window.tgScenarioFlowRefresh();
      if (typeof window.tgScenarioFlowCloseDrawer === 'function') window.tgScenarioFlowCloseDrawer();
    } catch (error) {
      showPanelAlert(error && error.message ? error.message : 'Не удалось удалить блок.');
    }
  });
  const cardTitle = (type) => ({text:'Текст', image:'Картинка', file:'Файл', audio:'Аудио', video:'Видео', video_note:'Видео-заметка', gallery:'Галерея', question:'Вопрос'}[type] || 'Текст');
  const mediaAccept = (type) => type === 'image' ? 'image/*' : type === 'video_note' ? '.mp4,.m4v,video/mp4,video/x-m4v' : type === 'video' ? 'video/*' : type === 'audio' ? 'audio/*' : '*/*';
  const mediaUrl = (card) => { let url=String(card.media_file_path||card.media_url||'').trim(); if(!url)return''; if(/^https?:\/\//i.test(url))return url; return '/' + url.replace(/^\/+/, ''); };
  const VIDEO_NOTE_MAX_SIZE = 10 * 1024 * 1024;
  const VIDEO_NOTE_MAX_SECONDS = 60;
  function videoNoteStatus(card, message='', kind='info'){
    const el = card.querySelector('[data-video-note-status]');
    if(!el) return;
    el.className = 'tg-video-note-status' + (message ? ' is-open is-' + kind : '');
    el.textContent = message || '';
  }
  function videoNoteFileLooksValid(file){
    if(!file) return {ok:true};
    const name = String(file.name || '').toLowerCase();
    if(!(/\.(mp4|m4v)$/.test(name) || file.type === 'video/mp4' || file.type === 'video/x-m4v')) {
      return {ok:false, message:'Видео-заметка принимает только MP4 или M4V.'};
    }
    if(file.size > VIDEO_NOTE_MAX_SIZE) {
      return {ok:false, message:'Файл видео-заметки больше 10 МБ. Выберите файл поменьше.'};
    }
    return {ok:true};
  }
  function validateVideoNoteFile(card, file){
    if(!card || (card.dataset.type || '') !== 'video_note') return;
    const input = card.querySelector('[data-media-file]');
    const basic = videoNoteFileLooksValid(file);
    if(!basic.ok){
      if(input) input.value='';
      const label=card.querySelector('[data-upload-name]');
      if(label) label.textContent='Файл не выбран';
      videoNoteStatus(card, basic.message, 'error');
      collectCards();
      return;
    }
    if(!file){ videoNoteStatus(card,''); return; }
    const url = URL.createObjectURL(file);
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.onloadedmetadata = () => {
      URL.revokeObjectURL(url);
      const duration = Number(video.duration || 0);
      if(duration && duration > VIDEO_NOTE_MAX_SECONDS){
        if(input) input.value='';
        const label=card.querySelector('[data-upload-name]');
        if(label) label.textContent='Файл не выбран';
        videoNoteStatus(card, 'Длительность видео-заметки больше 60 секунд. Выберите короткое видео.', 'error');
        collectCards();
        return;
      }
      videoNoteStatus(card, 'Файл подходит для видео-заметки. После отправки Telegram покажет его кружком.', 'ok');
    };
    video.onerror = () => {
      URL.revokeObjectURL(url);
      videoNoteStatus(card, 'Формат похож на MP4/M4V. Длительность браузер не смог проверить, сервер проверит размер и расширение.', 'warn');
    };
    video.src = url;
  }
  function validateVideoNoteUrl(card){
    if(!card || (card.dataset.type || '') !== 'video_note') return true;
    const input = card.querySelector('[data-media-url]');
    const value = String(input?.value || '').trim();
    if(!value) { videoNoteStatus(card,''); return true; }
    if(!/^https?:\/\//i.test(value)) {
      videoNoteStatus(card, 'Ссылка на видео-заметку должна начинаться с http:// или https://.', 'error');
      return false;
    }
    if(!/\.(mp4|m4v)(\?|#|$)/i.test(value)) {
      videoNoteStatus(card, 'Для видео-заметки ссылка должна вести на MP4 или M4V. Размер и длительность ссылки проверяются при отправке.', 'warn');
      return true;
    }
    videoNoteStatus(card, 'Ссылка похожа на MP4/M4V. Размер до 10 МБ и длительность до 60 секунд проверим при отправке.', 'warn');
    return true;
  }
  function galleryImageUrl(item){ const raw=String((item&& (item.media_file_path||item.media_url||item.url))||'').trim(); if(!raw)return''; if(/^https?:\/\//i.test(raw)||raw.startsWith('/'))return raw; return '/' + raw.replace(/^\/+/, ''); }
  function galleryItems(source){
    const raw = source && Array.isArray(source.gallery_items) ? source.gallery_items : [];
    return raw.map(item=>({media_url:String((item&& (item.media_url||item.url))||'').trim(),media_file_path:String((item&&item.media_file_path)||'').trim(),media_file_name:String((item&&item.media_file_name)||'').trim()})).filter(item=>galleryImageUrl(item));
  }
  function setGalleryItems(card, items){ card.dataset.galleryItems = JSON.stringify(Array.isArray(items)?items:[]); }
  function getGalleryItems(card){ try { const parsed=JSON.parse(card.dataset.galleryItems||'[]'); return Array.isArray(parsed)?parsed:[]; } catch(e){ return []; } }
  function renderGalleryItems(card){
    const list = card.querySelector('[data-gallery-list]');
    if(!list) return;
    const items = getGalleryItems(card);
    const input = card.querySelector('[data-gallery-files]');
    const selected = input && input.files ? Array.from(input.files) : [];
    let html = '';
    items.forEach((item,index)=>{
      const src = galleryImageUrl(item);
      html += '<div class="tg-gallery-item"><div class="tg-gallery-thumb">'+(src?'<img src="'+esc(src)+'" alt="">':'')+'</div><div class="tg-gallery-name">'+esc(item.media_file_name||item.media_url||item.media_file_path||('Картинка '+(index+1)))+'</div><button type="button" data-gallery-remove="'+index+'" title="Удалить">'+adminIcon('delete')+'</button></div>';
    });
    selected.forEach((file)=>{ html += '<div class="tg-gallery-item is-new"><div class="tg-gallery-thumb">+</div><div class="tg-gallery-name">'+esc(file.name)+' <span>после сохранения</span></div><button type="button" disabled title="Будет загружено при сохранении">'+adminIcon('image')+'</button></div>'; });
    list.innerHTML = html || '<div class="tg-gallery-empty">Добавьте картинки файлом или ссылкой. Они сохранятся одной карточкой галереи.</div>';
    list.querySelectorAll('[data-gallery-remove]').forEach(btn=>btn.addEventListener('click',()=>{ const idx=parseInt(btn.dataset.galleryRemove||'-1',10); const next=getGalleryItems(card); if(idx>=0) next.splice(idx,1); setGalleryItems(card,next); renderGalleryItems(card); collectCards(); }));
  }
  function addGalleryUrl(card){
    const input=card.querySelector('[data-gallery-url]');
    const value=String(input?.value||'').trim();
    if(!value) { input?.focus(); return; }
    const items=getGalleryItems(card);
    items.push({media_url:value,media_file_path:'',media_file_name:value.split('/').pop()||'Картинка'});
    setGalleryItems(card,items);
    if(input) input.value='';
    renderGalleryItems(card); collectCards();
  }
  function adminIcon(name){
    const map={bold:'bold',italic:'italic',underline:'underline',strike:'strikethrough',mono:'mono',code:'code',spoiler:'eye-off',quote:'quote','event-date':'date',calendar:'date',link:'link',emoji:'emoji',variables:'variables',clear:'clear',delete:'delete','arrow-up':'arrow-up','arrow-down':'arrow-down',text:'text',image:'image',file:'file',audio:'audio',video:'video',video_note:'video',gallery:'image',question:'help'};
    const file=map[name]||name;
    return '<img class="tg-ui-icon tg-ui-icon--toolbar" src="/assets/admin/icons/tg2-'+file+'-gray.svg?v=20260530-tg2-gray" alt="" aria-hidden="true">';
  }
  function emojiHtml(){return Object.entries(emojiGroups).map(([title,items])=>'<div class="tg-emoji-section"><div class="tg-emoji-title">'+esc(title)+'</div><div class="tg-emoji-grid">'+items.map(e=>'<button type="button" data-emoji-insert="'+esc(e)+'">'+esc(e)+'</button>').join('')+'</div></div>').join('');}
  function macroMenuHtml(){
    const grouped={}; (macroCatalog||[]).forEach(item=>{const g=item.group||'Переменные'; (grouped[g]=grouped[g]||[]).push(item);});
    let html='<input class="tg-macro-search" placeholder="Найти переменную" data-macro-search>';
    Object.keys(grouped).forEach(group=>{html+='<div class="tg-macro-group">'+esc(group)+'</div>'; grouped[group].forEach(item=>{const token=String(item.token||''); if(!token)return; html+='<button type="button" class="tg-macro-item" data-macro-insert="'+esc(token)+'" data-macro-search-text="'+esc((item.title||'')+' '+token+' '+group)+'"><span>'+esc(item.icon||'Т')+'</span><span>'+esc(item.title||token)+'</span><span class="tg-macro-token">'+esc(token)+'</span></button>';});});
    return html;
  }
  function normalizeRows(buttons){
    if(!Array.isArray(buttons)) return [];
    const rows=[]; buttons.forEach(row=>{ if(!Array.isArray(row)) row=[row]; const clean=row.filter(btn=>btn&&typeof btn==='object').map(btn=>({type:btn.type==='transition'?'transition':'url', text:String(btn.text||btn.title||''), url:String(btn.url||''), target_block_id:parseInt(btn.target_block_id||0,10)||0})); if(clean.length) rows.push(clean); });
    return rows;
  }
  function renderButtons(card){
    const btnBox=card.querySelector('[data-buttons-box]');
    if(!btnBox) return;
    const rows=getRows(card);
    btnBox.innerHTML='';
    rows.forEach((row,ri)=>{
      if(!Array.isArray(row) || !row.length) return;
      const line=document.createElement('div');
      line.className='tg-message-button-row';
      row.forEach((btn,bi)=>{
        const button=document.createElement('button');
        button.type='button';
        button.className='tg-message-button';
        button.textContent=btn.text || btn.title || 'Кнопка';
        button.addEventListener('click',()=>openButtonModal(card,ri,bi,false));
        line.appendChild(button);
        const plus=document.createElement('button');
        plus.type='button';
        plus.className='tg-message-button-add';
        plus.textContent='+';
        plus.title='Добавить рядом';
        plus.addEventListener('click',()=>openButtonModal(card,ri,bi+1,true));
        line.appendChild(plus);
      });
      btnBox.appendChild(line);
      const below=document.createElement('button');
      below.type='button';
      below.className='tg-btn-ghost self-start';
      below.textContent='+ Добавить ниже';
      below.addEventListener('click',()=>openButtonModal(card,ri+1,0,true));
      btnBox.appendChild(below);
    });
  }
  function getRows(card){
    try {
      const parsed=JSON.parse(card.dataset.buttons||'[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch(e) {
      return [];
    }
  }
  function setRows(card, rows){
    const clean=normalizeRows(rows||[]);
    card.dataset.buttons=JSON.stringify(clean);
    renderButtons(card);
    collectCards();
  }
  function openButtonModal(card,rowIndex,buttonIndex,isNew){
    const rows=getRows(card);
    if(rowIndex==null){rowIndex=rows.length; buttonIndex=0; isNew=true;}
    const current=!isNew&&rows[rowIndex]&&rows[rowIndex][buttonIndex]?rows[rowIndex][buttonIndex]:{type:'url',text:'',url:'',target_block_id:0};
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-message-button-modal is-open';
    const opts=['<option value="0">Без перехода</option>'].concat(transitionOptions.map(o=>'<option value="'+esc(o.id)+'" '+(parseInt(current.target_block_id||0,10)===parseInt(o.id,10)?'selected':'')+'>'+esc(o.title)+'</option>')).join('');
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Кнопка сообщения</h4><button type="button" class="tg-question-modal-close" data-btn-cancel>×</button></div><div class="tg-button-modal-body"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Текст кнопки</span><input class="tg-flow-panel-input" data-modal-btn-text value="'+esc(current.text||current.title||'')+'" maxlength="64" placeholder="Например: Перейти"></label><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Действие</span><select class="tg-button-select" data-modal-btn-type><option value="url" '+(current.type==='transition'?'':'selected')+'>Открыть ссылку</option><option value="transition" '+(current.type==='transition'?'selected':'')+'>Переход к блоку</option></select></label><label class="tg-flow-panel-field" data-url-field><span class="tg-flow-panel-label">Ссылка</span><input class="tg-flow-panel-input" data-modal-btn-url value="'+esc(current.url||'')+'" placeholder="https://..."></label><label class="tg-flow-panel-field" data-target-field><span class="tg-flow-panel-label">Целевой блок</span><select class="tg-button-select" data-modal-btn-target>'+opts+'</select></label></div><div class="tg-button-modal-foot"><button type="button" class="tg-button-danger" data-btn-delete>Удалить</button><button type="button" class="tg-button-muted" data-btn-cancel>Отмена</button><button type="button" class="tg-button-save" data-btn-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const typeSel=modal.querySelector('[data-modal-btn-type]'), urlField=modal.querySelector('[data-url-field]'), targetField=modal.querySelector('[data-target-field]');
    function sync(){const t=typeSel.value==='transition'; urlField.style.display=t?'none':''; targetField.style.display=t?'':'none';}
    typeSel.addEventListener('change',sync); sync();
    const close=()=>modal.remove();
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-btn-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault();e.stopPropagation();close();}));
    const deleteBtn=modal.querySelector('[data-btn-delete]');
    if(deleteBtn){
      deleteBtn.style.display=isNew?'none':'';
      deleteBtn.addEventListener('click',(e)=>{e.preventDefault();e.stopPropagation(); if(rows[rowIndex]){rows[rowIndex].splice(buttonIndex,1); if(!rows[rowIndex].length)rows.splice(rowIndex,1);} setRows(card,rows); close(); });
    }
    const textInput=modal.querySelector('[data-modal-btn-text]');
    textInput?.focus();
    textInput?.addEventListener('keydown',(e)=>{ if(e.key==='Enter'){ e.preventDefault(); modal.querySelector('[data-btn-save]')?.click(); }});
    modal.querySelector('[data-btn-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const type=typeSel.value==='transition'?'transition':'url';
      const item={type,text:String(textInput?.value||'').trim(),url:type==='url'?String(modal.querySelector('[data-modal-btn-url]')?.value||'').trim():'',target_block_id:type==='transition'?(parseInt(modal.querySelector('[data-modal-btn-target]')?.value||'0',10)||0):0};
      if(!item.text){textInput?.focus();return;}
      if(!rows[rowIndex]) rows[rowIndex]=[];
      rows[rowIndex].splice(buttonIndex, isNew?0:1, item);
      setRows(card,rows.filter(r=>r&&r.length));
      close();
    });
  }
  function sanitizeEditorHtml(html){
    const wrap=document.createElement('div'); wrap.innerHTML=html||'';
    const allowed=new Set(['BR','B','STRONG','I','EM','U','S','STRIKE','CODE','PRE','BLOCKQUOTE','A','TG-SPOILER']);
    function clean(node){
      if(node.nodeType===Node.TEXT_NODE) return document.createTextNode(node.nodeValue.replace(/\u200b/g,''));
      if(node.nodeType!==Node.ELEMENT_NODE) return document.createTextNode('');
      if(!allowed.has(node.tagName)){const frag=document.createDocumentFragment(); Array.from(node.childNodes).forEach(ch=>frag.appendChild(clean(ch))); return frag;}
      let tag=node.tagName.toLowerCase(); if(tag==='strong')tag='b'; if(tag==='em')tag='i'; if(tag==='strike')tag='s';
      const el=document.createElement(tag); if(tag==='a'){const href=node.getAttribute('href')||''; if(/^https?:\/\//i.test(href)||/^tg:\/\//i.test(href)) el.setAttribute('href',href);}
      Array.from(node.childNodes).forEach(ch=>el.appendChild(clean(ch))); return el;
    }
    const out=document.createElement('div'); Array.from(wrap.childNodes).forEach(ch=>out.appendChild(clean(ch))); return out.innerHTML;
  }
  function plainTextToEditorHtml(text){
    return esc(String(text||'').replace(/\u200b/g,'')).replace(/\r\n|\r|\n/g,'<br>');
  }
  function resetTypingFormatState(){
    ['bold','italic','underline','strikeThrough'].forEach(cmd=>{
      try { if(document.queryCommandState(cmd)) document.execCommand(cmd,false,null); } catch(e) {}
    });
  }
  function nearestInlineFormatAncestor(editor,node){
    const tags=new Set(['B','STRONG','I','EM','U','S','STRIKE','CODE','TG-SPOILER','A']);
    let current=node&&node.nodeType===Node.ELEMENT_NODE?node:node?.parentNode;
    let found=null;
    while(current&&current!==editor){
      if(current.nodeType===Node.ELEMENT_NODE&&tags.has(current.tagName)) found=current;
      current=current.parentNode;
    }
    return found;
  }
  function placeCaretAfter(node){
    const sel=window.getSelection();
    if(!sel)return;
    const range=document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
  }
  function insertHtmlAtSelection(editor, html){
    editor.focus(); const sel=window.getSelection(); if(!sel||!sel.rangeCount){editor.insertAdjacentHTML('beforeend',html); return;}
    const range=sel.getRangeAt(0); if(!editor.contains(range.commonAncestorContainer)){editor.insertAdjacentHTML('beforeend',html); return;}
    range.deleteContents(); const tmp=document.createElement('div'); tmp.innerHTML=html; const frag=document.createDocumentFragment(); let last=null; while(tmp.firstChild){last=frag.appendChild(tmp.firstChild);} range.insertNode(frag); if(last){range.setStartAfter(last); range.collapse(true); sel.removeAllRanges(); sel.addRange(range);}
  }
  function wrapSelection(editor,before,after){
    editor.focus(); const sel=window.getSelection(); const text=sel&&sel.rangeCount?String(sel):''; if(text){insertHtmlAtSelection(editor,before+esc(text)+after);} else insertHtmlAtSelection(editor,before+after);
  }
  function insertPlainBreak(editor){insertHtmlAtSelection(editor,'<br>');}
  function insertCleanBreak(editor){
    editor.focus();
    const sel=window.getSelection();
    if(!sel||!sel.rangeCount){editor.insertAdjacentHTML('beforeend','<br>'); resetTypingFormatState(); return;}
    const range=sel.getRangeAt(0);
    if(!editor.contains(range.commonAncestorContainer)){editor.insertAdjacentHTML('beforeend','<br>'); resetTypingFormatState(); return;}
    if(!range.collapsed){range.deleteContents();}
    const inline=nearestInlineFormatAncestor(editor,range.startContainer);
    if(inline){
      const br=document.createElement('br');
      inline.parentNode.insertBefore(br,inline.nextSibling);
      placeCaretAfter(br);
    } else {
      insertHtmlAtSelection(editor,'<br>');
    }
    resetTypingFormatState();
  }
  function clearEditorDefaultText(editor){
    if(!editor || editor.dataset.clearOnFocus!=='1') return false;
    const text=String(editor.innerText||'').replace(/\u200b/g,'').trim();
    if(text==='Новое сообщение'){
      editor.innerHTML='';
      editor.dataset.clearOnFocus='0';
      return true;
    }
    editor.dataset.clearOnFocus='0';
    return false;
  }
  function panelNotice(message, keep=false){
    const alert=panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
    if(alert){
      alert.textContent=String(message||'');
      alert.classList.add('is-open');
      alert.style.whiteSpace='pre-wrap';
      try { alert.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e) {}
      window.clearTimeout(panelNotice._timer);
      if(!keep) panelNotice._timer=window.setTimeout(()=>alert.classList.remove('is-open'),2600);
    }
  }
  function panelDiag(stage, data){
    const payload = data || {};
    window.__tgScenarioQuestionDiag = window.__tgScenarioQuestionDiag || [];
    const row = {time: new Date().toISOString(), stage, data: payload};
    window.__tgScenarioQuestionDiag.push(row);
    if (window.__tgScenarioQuestionDiag.length > 30) window.__tgScenarioQuestionDiag.shift();
    try { console.info('[ScenarioQuestionDiag]', row); } catch(e) {}
    return row;
  }
  function showPanelDiag(message, data){
    const suffix = data ? '\n\nДиагностика:\n' + JSON.stringify(data, null, 2).slice(0, 1600) : '';
    panelNotice(String(message || '') + suffix, true);
  }
  const editorSavedRanges=new WeakMap();
  function isRangeInsideEditor(editor,range){
    if(!editor||!range)return false;
    const node=range.commonAncestorContainer;
    return node===editor || editor.contains(node);
  }
  function rememberEditorSelection(editor){
    const sel=window.getSelection();
    if(!sel||!sel.rangeCount)return;
    const range=sel.getRangeAt(0);
    if(isRangeInsideEditor(editor,range) && String(sel).trim()!=='') editorSavedRanges.set(editor,range.cloneRange());
  }
  function selectedRangeForEditor(editor){
    const sel=window.getSelection();
    if(sel&&sel.rangeCount){
      const range=sel.getRangeAt(0);
      if(isRangeInsideEditor(editor,range) && String(sel).trim()!=='') return range.cloneRange();
    }
    const saved=editorSavedRanges.get(editor);
    if(saved && isRangeInsideEditor(editor,saved) && saved.toString().trim()!=='') return saved.cloneRange();
    return null;
  }
  let dateContext=null;
  function openDateModal(editor){
    const modal=panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal');
    const dateInput=panel ? panel.querySelector('#tgScenarioDateValue') : document.getElementById('tgScenarioDateValue');
    const timeInput=panel ? panel.querySelector('#tgScenarioTimeValue') : document.getElementById('tgScenarioTimeValue');
    if(!modal||!dateInput||!timeInput)return;
    const range=selectedRangeForEditor(editor);
    if(!range){
      panelNotice('Сначала выделите слово или фразу, к которой нужно привязать дату.');
      return;
    }
    const now=new Date();
    dateInput.value=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
    timeInput.value='10:00';
    dateContext={editor,range};
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
  }
  function closeDateModal(){
    const modal=panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal');
    if(modal){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');}
    dateContext=null;
  }
  function applyDateModal(){
    const dateInput=panel ? panel.querySelector('#tgScenarioDateValue') : document.getElementById('tgScenarioDateValue');
    const timeInput=panel ? panel.querySelector('#tgScenarioTimeValue') : document.getElementById('tgScenarioTimeValue');
    if(!dateContext||!dateInput)return;
    const d=dateInput.value;
    const t=(timeInput&&timeInput.value?timeInput.value:'00:00');
    if(!d){panelNotice('Выберите дату.');return;}
    const dt=new Date(d+'T'+t+':00');
    const ts=Math.floor(dt.getTime()/1000);
    if(!Number.isFinite(ts)){panelNotice('Дата указана некорректно.');return;}
    const range=dateContext.range.cloneRange();
    if(!isRangeInsideEditor(dateContext.editor,range) || range.toString().trim()===''){
      panelNotice('Не удалось применить дату: выделите текст ещё раз.');
      closeDateModal();
      return;
    }
    const anchor=document.createElement('a');
    anchor.className='tg-date-link';
    anchor.setAttribute('href','tg://msg?timestamp='+ts);
    anchor.textContent=range.toString();
    range.deleteContents();
    range.insertNode(anchor);
    const sel=window.getSelection();
    if(sel){
      const after=document.createRange();
      after.setStartAfter(anchor);
      after.collapse(true);
      sel.removeAllRanges();
      sel.addRange(after);
    }
    const card=dateContext.editor.closest('[data-card]');
    if(card){collectCards();updateCharCount(card);}
    editorSavedRanges.delete(dateContext.editor);
    closeDateModal();
  }
  function closeFloatingMenus(except=null){form.querySelectorAll('.tg-emoji-menu.is-open,.tg-macro-menu.is-open').forEach(m=>{if(m!==except)m.classList.remove('is-open');});}
  function editorTemplate(type, text, visible=true){
    const limit=(type==='text'||type==='question')?4096:1024; const placeholder=type==='question'?'Текст вопроса':(type==='text'?'Текст сообщения':'Подпись к медиа');
    const hidden = visible ? '' : ' style="display:none"';
    const rawText=String(text||'');
    const clearOnFocus=(type==='text' && rawText.replace(/<[^>]*>/g,'').replace(/&nbsp;/g,' ').trim()==='Новое сообщение')?' data-clear-on-focus="1"':'';
    return '<div class="tg-caption-editor" data-caption-editor'+hidden+'><div class="tg-card-toolbar"><button type="button" title="Жирный" data-format="bold">'+adminIcon('bold')+'</button><button type="button" title="Курсив" data-format="italic">'+adminIcon('italic')+'</button><button type="button" title="Подчёркнутый" data-format="underline">'+adminIcon('underline')+'</button><button type="button" title="Зачёркнутый" data-format="strikeThrough">'+adminIcon('strike')+'</button><button type="button" title="Моноширинный текст" data-wrap="<code>|</code>">'+adminIcon('mono')+'</button><button type="button" title="Скрытый текст" data-wrap="<tg-spoiler>|</tg-spoiler>">'+adminIcon('spoiler')+'</button><button type="button" title="Код" data-wrap="<pre>|</pre>">'+adminIcon('code')+'</button><button type="button" title="Цитата" data-wrap="<blockquote>|</blockquote>">'+adminIcon('quote')+'</button><span class="tg-toolbar-sep"></span><button type="button" title="Дата / напоминание" data-date>'+adminIcon('calendar')+'</button><button type="button" title="Ссылка" data-link>'+adminIcon('link')+'</button></div><div class="tg-editor-wrap"><div class="tg-card-editor" contenteditable="true" data-editor data-placeholder="'+esc(placeholder)+'" spellcheck="true"'+clearOnFocus+'>'+(text||'')+'</div><div class="tg-card-bottom"><div class="tg-editor-tools"><button type="button" class="tg-mini-btn" data-emoji>'+adminIcon('emoji')+'<span>Эмодзи</span></button><button type="button" class="tg-mini-btn" data-macro>'+adminIcon('variables')+'<span>Переменные</span></button><button type="button" class="tg-mini-btn" data-clear-format>'+adminIcon('clear')+'<span>Очистить</span></button><div class="tg-emoji-menu">'+emojiHtml()+'</div><div class="tg-macro-menu">'+macroMenuHtml()+'</div></div><div class="tg-char-count"><span data-char-count>0</span> / '+limit+'</div></div></div></div>';
  }
  function disablePreviewOptionHtml(source){
    const checked = source && source.disable_web_page_preview ? ' checked' : '';
    return '<label class="tg-protect-option"><input type="checkbox" data-disable-preview-toggle'+checked+'> Отключить предпросмотр ссылок <span class="tg-protect-help" tabindex="0" aria-label="Ссылки в тексте сообщения будут отправлены без автоматического предпросмотра" data-tooltip="Ссылки в тексте сообщения будут отправлены без автоматического предпросмотра">?</span></label>';
  }
  function protectOptionHtml(source){
    const checked = source && source.protect_content ? ' checked' : '';
    return '<label class="tg-protect-option"><input type="checkbox" data-protect-toggle'+checked+'> Защищать контент <span class="tg-protect-help" tabindex="0" aria-label="Защищает содержимое отправленного сообщения от пересылки и сохранения" data-tooltip="Защищает содержимое отправленного сообщения от пересылки и сохранения">?</span></label>';
  }
  function cardOptionsHtml(source, captionHtml=''){
    return '<div class="tg-card-options">'+captionHtml+protectOptionHtml(source || {})+'</div>';
  }
  function questionFieldOptionsHtml(selected){
    const current=String(selected||'');
    let html='<option value="">Не сохранять</option>';
    let count=0;
    (questionFieldOptions||[]).forEach(field=>{
      const code=String(field.code||''); if(!code)return;
      const type=String(field.field_type||'text');
      if(type!=='text' && type!=='number') return;
      const typeLabel=type==='number'?'число':'текст';
      html+='<option value="'+esc(code)+'" data-field-type="'+esc(type)+'" '+(code===current?'selected':'')+'>'+esc(field.title||code)+' · '+typeLabel+'</option>';
      count++;
    });
    if(!count) html+='<option value="" disabled>Создайте пользовательское поле типа текст или число</option>';
    return html;
  }
  function normalizeQuestionAnswers(answers){
    if(!Array.isArray(answers)) return [];
    return answers.map(item=>({text:String((item&& (item.text||item.title))||'').trim().slice(0,128),target_block_id:parseInt((item&&item.target_block_id)||0,10)||0})).filter(item=>item.text).slice(0,20);
  }
  function getQuestionAnswers(card){try{const parsed=JSON.parse(card.dataset.questionAnswers||'[]'); return normalizeQuestionAnswers(parsed);}catch(e){return[];}}
  function setQuestionAnswers(card,answers){
    card.dataset.questionAnswers=JSON.stringify(normalizeQuestionAnswers(answers));
    renderQuestionAnswers(card);
    try { collectCards(); } catch (e) { console.error('Question answers collect error', e); panelNotice('Не удалось обновить ответы. Проверьте карточку вопроса.'); }
  }
  function panelModalHost(){ return panel || document.body; }
  function closeQuestionModals(){
    (panel || document).querySelectorAll('.tg-question-answer-modal,.tg-question-settings-modal').forEach(modal=>modal.remove());
    document.querySelectorAll('body > .tg-question-answer-modal, body > .tg-question-settings-modal').forEach(modal=>modal.remove());
  }
  function questionWaitLabel(card){
    const value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
    const unit=card.dataset.questionWaitUnit||'hours';
    const unitLabel=unit==='minutes'?'мин.':(unit==='days'?'дн.':'часа');
    return 'Ожидание ответа: '+value+' '+unitLabel;
  }
  function renderQuestionAnswers(card){
    const list=card.querySelector('[data-question-answers]'); if(!list)return;
    const answers=getQuestionAnswers(card);
    if(!answers.length){list.innerHTML='<div class="tg-question-empty">Ответы не добавлены. Подписчик сможет написать обычное сообщение.</div>';}
    else {list.innerHTML=answers.map((answer,index)=>'<span class="tg-question-answer-chip" data-question-answer-edit="'+index+'">'+esc(answer.text)+'<button type="button" data-question-answer-remove="'+index+'" title="Удалить">×</button></span>').join('');}
    list.querySelectorAll('[data-question-answer-edit]').forEach(chip=>chip.addEventListener('click',e=>{if(e.target&&e.target.matches('button'))return; openQuestionAnswerModal(card,parseInt(chip.dataset.questionAnswerEdit||'0',10));}));
    list.querySelectorAll('[data-question-answer-remove]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation(); const idx=parseInt(btn.dataset.questionAnswerRemove||'-1',10); const next=getQuestionAnswers(card); if(idx>=0) next.splice(idx,1); setQuestionAnswers(card,next);}));
  }
  function openQuestionAnswerModal(card,index=null){
    if(!card || (card.dataset.type||'') !== 'question') return;
    closeQuestionModals();
    const answers=getQuestionAnswers(card);
    const openedFromAdd = index===null || index<0;
    const rowsData = answers.map((answer,idx)=>({text:String(answer.text||''), target_block_id:parseInt(answer.target_block_id||0,10)||0, original_index:idx}));
    if(openedFromAdd && rowsData.length < 20){
      rowsData.push({text:'', target_block_id:0, original_index:-1});
    }
    if(!rowsData.length){
      rowsData.push({text:'', target_block_id:0, original_index:-1});
    }
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-question-answer-modal is-open';
    const rowHtml=(row={})=>'<div class="tg-question-answer-row" data-question-answer-row data-original-index="'+String(parseInt(row.original_index||-1,10)||-1)+'" data-target-block-id="'+String(parseInt(row.target_block_id||0,10)||0)+'"><input class="tg-flow-panel-input" data-question-answer-text value="'+esc(row.text||'')+'" maxlength="128" placeholder="Например: Валик"><button type="button" class="tg-question-row-remove" data-question-row-remove title="Удалить строку">×</button></div>';
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Ответы</h4><button type="button" class="tg-question-modal-close" data-question-cancel>×</button></div><div class="tg-button-modal-body"><div class="tg-question-answer-bulk"><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Варианты ответов</span><div class="tg-question-answer-rows" data-question-answer-rows>'+rowsData.map(rowHtml).join('')+'</div></label><button type="button" class="tg-btn-ghost tg-question-add-row" data-question-add-row>+ Ещё ответ</button><div class="tg-question-settings-meta">Можно добавить до 20 вариантов, максимум 128 символов каждый. Переходы настраиваются на холсте через точки рядом с ответами.</div></div></div><div class="tg-button-modal-foot"><button type="button" class="tg-button-muted" data-question-cancel>Отмена</button><button type="button" class="tg-button-save" data-question-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const close=()=>modal.remove();
    const focusLast=()=>{const inputs=modal.querySelectorAll('[data-question-answer-text]'); inputs[inputs.length-1]?.focus();};
    const syncRemoveButtons=()=>{const rows=modal.querySelectorAll('[data-question-answer-row]'); rows.forEach(row=>{const btn=row.querySelector('[data-question-row-remove]'); if(btn) btn.style.visibility=rows.length>1?'visible':'hidden';});};
    const currentCount=()=>modal.querySelectorAll('[data-question-answer-row]').length;
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-question-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close();}));
    modal.querySelector('[data-question-add-row]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const rowsBox=modal.querySelector('[data-question-answer-rows]');
      if(!rowsBox || currentCount()>=20){ panelNotice('В вопросе можно добавить максимум 20 ответов.'); return; }
      rowsBox.insertAdjacentHTML('beforeend', rowHtml({text:'',target_block_id:0,original_index:-1}));
      syncRemoveButtons();
      focusLast();
    });
    modal.addEventListener('click',(e)=>{
      const btn=e.target&&e.target.closest?e.target.closest('[data-question-row-remove]'):null;
      if(!btn) return;
      e.preventDefault(); e.stopPropagation();
      const row=btn.closest('[data-question-answer-row]');
      if(row && currentCount()>1) row.remove();
      syncRemoveButtons();
    });
    modal.addEventListener('keydown',(e)=>{
      if((e.ctrlKey||e.metaKey) && e.key==='Enter'){
        e.preventDefault(); modal.querySelector('[data-question-save]')?.click(); return;
      }
      if(e.key==='Enter' && !e.shiftKey){
        e.preventDefault();
        const inputs=Array.from(modal.querySelectorAll('[data-question-answer-text]'));
        const last=inputs[inputs.length-1];
        if(document.activeElement===last && String(last.value||'').trim() && currentCount()<20) modal.querySelector('[data-question-add-row]')?.click();
        else modal.querySelector('[data-question-save]')?.click();
      }
    });
    syncRemoveButtons();
    const inputs=Array.from(modal.querySelectorAll('[data-question-answer-text]'));
    if(index!==null && index>=0 && inputs[index]) inputs[index].focus(); else focusLast();
    modal.querySelector('[data-question-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      const rows=Array.from(modal.querySelectorAll('[data-question-answer-row]'));
      const next=[];
      const seen=new Set();
      rows.forEach(row=>{
        if(next.length>=20) return;
        const input=row.querySelector('[data-question-answer-text]');
        const text=String(input?.value||'').trim().slice(0,128);
        if(!text || seen.has(text)) return;
        seen.add(text);
        const originalIndex=parseInt(row.dataset.originalIndex||'-1',10);
        const storedTarget=parseInt(row.dataset.targetBlockId||'0',10)||0;
        const originalAnswer=originalIndex>=0 ? (answers[originalIndex]||null) : null;
        const target=originalAnswer ? (parseInt(originalAnswer.target_block_id||0,10)||0) : storedTarget;
        next.push({text,target_block_id:target});
      });
      setQuestionAnswers(card,next);
      panelDiag('questionAnswers:saveAll',{count:next.length, answers:next.map(a=>a.text)});
      close();
    });
  }
  function openQuestionSettingsModal(card){
    if(!card || (card.dataset.type||'') !== 'question') return;
    closeQuestionModals();
    const value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
    const unit=card.dataset.questionWaitUnit||'hours';
    const check=card.dataset.questionCheck==='1';
    const remind=card.dataset.questionRemind==='1';
    const answers=getQuestionAnswers(card);
    const modal=document.createElement('div');
    modal.className='tg-button-modal-backdrop tg-question-settings-modal is-open';
    const chips = answers.length
      ? answers.map((answer,index)=>'<span class="tg-question-settings-chip">'+esc(answer.text||'Ответ')+'<button type="button" data-settings-answer-remove="'+index+'">×</button></span>').join('')
      : '<span class="tg-question-empty">Ответы не добавлены.</span>';
    modal.innerHTML='<div class="tg-button-modal"><div class="tg-button-modal-head"><h4>Настройки вопроса</h4><button type="button" class="tg-question-modal-close" data-question-settings-cancel>×</button></div>'
      + '<div class="tg-button-modal-body">'
      + '<label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Варианты ответов</span><div class="tg-question-settings-answer-box"><div class="tg-question-settings-chips">'+chips+'<button type="button" class="tg-question-settings-add" data-settings-add-answer>+ ответ</button></div><div class="tg-question-settings-meta">До 20 элементов. Максимум 128 символов каждый.</div></div></label>'
      + '<div class="tg-flow-panel-label" style="margin-top:18px;margin-bottom:8px">Ожидание ответа пользователя</div>'
      + '<div class="tg-question-settings-row"><input class="tg-flow-panel-input" type="number" min="1" max="999" data-question-wait-value value="'+esc(value)+'"><select class="tg-button-select" data-question-wait-unit><option value="minutes" '+(unit==='minutes'?'selected':'')+'>минут</option><option value="hours" '+(unit==='hours'?'selected':'')+'>часов</option><option value="days" '+(unit==='days'?'selected':'')+'>дней</option></select></div>'
      + '<div class="tg-question-switches"><label class="tg-switch-line"><input type="checkbox" data-question-check '+(check?'checked':'')+'> <span>Включить проверку</span></label><label class="tg-switch-line"><input type="checkbox" data-question-remind '+(remind?'checked':'')+'> <span>Напомнить, если нет ответа</span></label></div>'
      + '<div class="tg-reminder-box '+(remind?'is-open':'')+'" data-reminder-box><label class="tg-flow-panel-field"><span class="tg-flow-panel-label">Текст напоминания</span><textarea class="tg-flow-panel-input" data-question-remind-text maxlength="600" placeholder="{%first_name%}, пожалуйста, уточните ваш ответ…">'+esc(card.dataset.questionRemindText||'')+'</textarea></label><div class="tg-question-settings-row"><input class="tg-flow-panel-input" type="number" min="1" max="999" data-question-remind-value value="'+esc(card.dataset.questionRemindValue||'5')+'"><select class="tg-button-select" data-question-remind-unit><option value="minutes" '+((card.dataset.questionRemindUnit||'minutes')==='minutes'?'selected':'')+'>минут</option><option value="hours" '+((card.dataset.questionRemindUnit||'minutes')==='hours'?'selected':'')+'>часов</option><option value="days" '+((card.dataset.questionRemindUnit||'minutes')==='days'?'selected':'')+'>дней</option></select></div></div>'
      + '</div><div class="tg-button-modal-foot"><button type="button" class="tg-button-muted" data-question-settings-cancel>Отмена</button><button type="button" class="tg-button-save" data-question-settings-save>Сохранить</button></div></div>';
    panelModalHost().appendChild(modal);
    const close=()=>modal.remove();
    modal.addEventListener('mousedown',(e)=>{ if(e.target===modal) close(); });
    modal.querySelectorAll('[data-question-settings-cancel]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close();}));
    modal.querySelector('[data-settings-add-answer]')?.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); close(); openQuestionAnswerModal(card,null);});
    modal.querySelectorAll('[data-settings-answer-remove]').forEach(btn=>btn.addEventListener('click',(e)=>{e.preventDefault(); e.stopPropagation(); const idx=parseInt(btn.dataset.settingsAnswerRemove||'-1',10); const next=getQuestionAnswers(card); if(idx>=0){next.splice(idx,1); setQuestionAnswers(card,next);} close(); openQuestionSettingsModal(card);}));
    const remindToggle=modal.querySelector('[data-question-remind]');
    const remindBox=modal.querySelector('[data-reminder-box]');
    remindToggle?.addEventListener('change',()=>{if(remindBox) remindBox.classList.toggle('is-open', !!remindToggle.checked);});
    modal.querySelector('[data-question-settings-save]')?.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      card.dataset.questionWaitValue=String(Math.max(1,parseInt(modal.querySelector('[data-question-wait-value]').value||'24',10)||24));
      card.dataset.questionWaitUnit=modal.querySelector('[data-question-wait-unit]').value||'hours';
      card.dataset.questionCheck=modal.querySelector('[data-question-check]').checked?'1':'0';
      card.dataset.questionRemind=modal.querySelector('[data-question-remind]').checked?'1':'0';
      card.dataset.questionRemindText=modal.querySelector('[data-question-remind-text]')?.value||'';
      card.dataset.questionRemindValue=String(Math.max(1,parseInt(modal.querySelector('[data-question-remind-value]')?.value||'5',10)||5));
      card.dataset.questionRemindUnit=modal.querySelector('[data-question-remind-unit]')?.value||'minutes';
      const wait=card.querySelector('[data-question-wait-label]'); if(wait) wait.textContent=questionWaitLabel(card);
      try { collectCards(); } catch(err) { console.error('Question settings collect error', err); panelNotice('Не удалось сохранить настройки вопроса.'); }
      close();
    });
  }
  
  function questionControlsHtml(source){
    const selected=source.save_field_code||'';
    const previewChecked=source.disable_web_page_preview?' checked':'';
    const protectChecked=source && source.protect_content ? ' checked' : '';
    return '<div class="tg-question-box">'
      + '<div class="tg-question-option-stack">'
      + '<label class="tg-question-option-line"><input type="checkbox" data-question-disable-preview'+previewChecked+'> <span>Отключить предпросмотр ссылок</span><span class="tg-help-dot" data-tip="Ссылки в тексте вопроса будут отправлены без автоматического предпросмотра">?</span></label>'
      + '<label class="tg-question-option-line"><input type="checkbox" data-protect-toggle'+protectChecked+'> <span>Защищать контент</span><span class="tg-protect-help" data-tip="Защищает содержимое отправленного сообщения от пересылки и сохранения">?</span></label>'
      + '</div>'
      + '<label class="tg-question-save-field"><span class="tg-question-field-title">Сохранить ответ в поле</span><select class="tg-button-select" data-question-save-field>'+questionFieldOptionsHtml(selected)+'</select><div class="tg-question-field-hint">Для вопросов доступны только пользовательские поля типа «текст» и «число». Поля «дата» и «дата со временем» здесь скрыты, чтобы подписчик не ломал формат ответа.</div></label>'
      + '<div class="tg-question-answers-panel"><div class="tg-question-answers-head"><div><div class="tg-question-answers-title">Ответы</div><div class="tg-question-answers-hint">Кнопки ответа можно добавить здесь или оставить пустым.</div></div></div><div class="tg-question-answers" data-question-answers></div><div class="tg-question-actions"><button type="button" class="tg-btn-ghost" data-question-add-answer>+ Добавить ответ</button><button type="button" class="tg-btn-ghost" data-question-settings>Настройки</button><span class="tg-question-wait" data-question-wait-label></span></div></div>'
      + '<div class="tg-question-noanswer"><strong>Подписчик не ответил</strong><span>Отдельный выход на холсте добавим следующим шагом.</span></div>'
      + '</div>';
  }
  function addCard(type='text', source={}){
    const card=document.createElement('div'); card.className='tg-message-card'; card.dataset.card='1'; card.dataset.type=type; card.dataset.buttons=JSON.stringify(normalizeRows(source.buttons));
    if(type==='question'){card.dataset.questionAnswers=JSON.stringify(normalizeQuestionAnswers(source.answers)); card.dataset.questionWaitValue=String(source.wait_value||24); card.dataset.questionWaitUnit=String(source.wait_unit||'hours'); card.dataset.questionCheck=source.enable_check?'1':'0'; card.dataset.questionRemind=source.remind_no_answer?'1':'0'; card.dataset.questionRemindText=String(source.remind_text||''); card.dataset.questionRemindValue=String(source.remind_value||5); card.dataset.questionRemindUnit=String(source.remind_unit||'minutes'); card.dataset.questionNoAnswerTarget=String(parseInt(source.no_answer_target_block_id||0,10)||0);}
    let media=''; const url=mediaUrl(source);
    if(type==='question'){
      media='';
    } else if(type==='gallery'){
      setGalleryItems(card, galleryItems(source));
      const captionOn = !!(source.caption_enabled || String(source.text||'').trim());
      const caption = captionOn ? ' checked' : '';
      media='<div class="tg-flow-gallery-box"><div class="tg-flow-media-grid"><label class="tg-media-field"><span class="tg-form-label">Картинки</span><div class="tg-flow-file-control"><input type="file" data-gallery-files multiple accept="image/*"><span class="tg-flow-file-button">Выберите файлы</span><span class="tg-flow-upload-name" data-upload-name>Можно выбрать сразу несколько</span></div></label><label class="tg-media-field"><span class="tg-form-label">Или ссылка на картинку</span><div class="tg-gallery-url-row"><input class="tg-form-field tg-media-url-field" data-gallery-url placeholder="https://..."><button type="button" data-gallery-url-add>+</button></div></label></div><div class="tg-gallery-list" data-gallery-list></div><div class="tg-gallery-options"><label><input type="checkbox" data-gallery-caption'+caption+'> Подпись</label>'+protectOptionHtml(source)+'</div><div class="tg-flow-card-note"><strong>Галерея</strong><span>Можно добавить несколько картинок одной карточкой. Отправку в Telegram через медиагруппу подключим следующим шагом.</span></div></div>';
    } else if(type!=='text'){
      const fileLabel = type === 'video_note' ? 'Файл MP4 / M4V' : 'Файл';
      const urlLabel = type === 'video_note' ? 'Или ссылка на видео' : 'Или ссылка на медиа';
      const captionOn = !!(source.caption_enabled || String(source.text||'').trim());
      media='<div class="tg-flow-media-grid"><label class="tg-media-field"><span class="tg-form-label">'+fileLabel+'</span><div class="tg-flow-file-control"><input type="file" data-media-file accept="'+mediaAccept(type)+'"><span class="tg-flow-file-button">Выберите файл</span><span class="tg-flow-upload-name" data-upload-name>'+esc(source.media_file_name||'Файл не выбран')+'</span></div></label><label class="tg-media-field"><span class="tg-form-label">'+urlLabel+'</span><input class="tg-form-field tg-media-url-field" data-media-url placeholder="https://..." value="'+esc(source.media_url||'')+'"></label></div>'+cardOptionsHtml(source, '<label><input type="checkbox" data-caption-toggle'+(captionOn?' checked':'')+'> Подпись</label>')+'<input type="hidden" data-existing-file-path value="'+esc(source.media_file_path||'')+'"><input type="hidden" data-existing-file-name value="'+esc(source.media_file_name||'')+'">';
      if(type==='video_note') media+='<div class="tg-flow-card-note"><strong>Видео-заметка Telegram</strong><span>MP4 или M4V, до 10 МБ и до 60 секунд. В Telegram будет отображаться как кружок.</span></div><div class="tg-video-note-status" data-video-note-status></div>';
      if(url&&type==='image') media+='<div class="tg-flow-media-preview"><img src="'+esc(url)+'" alt="Превью картинки"></div>'; else if(url) media+='<div class="tg-flow-media-preview"><strong>'+esc(source.media_file_name||cardTitle(type))+'</strong><div>'+esc(url)+'</div></div>';
    }
    const captionVisible = type==='text' || type==='question' || !!(source.caption_enabled || String(source.text||'').trim());
    const afterEditorOptions = type==='text' ? '<div class="tg-card-options tg-card-options-after">'+disablePreviewOptionHtml(source)+protectOptionHtml(source)+'</div>' : (type==='question' ? questionControlsHtml(source) : '');
    const buttonsHtml = type==='question' ? '' : '<div class="tg-message-buttons" data-buttons-box></div><div class="tg-flow-button-add-line"><button type="button" class="tg-btn-ghost" data-add-button>+ Добавить кнопку</button></div>';
    const editorHtml = type==='question' ? '<div class="tg-question-editor-wrap">'+editorTemplate(type, source.text||'', true)+'</div>' : editorTemplate(type, source.text||'', captionVisible);
    card.innerHTML='<div class="tg-flow-card-head"><h5>'+cardTitle(type)+'</h5><div class="tg-flow-card-actions"><button type="button" title="Выше" data-card-up>'+adminIcon('arrow-up')+'</button><button type="button" title="Ниже" data-card-down>'+adminIcon('arrow-down')+'</button><button type="button" title="Удалить" data-card-delete>'+adminIcon('delete')+'</button></div></div>'+media+editorHtml+afterEditorOptions+buttonsHtml;
    box.appendChild(card); bindCard(card); renderButtons(card); if(type==='gallery') renderGalleryItems(card); if(type==='question'){renderQuestionAnswers(card); const wait=card.querySelector('[data-question-wait-label]'); if(wait) wait.textContent=questionWaitLabel(card);} reindexFiles(); updateCharCount(card); return card;
  }
  function syncCaptionEditor(card, clearWhenHidden=false){
    const type=card.dataset.type||'text';
    const editorBox=card.querySelector('[data-caption-editor]');
    if(!editorBox || type==='text' || type==='question') return;
    const toggle=card.querySelector('[data-caption-toggle], [data-gallery-caption]');
    const enabled=!!(toggle && toggle.checked);
    editorBox.style.display=enabled ? '' : 'none';
    if(!enabled && clearWhenHidden){
      const editor=card.querySelector('[data-editor]');
      if(editor) editor.innerHTML='';
    }
    updateCharCount(card);
  }
  function bindCard(card){
    const editor=card.querySelector('[data-editor]');
    editor.addEventListener('focus',()=>{if(clearEditorDefaultText(editor)){collectCards();updateCharCount(card);}});
    editor.addEventListener('paste',e=>{e.preventDefault(); clearEditorDefaultText(editor); const data=e.clipboardData||window.clipboardData; const text=data?.getData('text/plain')||''; insertHtmlAtSelection(editor, plainTextToEditorHtml(text)); resetTypingFormatState(); collectCards(); updateCharCount(card);});
    editor.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault(); clearEditorDefaultText(editor); insertCleanBreak(editor); collectCards(); updateCharCount(card);}});
    editor.addEventListener('keyup',()=>rememberEditorSelection(editor));
    editor.addEventListener('mouseup',()=>rememberEditorSelection(editor));
    editor.addEventListener('touchend',()=>setTimeout(()=>rememberEditorSelection(editor),0));
    card.addEventListener('input',()=>{rememberEditorSelection(editor); collectCards(); updateCharCount(card);}); card.addEventListener('change',()=>{collectCards(); updateCharCount(card);});
    card.querySelectorAll('.tg-card-toolbar button,.tg-mini-btn').forEach(btn=>btn.addEventListener('mousedown',e=>e.preventDefault()));
    card.querySelectorAll('[data-format]').forEach(btn=>btn.addEventListener('click',()=>{editor.focus(); document.execCommand(btn.dataset.format,false,null); rememberEditorSelection(editor); collectCards(); updateCharCount(card);}));
    card.querySelectorAll('[data-wrap]').forEach(btn=>btn.addEventListener('click',()=>{const [a,b]=(btn.dataset.wrap||'').split('|'); wrapSelection(editor,a,b); rememberEditorSelection(editor); collectCards(); updateCharCount(card);}));
    card.querySelector('[data-date]')?.addEventListener('click',e=>{e.preventDefault(); e.stopPropagation(); rememberEditorSelection(editor); openDateModal(editor);});
    card.querySelector('[data-link]')?.addEventListener('click',()=>{const url=prompt('Вставьте ссылку','https://'); if(url) {wrapSelection(editor,'<a href="'+esc(url)+'">','</a>'); collectCards();}});
    card.querySelector('[data-clear-format]')?.addEventListener('click',()=>{editor.focus(); document.execCommand('removeFormat',false,null); collectCards();});
    const emojiBtn=card.querySelector('[data-emoji]'), emojiMenu=card.querySelector('.tg-emoji-menu'), macroBtn=card.querySelector('[data-macro]'), macroMenu=card.querySelector('.tg-macro-menu');
    emojiBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!emojiMenu.classList.contains('is-open'); closeFloatingMenus(); emojiMenu.classList.toggle('is-open',open);});
    macroBtn?.addEventListener('click',e=>{e.stopPropagation(); const open=!macroMenu.classList.contains('is-open'); closeFloatingMenus(); macroMenu.classList.toggle('is-open',open);});
    emojiMenu?.addEventListener('click',e=>e.stopPropagation()); macroMenu?.addEventListener('click',e=>e.stopPropagation());
    card.querySelector('[data-macro-search]')?.addEventListener('input',e=>{const q=String(e.target.value||'').toLowerCase().trim(); card.querySelectorAll('[data-macro-search-text]').forEach(item=>{item.style.display=!q||String(item.dataset.macroSearchText||'').toLowerCase().includes(q)?'grid':'none';});});
    card.querySelectorAll('[data-emoji-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.emojiInsert||'');emojiMenu.classList.remove('is-open');collectCards();updateCharCount(card);}));
    card.querySelectorAll('[data-macro-insert]').forEach(b=>b.addEventListener('click',()=>{insertHtmlAtSelection(editor,b.dataset.macroInsert||'');macroMenu.classList.remove('is-open');collectCards();updateCharCount(card);}));
    card.querySelector('[data-add-button]')?.addEventListener('click',()=>openButtonModal(card,null,0,true));
    card.querySelector('[data-card-delete]')?.addEventListener('click',()=>{card.remove(); if(!box.querySelector('[data-card]')) addCard('text',{}); reindexFiles(); collectCards();});
    card.querySelector('[data-card-up]')?.addEventListener('click',()=>{if(card.previousElementSibling) box.insertBefore(card,card.previousElementSibling); reindexFiles(); collectCards();});
    card.querySelector('[data-card-down]')?.addEventListener('click',()=>{if(card.nextElementSibling) box.insertBefore(card.nextElementSibling,card); reindexFiles(); collectCards();});
    card.querySelector('[data-media-file]')?.addEventListener('change',e=>{const f=e.target.files&&e.target.files[0]; const label=card.querySelector('[data-upload-name]'); if(label) label.textContent=f?f.name:'Файл не выбран'; if((card.dataset.type||'')==='video_note'){validateVideoNoteFile(card,f);} collectCards();});
    card.querySelector('[data-media-url]')?.addEventListener('blur',()=>{validateVideoNoteUrl(card); collectCards();});
    card.querySelector('[data-gallery-files]')?.addEventListener('change',e=>{const files=e.target.files?Array.from(e.target.files):[]; const label=card.querySelector('[data-upload-name]'); if(label) label.textContent=files.length?('Выбрано: '+files.length):'Можно выбрать сразу несколько'; renderGalleryItems(card); collectCards();});
    card.querySelector('[data-gallery-url-add]')?.addEventListener('click',()=>addGalleryUrl(card));
    card.querySelector('[data-gallery-url]')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addGalleryUrl(card);}});
    card.querySelector('[data-caption-toggle]')?.addEventListener('change',e=>{syncCaptionEditor(card,true); collectCards();});
    card.querySelector('[data-gallery-caption]')?.addEventListener('change',e=>{syncCaptionEditor(card,true); collectCards();});
    card.querySelector('[data-protect-toggle]')?.addEventListener('change',collectCards);
    card.querySelector('[data-question-add-answer]')?.addEventListener('click',()=>openQuestionAnswerModal(card,null));
    card.querySelector('[data-question-settings]')?.addEventListener('click',()=>openQuestionSettingsModal(card));
    card.querySelector('[data-question-save-field]')?.addEventListener('change',collectCards);
    card.querySelector('[data-question-disable-preview]')?.addEventListener('change',collectCards);
    syncCaptionEditor(card,false);
  }
  function updateCharCount(card){const editor=card.querySelector('[data-editor]'); const counter=card.querySelector('[data-char-count]'); if(counter) counter.textContent=String((editor?.innerText||'').length);}
  function reindexFiles(){box.querySelectorAll('[data-card]').forEach((card,index)=>{const input=card.querySelector('[data-media-file]'); if(input) input.name='card_media_file_'+index; const galleryInput=card.querySelector('[data-gallery-files]'); if(galleryInput) galleryInput.name='gallery_media_file_'+index+'[]';});}
  function collectCards(){
    const cards=[];
    try {
      box.querySelectorAll('[data-card]').forEach((card,index)=>{
        const type=card.dataset.type||'text';
        const captionEnabled = (type==='text'||type==='question') ? true : !!card.querySelector('[data-caption-toggle], [data-gallery-caption]')?.checked;
        const rawText=(card.querySelector('[data-editor]')?.innerHTML||'').trim();
        const item={type,text:captionEnabled?rawText:'',buttons:type==='question'?[]:getRows(card),protect_content:!!card.querySelector('[data-protect-toggle]')?.checked};
        if(type==='text'){
          item.disable_web_page_preview=!!card.querySelector('[data-disable-preview-toggle]')?.checked;
        }
        if(type==='question'){
          item.disable_web_page_preview=!!card.querySelector('[data-question-disable-preview]')?.checked;
          item.save_field_code=card.querySelector('[data-question-save-field]')?.value||'';
          item.answers=getQuestionAnswers(card);
          item.wait_value=Math.max(1,parseInt(card.dataset.questionWaitValue||'24',10)||24);
          item.wait_unit=card.dataset.questionWaitUnit||'hours';
          item.enable_check=card.dataset.questionCheck==='1';
          item.remind_no_answer=card.dataset.questionRemind==='1';
          item.remind_text=card.dataset.questionRemindText||'';
          item.remind_value=Math.max(1,parseInt(card.dataset.questionRemindValue||'5',10)||5);
          item.remind_unit=card.dataset.questionRemindUnit||'minutes';
          item.no_answer_target_block_id=parseInt(card.dataset.questionNoAnswerTarget||'0',10)||0;
        } else if(type==='gallery'){
          item.gallery_items=getGalleryItems(card); item.caption_enabled=captionEnabled; const gf=card.querySelector('[data-gallery-files]'); if(gf&&gf.files&&gf.files.length){item.has_gallery_upload=true; item.gallery_upload_slot=index;}
        } else if(type!=='text'){
          item.caption_enabled=captionEnabled; item.media_url=card.querySelector('[data-media-url]')?.value||''; item.media_file_path=card.querySelector('[data-existing-file-path]')?.value||''; item.media_file_name=card.querySelector('[data-existing-file-name]')?.value||''; const f=card.querySelector('[data-media-file]'); if(f&&f.files&&f.files.length){item.has_upload=true; item.upload_slot=index;}
        }
        cards.push(item);
      });
      jsonInput.value=JSON.stringify(cards);
      panelDiag('collectCards:ok',{count:cards.length, questionAnswers:cards.filter(c=>c.type==='question').map(c=>(c.answers||[]).length), jsonLength:jsonInput.value.length});
      return cards;
    } catch(e) {
      panelDiag('collectCards:error',{message:e && e.message ? e.message : String(e), stack:e && e.stack ? String(e.stack).slice(0,900) : ''});
      throw e;
    }
  }
  document.querySelectorAll('[data-add-card]').forEach(btn=>btn.addEventListener('click',()=>{
    const type = btn.dataset.addCard || 'text';
    addCard(type,{}); collectCards();
  }));
  (panel ? panel.querySelector('#tgScenarioDateApply') : document.getElementById('tgScenarioDateApply'))?.addEventListener('click',applyDateModal);
  (panel || document).querySelectorAll('[data-date-modal-close]').forEach(btn=>btn.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();closeDateModal();}));
  (panel ? panel.querySelector('#tgScenarioDateModal') : document.getElementById('tgScenarioDateModal'))?.addEventListener('click',e=>{if(e.target&&e.target.id==='tgScenarioDateModal')closeDateModal();});
  document.addEventListener('click',()=>closeFloatingMenus());
  (Array.isArray(initialCards)&&initialCards.length?initialCards:[{type:'text',text:'',buttons:[]}]).forEach(card=>addCard(card.type||'text',card));
  collectCards();
  function prepareScenarioPanelSave(){
    let ok=true;
    try {
      closeQuestionModals();
      const alertBox = panel ? panel.querySelector('#scenario-message-alert') : document.getElementById('scenario-message-alert');
      if(alertBox){ alertBox.textContent=''; alertBox.classList.remove('is-open'); }
      box.querySelectorAll('[data-card][data-type="question"]').forEach(card=>{
        // Принудительно нормализуем ответы прямо перед сохранением: это убирает рассинхрон
        // между чипсами в интерфейсе и hidden scenario_cards_json.
        const answers = getQuestionAnswers(card);
        card.dataset.questionAnswers = JSON.stringify(answers);
        renderQuestionAnswers(card);
        const wait = card.querySelector('[data-question-wait-label]');
        if(wait) wait.textContent = questionWaitLabel(card);
      });
      box.querySelectorAll('[data-card][data-type="video_note"]').forEach(card=>{
        if(!validateVideoNoteUrl(card)) ok=false;
        const input=card.querySelector('[data-media-file]');
        const file=input&&input.files&&input.files[0];
        const basic=videoNoteFileLooksValid(file);
        if(!basic.ok){ videoNoteStatus(card,basic.message,'error'); ok=false; }
      });
      const cards = collectCards();
      panelDiag('prepareSave:afterCollect',{count:Array.isArray(cards)?cards.length:0,jsonLength:(jsonInput&&jsonInput.value?jsonInput.value.length:0),questions:(Array.isArray(cards)?cards.filter(c=>c.type==='question').map(c=>({textLength:String(c.text||'').length,answers:(c.answers||[]).map(a=>a.text)})):[])});
      if(!Array.isArray(cards) || !cards.length){
        showPanelDiag('Добавьте хотя бы одну карточку сообщения.', {stage:'prepareSave:noCards'});
        ok=false;
      }
    } catch(e) {
      console.error('Scenario panel save prepare error', e);
      showPanelDiag('Не удалось подготовить карточки к сохранению. Проверьте карточку «Вопрос» и варианты ответов. Ошибка: '+(e && e.message ? e.message : e), {stage:'prepareSave:catch', stack:e && e.stack ? String(e.stack).slice(0,1200) : ''});
      ok=false;
    }
    return ok;
  }
  form.tgFlowPrepareSave = prepareScenarioPanelSave;
  const saveButton = form.querySelector('.tg-flow-panel-primary');
  if(saveButton){
    saveButton.addEventListener('click',()=>{
      try {
        const questionCards = Array.from(box.querySelectorAll('[data-card][data-type="question"]')).map((card,idx)=>({idx,answers:getQuestionAnswers(card).map(a=>a.text),textLength:(card.querySelector('[data-editor]')?.innerText||'').trim().length}));
        panelDiag('saveButton:click',{questions:questionCards,jsonLength:(jsonInput&&jsonInput.value?jsonInput.value.length:0)});
      } catch(e) { panelDiag('saveButton:click-error',{message:e && e.message ? e.message : String(e)}); }
    }, true);
  }
  form.addEventListener('submit',(event)=>{
    if(form.dataset.flowDrawerAjax === '1') return;
    if(!prepareScenarioPanelSave()){
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  });
})();
</script>
