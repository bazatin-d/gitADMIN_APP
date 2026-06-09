<?php
defined('ASR_ADMIN') || exit;

/**
 * VK API client for the Chat-bots module.
 *
 * This file stays platform-local. Telegram senders/runtime do not depend on it.
 */

if (!function_exists('asr_tg_vk_api_version')) {
    function asr_tg_vk_api_version(): string {
        return '5.199';
    }
}

if (!function_exists('asr_tg_vk_channel_has_token')) {
    function asr_tg_vk_channel_has_token(array $channel): bool {
        return trim((string)($channel['vk_api_token_encrypted'] ?? '')) !== '';
    }
}

if (!function_exists('asr_tg_vk_api_request')) {
    function asr_tg_vk_api_request(string $token, string $method, array $payload = []): array {
        $token = trim($token);
        $method = trim($method);
        if ($token === '' || $method === '') {
            throw new InvalidArgumentException('Не указан токен или метод VK API.');
        }
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $method)) {
            throw new InvalidArgumentException('Некорректный метод VK API.');
        }

        $payload['access_token'] = $token;
        $payload['v'] = $payload['v'] ?? asr_tg_vk_api_version();

        $url = 'https://api.vk.com/method/' . $method;
        $body = http_build_query($payload, '', '&');
        $response = false;
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new RuntimeException('VK API недоступен: ' . ($error ?: 'ошибка cURL'));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 25,
                    'ignore_errors' => true,
                ],
            ]);
            $response = file_get_contents($url, false, $context);
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) { $httpCode = (int)$m[1]; break; }
                }
            }
            if ($response === false) {
                throw new RuntimeException('VK API недоступен: не удалось выполнить HTTP-запрос.');
            }
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new RuntimeException('VK API вернул некорректный ответ. HTTP ' . $httpCode . '. Ответ: ' . mb_substr((string)$response, 0, 300, 'UTF-8'));
        }
        if (isset($data['error']) && is_array($data['error'])) {
            $error = $data['error'];
            $code = (int)($error['error_code'] ?? 0);
            $message = (string)($error['error_msg'] ?? 'неизвестная ошибка');
            throw new RuntimeException('VK API: ' . $message . ($code ? ' #' . $code : ''));
        }

        return $data;
    }
}

if (!function_exists('asr_tg_vk_send_message')) {
    function asr_tg_vk_send_message(string $token, int|string $peerId, string $text, array $extra = []): array {
        $peerId = trim((string)$peerId);
        $text = trim($text);
        if ($peerId === '') throw new InvalidArgumentException('Не указан получатель VK.');
        if ($text === '') throw new InvalidArgumentException('Введите текст сообщения VK.');
        if (mb_strlen($text, 'UTF-8') > 4096) {
            throw new InvalidArgumentException('Сообщение слишком длинное. Лимит VK: 4096 символов.');
        }

        $payload = array_merge([
            'peer_id' => $peerId,
            'message' => $text,
            'random_id' => random_int(1, PHP_INT_MAX),
        ], $extra);

        return asr_tg_vk_api_request($token, 'messages.send', $payload);
    }
}
