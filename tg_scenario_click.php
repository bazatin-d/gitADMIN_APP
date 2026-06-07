<?php
declare(strict_types=1);

define('ASR_ADMIN', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_app/modules/telegram_bots/service.php';

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
try {
    $result = function_exists('asr_tg_scenario_stats_handle_click_redirect') ? asr_tg_scenario_stats_handle_click_redirect($pdo, $token) : ['ok' => false, 'url' => ''];
    $url = (string)($result['url'] ?? '');
    if (!empty($result['ok']) && $url !== '') {
        header('Location: ' . $url, true, 302);
        exit;
    }
} catch (Throwable $e) {
    try { if (function_exists('asr_tg_log')) asr_tg_log($pdo, 0, 'warning', 'scenario_click_redirect_failed', $e->getMessage(), ['token' => substr($token, 0, 12)]); } catch (Throwable $ignored) {}
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Ссылка не найдена или устарела.";
