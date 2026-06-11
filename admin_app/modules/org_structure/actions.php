<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/repository.php';

if (!function_exists('asr_org_structure_redirect')) {
    function asr_org_structure_redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

if (!$is_shared_view && isset($_SESSION['logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['org_action'] ?? '');
    if ($action !== '') {
        try {
            if (!isAdmin()) {
                throw new RuntimeException('Недостаточно прав для изменения оргсхем.');
            }
            asr_org_structure_ensure_schema($pdo);

            if ($action === 'create_scheme') {
                $schemeId = asr_org_structure_create_scheme($pdo, $_POST);
                asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
            }

            if ($action === 'update_scheme') {
                $schemeId = (int)($_POST['scheme_id'] ?? 0);
                asr_org_structure_update_scheme($pdo, $schemeId, $_POST);
                if (!empty($_POST['return_to_scheme'])) {
                    asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
                }
                asr_org_structure_redirect('admin.php?tab=org_structure');
            }

            if ($action === 'archive_scheme') {
                asr_org_structure_archive_scheme($pdo, (int)($_POST['scheme_id'] ?? 0));
                asr_org_structure_redirect('admin.php?tab=org_structure');
            }

            if ($action === 'create_node') {
                $schemeId = (int)($_POST['scheme_id'] ?? 0);
                asr_org_structure_create_node($pdo, $_POST);
                asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
            }

            if ($action === 'director_add') {
                $schemeId = (int)($_POST['scheme_id'] ?? 0);
                asr_org_structure_create_director_child($pdo, $_POST);
                asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
            }

            if ($action === 'update_node') {
                $schemeId = (int)($_POST['scheme_id'] ?? 0);
                asr_org_structure_update_node($pdo, (int)($_POST['node_id'] ?? 0), $_POST);
                asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
            }

            if ($action === 'delete_node') {
                $schemeId = (int)($_POST['scheme_id'] ?? 0);
                asr_org_structure_delete_node($pdo, (int)($_POST['node_id'] ?? 0), $schemeId);
                asr_org_structure_redirect('admin.php?tab=org_structure&page=scheme&id=' . $schemeId);
            }
        } catch (Throwable $e) {
            $_SESSION['org_structure_error'] = $e->getMessage();
            $schemeId = (int)($_POST['scheme_id'] ?? 0);
            $back = $schemeId > 0 ? 'admin.php?tab=org_structure&page=scheme&id=' . $schemeId : 'admin.php?tab=org_structure';
            asr_org_structure_redirect($back);
        }
    }
}
