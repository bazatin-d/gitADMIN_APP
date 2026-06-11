<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/repository.php';

/**
 * Бизнес-логика модуля пользователей: валидация, нормализация и безопасные действия.
 */

function asr_users_redirect(string $message = '', string $error = ''): void {
    $url = 'admin.php?tab=users';
    if ($message !== '') $url .= '&users_msg=' . urlencode($message);
    if ($error !== '') $url .= '&users_error=' . urlencode($error);
    header('Location: ' . $url);
    exit;
}

function asr_users_known_actions(): array {
    return ['add_user', 'edit_user', 'toggle_user_active', 'delete_user', 'restore_user', 'purge_user', 'update_my_pwa_dialog_notifications'];
}

function asr_users_validate_name(string $fullName): string {
    $fullName = trim($fullName);
    if ($fullName === '') {
        throw new RuntimeException('Укажите ФИО пользователя.');
    }
    if (mb_strlen($fullName) > 160) {
        throw new RuntimeException('ФИО слишком длинное.');
    }
    return $fullName;
}

function asr_users_validate_username(string $username): string {
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Укажите логин или email пользователя.');
    }
    if (mb_strlen($username) > 190) {
        throw new RuntimeException('Логин слишком длинный.');
    }
    return $username;
}

function asr_users_validate_password(string $password, bool $required): string {
    $password = (string)$password;
    if ($required && $password === '') {
        throw new RuntimeException('Укажите пароль пользователя.');
    }
    if ($password !== '' && mb_strlen($password) < 6) {
        throw new RuntimeException('Пароль должен быть не короче 6 символов.');
    }
    return $password;
}

function asr_users_normalize_role_for_save(string $role): string {
    return function_exists('asr_normalize_admin_role') ? asr_normalize_admin_role($role) : (in_array($role, ['admin', 'manager', 'operator'], true) ? $role : 'operator');
}

function asr_users_is_protected(array $user): bool {
    return function_exists('asr_is_protected_user') ? asr_is_protected_user($user) : ((int)($user['id'] ?? 0) === 1 || (string)($user['role'] ?? '') === 'superadmin');
}

function asr_users_permissions_from_post(array $data, string $role): array {
    if (!in_array($role, ['admin', 'manager'], true)) {
        return [];
    }
    $posted = $data['access_permissions'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    // Администратору по умолчанию включаем все права модуля, если чекбоксы не отправлены.
    if ($role === 'admin' && !$posted && function_exists('asr_all_known_permission_keys')) {
        return asr_all_known_permission_keys();
    }
    return array_values(array_map('strval', $posted));
}

function asr_users_create(PDO $pdo, array $data): void {
    $fullName = asr_users_validate_name((string)($data['new_full_name'] ?? ''));
    $username = asr_users_validate_username((string)($data['new_username'] ?? ''));
    $password = asr_users_validate_password((string)($data['new_password'] ?? ''), true);
    $role = asr_users_normalize_role_for_save((string)($data['new_role'] ?? 'operator'));
    $connectToDialogs = isset($data['new_connect_to_dialogs']) ? 1 : 0;

    if (asr_users_repository_username_exists($pdo, $username)) {
        throw new RuntimeException('Пользователь с таким логином уже есть.');
    }

    $userId = asr_users_repository_create($pdo, $fullName, $username, $password, $role, $connectToDialogs);
    if (function_exists('asr_save_user_permissions')) {
        asr_save_user_permissions($pdo, $userId, asr_users_permissions_from_post($data, $role));
    }
}

function asr_users_update(PDO $pdo, array $data): void {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $editedUser = asr_users_repository_find($pdo, $userId);
    if (!$editedUser) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $fullName = asr_users_validate_name((string)($data['full_name'] ?? ''));
    $username = asr_users_validate_username((string)($data['username'] ?? ''));
    $password = asr_users_validate_password((string)($data['new_password'] ?? ''), false);
    $role = asr_users_normalize_role_for_save((string)($data['role'] ?? 'operator'));
    $isProtectedUser = asr_users_is_protected($editedUser);
    // У суперадмина опасные поля скрыты в интерфейсе. Поэтому отсутствие checkbox в POST
    // не должно молча сбрасывать уже включённое «не выходить из системы».
    if ($isProtectedUser && !array_key_exists('remember_365_days', $data)) {
        $remember365Days = (int)($editedUser['remember_365_days'] ?? 0) === 1 ? 1 : 0;
    } else {
        $remember365Days = isset($data['remember_365_days']) ? 1 : 0;
    }
    $telegramChatId = trim((string)($data['telegram_chat_id'] ?? ''));
    $telegramUsername = trim((string)($data['telegram_username'] ?? ''));
    $connectToDialogs = isset($data['connect_to_dialogs']) ? 1 : 0;
    $broadcastTestReceiveBroadcasts = isset($data['telegram_broadcast_test_receive_broadcasts']) ? 1 : 0;
    $broadcastTestNotifyDialogs = isset($data['telegram_broadcast_test_notify_dialogs']) ? 1 : 0;
    $pwaDialogNotifyEnabled = isset($data['pwa_dialog_notify_enabled']) ? 1 : 0;

    if ($isProtectedUser) {
        $role = (string)($editedUser['role'] ?? 'admin');
    }

    if (asr_users_repository_username_exists($pdo, $username, $userId)) {
        throw new RuntimeException('Пользователь с таким логином уже есть.');
    }

    asr_users_repository_update($pdo, $userId, $fullName, $username, $role, $password, $remember365Days, $telegramChatId, $telegramUsername, $connectToDialogs, $broadcastTestReceiveBroadcasts, $broadcastTestNotifyDialogs, $pwaDialogNotifyEnabled);

    // Если пользователь включил/выключил «не выходить из системы» для самого себя,
    // применяем это сразу, без обязательного перелогина.
    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['user_id'] ?? 0) === $userId) {
        $_SESSION['remember_365_days'] = $remember365Days;
        if ($remember365Days === 1) {
            if (function_exists('asr_persist_current_session_for_days')) {
                asr_persist_current_session_for_days(365);
            }
            if (function_exists('asr_issue_remember_token')) {
                asr_issue_remember_token($pdo, $userId, 365);
            }
        } elseif (function_exists('asr_clear_remember_tokens')) {
            asr_clear_remember_tokens($pdo, $userId);
        }
    }

    if (function_exists('asr_save_user_permissions')) {
        asr_save_user_permissions($pdo, $userId, asr_users_permissions_from_post($data, $role));
    }
}


function asr_users_update_my_pwa_dialog_notifications(PDO $pdo, int $currentUserId, array $data): array {
    if ($currentUserId <= 0) {
        throw new RuntimeException('Пользователь не найден.');
    }
    asr_users_repository_ensure_schema($pdo);
    $enabled = (int)($data['enabled'] ?? 0) === 1 ? 1 : 0;
    asr_users_repository_set_pwa_dialog_notify_enabled($pdo, $currentUserId, $enabled);
    return [
        'ok' => true,
        'enabled' => $enabled === 1,
        'message' => $enabled === 1 ? 'PWA-уведомления о диалогах включены.' : 'PWA-уведомления о диалогах отключены.',
    ];
}

function asr_users_toggle_active(PDO $pdo, array $data, int $currentUserId): void {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0 || $userId === $currentUserId) {
        throw new RuntimeException('Нельзя изменить статус этого пользователя.');
    }

    $targetUser = asr_users_repository_find($pdo, $userId);
    if (!$targetUser) {
        throw new RuntimeException('Пользователь не найден.');
    }
    if (asr_users_is_protected($targetUser)) {
        throw new RuntimeException('Суперадмина нельзя заблокировать.');
    }

    $newState = (int)($data['new_state'] ?? 1) === 1 ? 1 : 0;
    asr_users_repository_set_active($pdo, $userId, $newState);
}

function asr_users_delete(PDO $pdo, array $data, int $currentUserId): void {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0 || $userId === $currentUserId) {
        throw new RuntimeException('Нельзя удалить этого пользователя.');
    }

    $targetUser = asr_users_repository_find($pdo, $userId);
    if (!$targetUser) {
        throw new RuntimeException('Пользователь не найден.');
    }
    if (asr_users_is_protected($targetUser)) {
        throw new RuntimeException('Суперадмина нельзя удалить.');
    }

    asr_users_repository_archive($pdo, $userId, $currentUserId);
    // При увольнении убираем сотрудника из списков фактически выданных доступов и индивидуальной видимости.
    try {
        if (is_file(__DIR__ . '/../access_vault/repository.php')) require_once __DIR__ . '/../access_vault/repository.php';
        if (function_exists('asr_av_remove_user_from_access_links')) asr_av_remove_user_from_access_links($pdo, $userId);
    } catch (Throwable $e) {}
}


function asr_users_restore(PDO $pdo, array $data): void {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Пользователь не найден.');
    asr_users_repository_restore($pdo, $userId);
}

function asr_users_purge(PDO $pdo, array $data, int $currentUserId): void {
    $userId = (int)($data['user_id'] ?? 0);
    if ($userId <= 0 || $userId === $currentUserId) throw new RuntimeException('Нельзя удалить этого пользователя.');
    $targetUser = asr_users_repository_find($pdo, $userId);
    if (!$targetUser) throw new RuntimeException('Пользователь не найден.');
    if (asr_users_is_protected($targetUser)) throw new RuntimeException('Суперадмина нельзя удалить.');
    asr_users_repository_delete($pdo, $userId);
}
