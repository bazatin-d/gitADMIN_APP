<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';

/**
 * POST-действия модуля пользователей.
 * Подключается диспетчером раньше legacy actions/admin.php.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if (in_array($action, asr_users_known_actions(), true)) {
        if (!function_exists('isAdmin') || !isAdmin()) {
            header('Location: admin.php');
            exit;
        }

        try {
            asr_users_repository_ensure_schema($pdo);
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);

            if ($action === 'add_user') {
                asr_users_create($pdo, $_POST);
                asr_users_redirect('Пользователь создан.');
            }

            if ($action === 'edit_user') {
                asr_users_update($pdo, $_POST);
                asr_users_redirect('Пользователь обновлён.');
            }

            if ($action === 'toggle_user_active') {
                asr_users_toggle_active($pdo, $_POST, $currentUserId);
                asr_users_redirect('Статус пользователя обновлён.');
            }

            if ($action === 'delete_user') {
                asr_users_delete($pdo, $_POST, $currentUserId);
                asr_users_redirect('Пользователь отправлен в архив.');
            }

            if ($action === 'restore_user') {
                asr_users_restore($pdo, $_POST);
                asr_users_redirect('Пользователь восстановлен.');
            }

            if ($action === 'purge_user') {
                asr_users_purge($pdo, $_POST, $currentUserId);
                asr_users_redirect('Пользователь удалён безвозвратно.');
            }
        } catch (Throwable $e) {
            asr_users_redirect('', $e->getMessage());
        }
    }
}
