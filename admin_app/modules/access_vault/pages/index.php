<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/../service.php';

$category = asr_av_normalize_category((string)($_GET['category'] ?? 'sites'));
$categories = asr_av_categories();
$schemaReady = asr_av_schema_ready($pdo);
$cryptoReady = asr_access_vault_has_crypto_key();
$search = trim((string)($_GET['q'] ?? ''));
$canView = asr_av_can('view');
$canCreate = asr_av_can('create');
$canEdit = asr_av_can('edit');
$canArchive = asr_av_can('archive');
$canRestore = asr_av_can('restore');
$canCopy = asr_av_can('copy');
$canShare = asr_av_can('share');
$canManageIndividual = asr_av_can('individual_access');
$canImportExport = asr_av_can('import_export');
$canAudit = asr_av_can('audit');
$isSuperAdmin = function_exists('asr_is_protected_user') ? asr_is_protected_user(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => asr_current_role()]) : (asr_current_role() === 'superadmin');

if (!$canView): ?>
<div class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-bold">Недостаточно прав для просмотра модуля «Доступы».</div>
<?php return; endif; ?>

<?php if (!$schemaReady): ?>
<div class="bg-white rounded-3xl border border-yellow-100 p-8 text-left">
    <h3 class="text-lg font-black text-yellow-700 uppercase">Модуль «Доступы» ещё не установлен</h3>
    <p class="mt-3 text-sm font-semibold text-gray-600">Выполните SQL-миграцию <code>database/migrations/2026_05_27_010_create_access_vault.sql</code>.</p>
</div>
<?php return; endif; ?>

<?php if (!$cryptoReady): ?>
<div class="mb-5 bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold text-left">Не задан ключ шифрования ACCESS_VAULT_KEY. Добавьте его в config.php.</div>
<?php endif; ?>
<?php if (!empty($_GET['av_error'])): ?><div class="mb-4 bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold text-left"><?php echo htmlspecialchars((string)$_GET['av_error']); ?></div><?php endif; ?>
<?php if (!empty($_GET['av_msg'])): ?><div class="mb-4 bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold text-left"><?php echo htmlspecialchars((string)$_GET['av_msg']); ?></div><?php endif; ?>

<div class="av-panel bg-white rounded-3xl shadow-sm border border-gray-100 p-4 sm:p-5 mb-5 text-left">
    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
        <h2 class="text-2xl sm:text-3xl font-black text-gray-700 uppercase tracking-tight">Доступы</h2>
        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
            <?php foreach (array_filter($categories, static fn($k) => !in_array($k, ['archive','audit','import_export'], true), ARRAY_FILTER_USE_KEY) as $key => $label): ?>
                <a href="admin.php?tab=access_vault&category=<?php echo urlencode($key); ?>" class="shrink-0 px-2.5 py-2 rounded-lg text-[11px] font-black uppercase tracking-wide whitespace-nowrap <?php echo $category === $key ? 'bg-[#FFA048] text-white shadow-lg shadow-orange-500/10' : 'bg-gray-50 text-gray-600 hover:bg-orange-50 hover:text-[#FFA048]'; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
            <span class="hidden sm:inline-block w-5"></span>
            <?php if ($canAudit): ?><a href="admin.php?tab=access_vault&category=audit" class="av-icon-link <?php echo $category === 'audit' ? 'is-active' : ''; ?>" title="Журнал">Журнал</a><?php endif; ?>
            <a href="admin.php?tab=access_vault&category=archive" class="av-icon-link <?php echo $category === 'archive' ? 'is-active' : ''; ?>" title="Архив">Архив</a>
        </div>
    </div>
    <?php if (!in_array($category, ['audit','import_export'], true)): ?>
    <div class="mt-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <form method="GET" class="flex-1 flex flex-col sm:flex-row gap-3 sm:items-center">
            <input type="hidden" name="tab" value="access_vault">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="flex-1 px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-bold" placeholder="Найти по названию, URL, логину...">
            <button class="bg-[#FFA048] text-white rounded-xl px-6 py-3 text-xs font-black uppercase tracking-widest">Найти</button>
            <?php if ($search !== ''): ?><a href="admin.php?tab=access_vault&category=<?php echo urlencode($category); ?>" class="bg-gray-50 text-gray-500 rounded-xl px-6 py-3 text-xs font-black uppercase tracking-widest text-center">Сбросить</a><?php endif; ?>
        </form>
        <?php if ($category !== 'archive' && $canCreate): ?>
        <div class="relative shrink-0">
            <button type="button" onclick="toggleAvAddMenu()" class="av-add-main w-full sm:w-auto bg-white hover:bg-orange-50 border border-orange-100 text-gray-600 rounded-xl px-8 py-3 text-xs font-black uppercase tracking-widest">Добавить</button>
            <div id="avAddMenu" class="hidden absolute right-0 mt-2 w-52 bg-white border border-gray-100 rounded-2xl shadow-xl z-30 overflow-hidden">
                <button type="button" onclick="openAvResourceModal({category:'<?php echo htmlspecialchars($category); ?>'}); toggleAvAddMenu(false);" class="w-full text-left px-4 py-3 text-xs font-black uppercase text-gray-600 hover:bg-orange-50">Добавить ресурс</button>
                <button type="button" onclick="openAvGroupsModal(); toggleAvAddMenu(false);" class="w-full text-left px-4 py-3 text-xs font-black uppercase text-gray-600 hover:bg-orange-50">Добавить группу</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($category === 'audit'): ?>
<?php if (!$canAudit): ?><div class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-bold">Недостаточно прав для журнала.</div><?php return; endif; ?>
<div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden text-left">
    <div class="px-6 py-4 bg-gray-50/70 border-b border-gray-100"><h3 class="font-black text-gray-800 uppercase tracking-widest text-sm">Журнал действий</h3></div>
    <div class="overflow-x-auto custom-scrollbar"><table class="w-full text-left text-sm whitespace-nowrap"><thead class="bg-gray-50"><tr><th class="p-4 text-xs text-gray-400 uppercase">Когда</th><th class="p-4 text-xs text-gray-400 uppercase">Кто</th><th class="p-4 text-xs text-gray-400 uppercase">Действие</th><th class="p-4 text-xs text-gray-400 uppercase">Раздел</th><th class="p-4 text-xs text-gray-400 uppercase">Ресурс</th></tr></thead><tbody class="divide-y divide-gray-50">
    <?php foreach (asr_av_audit_rows($pdo, 120) as $a): ?><tr><td class="p-4 font-semibold text-gray-500"><?php echo htmlspecialchars((string)$a['created_at']); ?></td><td class="p-4 font-bold text-gray-800"><?php echo htmlspecialchars((string)($a['full_name'] ?: $a['username'] ?: '—')); ?></td><td class="p-4 font-mono text-xs text-gray-800"><?php echo htmlspecialchars((string)$a['action']); ?></td><td class="p-4 font-semibold text-gray-600"><?php echo htmlspecialchars((string)($a['resource_category'] ?: '—')); ?></td><td class="p-4 font-semibold text-gray-600"><?php echo htmlspecialchars((string)($a['resource_title'] ?: '—')); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php return; endif; ?>

<?php if ($category === 'import_export'): ?>
<?php if (!$canImportExport): ?><div class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-bold">Недостаточно прав для импорта и экспорта доступов.</div><?php return; endif; ?>
<section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 mb-6 text-left"><h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Импорт/экспорт доступов</h3><p class="text-sm text-gray-400 font-bold mt-1">CSV-выгрузка содержит расшифрованные пароли.</p></section>
<section class="grid grid-cols-1 md:grid-cols-2 gap-5 text-left">
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 space-y-4"><h4 class="text-base font-black text-gray-800 uppercase">Экспорт доступов</h4><form method="POST" onsubmit="return confirm('Экспорт содержит расшифрованные пароли. Продолжить?');" class="space-y-3"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_export_csv"><input type="hidden" name="category" value="import_export"><select name="work_category" class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-sm font-bold text-gray-600"><?php foreach (['sites'=>'Наши сайты','services'=>'Сервисы','social'=>'Соц. сети','email'=>'Почта','archive'=>'Архив'] as $k => $v): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?></select><button class="inline-flex px-5 py-3 bg-[#FFA048] text-white rounded-xl text-xs font-black uppercase tracking-widest">Скачать CSV</button></form></div>
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 space-y-4"><h4 class="text-base font-black text-gray-800 uppercase">Импорт доступов</h4><form method="POST" enctype="multipart/form-data" class="space-y-3"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_import_csv"><input type="hidden" name="category" value="import_export"><select name="work_category" class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-sm font-bold text-gray-600"><?php foreach (['sites'=>'Наши сайты','services'=>'Сервисы','social'=>'Соц. сети','email'=>'Почта'] as $k => $v): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?></select><input type="file" name="csv_file" accept=".csv,text/csv" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-sm font-bold text-gray-600"><button type="submit" class="inline-flex px-5 py-3 bg-[#FFA048] text-white rounded-xl text-xs font-black uppercase tracking-widest">Импортировать CSV</button></form></div>
</section>
<?php return; endif; ?>

<?php
$resources = asr_av_resources($pdo, $category, $search);
$resourceIds = array_map(fn($r) => (int)$r['id'], $resources);
$credentialsByResource = asr_av_credentials_for_resources($pdo, $resourceIds, $category === 'archive', (int)($_SESSION['user_id'] ?? 0));
$paymentReady = function_exists('asr_av_payment_tables_ready') && asr_av_payment_tables_ready($pdo);
$canManagePayment = $canEdit && $paymentReady;
$paymentsByResource = ($paymentReady && function_exists('asr_av_payments_for_resources')) ? asr_av_payments_for_resources($pdo, $resourceIds) : [];
$paymentRecipientsByPayment = ($paymentReady && function_exists('asr_av_payment_recipients_for_payments')) ? asr_av_payment_recipients_for_payments($pdo, array_map(static fn($p) => (int)($p['id'] ?? 0), $paymentsByResource)) : [];
$groups = $category !== 'archive' ? asr_av_groups($pdo, $category) : [];
$editableCategories = array_filter($categories, static fn($k) => !in_array($k, ['archive','audit','import_export'], true), ARRAY_FILTER_USE_KEY);
$groupsByCategory = [];
foreach ($editableCategories as $catKey => $_catLabel) {
    $groupsByCategory[$catKey] = asr_av_groups($pdo, (string)$catKey);
}
$allAvUsers = asr_av_get_users_for_share($pdo);
$shareUsers = $canShare ? $allAvUsers : [];
$individualUsers = $canManageIndividual ? $allAvUsers : $allAvUsers;
$avUserNamesById = [];
foreach ($allAvUsers as $u) {
    $avUserNamesById[(int)$u['id']] = trim((string)($u['full_name'] ?: $u['username']));
}
$paymentUsers = ($canEdit && function_exists('asr_av_users_for_payment')) ? asr_av_users_for_payment($pdo) : [];
$icons = function_exists('asr_av_group_icons') ? asr_av_group_icons() : ['flask'=>'⚗'];
$grouped = [];
foreach ($resources as $r) {
    $gid = (int)($r['group_id'] ?? 0);
    $key = $gid > 0 ? ('g' . $gid) : 'g0';
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['id'=>$gid,'title'=>trim((string)($r['group_title'] ?? '')) ?: 'Без группы','color'=>(string)($r['group_color'] ?? '#F4E4A6') ?: '#F4E4A6','text_color'=>(string)($r['group_text_color'] ?? '#4B5563') ?: '#4B5563','icon'=>(string)($r['group_icon'] ?? 'flask') ?: 'flask','items'=>[]];
    }
    $grouped[$key]['items'][] = $r;
}
?>

<?php if (!$grouped): ?><div class="bg-white rounded-3xl border border-gray-100 p-8 text-center text-gray-400 font-bold">Записей пока нет.</div><?php endif; ?>

<div class="av-access-list text-left space-y-5">
<?php foreach ($grouped as $groupKey => $groupData): ?>
    <?php $gColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$groupData['color']) ? (string)$groupData['color'] : '#F4E4A6'; $gTextColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($groupData['text_color'] ?? '')) ? (string)$groupData['text_color'] : '#4B5563'; $gIconKey = array_key_exists((string)$groupData['icon'], $icons) ? (string)$groupData['icon'] : 'flask'; $gIcon = $icons[$gIconKey] ?? '⚗'; ?>
    <section class="av-group is-collapsed" data-av-group="<?php echo htmlspecialchars($groupKey); ?>">
        <button type="button" class="av-group-head" style="background-color: <?php echo htmlspecialchars($gColor); ?>; color: <?php echo htmlspecialchars($gTextColor); ?>" onclick="toggleAvGroup('<?php echo htmlspecialchars($groupKey); ?>')"><span class="av-group-title"><span class="av-group-ico"><?php echo htmlspecialchars($gIcon); ?></span><?php echo htmlspecialchars($groupData['title']); ?></span><span class="av-group-toggle">›</span></button>
        <div class="av-group-body">
        <?php foreach ($groupData['items'] as $r): ?>
            <?php $rCreds = $credentialsByResource[(int)$r['id']] ?? []; $rPayment = $paymentsByResource[(int)$r['id']] ?? []; $rPaymentRecipients = $rPayment ? ($paymentRecipientsByPayment[(int)$rPayment['id']] ?? []) : []; $rPaymentNames = ($rPaymentRecipients && function_exists('asr_av_payment_recipient_names')) ? asr_av_payment_recipient_names($pdo, $rPaymentRecipients) : []; $rPaymentNamesText = $rPaymentNames ? implode(', ', $rPaymentNames) : 'получатели не выбраны'; $rPaymentActive = $rPayment && (int)($rPayment['is_enabled'] ?? 0) === 1; $rAutoPayment = $rPayment && (int)($rPayment['auto_payment'] ?? 0) === 1; $rAutoPaymentPeriod = in_array((string)($rPayment['auto_payment_period'] ?? ''), ['monthly','yearly'], true) ? (string)$rPayment['auto_payment_period'] : 'monthly'; $rAutoPaymentText = $rAutoPaymentPeriod === 'yearly' ? 'Ежегодная автооплата' : 'Ежемесячная автооплата'; $rPaymentTooltip = $rAutoPayment ? $rAutoPaymentText : ($rPayment ? ('Оповещения об оплате: ' . $rPaymentNamesText) : 'Настроить напоминание об оплате'); $rPaymentPayload = ['resource_id'=>(int)$r['id'],'title'=>(string)$r['title'],'is_enabled'=>(int)($rPayment['is_enabled'] ?? 1),'payment_date'=>(string)($rPayment['payment_date'] ?? ''),'remind_days_before'=>(string)($rPayment['remind_days_before'] ?? '14,3'),'repeat_type'=>(string)($rPayment['repeat_type'] ?? 'yearly'),'auto_payment'=>$rAutoPayment ? 1 : 0,'auto_payment_period'=>$rAutoPaymentPeriod,'payment_amount'=>(string)($rPayment['payment_amount'] ?? ''),'payment_currency'=>(string)($rPayment['payment_currency'] ?? '₸'),'message'=>(string)($rPayment['message'] ?? (function_exists('asr_av_payment_default_message') ? asr_av_payment_default_message($r) : '')),'recipients'=>$rPaymentRecipients]; ?>
            <article class="av-resource <?php echo $r['status']==='archived' ? 'is-archived' : ''; ?>">
                <div class="av-resource-head"><div class="av-resource-info"><div class="av-resource-title"><?php echo htmlspecialchars($r['title']); ?></div><?php if (trim((string)$r['url']) !== ''): ?><a href="<?php echo htmlspecialchars($r['url']); ?>" target="_blank" rel="noopener noreferrer" class="av-resource-url"><?php echo htmlspecialchars($r['url']); ?></a><?php endif; ?><?php if (trim((string)$r['comment']) !== ''): ?><div class="av-resource-comment"><?php echo nl2br(htmlspecialchars($r['comment'])); ?></div><?php endif; ?></div>
                    <div class="av-resource-actions"><?php if ($category !== 'archive' && $canManagePayment && $rPaymentActive): ?><button type="button" class="av-payment-indicator <?php echo $rAutoPayment ? 'is-auto-payment' : ($rPaymentRecipients ? 'has-recipients' : ''); ?>" onclick='openAvPaymentModal(<?php echo htmlspecialchars(json_encode($rPaymentPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' title="<?php echo htmlspecialchars($rPaymentTooltip); ?>" aria-label="<?php echo $rAutoPayment ? 'Автоматическая оплата' : 'Оповещения об оплате'; ?>"><?php echo $rAutoPayment ? '<img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-auto-payment-gray.svg" alt="" aria-hidden="true">' : '<img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-bell-gray.svg" alt="" aria-hidden="true">'; ?></button><?php endif; ?><div class="av-more-wrap"><button type="button" class="av-more-btn" onclick="toggleAvMoreMenu(this)" aria-label="Действия ресурса"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-more-vertical-gray.svg" alt="" aria-hidden="true"></button><div class="av-more-menu"><?php if ($category !== 'archive' && $canCreate): ?><button type="button" onclick='openAvCredentialModal({resource_id:<?php echo (int)$r['id']; ?>, resource_group_id:<?php echo (int)($r['group_id'] ?? 0); ?>})' class="av-more-item">+ Доступ</button><?php endif; ?><?php if ($category !== 'archive' && $canManagePayment): ?><?php $paymentPayload = ['resource_id'=>(int)$r['id'],'title'=>(string)$r['title'],'is_enabled'=>(int)($rPayment['is_enabled'] ?? 1),'payment_date'=>(string)($rPayment['payment_date'] ?? ''),'remind_days_before'=>(string)($rPayment['remind_days_before'] ?? '14,3'),'repeat_type'=>(string)($rPayment['repeat_type'] ?? 'yearly'),'auto_payment'=>$rAutoPayment ? 1 : 0,'auto_payment_period'=>$rAutoPaymentPeriod,'payment_amount'=>(string)($rPayment['payment_amount'] ?? ''),'payment_currency'=>(string)($rPayment['payment_currency'] ?? '₸'),'message'=>(string)($rPayment['message'] ?? asr_av_payment_default_message($r)),'recipients'=>$rPaymentRecipients]; ?><button type="button" onclick='openAvPaymentModal(<?php echo htmlspecialchars(json_encode($paymentPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="av-more-item" title="<?php echo htmlspecialchars($rPaymentTooltip); ?>">Контроль оплаты</button><?php endif; ?><?php if ($canEdit): ?><button type="button" onclick='openAvResourceModal(<?php echo htmlspecialchars(json_encode(['id'=>(int)$r['id'],'category'=>$r['category'],'group_id'=>(int)($r['group_id'] ?? 0),'title'=>$r['title'],'url'=>$r['url'],'comment'=>$r['comment']], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)' class="av-more-item">Редактировать</button><?php endif; ?><?php if ($r['status'] === 'active' && $canArchive): ?><form method="POST" onsubmit="return confirm('Отправить ресурс и все его доступы в архив?');"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_archive_resource"><input type="hidden" name="resource_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><button class="av-more-item av-more-danger">В архив</button></form><?php endif; ?><?php if ($r['status'] === 'archived' && $canRestore): ?><form method="POST"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_restore_resource"><input type="hidden" name="resource_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="category" value="archive"><button class="av-more-item">Восстановить</button></form><?php endif; ?><?php if ($r['status'] === 'archived' && $isSuperAdmin): ?><form method="POST" onsubmit="return confirm('Удалить ресурс безвозвратно? Это действие нельзя отменить.');"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_delete_resource_permanent"><input type="hidden" name="resource_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="category" value="archive"><button class="av-more-item av-more-danger">Удалить полностью</button></form><?php endif; ?></div></div></div></div>
                <?php if (!$rCreds): ?><div class="av-empty-credentials">У ресурса пока нет доступов.</div><?php endif; ?>
                <div class="av-credentials">
                <?php foreach ($rCreds as $c): ?>
                    <?php $plain = $cryptoReady ? asr_access_vault_decrypt((string)$c['password_encrypted']) : ''; $fullText = asr_av_format_credential_text($r, $c, $plain); $allowedUserIds = $canManageIndividual ? asr_av_credential_allowed_user_ids($pdo, (int)$c['id']) : []; $assignedUserIds = asr_av_credential_assigned_user_ids($pdo, (int)$c['id']); $isIndividual = !empty($allowedUserIds); $isAssigned = !empty($assignedUserIds); $assignedNames = array_values(array_filter(array_map(static fn($uid) => $avUserNamesById[(int)$uid] ?? '', $assignedUserIds))); $allowedNames = array_values(array_filter(array_map(static fn($uid) => $avUserNamesById[(int)$uid] ?? '', $allowedUserIds))); ?>
                    <div class="av-credential-row <?php echo $c['status']==='archived' ? 'is-archived' : ''; ?>" data-credential-id="<?php echo (int)$c['id']; ?>"><button type="button" class="av-drag-handle" draggable="true" title="Перетащить"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-drag-gray.svg" alt="" aria-hidden="true"></button><div class="av-cred-cell av-login-cell"><div class="av-cell-label">логин</div><div class="av-cell-value"><?php echo htmlspecialchars($c['login']); ?></div></div><div class="av-cred-cell av-password-cell"><div class="av-cell-label">пароль</div><div class="av-pass-wrap"><input type="password" readonly value="<?php echo htmlspecialchars($plain); ?>" class="av-password-input"><button type="button" onclick="toggleAvPassword(this)" class="av-eye" title="Показать пароль"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-eye-gray.svg" alt="" aria-hidden="true"></button></div></div><div class="av-cred-cell av-note-cell"><div class="av-cell-label">Примечание</div><div class="av-note-text"><?php echo trim((string)$c['comment']) !== '' ? nl2br(htmlspecialchars($c['comment'])) : '—'; ?></div></div><div class="av-cred-actions"><div class="av-cred-flags"><?php if ($isAssigned): ?><span class="av-cred-flag av-issued-badge" title="Кому выдан доступ: <?php echo htmlspecialchars(implode(', ', $assignedNames) ?: 'сотрудники не найдены'); ?>"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-users-gray.svg" alt="" aria-hidden="true"></span><?php endif; ?><?php if ($isIndividual): ?><span class="av-cred-flag av-individual-badge" title="Индивидуально видят: <?php echo htmlspecialchars(implode(', ', $allowedNames) ?: 'сотрудники не найдены'); ?>"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-lock-gray.svg" alt="" aria-hidden="true"></span><?php endif; ?></div><div class="av-more-wrap"><button type="button" class="av-more-btn" onclick="toggleAvMoreMenu(this)" aria-label="Действия доступа"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-more-vertical-gray.svg" alt="" aria-hidden="true"></button><div class="av-more-menu"><?php if ($canShare): ?><button type="button" onclick='openAvShareModal(<?php echo (int)$c['id']; ?>, <?php echo json_encode($fullText, JSON_UNESCAPED_UNICODE); ?>)' class="av-more-item">Поделиться</button><?php endif; ?><?php if ($canEdit && $c['status']==='active'): ?><button type="button" onclick='openAvCredentialModal(<?php echo htmlspecialchars(json_encode(['id'=>(int)$c['id'],'resource_id'=>(int)$r['id'],'login'=>$c['login'],'comment'=>$c['comment'],'allowed_user_ids'=>$allowedUserIds,'assigned_user_ids'=>$assignedUserIds,'resource_category'=>(string)$r['category']], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)' class="av-more-item">Редактировать</button><button type="button" onclick="startAvCredentialReorder(<?php echo (int)$r['id']; ?>)" class="av-more-item">Поменять порядок</button><?php endif; ?><?php if ($c['status']==='active' && $canArchive): ?><form method="POST"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_archive_credential"><input type="hidden" name="credential_id" value="<?php echo (int)$c['id']; ?>"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><button class="av-more-item av-more-danger">В архив</button></form><?php endif; ?><?php if ($c['status']==='archived' && $canRestore): ?><form method="POST"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_restore_credential"><input type="hidden" name="credential_id" value="<?php echo (int)$c['id']; ?>"><input type="hidden" name="category" value="archive"><button class="av-more-item">Восстановить</button></form><?php endif; ?><?php if ($c['status']==='archived' && $isSuperAdmin): ?><form method="POST" onsubmit="return confirm('Удалить доступ безвозвратно? Это действие нельзя отменить.');"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_delete_credential_permanent"><input type="hidden" name="credential_id" value="<?php echo (int)$c['id']; ?>"><input type="hidden" name="category" value="archive"><button class="av-more-item av-more-danger">Удалить полностью</button></form><?php endif; ?></div></div></div></div>
                <?php endforeach; ?>
                </div>
                <?php if ($canEdit && $rCreds): ?><form method="POST" class="av-order-save hidden" data-av-order-save-resource="<?php echo (int)$r['id']; ?>"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_reorder_credentials"><input type="hidden" name="resource_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><input type="hidden" name="credential_order" value=""><button type="submit" class="av-save-order-btn">Сохранить порядок</button></form><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>

<?php if ($canCreate || $canEdit): ?>
<div id="avResourceModal" class="hidden fixed inset-0 z-[9999] bg-gray-900/60 backdrop-blur-sm items-start justify-center p-3 sm:p-4 overflow-y-auto"><div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl max-h-[calc(100dvh-24px)] overflow-hidden flex flex-col my-3 sm:my-4"><div class="p-5 sm:p-6 border-b border-gray-100 flex justify-between shrink-0"><h3 class="font-black text-[#FFA048] uppercase">Ресурс</h3><button type="button" onclick="closeAvModal('avResourceModal')"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-close-gray.svg" alt="" aria-hidden="true"></button></div><form method="POST" class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-5 sm:p-6 space-y-4"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" id="av_resource_action" value="av_create_resource"><input type="hidden" name="resource_id" id="av_resource_id"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><div><label class="text-[10px] font-black text-gray-400 uppercase">Раздел</label><select name="resource_category" id="av_resource_category" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><?php foreach ($editableCategories as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?></select></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Группа</label><select name="group_id" id="av_resource_group" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><option value="0" data-category="all">Без группы</option><?php foreach ($groupsByCategory as $catKey => $catGroups): ?><?php foreach ($catGroups as $g): ?><option value="<?php echo (int)$g['id']; ?>" data-category="<?php echo htmlspecialchars((string)$catKey); ?>"><?php echo htmlspecialchars($g['title']); ?></option><?php endforeach; ?><?php endforeach; ?></select></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Название</label><input name="title" id="av_resource_title" required class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div><div><label class="text-[10px] font-black text-gray-400 uppercase">URL / email</label><input name="url" id="av_resource_url" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Комментарий</label><textarea name="comment" id="av_resource_comment" rows="4" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-semibold"></textarea></div><div class="sticky bottom-0 -mx-5 sm:-mx-6 -mb-5 sm:-mb-6 mt-4 px-5 sm:px-6 py-4 bg-white border-t border-gray-100 flex justify-end gap-3"><button type="button" onclick="closeAvModal('avResourceModal')" class="px-5 py-3 text-xs font-black text-gray-400 uppercase">Отмена</button><button type="submit" class="px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase">Сохранить</button></div></form></div></div>
<div id="avCredentialModal" class="hidden fixed inset-0 z-[9999] bg-gray-900/60 backdrop-blur-sm items-start justify-center p-3 sm:p-4 overflow-y-auto"><div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl max-h-[calc(100dvh-24px)] overflow-hidden flex flex-col my-3 sm:my-4"><div class="p-5 sm:p-6 border-b border-gray-100 flex justify-between shrink-0"><h3 class="font-black text-[#FFA048] uppercase">Доступ</h3><button type="button" onclick="closeAvModal('avCredentialModal')"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-close-gray.svg" alt="" aria-hidden="true"></button></div><form method="POST" class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-5 sm:p-6 space-y-4"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" id="av_credential_action" value="av_create_credential"><input type="hidden" name="credential_id" id="av_credential_id"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><div><label class="text-[10px] font-black text-gray-400 uppercase">Ресурс доступа</label><select name="resource_id" id="av_credential_resource_id" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><?php foreach ($resources as $resForSelect): ?><option value="<?php echo (int)$resForSelect['id']; ?>"><?php echo htmlspecialchars($resForSelect['title']); ?></option><?php endforeach; ?></select><p class="text-xs text-gray-400 mt-1">Переносит только этот конкретный логин/пароль в другой ресурс текущего раздела.</p></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Логин</label><input name="login" id="av_credential_login" required class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Пароль</label><div class="flex gap-2"><input name="password" id="av_credential_password" type="text" class="flex-1 px-4 py-3 rounded-xl border border-gray-200 font-mono font-bold"><button type="button" onclick="generateAvPassword()" class="px-4 rounded-xl bg-orange-50 text-[#FFA048] text-xs font-black uppercase">ген</button></div><p class="text-xs text-gray-400 mt-1">При редактировании оставьте пустым, если пароль менять не нужно.</p></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Комментарий</label><textarea name="comment" id="av_credential_comment" rows="4" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-semibold"></textarea></div><div class="rounded-2xl border border-gray-100 bg-gray-50/80 p-4 space-y-3"><label class="text-[10px] font-black text-gray-500 uppercase">Кому выдан доступ</label><p class="text-xs text-gray-500 font-semibold">Отметьте сотрудников, которым этот доступ реально выдан на стороне сервиса. Например, администраторы YouTube-канала или менеджеры рекламного кабинета. Это справочный список: он не ограничивает видимость доступа.</p><button type="button" onclick="toggleAvAssignedDropdown()" class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-white text-left text-sm font-black text-gray-600">Выбрать сотрудников</button><div id="avAssignedSelected" class="flex flex-wrap gap-2 text-xs font-bold text-gray-500"><span class="text-gray-400">Сотрудники не выбраны</span></div><div id="avAssignedDropdown" class="hidden rounded-2xl border border-gray-200 bg-white p-3 space-y-3"><input type="text" id="avAssignedSearch" oninput="filterAvAssignedUsers(this.value)" placeholder="Поиск сотрудника..." class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm font-bold"><div class="max-h-56 overflow-y-auto custom-scrollbar space-y-2"><?php foreach ($individualUsers as $u): ?><?php $label = ($u['full_name'] ?: $u['username']) . ' — ' . $u['username']; ?><label class="av-assigned-user flex items-center gap-3 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 text-sm font-bold text-gray-600 cursor-pointer" data-search="<?php echo htmlspecialchars(mb_strtolower($label . ' ' . $u['username'], 'UTF-8')); ?>"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$u['id']; ?>" class="av-assigned-checkbox w-4 h-4" onchange="updateAvAssignedSelected()"><span><?php echo htmlspecialchars($label); ?></span></label><?php endforeach; ?></div></div></div><?php if ($canManageIndividual): ?><div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-4 space-y-3"><label class="text-[10px] font-black text-[#FFA048] uppercase">Индивидуально показывать доступ</label><p class="text-xs text-gray-500 font-semibold">Если никого не выбрать, доступ видят все пользователи, у кого есть право просмотра модуля. Если выбрать сотрудников, доступ увидят только они и администраторы.</p><button type="button" onclick="toggleAvIndividualDropdown()" class="w-full px-4 py-3 rounded-xl border border-orange-100 bg-white text-left text-sm font-black text-gray-600">Выбрать сотрудников</button><div id="avIndividualSelected" class="flex flex-wrap gap-2 text-xs font-bold text-gray-500"><span class="text-gray-400">Индивидуальные сотрудники не выбраны</span></div><div id="avIndividualDropdown" class="hidden rounded-2xl border border-orange-100 bg-white p-3 space-y-3"><input type="text" id="avIndividualSearch" oninput="filterAvIndividualUsers(this.value)" placeholder="Поиск сотрудника..." class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm font-bold"><div class="max-h-56 overflow-y-auto custom-scrollbar space-y-2"><?php foreach ($individualUsers as $u): ?><?php $label = ($u['full_name'] ?: $u['username']) . ' — ' . $u['username']; ?><label class="av-individual-user flex items-center gap-3 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 text-sm font-bold text-gray-600 cursor-pointer" data-search="<?php echo htmlspecialchars(mb_strtolower($label . ' ' . $u['username'], 'UTF-8')); ?>"><input type="checkbox" name="allowed_user_ids[]" value="<?php echo (int)$u['id']; ?>" class="av-individual-checkbox w-4 h-4" onchange="updateAvIndividualSelected()"><span><?php echo htmlspecialchars($label); ?></span></label><?php endforeach; ?></div></div></div><?php endif; ?><div class="sticky bottom-0 -mx-5 sm:-mx-6 -mb-5 sm:-mb-6 mt-4 px-5 sm:px-6 py-4 bg-white border-t border-gray-100 flex justify-end gap-3"><button type="button" onclick="closeAvModal('avCredentialModal')" class="px-5 py-3 text-xs font-black text-gray-400 uppercase">Отмена</button><button type="submit" class="px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase">Сохранить</button></div></form></div></div>
<?php if ($category !== 'archive'): ?>
<div id="avGroupsModal" class="hidden fixed inset-0 z-[9999] bg-gray-900/60 backdrop-blur-sm items-start justify-center p-3 sm:p-4 overflow-y-auto">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[calc(100dvh-24px)] overflow-hidden flex flex-col my-3 sm:my-4">
    <div class="p-5 sm:p-6 border-b border-gray-100 flex justify-between shrink-0">
      <h3 class="font-black text-[#FFA048] uppercase">Группы</h3>
      <button type="button" onclick="closeAvModal('avGroupsModal')"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-close-gray.svg" alt="" aria-hidden="true"></button>
    </div>
    <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-5 sm:p-6 space-y-5">
      <?php if ($canCreate): ?>
      <form method="POST" class="av-group-edit-row av-group-new-row">
        <?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?>
        <input type="hidden" name="action" value="av_create_group">
        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
        <input type="text" name="group_title" required class="av-group-title-input" placeholder="Название новой группы">
        <input type="number" name="sort_order" min="0" max="9999" value="100" class="av-group-sort-input" title="Сортировка">
        <label class="av-color-field" title="Цвет фона"><span>Фон</span><input type="color" name="group_color" value="#F4E4A6" class="av-group-color-input"></label>
        <label class="av-color-field" title="Цвет текста"><span>Текст</span><input type="color" name="group_text_color" value="#4B5563" class="av-group-color-input"></label>
        <select name="group_icon" class="av-group-icon-select" title="Иконка"><?php foreach ($icons as $ik => $iv): ?><option value="<?php echo htmlspecialchars($ik); ?>"><?php echo htmlspecialchars($iv); ?></option><?php endforeach; ?></select>
        <button class="av-save-btn">Добавить</button>
      </form>
      <?php endif; ?>

      <form method="POST" id="avBulkGroupsForm" class="space-y-3">
        <?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?>
        <input type="hidden" name="action" value="av_bulk_update_groups">
        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
        <?php foreach ($groups as $g): ?>
          <?php $gColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($g['color'] ?? '')) ? (string)$g['color'] : '#F4E4A6'; $gTextColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($g['text_color'] ?? '')) ? (string)$g['text_color'] : '#4B5563'; $gIconKey = array_key_exists((string)($g['icon_key'] ?? ''), $icons) ? (string)$g['icon_key'] : 'flask'; ?>
          <div class="av-group-edit-row av-group-edit-form">
            <input type="hidden" name="groups[<?php echo (int)$g['id']; ?>][id]" value="<?php echo (int)$g['id']; ?>">
            <input type="text" name="groups[<?php echo (int)$g['id']; ?>][title]" value="<?php echo htmlspecialchars($g['title']); ?>" required class="av-group-title-input">
            <input type="number" name="groups[<?php echo (int)$g['id']; ?>][sort_order]" min="0" max="9999" value="<?php echo (int)($g['sort_order'] ?? 100); ?>" class="av-group-sort-input" title="Сортировка">
            <label class="av-color-field" title="Цвет фона"><span>Фон</span><input type="color" name="groups[<?php echo (int)$g['id']; ?>][color]" value="<?php echo htmlspecialchars($gColor); ?>" class="av-group-color-input"></label>
            <label class="av-color-field" title="Цвет текста"><span>Текст</span><input type="color" name="groups[<?php echo (int)$g['id']; ?>][text_color]" value="<?php echo htmlspecialchars($gTextColor); ?>" class="av-group-color-input"></label>
            <select name="groups[<?php echo (int)$g['id']; ?>][icon_key]" class="av-group-icon-select" title="Иконка"><?php foreach ($icons as $ik => $iv): ?><option value="<?php echo htmlspecialchars($ik); ?>" <?php echo $gIconKey === $ik ? 'selected' : ''; ?>><?php echo htmlspecialchars($iv); ?></option><?php endforeach; ?></select>
            <?php if ($canEdit): ?><button type="submit" form="avDeleteGroupForm<?php echo (int)$g['id']; ?>" class="av-delete-btn" title="Удалить группу" onclick="return confirm('Удалить группу? Если внутри есть ресурсы, система не даст это сделать.');"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-delete-gray.svg" alt="" aria-hidden="true"></button><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$groups): ?><div class="text-sm font-bold text-gray-400">Групп пока нет.</div><?php endif; ?>
      </form>

      <?php foreach ($groups as $g): ?><?php if ($canEdit): ?>
      <form method="POST" id="avDeleteGroupForm<?php echo (int)$g['id']; ?>" class="hidden">
        <?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?>
        <input type="hidden" name="action" value="av_delete_group">
        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
        <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
      </form>
      <?php endif; ?><?php endforeach; ?>
    </div>
    <?php if ($groups): ?>
    <div class="px-5 sm:px-6 py-4 bg-white border-t border-gray-100 flex justify-end shrink-0">
      <button type="submit" form="avBulkGroupsForm" class="px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase">Сохранить все</button>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($canManagePayment): ?>
<div id="avPaymentModal" class="hidden fixed inset-0 z-[9999] bg-gray-900/60 backdrop-blur-sm items-start justify-center p-3 sm:p-4 overflow-y-auto">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[calc(100dvh-24px)] overflow-hidden flex flex-col my-3 sm:my-4">
    <div class="p-5 sm:p-6 border-b border-gray-100 flex justify-between shrink-0">
      <div><h3 class="font-black text-[#FFA048] uppercase">Оплата ресурса</h3><p id="av_payment_resource_title" class="text-xs font-bold text-gray-400 mt-1"></p></div>
      <button type="button" onclick="closeAvModal('avPaymentModal')"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-close-gray.svg" alt="" aria-hidden="true"></button>
    </div>
    <form method="POST" class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-5 sm:p-6 space-y-4">
      <?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?>
      <input type="hidden" name="action" value="av_save_payment">
      <input type="hidden" name="resource_id" id="av_payment_resource_id">
      <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label class="inline-flex items-center gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 px-4 py-3 text-sm font-bold text-gray-600">
          <input type="checkbox" name="is_enabled" value="1" id="av_payment_enabled" class="w-4 h-4 rounded border-gray-300 text-[#FFA048]">
          <span>Включить напоминание об оплате</span>
        </label>
        <label class="inline-flex items-center gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 px-4 py-3 text-sm font-bold text-gray-600">
          <input type="checkbox" name="auto_payment" value="1" id="av_payment_auto" class="w-4 h-4 rounded border-gray-300 text-[#FFA048]">
          <span>Автоматическая оплата</span>
        </label>
      </div>
      <div id="av_auto_payment_options" class="av-auto-payment-options hidden rounded-2xl border border-gray-100 bg-gray-50/70 px-4 py-3">
        <div class="text-[10px] font-black text-gray-400 uppercase mb-2">Период автооплаты</div>
        <div class="flex flex-col sm:flex-row gap-3 text-sm font-bold text-gray-600">
          <label class="inline-flex items-center gap-2"><input type="radio" name="auto_payment_period" value="yearly" class="av-auto-payment-period w-4 h-4 text-[#FFA048]"> Ежегодно</label>
          <label class="inline-flex items-center gap-2"><input type="radio" name="auto_payment_period" value="monthly" class="av-auto-payment-period w-4 h-4 text-[#FFA048]"> Ежемесячно</label>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2"><label class="text-[10px] font-black text-gray-400 uppercase">Размер оплаты</label><input type="number" name="payment_amount" id="av_payment_amount" min="0" step="0.01" inputmode="decimal" placeholder="0" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div>
        <div><label class="text-[10px] font-black text-gray-400 uppercase">Валюта</label><select name="payment_currency" id="av_payment_currency" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><option value="₸">₸</option><option value="₽">₽</option><option value="$">$</option></select></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div><label class="text-[10px] font-black text-gray-400 uppercase">Дата оплаты</label><input type="date" name="payment_date" id="av_payment_date" required class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div>
        <div><label class="text-[10px] font-black text-gray-400 uppercase">Напомнить за дни</label><input type="text" name="remind_days_before" id="av_payment_days" placeholder="14,3,1" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></div>
        <div><label class="text-[10px] font-black text-gray-400 uppercase">Повтор</label><select name="repeat_type" id="av_payment_repeat" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><option value="none">Разово</option><option value="monthly">Ежемесячно</option><option value="yearly">Ежегодно</option></select></div>
      </div>
      <div><label class="text-[10px] font-black text-gray-400 uppercase">Сообщение</label><textarea name="message" id="av_payment_message" rows="4" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-semibold"></textarea><p class="text-xs text-gray-400 mt-1">Уведомление уйдёт выбранным сотрудникам в Telegram и на email, если у сотрудника указан email-логин.</p><p class="text-[11px] leading-relaxed text-gray-400 mt-2 font-semibold">Переменные: <code>{{name_user}}</code> — имя сотрудника, <code>{{pay}}</code> — размер оплаты и валюта, <code>{{date_pay}}</code> — дата оплаты, <code>{{period}}</code> — повтор оплаты.</p></div>
      <div class="rounded-2xl border border-gray-100 bg-gray-50/70 p-4 space-y-3">
        <div class="text-[10px] font-black text-gray-400 uppercase">Получатели</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-y-auto custom-scrollbar pr-1">
          <?php foreach ($paymentUsers as $u): ?>
            <label class="flex items-start gap-2 bg-white rounded-xl border border-gray-100 px-3 py-2 text-xs font-bold text-gray-600"><input type="checkbox" name="payment_recipients[]" value="<?php echo (int)$u['id']; ?>" class="av-payment-recipient mt-0.5"><span><?php echo htmlspecialchars(($u['full_name'] ?: $u['username']) . ' — ' . $u['username']); ?><?php if (!empty($u['telegram_chat_id'])): ?><span class="text-green-600"> · TG</span><?php endif; ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="sticky bottom-0 -mx-5 sm:-mx-6 -mb-5 sm:-mb-6 mt-4 px-5 sm:px-6 py-4 bg-white border-t border-gray-100 flex flex-col sm:flex-row justify-between gap-3">
        <button type="submit" formaction="admin.php?tab=access_vault&category=<?php echo urlencode($category); ?>" class="px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase">Сохранить напоминание</button>
        <button type="button" onclick="closeAvModal('avPaymentModal')" class="px-5 py-3 text-xs font-black text-gray-400 uppercase">Отмена</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canShare): ?>
<div id="avShareModal" class="hidden fixed inset-0 z-[9999] bg-gray-900/60 backdrop-blur-sm items-start justify-center p-3 sm:p-4 overflow-y-auto"><div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl max-h-[calc(100dvh-24px)] overflow-hidden flex flex-col my-3 sm:my-4"><div class="p-5 sm:p-6 border-b border-gray-100 flex justify-between shrink-0"><h3 class="font-black text-[#FFA048] uppercase">Поделиться доступом</h3><button type="button" onclick="closeAvModal('avShareModal')"><img class="av-svg-icon av-svg-icon--sm" src="/assets/admin/icons/av2-close-gray.svg" alt="" aria-hidden="true"></button></div><div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar p-5 sm:p-6 space-y-5"><div class="rounded-2xl bg-orange-50 border border-orange-100 text-orange-700 p-4 text-sm font-bold">Будьте аккуратны. Отправлять доступы можно только сотрудникам, которые есть в системе.</div><form method="POST" class="space-y-3"><?php echo function_exists('asr_csrf_input') ? asr_csrf_input() : ''; ?><input type="hidden" name="action" value="av_send_email"><input type="hidden" name="credential_id" id="av_email_credential_id"><input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>"><div><label class="text-[10px] font-black text-gray-400 uppercase">Получатель</label><select name="to_user_id" required class="w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><?php foreach ($shareUsers as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars(($u['full_name'] ?: $u['username']) . ' — ' . $u['username']); ?></option><?php endforeach; ?></select></div><div><label class="text-[10px] font-black text-gray-400 uppercase">Комментарий</label><textarea name="note" rows="2" class="w-full px-4 py-3 rounded-xl border border-gray-200 font-semibold"></textarea></div><button type="submit" class="w-full px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase">На почту</button></form><button type="button" onclick="sendAvShareMessenger()" class="w-full px-6 py-3 rounded-xl bg-orange-50 text-[#FFA048] text-xs font-black uppercase">В мессенджер</button></div></div></div>
<?php endif; ?>
<link rel="stylesheet" href="/assets/admin/icons/icons.css?v=20260530-av2-gray">
<link rel="stylesheet" href="/assets/admin/modules/access_vault/access_vault.css?v=20260530-step2-icons-av">
<script>window.asrAvCategory = <?php echo json_encode($category); ?>; window.asrCsrfToken = <?php echo json_encode(asr_csrf_token()); ?>;</script>
<script src="/assets/admin/modules/access_vault/access_vault.js?v=20260530-v6-payment-amount"></script>