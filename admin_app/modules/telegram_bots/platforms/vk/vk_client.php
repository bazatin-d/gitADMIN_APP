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
        $hasAttachment = trim((string)($extra['attachment'] ?? '')) !== '';
        if ($peerId === '') throw new InvalidArgumentException('Не указан получатель VK.');
        if ($text === '' && !$hasAttachment) throw new InvalidArgumentException('Введите текст сообщения VK или прикрепите изображение.');
        if (mb_strlen($text, 'UTF-8') > 4096) {
            throw new InvalidArgumentException('Сообщение слишком длинное. Лимит VK: 4096 символов.');
        }

        $payload = [
            'peer_id' => $peerId,
            'random_id' => random_int(1, PHP_INT_MAX),
        ];
        if ($text !== '') $payload['message'] = $text;
        $payload = array_merge($payload, $extra);

        return asr_tg_vk_api_request($token, 'messages.send', $payload);
    }
}

if (!function_exists('asr_tg_vk_upload_multipart_json')) {
    function asr_tg_vk_upload_multipart_json(string $url, string $fieldName, string $filePath): array {
        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            throw new RuntimeException('Для загрузки изображений VK нужен cURL на сервере.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Файл изображения для VK не найден или недоступен для чтения.');
        }
        $mime = function_exists('mime_content_type') ? (string)mime_content_type($filePath) : '';
        if ($mime === '') {
            $mime = $fieldName === 'photo' ? 'image/jpeg' : 'application/octet-stream';
        }
        if ($fieldName === 'photo' && !str_starts_with(strtolower($mime), 'image/')) {
            $mime = 'image/jpeg';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [$fieldName => new CURLFile($filePath, $mime, basename($filePath))],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('VK upload недоступен: ' . ($error ?: 'ошибка cURL'));
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new RuntimeException('VK upload вернул некорректный ответ. HTTP ' . $httpCode . '. Ответ: ' . mb_substr((string)$response, 0, 300, 'UTF-8'));
        }
        if (isset($data['error'])) {
            throw new RuntimeException('VK upload: ' . (is_string($data['error']) ? $data['error'] : json_encode($data['error'], JSON_UNESCAPED_UNICODE)));
        }
        return $data;
    }
}

if (!function_exists('asr_tg_vk_upload_message_photo')) {
    function asr_tg_vk_upload_message_photo(string $token, int|string $peerId, string $filePath): string {
        $peerId = trim((string)$peerId);
        if ($peerId === '') throw new InvalidArgumentException('Не указан получатель VK для загрузки изображения.');
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Файл изображения VK не найден.');

        $uploadServer = asr_tg_vk_api_request($token, 'photos.getMessagesUploadServer', ['peer_id' => $peerId]);
        $uploadUrl = trim((string)($uploadServer['response']['upload_url'] ?? ''));
        if ($uploadUrl === '') throw new RuntimeException('VK не вернул upload_url для изображения.');

        $uploaded = asr_tg_vk_upload_multipart_json($uploadUrl, 'photo', $filePath);
        $photo = (string)($uploaded['photo'] ?? '');
        $server = (string)($uploaded['server'] ?? '');
        $hash = (string)($uploaded['hash'] ?? '');
        if ($photo === '' || $server === '' || $hash === '') {
            throw new RuntimeException('VK вернул неполные данные после загрузки изображения.');
        }

        $saved = asr_tg_vk_api_request($token, 'photos.saveMessagesPhoto', [
            'photo' => $photo,
            'server' => $server,
            'hash' => $hash,
        ]);
        $item = is_array($saved['response'][0] ?? null) ? $saved['response'][0] : [];
        $ownerId = (string)($item['owner_id'] ?? '');
        $id = (string)($item['id'] ?? '');
        $accessKey = (string)($item['access_key'] ?? '');
        if ($ownerId === '' || $id === '') throw new RuntimeException('VK не сохранил изображение для сообщения.');
        return 'photo' . $ownerId . '_' . $id . ($accessKey !== '' ? '_' . $accessKey : '');
    }
}


if (!function_exists('asr_tg_vk_upload_message_document')) {
    function asr_tg_vk_upload_message_document(string $token, int|string $peerId, string $filePath, string $title = ''): string {
        $peerId = trim((string)$peerId);
        if ($peerId === '') throw new InvalidArgumentException('Не указан получатель VK для загрузки файла.');
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Файл VK не найден.');

        $title = trim($title);
        if ($title === '') $title = basename($filePath);
        $title = preg_replace('/[\r\n]+/u', ' ', $title) ?: basename($filePath);
        $title = mb_substr($title, 0, 120, 'UTF-8');

        $uploadServer = asr_tg_vk_api_request($token, 'docs.getMessagesUploadServer', [
            'peer_id' => $peerId,
            'type' => 'doc',
        ]);
        $uploadUrl = trim((string)($uploadServer['response']['upload_url'] ?? ''));
        if ($uploadUrl === '') throw new RuntimeException('VK не вернул upload_url для файла.');

        $uploaded = asr_tg_vk_upload_multipart_json($uploadUrl, 'file', $filePath);
        $file = (string)($uploaded['file'] ?? '');
        if ($file === '') throw new RuntimeException('VK вернул неполные данные после загрузки файла.');

        $saved = asr_tg_vk_api_request($token, 'docs.save', [
            'file' => $file,
            'title' => $title,
        ]);
        $response = $saved['response'] ?? null;
        $item = [];
        if (is_array($response)) {
            if (is_array($response['doc'] ?? null)) {
                $item = $response['doc'];
            } elseif (is_array($response[0] ?? null)) {
                $item = $response[0];
            }
        }
        $ownerId = (string)($item['owner_id'] ?? '');
        $id = (string)($item['id'] ?? '');
        $accessKey = (string)($item['access_key'] ?? '');
        if ($ownerId === '' || $id === '') throw new RuntimeException('VK не сохранил файл для сообщения.');
        return 'doc' . $ownerId . '_' . $id . ($accessKey !== '' ? '_' . $accessKey : '');
    }
}


if (!function_exists('asr_tg_vk_upload_message_video')) {
    function asr_tg_vk_upload_message_video(string $token, int|string $peerId, string $filePath, string $title = ''): string {
        $peerId = trim((string)$peerId);
        if ($peerId === '') throw new InvalidArgumentException('Не указан получатель VK для загрузки видео.');
        if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Файл VK-видео не найден.');

        $title = trim($title);
        if ($title === '') $title = basename($filePath);
        $title = preg_replace('/[\r\n]+/u', ' ', $title) ?: basename($filePath);
        $title = mb_substr($title, 0, 120, 'UTF-8');

        $saved = asr_tg_vk_api_request($token, 'video.save', [
            'name' => $title,
            'description' => '',
            'is_private' => 1,
            'wallpost' => 0,
            'repeat' => 0,
            'peer_id' => $peerId,
        ]);
        $response = is_array($saved['response'] ?? null) ? $saved['response'] : [];
        $uploadUrl = trim((string)($response['upload_url'] ?? ''));
        if ($uploadUrl === '') throw new RuntimeException('VK не вернул upload_url для видео.');

        $uploaded = asr_tg_vk_upload_multipart_json($uploadUrl, 'video_file', $filePath);
        $ownerId = (string)($uploaded['owner_id'] ?? $response['owner_id'] ?? '');
        $id = (string)($uploaded['video_id'] ?? $uploaded['id'] ?? $response['video_id'] ?? $response['id'] ?? '');
        $accessKey = (string)($uploaded['access_key'] ?? $response['access_key'] ?? '');
        if ($ownerId === '' || $id === '') throw new RuntimeException('VK не сохранил видео для сообщения.');
        return 'video' . $ownerId . '_' . $id . ($accessKey !== '' ? '_' . $accessKey : '');
    }
}


if (!function_exists('asr_tg_vk_keyboard_from_buttons')) {
    /**
     * Build VK inline keyboard from broadcast/scenario buttons.
     *
     * Broadcasts still use only open_link buttons. Scenario runtime can pass
     * Telegram-like callback_data; for VK we render it as a text button with
     * payload. VK then returns a normal message_new event, so the user does
     * not need to enable the separate message_event callback type.
     */
    function asr_tg_vk_keyboard_from_buttons(array $rows): string {
        $buttons = [];
        $total = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $btn) {
                if (!is_array($btn)) continue;
                $label = trim((string)($btn['text'] ?? ''));
                if ($label === '') continue;

                $url = trim((string)($btn['url'] ?? ''));
                $callbackData = trim((string)($btn['callback_data'] ?? ''));
                $type = (string)($btn['type'] ?? ($url !== '' ? 'url' : 'callback'));
                $action = null;

                if ($url !== '' && $type === 'url') {
                    $action = [
                        'type' => 'open_link',
                        'label' => mb_substr($label, 0, 40, 'UTF-8'),
                        'link' => $url,
                    ];
                } elseif ($callbackData !== '' && strncmp($callbackData, 'scn_', 4) === 0) {
                    $payload = json_encode([
                        'asr' => 'scenario',
                        'callback_data' => mb_substr($callbackData, 0, 64, 'UTF-8'),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($payload === false || $payload === '') continue;
                    $action = [
                        'type' => 'text',
                        'label' => mb_substr($label, 0, 40, 'UTF-8'),
                        'payload' => $payload,
                    ];
                }

                if (!$action) continue;

                $button = ['action' => $action];
                if (($action['type'] ?? '') === 'text') {
                    $button['color'] = 'secondary';
                }
                $buttons[] = [$button];
                $total++;
                if ($total >= 5) break 2;
            }
        }
        if (!$buttons) return '';
        return json_encode([
            'inline' => true,
            'buttons' => $buttons,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
