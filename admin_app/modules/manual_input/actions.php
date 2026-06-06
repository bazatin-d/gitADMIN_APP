<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/service.php';

if (asr_can_work_results() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_save_result') {
    try {
        $payload = asr_manual_input_build_payload($_POST);
        $resultId = asr_manual_input_insert_result($pdo, $payload);
        asr_manual_input_sync_to_bitrix($pdo, $resultId, $payload);

        header('Location: admin.php?view=' . $resultId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
