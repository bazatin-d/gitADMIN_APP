<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/repository.php';

/**
 * POST-действия модуля настроек.
 * Должны подключаться через admin_app/actions/dispatcher.php до legacy admin.php.
 */


function asr_settings_tg_api_request(string $token, string $method, array $payload = []): array {
    $token = trim($token);
    $method = trim($method);
    if ($token === '' || $method === '') {
        throw new RuntimeException('Не указан токен технического бота.');
    }
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
    $response = false;
    $httpCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Telegram API недоступен: ' . ($error ?: 'ошибка cURL'));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload, '', '&'),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Telegram API недоступен: не удалось выполнить HTTP-запрос.');
        }
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Telegram API вернул нечитаемый ответ. HTTP ' . $httpCode . '.');
    }
    if (empty($data['ok'])) {
        throw new RuntimeException('Telegram API: ' . (string)($data['description'] ?? 'неизвестная ошибка'));
    }
    return $data;
}

function asr_settings_broadcast_test_webhook_url(array $settings): string {
    $secret = trim((string)($settings['telegram_broadcast_test_webhook_secret'] ?? ''));
    return rtrim(asr_current_base_url(), '/') . '/telegram/broadcast_test_bot.php?secret=' . rawurlencode($secret);
}

if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'telegram_broadcast_test_set_webhook') {
    try {
        $settings = asr_get_all_settings();
        $token = trim((string)($settings['telegram_broadcast_test_bot_token'] ?? ''));
        $secret = trim((string)($settings['telegram_broadcast_test_webhook_secret'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Сначала сохраните токен технического бота рассылок.');
        }
        if ($secret === '') {
            throw new RuntimeException('Сначала сохраните секрет вебхука технического бота рассылок.');
        }
        $url = asr_settings_broadcast_test_webhook_url($settings);
        // Важно: технический бот получает не только /start как message,
        // но и нажатия inline-кнопок «Закрыть» / «В спам» как callback_query.
        // Если оставить только ['message'], Telegram просто не присылает callback в webhook.
        asr_settings_tg_api_request($token, 'setWebhook', [
            'url' => $url,
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_UNICODE),
            'drop_pending_updates' => '0',
        ]);
        header('Location: admin.php?tab=settings&tg_btest_webhook=ok');
    } catch (Throwable $e) {
        header('Location: admin.php?tab=settings&settings_error=' . urlencode($e->getMessage()));
    }
    exit;
}

if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    try {
        asr_settings_repository_save(asr_settings_collect_general_pairs($_POST));
        header('Location: admin.php?tab=settings&saved=1');
    } catch (Throwable $e) {
        header('Location: admin.php?tab=settings&settings_error=' . urlencode($e->getMessage()));
    }
    exit;
}

// save_email_settings вынесен в admin_app/modules/emails/actions.php
