<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/../repository.php';
require_once __DIR__ . '/../service.php';
if (is_file(__DIR__ . '/../../access_vault/repository.php') && is_file(__DIR__ . '/../../access_vault/service.php')) {
    require_once __DIR__ . '/../../access_vault/repository.php';
    require_once __DIR__ . '/../../access_vault/crypto.php';
    require_once __DIR__ . '/../../access_vault/service.php';
}

asr_users_repository_ensure_schema($pdo);

function asr_users_safe_telegram_link(PDO $pdo, array $user): string {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return '';

    if (function_exists('asr_av_user_telegram_link')) {
        try {
            $link = trim((string)asr_av_user_telegram_link($pdo, $userId));
            if ($link !== '') return $link;
        } catch (Throwable $e) {
            // Ниже есть резервный способ, чтобы не оставлять окно без ссылки.
        }
    }

    $botUsername = '';
    try {
        if (function_exists('asr_get_setting')) {
            $botUsername = trim((string)asr_get_setting('telegram_bot_username', ''));
        }
        if ($botUsername === '' && function_exists('asr_get_all_settings')) {
            $settings = asr_get_all_settings();
            $botUsername = trim((string)($settings['telegram_bot_username'] ?? ''));
        }
    } catch (Throwable $e) {
        $botUsername = '';
    }
    $botUsername = ltrim($botUsername, '@');
    if ($botUsername === '') return '';

    try {
        $hasTokenColumn = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', 'telegram_bind_token')
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_bind_token'));
        if (!$hasTokenColumn) {
            try { $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL"); } catch (Throwable $e) {}
        }

        $stmt = $pdo->prepare('SELECT telegram_bind_token FROM oca_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $token = trim((string)$stmt->fetchColumn());
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $upd = $pdo->prepare('UPDATE oca_users SET telegram_bind_token = ? WHERE id = ?');
            $upd->execute([$token, $userId]);
        }
        return 'https://t.me/' . $botUsername . '?start=u' . $userId . '_' . $token;
    } catch (Throwable $e) {
        return '';
    }
}

function asr_users_telegram_connected(array $user): bool {
    return trim((string)($user['telegram_chat_id'] ?? '')) !== '';
}

function asr_users_safe_broadcast_test_telegram_link(PDO $pdo, array $user): string {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return '';

    $botUsername = '';
    try {
        if (function_exists('asr_get_setting')) {
            $botUsername = trim((string)asr_get_setting('telegram_broadcast_test_bot_username', ''));
        }
        if ($botUsername === '' && function_exists('asr_get_all_settings')) {
            $settings = asr_get_all_settings();
            $botUsername = trim((string)($settings['telegram_broadcast_test_bot_username'] ?? ''));
        }
    } catch (Throwable $e) {
        $botUsername = '';
    }
    $botUsername = ltrim($botUsername, '@');
    if ($botUsername === '') return '';

    try {
        $hasTokenColumn = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', 'telegram_bind_token')
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_bind_token'));
        if (!$hasTokenColumn) {
            try { $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL"); } catch (Throwable $e) {}
        }

        $stmt = $pdo->prepare('SELECT telegram_bind_token FROM oca_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $token = trim((string)$stmt->fetchColumn());
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $upd = $pdo->prepare('UPDATE oca_users SET telegram_bind_token = ? WHERE id = ?');
            $upd->execute([$token, $userId]);
        }
        return 'https://t.me/' . $botUsername . '?start=btest_u' . $userId . '_' . $token;
    } catch (Throwable $e) {
        return '';
    }
}

function asr_users_broadcast_test_telegram_connected(array $user): bool {
    return trim((string)($user['telegram_broadcast_test_chat_id'] ?? '')) !== '';
}

function asr_users_access_permission_payload(PDO $pdo, array $u): array {
    $role = (string)($u['role'] ?? 'operator');
    $known = function_exists('asr_all_known_permission_keys') ? asr_all_known_permission_keys() : [];
    $stored = function_exists('asr_user_permissions_for_user') ? asr_user_permissions_for_user($pdo, (int)($u['id'] ?? 0)) : [];
    $out = [];
    foreach ($known as $key) {
        if (array_key_exists($key, $stored)) {
            $out[$key] = (bool)$stored[$key];
        } else {
            $out[$key] = in_array($role, ['admin', 'superadmin'], true);
        }
    }
    return $out;
}

function asr_users_issued_access_payload(PDO $pdo, int $userId): array {
    if ($userId <= 0 || !function_exists('asr_av_assigned_credentials_for_user')) return [];
    try {
        return asr_av_assigned_credentials_for_user($pdo, $userId);
    } catch (Throwable $e) {
        return [];
    }
}

function asr_user_role_badge_class(string $role): string {
    return match ($role) {
        'admin', 'superadmin' => 'bg-orange-50 text-[#c96f2b] border-orange-100',
        'manager' => 'bg-slate-50 text-slate-600 border-slate-100',
        default => 'bg-gray-50 text-gray-500 border-gray-100',
    };
}

function asr_users_build_payload(PDO $pdo, array $u): array {
    $role = (string)($u['role'] ?? 'operator');
    $isProtected = function_exists('asr_is_protected_user') ? asr_is_protected_user($u) : ((int)($u['id'] ?? 0) === 1 || $role === 'superadmin');
    $tgLink = asr_users_safe_telegram_link($pdo, $u);
    return [
        'id' => (int)($u['id'] ?? 0),
        'full_name' => (string)($u['full_name'] ?? ''),
        'username' => (string)($u['username'] ?? ''),
        'role' => (($isProtected && $role === 'superadmin') ? 'admin' : (function_exists('asr_normalize_admin_role') ? asr_normalize_admin_role($role) : $role)),
        'protected' => $isProtected,
        'remember_365_days' => (int)($u['remember_365_days'] ?? 0) === 1,
        'access_permissions' => asr_users_access_permission_payload($pdo, $u),
        'issued_access' => asr_users_issued_access_payload($pdo, (int)($u['id'] ?? 0)),
        'telegram_connected' => asr_users_telegram_connected($u),
        'telegram_link' => $tgLink,
        'telegram_username' => (string)($u['telegram_username'] ?? ''),
        'broadcast_test_telegram_connected' => asr_users_broadcast_test_telegram_connected($u),
        'broadcast_test_telegram_link' => asr_users_safe_broadcast_test_telegram_link($pdo, $u),
        'broadcast_test_telegram_username' => (string)($u['telegram_broadcast_test_username'] ?? ''),
        'broadcast_test_receive_broadcasts' => (int)($u['telegram_broadcast_test_receive_broadcasts'] ?? 1) === 1,
        'broadcast_test_notify_dialogs' => (int)($u['telegram_broadcast_test_notify_dialogs'] ?? 0) === 1,
        'connect_to_dialogs' => (int)($u['connect_to_dialogs'] ?? 0) === 1,
    ];
}

$isArchivePage = (string)($_GET['category'] ?? '') === 'archive';
$hasUserActiveColumn = asr_users_repository_has_active_column($pdo);
$users = asr_users_repository_all($pdo);
$archivedUsers = asr_users_repository_archived_all($pdo);
?>

<?php if (!empty($_GET['users_error'])): ?>
    <div class="mb-4 bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-semibold text-left"><?php echo htmlspecialchars((string)$_GET['users_error']); ?></div>
<?php endif; ?>
<?php if (!empty($_GET['users_msg'])): ?>
    <div class="mb-4 bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-semibold text-left"><?php echo htmlspecialchars((string)$_GET['users_msg']); ?></div>
<?php endif; ?>


<style>
.users-svg-icon{
    width:20px;
    height:20px;
    display:inline-block;
    vertical-align:middle;
    object-fit:contain;
    flex:0 0 auto;
    opacity:.96;
}
.users-svg-icon--sm{width:18px;height:18px;}
.users-svg-icon--md{width:22px;height:22px;}
.users-icon-btn{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:8px!important;
}
.users-action-icon-btn{
    width:42px;
    height:42px;
    min-width:42px;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
}
.users-action-icon-btn .users-svg-icon{width:24px;height:24px;}
.users-modal-close{
    width:38px;
    height:38px;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.users-modal-close .users-svg-icon{width:22px;height:22px;}
@media (max-width:767px){
    .users-svg-icon{width:18px;height:18px;}
    .users-action-icon-btn{width:40px;height:40px;min-width:40px;}
}
</style>

<div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden text-left">
    <div class="px-6 py-5 border-b border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="text-[11px] uppercase tracking-[0.22em] text-gray-400 font-semibold">Admin App</div>
            <h2 class="mt-1 text-2xl md:text-3xl font-semibold text-gray-800"><?php echo $isArchivePage ? 'Архив сотрудников' : 'Наша команда'; ?></h2>
            <p class="mt-1 text-sm text-gray-500 font-medium"><?php echo $isArchivePage ? 'Архивированные сотрудники. Здесь можно восстановить или удалить окончательно.' : 'Сотрудники, роли, доступы и подключение Telegram-оповещений.'; ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <?php if ($isArchivePage): ?>
                <a href="admin.php?tab=users" class="inline-flex items-center justify-center px-5 py-3 rounded-2xl border border-gray-200 text-gray-600 hover:bg-gray-50 text-xs font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-back-gray.svg" alt="" aria-hidden="true">К сотрудникам</a>
            <?php else: ?>
                <button type="button" onclick="openAddUserModal()" class="inline-flex items-center justify-center px-5 py-3 rounded-2xl bg-[#FFA048] text-white hover:bg-[#f28d35] text-xs font-semibold uppercase tracking-wider shadow-sm"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-plus-gray.svg" alt="" aria-hidden="true">Добавить сотрудника</button>
                <a href="admin.php?tab=users&category=archive" class="inline-flex items-center justify-center px-5 py-3 rounded-2xl bg-gray-100 text-gray-600 hover:bg-gray-200 text-xs font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-archive-gray.svg" alt="" aria-hidden="true">Архив</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isArchivePage): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Сотрудник</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Логин</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Архивирован</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($archivedUsers)): ?>
                        <tr><td colspan="4" class="p-8 text-center text-sm text-gray-400 font-medium">Архив пуст.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($archivedUsers as $au): ?>
                        <tr class="text-sm text-gray-600 hover:bg-gray-50/60">
                            <td class="p-5 font-semibold text-gray-800"><?php echo htmlspecialchars((string)$au['full_name']); ?></td>
                            <td class="p-5"><?php echo htmlspecialchars((string)$au['username']); ?></td>
                            <td class="p-5"><?php echo htmlspecialchars((string)($au['archived_at'] ?? '')); ?></td>
                            <td class="p-5 text-right">
                                <div class="inline-flex gap-2">
                                    <form method="POST" class="inline m-0"><input type="hidden" name="action" value="restore_user"><input type="hidden" name="user_id" value="<?php echo (int)$au['id']; ?>"><button class="users-icon-btn px-4 py-2 rounded-xl bg-green-50 text-green-700 text-[11px] font-semibold uppercase"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-unlock-gray.svg" alt="" aria-hidden="true">Восстановить</button></form>
                                    <form method="POST" class="inline m-0" onsubmit="return confirm('Удалить сотрудника безвозвратно? Это действие нельзя отменить.');"><input type="hidden" name="action" value="purge_user"><input type="hidden" name="user_id" value="<?php echo (int)$au['id']; ?>"><button class="users-icon-btn px-4 py-2 rounded-xl bg-red-50 text-red-600 text-[11px] font-semibold uppercase"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-delete-gray.svg" alt="" aria-hidden="true">Удалить полностью</button></form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto users-desktop-table">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Имя</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Логин</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider">Роль / статус</th>
                        <th class="p-5 text-xs font-semibold text-gray-400 uppercase tracking-wider text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach($users as $u): ?>
                        <?php
                            $isActive = !$hasUserActiveColumn || (int)($u['is_active'] ?? 1) === 1;
                            $isProtected = function_exists('asr_is_protected_user') ? asr_is_protected_user($u) : ((int)($u['id'] ?? 0) === 1 || (string)($u['role'] ?? '') === 'superadmin');
                            $role = (string)($u['role'] ?? 'operator');
                            $roleLabel = function_exists('asr_role_label') ? asr_role_label($role) : $role;
                            $editUserPayload = asr_users_build_payload($pdo, $u);
                            $savedAccessPassword = trim((string)($u['password_plain'] ?? ''));
                            $passwordSaved = $savedAccessPassword !== '';
                            $passwordText = $passwordSaved ? $savedAccessPassword : 'пароль не сохранён. Задайте новый пароль в карточке пользователя и снова нажмите «Поделиться доступами».';
                            $shareText = function_exists('asr_render_template') ? asr_render_template(asr_get_setting('access_share_message'), [
                                'full_name' => (string)($u['full_name'] ?? ''),
                                'username' => (string)($u['username'] ?? ''),
                                'login' => (string)($u['username'] ?? ''),
                                'role' => $roleLabel,
                                'admin_url' => asr_current_base_url() . 'admin.php',
                                'password' => $passwordText,
                            ]) : '';
                            $sharePayload = ['full_name'=>(string)($u['full_name'] ?? ''), 'username'=>(string)($u['username'] ?? ''), 'text'=>$shareText, 'passwordSaved'=>$passwordSaved, 'editData'=>$editUserPayload];
                        ?>
                        <tr class="hover:bg-gray-50/60 text-sm <?php echo $isActive ? '' : 'opacity-60 bg-gray-50'; ?>">
                            <td class="p-5 font-semibold text-gray-900">
                                <div><?php echo htmlspecialchars((string)$u['full_name']); ?></div>
                                <?php if ($isProtected): ?><div class="mt-1 text-[10px] uppercase tracking-widest text-[#c96f2b] font-semibold">Суперадмин</div><?php endif; ?>
                            </td>
                            <td class="p-5 text-gray-500 align-top">
                                <div class="font-medium"><?php echo htmlspecialchars((string)$u['username']); ?></div>
                                <?php $tgConnected = asr_users_telegram_connected($u); ?>
                                <div class="mt-2"><span class="px-2 py-1 rounded-lg text-[10px] font-semibold uppercase <?php echo $tgConnected ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-400'; ?>"><?php echo $tgConnected ? 'Telegram подключён' : 'Telegram не подключён'; ?></span></div>
                                <?php if ((int)($u['connect_to_dialogs'] ?? 0) === 1): ?><div class="mt-2"><span class="px-2 py-1 rounded-lg text-[10px] font-semibold uppercase bg-orange-50 text-[#c96f2b]">Диалоги подключены</span></div><?php endif; ?>
                                <?php if (asr_users_broadcast_test_telegram_connected($u)): ?><div class="mt-2"><span class="px-2 py-1 rounded-lg text-[10px] font-semibold uppercase bg-orange-50 text-[#c96f2b]">Тест-бот рассылок</span></div><?php if ((int)($u['telegram_broadcast_test_notify_dialogs'] ?? 0) === 1): ?><div class="mt-2"><span class="px-2 py-1 rounded-lg text-[10px] font-semibold uppercase bg-green-50 text-green-700">Уведомления о диалогах</span></div><?php endif; ?><?php endif; ?>
                            </td>
                            <td class="p-5 align-top">
                                <div class="flex flex-col items-start gap-2">
                                    <span class="px-3 py-1 rounded-full text-[10px] font-semibold uppercase tracking-widest border <?php echo asr_user_role_badge_class($role); ?>"><?php echo htmlspecialchars($roleLabel); ?></span>
                                    <span class="px-3 py-1 rounded-full text-[10px] font-semibold uppercase tracking-widest <?php echo $isActive ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo $isActive ? 'Активен' : 'Заблокирован'; ?></span>
                                </div>
                            </td>
                            <td class="p-5 text-right">
                                <div class="flex justify-end items-center gap-2">
                                    <button type="button" onclick='openShareAccessModal(<?php echo htmlspecialchars(json_encode($sharePayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-icon-btn px-3 py-2 rounded-xl bg-orange-50 text-[#c96f2b] hover:bg-orange-100 text-[10px] font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-share-gray.svg" alt="" aria-hidden="true">Поделиться</button>
                                    <?php if (!$isProtected): ?>
                                        <button type="button" onclick='openUserIssuedAccessModal(<?php echo htmlspecialchars(json_encode($editUserPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-action-icon-btn rounded-xl bg-gray-50 text-gray-400 hover:text-[#FFA048] hover:bg-orange-50" title="Выданные доступы"><img class="users-svg-icon" src="/assets/admin/icons/us2-users-gray.svg" alt="" aria-hidden="true"></button>
                                    <?php endif; ?>
                                    <button onclick='openEditUserModal(<?php echo htmlspecialchars(json_encode($editUserPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-action-icon-btn rounded-xl bg-gray-50 text-gray-400 hover:text-[#FFA048] hover:bg-orange-50" title="Редактировать"><img class="users-svg-icon" src="/assets/admin/icons/us2-edit-gray.svg" alt="" aria-hidden="true"></button>
                                    <?php if(!$isProtected && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                        <form method="POST" onsubmit="return confirm('<?php echo $isActive ? 'Временно заблокировать пользователя?' : 'Разблокировать пользователя?'; ?>');" class="inline m-0"><input type="hidden" name="action" value="toggle_user_active"><input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="new_state" value="<?php echo $isActive ? 0 : 1; ?>"><button type="submit" class="users-action-icon-btn rounded-xl <?php echo $isActive ? 'bg-red-50 text-red-500 hover:bg-red-100' : 'bg-green-50 text-green-600 hover:bg-green-100'; ?>" title="<?php echo $isActive ? 'Заблокировать' : 'Разблокировать'; ?>"><img class="users-svg-icon" src="<?php echo $isActive ? '/assets/admin/icons/us2-block-gray.svg' : '/assets/admin/icons/us2-unlock-gray.svg'; ?>" alt="" aria-hidden="true"></button></form>
                                        <form method="POST" onsubmit="return confirm('Уволить пользователя и отправить в архив? Он исчезнет из списков выданных доступов.');" class="inline m-0"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>"><button type="submit" class="users-action-icon-btn rounded-xl bg-gray-50 text-gray-400 hover:text-red-500 hover:bg-red-50" title="В архив"><img class="users-svg-icon" src="/assets/admin/icons/us2-delete-gray.svg" alt="" aria-hidden="true"></button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="users-mobile-list p-4 space-y-3">
            <?php foreach($users as $u): ?>
                <?php
                    $isActive = !$hasUserActiveColumn || (int)($u['is_active'] ?? 1) === 1;
                    $isProtected = function_exists('asr_is_protected_user') ? asr_is_protected_user($u) : ((int)($u['id'] ?? 0) === 1 || (string)($u['role'] ?? '') === 'superadmin');
                    $role = (string)($u['role'] ?? 'operator');
                    $roleLabel = function_exists('asr_role_label') ? asr_role_label($role) : $role;
                    $editUserPayload = asr_users_build_payload($pdo, $u);
                    $savedAccessPassword = trim((string)($u['password_plain'] ?? ''));
                    $passwordSaved = $savedAccessPassword !== '';
                    $passwordText = $passwordSaved ? $savedAccessPassword : 'пароль не сохранён. Задайте новый пароль в карточке пользователя и снова нажмите «Поделиться доступами».';
                    $shareText = function_exists('asr_render_template') ? asr_render_template(asr_get_setting('access_share_message'), ['full_name'=>(string)($u['full_name'] ?? ''),'username'=>(string)($u['username'] ?? ''),'login'=>(string)($u['username'] ?? ''),'role'=>$roleLabel,'admin_url'=>asr_current_base_url().'admin.php','password'=>$passwordText]) : '';
                    $sharePayload = ['full_name'=>(string)($u['full_name'] ?? ''), 'username'=>(string)($u['username'] ?? ''), 'text'=>$shareText, 'passwordSaved'=>$passwordSaved, 'editData'=>$editUserPayload];
                ?>
                <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm space-y-3 <?php echo $isActive ? '' : 'opacity-60'; ?>">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars((string)$u['full_name']); ?></div><div class="mt-1 text-xs font-medium text-gray-500 break-all"><?php echo htmlspecialchars((string)$u['username']); ?></div><?php if ($isProtected): ?><div class="mt-1 text-[10px] uppercase tracking-widest text-[#c96f2b] font-semibold">Суперадмин</div><?php endif; ?></div><span class="px-2.5 py-1 rounded-full text-[9px] font-semibold uppercase tracking-widest border <?php echo asr_user_role_badge_class($role); ?>"><?php echo htmlspecialchars($roleLabel); ?></span></div>
                    <div class="grid grid-cols-1 gap-2"><button type="button" onclick='openShareAccessModal(<?php echo htmlspecialchars(json_encode($sharePayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-icon-btn w-full px-3 py-2 rounded-xl bg-orange-50 text-[#c96f2b] text-[10px] font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-share-gray.svg" alt="" aria-hidden="true">Поделиться</button><?php if (!$isProtected): ?><button type="button" onclick='openUserIssuedAccessModal(<?php echo htmlspecialchars(json_encode($editUserPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-icon-btn w-full px-3 py-2 rounded-xl bg-gray-50 text-gray-500 text-[10px] font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-users-gray.svg" alt="" aria-hidden="true">Выданные доступы</button><?php endif; ?><button onclick='openEditUserModal(<?php echo htmlspecialchars(json_encode($editUserPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="users-icon-btn w-full px-3 py-2 rounded-xl bg-gray-50 text-gray-500 text-[10px] font-semibold uppercase tracking-wider"><img class="users-svg-icon users-svg-icon--sm" src="/assets/admin/icons/us2-edit-gray.svg" alt="" aria-hidden="true">Редактировать</button></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="addUserModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-start justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden my-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50"><h3 class="text-sm font-semibold text-[#c96f2b] uppercase tracking-widest">Добавить сотрудника</h3><button type="button" onclick="closeAddUserModal()" class="users-modal-close text-gray-400 hover:text-red-500"><img class="users-svg-icon" src="/assets/admin/icons/us2-close-gray.svg" alt="" aria-hidden="true"></button></div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add_user">
            <div><label class="text-[10px] font-semibold text-gray-400 uppercase block mb-1">ФИО</label><input type="text" name="new_full_name" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-medium"></div>
            <div><label class="text-[10px] font-semibold text-gray-400 uppercase block mb-1">Логин / email</label><input type="text" name="new_username" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-medium"></div>
            <div><label class="text-[10px] font-semibold text-gray-400 uppercase block mb-1">Пароль</label><input type="password" name="new_password" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm"></div>
            <div><label class="text-[10px] font-semibold text-gray-400 uppercase block mb-1">Роль</label><select name="new_role" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm bg-white font-medium text-gray-600"><option value="operator">Оператор</option><option value="manager">Менеджер</option><option value="admin">Администратор</option></select></div>
            <label class="flex items-start gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 p-4 text-sm font-semibold text-gray-600"><input type="checkbox" name="new_connect_to_dialogs" value="1" class="mt-1 rounded border-gray-300 text-[#FFA048]"><span><span class="block text-gray-800">Подключать к диалогам</span><span class="block mt-1 text-xs text-gray-500">Сотрудник появится в списке назначения диалогов.</span></span></label>
            <div class="flex justify-end gap-3 pt-2"><button type="button" onclick="closeAddUserModal()" class="px-5 py-3 rounded-xl text-xs font-semibold uppercase text-gray-400">Отмена</button><button type="submit" class="px-6 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-semibold uppercase">Создать</button></div>
        </form>
    </div>
</div>


<div id="userIssuedAccessModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-start justify-center p-3 sm:p-4 overflow-y-auto">
  <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden my-3 sm:my-4">
    <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50"><div><h3 class="font-semibold text-gray-700 uppercase tracking-wider text-sm">Выданные доступы</h3><p id="userIssuedAccessSubtitle" class="mt-1 text-xs text-gray-400 font-medium"></p></div><button type="button" onclick="closeUserIssuedAccessModal()" class="users-modal-close text-gray-400 hover:text-red-500"><img class="users-svg-icon" src="/assets/admin/icons/us2-close-gray.svg" alt="" aria-hidden="true"></button></div>
    <div id="userIssuedAccessList" class="p-6 space-y-3 max-h-[70vh] overflow-y-auto custom-scrollbar"></div>
  </div>
</div>

<script>
function openAddUserModal(){ const m=document.getElementById('addUserModal'); if(m) m.classList.remove('hidden'); }
function closeAddUserModal(){ const m=document.getElementById('addUserModal'); if(m) m.classList.add('hidden'); }
function closeUserIssuedAccessModal(){ const m=document.getElementById('userIssuedAccessModal'); if(m) m.classList.add('hidden'); }
function escapeUserAccessHtml(value){ return String(value || '').replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; }); }
function openUserIssuedAccessModal(data){
  data = data || {};
  const modal = document.getElementById('userIssuedAccessModal');
  const subtitle = document.getElementById('userIssuedAccessSubtitle');
  const list = document.getElementById('userIssuedAccessList');
  const rows = Array.isArray(data.issued_access) ? data.issued_access : [];
  if (subtitle) subtitle.textContent = (data.full_name || data.username || 'Сотрудник') + ' · ' + rows.length + ' доступов';
  if (list) {
    if (!rows.length) {
      list.innerHTML = '<div class="rounded-2xl bg-gray-50 border border-gray-100 p-5 text-sm font-medium text-gray-500">Для этого сотрудника пока не отмечены выданные доступы.</div>';
    } else {
      list.innerHTML = rows.map(function(row){
        const url = row.resource_url ? '<a class="text-[#c96f2b] hover:underline break-all" target="_blank" href="'+escapeUserAccessHtml(row.resource_url)+'">'+escapeUserAccessHtml(row.resource_url)+'</a>' : '<span class="text-gray-400">ссылка не указана</span>';
        return '<div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2"><div><div class="text-sm font-semibold text-gray-800">'+escapeUserAccessHtml(row.resource_title)+'</div><div class="mt-1 text-xs font-medium text-gray-400">'+escapeUserAccessHtml(row.group_title || row.category || 'Без группы')+'</div></div><span class="px-3 py-1 rounded-full bg-gray-50 text-gray-500 text-[10px] font-semibold uppercase">'+escapeUserAccessHtml(row.credential_status || 'active')+'</span></div><div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs font-medium text-gray-600"><div><div class="text-[10px] text-gray-400 uppercase font-semibold">Логин</div><div class="break-all">'+escapeUserAccessHtml(row.login || '—')+'</div></div><div><div class="text-[10px] text-gray-400 uppercase font-semibold">Ссылка</div>'+url+'</div></div>'+(row.credential_comment ? '<div class="mt-3 text-xs text-gray-500 font-medium whitespace-pre-wrap">'+escapeUserAccessHtml(row.credential_comment)+'</div>' : '')+'</div>';
      }).join('');
    }
  }
  if (modal) modal.classList.remove('hidden');
}
</script>
