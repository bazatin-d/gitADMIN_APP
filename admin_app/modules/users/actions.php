<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';
$asrPwaPushLib = __DIR__ . '/../../lib/pwa_push.php';
if (is_file($asrPwaPushLib)) { require_once $asrPwaPushLib; }

/**
 * POST-действия модуля пользователей.
 * Подключается диспетчером раньше legacy actions/admin.php.
 */


if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['pwa_push'] ?? '') === 'public_key') {
    try {
        if (!function_exists('asr_pwa_push_get_vapid_keys')) {
            throw new RuntimeException('Модуль PWA Push недоступен.');
        }
        $keys = asr_pwa_push_get_vapid_keys($pdo);
        asr_pwa_push_json_response(['ok' => true, 'public_key' => (string)$keys['public_key']]);
    } catch (Throwable $e) {
        if (function_exists('asr_pwa_push_json_response')) {
            asr_pwa_push_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        } else {
            header('Content-Type: application/json; charset=utf-8', true, 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if (in_array($action, asr_users_known_actions(), true)) {
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);

        if ($action === 'pwa_push_subscribe') {
            try {
                if (!function_exists('asr_pwa_push_register_subscription')) {
                    throw new RuntimeException('Модуль PWA Push недоступен.');
                }
                $raw = (string)($_POST['subscription_json'] ?? '');
                $subscription = json_decode($raw, true);
                if (!is_array($subscription)) {
                    throw new RuntimeException('Браузер не передал push-подписку.');
                }
                asr_pwa_push_register_subscription($pdo, $currentUserId, $subscription, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Серверные PWA Push-уведомления подключены.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                header('Content-Type: application/json; charset=utf-8', true, 400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        }

        if ($action === 'pwa_push_unsubscribe') {
            try {
                if (!function_exists('asr_pwa_push_unregister_subscription')) {
                    throw new RuntimeException('Модуль PWA Push недоступен.');
                }
                asr_pwa_push_unregister_subscription($pdo, $currentUserId, (string)($_POST['endpoint'] ?? ''));
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Серверные PWA Push-уведомления отключены для этого устройства.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                header('Content-Type: application/json; charset=utf-8', true, 400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        }

        if ($action === 'update_my_pwa_dialog_notifications') {
            try {
                $payload = asr_users_update_my_pwa_dialog_notifications($pdo, $currentUserId, $_POST);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                header('Content-Type: application/json; charset=utf-8', true, 400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        }

        if (!function_exists('isAdmin') || !isAdmin()) {
            header('Location: admin.php');
            exit;
        }

        try {
            asr_users_repository_ensure_schema($pdo);

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
