<?php
/**
 * POST-действия модуля редактируемого меню.
 */

defined('ASR_ADMIN') || exit;

if (!function_exists('asr_menu_redirect')) {
    function asr_menu_redirect(string $status, string $message = ''): void {
        $url = 'admin.php?tab=settings&' . $status . '=1';
        if ($message !== '') {
            $url .= '&menu_error=' . urlencode($message);
        }
        header('Location: ' . $url);
        exit;
    }
}

if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['save_admin_menu', 'create_admin_menu_item', 'delete_admin_menu_item'], true)) {
    try {
        $action = (string)$_POST['action'];

        if ($action === 'save_admin_menu') {
            if (!empty($_POST['delete_menu_id'])) {
                asr_delete_menu_item($pdo, (int)$_POST['delete_menu_id']);
            } else {
                asr_save_menu_items_from_post($pdo, $_POST);
            }
            asr_menu_redirect('menu_saved');
        }

        if ($action === 'create_admin_menu_item') {
            asr_create_menu_item_from_post($pdo, $_POST);
            asr_menu_redirect('menu_saved');
        }

        if ($action === 'delete_admin_menu_item') {
            asr_delete_menu_item($pdo, (int)($_POST['delete_menu_id'] ?? 0));
            asr_menu_redirect('menu_saved');
        }
    } catch (Throwable $e) {
        header('Location: admin.php?tab=settings&menu_error=' . urlencode($e->getMessage()));
        exit;
    }
}
