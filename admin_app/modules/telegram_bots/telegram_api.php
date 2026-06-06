<?php
defined('ASR_ADMIN') || exit;

function asr_tg_api_request(string $token, string $method, array $payload = []): array {
    $token = trim($token);
    $method = trim($method);
    if ($token === '' || $method === '') {
        throw new InvalidArgumentException('Не указан токен или метод Telegram API.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $method)) {
        throw new InvalidArgumentException('Некорректный метод Telegram API.');
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
    $hasFile = false;
    foreach ($payload as $value) {
        if ($value instanceof CURLFile) { $hasFile = true; break; }
    }
    $body = $hasFile ? $payload : http_build_query($payload, '', '&');
    $response = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = $hasFile ? [] : ['Content-Type: application/x-www-form-urlencoded'];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $hasFile ? 90 : 25,
        ]);
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Telegram API недоступен: ' . ($error ?: 'ошибка cURL'));
        }
    } else {
        if ($hasFile) {
            throw new RuntimeException('Для отправки загруженных файлов в Telegram на сервере нужен cURL. Либо включите cURL, либо используйте публичную https-ссылку на медиа.');
        }
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
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) { $httpCode = (int)$m[1]; break; }
            }
        }
        if ($response === false) {
            throw new RuntimeException('Telegram API недоступен: не удалось выполнить HTTP-запрос.');
        }
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Telegram API вернул некорректный ответ. HTTP ' . $httpCode . '. Ответ: ' . mb_substr((string)$response, 0, 300));
    }
    if (empty($data['ok'])) {
        $description = (string)($data['description'] ?? 'неизвестная ошибка');
        $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
        if (isset($parameters['retry_after'])) $description .= ' retry after ' . (int)$parameters['retry_after'];
        throw new RuntimeException('Telegram API: ' . $description);
    }
    return $data;
}

function asr_tg_api_get_me(string $token): array {
    $response = asr_tg_api_request($token, 'getMe');
    return is_array($response['result'] ?? null) ? $response['result'] : [];
}

function asr_tg_api_set_webhook(string $token, string $url, string $secretToken = ''): array {
    $payload = [
        'url' => $url,
        'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_UNICODE),
        'drop_pending_updates' => '0',
    ];
    if ($secretToken !== '') {
        $payload['secret_token'] = $secretToken;
    }
    return asr_tg_api_request($token, 'setWebhook', $payload);
}

function asr_tg_api_delete_webhook(string $token): array {
    return asr_tg_api_request($token, 'deleteWebhook', ['drop_pending_updates' => '0']);
}

function asr_tg_api_send_message(string $token, int|string $chatId, string $text, array $extra = []): array {
    $payload = array_merge([
        'chat_id' => (string)$chatId,
        'text' => $text,
        'parse_mode' => '',
        'disable_web_page_preview' => '1',
    ], $extra);
    return asr_tg_api_request($token, 'sendMessage', $payload);
}

function asr_tg_api_allowed_parse_mode(string $parseMode): string {
    $parseMode = trim($parseMode);
    return in_array($parseMode, ['HTML', 'MarkdownV2', 'Markdown'], true) ? $parseMode : '';
}


function asr_tg_api_reply_markup_from_buttons(array $rows): string {
    $keyboard = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $outRow = [];
        foreach ($row as $btn) {
            if (!is_array($btn)) continue;
            $text = trim((string)($btn['text'] ?? ''));
            if ($text === '') continue;
            $item = ['text' => mb_substr($text, 0, 64, 'UTF-8')];
            if (!empty($btn['url']) && preg_match('#^https?://#i', (string)$btn['url'])) {
                $item['url'] = (string)$btn['url'];
            } else {
                $item['callback_data'] = mb_substr((string)($btn['callback_data'] ?? 'action_stub'), 0, 64, 'UTF-8');
            }
            $outRow[] = $item;
            if (count($outRow) >= 4) break;
        }
        if ($outRow) $keyboard[] = $outRow;
        if (count($keyboard) >= 8) break;
    }
    return $keyboard ? json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

function asr_tg_api_local_file_field(string $path) {
    $path = trim($path);
    if ($path === '' || !is_file($path) || !is_readable($path)) return '';
    $mime = function_exists('mime_content_type') ? (string)mime_content_type($path) : 'application/octet-stream';
    return new CURLFile($path, $mime ?: 'application/octet-stream', basename($path));
}

function asr_tg_api_send_broadcast_payload(string $token, int|string $chatId, string $text, string $parseMode = 'HTML', string $mediaType = '', string $mediaUrl = '', bool $disablePreview = true, string $localFilePath = '', array $buttons = [], bool $protectContent = false): array {
    $parseMode = asr_tg_api_allowed_parse_mode($parseMode);
    $mediaType = trim($mediaType);
    $mediaUrl = trim($mediaUrl);
    $localFilePath = trim($localFilePath);
    $base = ['chat_id' => (string)$chatId];
    if ($parseMode !== '') $base['parse_mode'] = $parseMode;
    $replyMarkup = asr_tg_api_reply_markup_from_buttons($buttons);
    if ($replyMarkup !== '') $base['reply_markup'] = $replyMarkup;
    if ($protectContent) $base['protect_content'] = '1';

    if ($mediaType === '' || ($mediaUrl === '' && $localFilePath === '')) {
        $payload = $base + [
            'text' => $text,
            'disable_web_page_preview' => $disablePreview ? '1' : '0',
        ];
        if ($disablePreview) {
            $payload['link_preview_options'] = json_encode(['is_disabled' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return asr_tg_api_request($token, 'sendMessage', $payload);
    }

    $caption = mb_substr($text, 0, 1024, 'UTF-8');
    $payload = $base + [];
    if ($caption !== '') $payload['caption'] = $caption;
    $fileValue = '';
    if ($localFilePath !== '') {
        $fileValue = asr_tg_api_local_file_field($localFilePath);
    }
    if ($fileValue === '') $fileValue = $mediaUrl;

    if ($mediaType === 'photo') {
        $payload['photo'] = $fileValue;
        return asr_tg_api_request($token, 'sendPhoto', $payload);
    }
    if ($mediaType === 'video') {
        $payload['video'] = $fileValue;
        return asr_tg_api_request($token, 'sendVideo', $payload);
    }
    if ($mediaType === 'audio') {
        $payload['audio'] = $fileValue;
        return asr_tg_api_request($token, 'sendAudio', $payload);
    }
    if ($mediaType === 'document') {
        $payload['document'] = $fileValue;
        return asr_tg_api_request($token, 'sendDocument', $payload);
    }

    $payload = $base + ['text' => $text, 'disable_web_page_preview' => $disablePreview ? '1' : '0'];
    if ($disablePreview) {
        $payload['link_preview_options'] = json_encode(['is_disabled' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return asr_tg_api_request($token, 'sendMessage', $payload);
}

function asr_tg_api_media_file_value(array $item, string $attachName = '') {
    $localPath = trim((string)($item['local_file_path'] ?? ''));
    if ($localPath === '') {
        $relative = trim((string)($item['media_file_path'] ?? ''));
        if ($relative !== '' && function_exists('asr_tg_project_root_dir')) {
            $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
            if (is_file($candidate) && is_readable($candidate)) $localPath = $candidate;
        }
    }
    if ($localPath !== '') {
        return $attachName !== '' ? asr_tg_api_local_file_field($localPath) : asr_tg_api_local_file_field($localPath);
    }
    return trim((string)($item['media_url'] ?? ''));
}

function asr_tg_api_send_media_group(string $token, int|string $chatId, array $items, string $caption = '', string $parseMode = 'HTML', bool $protectContent = false): array {
    $parseMode = asr_tg_api_allowed_parse_mode($parseMode);
    $media = [];
    $payload = ['chat_id' => (string)$chatId];
    if ($protectContent) $payload['protect_content'] = '1';

    $index = 0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $source = trim((string)($item['media_url'] ?? ''));
        $localPath = trim((string)($item['local_file_path'] ?? ''));
        $relative = trim((string)($item['media_file_path'] ?? ''));
        if ($localPath === '' && $relative !== '' && function_exists('asr_tg_project_root_dir')) {
            $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
            if (is_file($candidate) && is_readable($candidate)) $localPath = $candidate;
        }
        if ($source === '' && $localPath === '') continue;

        $mediaField = $source;
        if ($localPath !== '') {
            $attachName = 'gallery_' . $index;
            $fileField = asr_tg_api_local_file_field($localPath);
            if ($fileField === '') continue;
            $payload[$attachName] = $fileField;
            $mediaField = 'attach://' . $attachName;
        }

        $entry = ['type' => 'photo', 'media' => $mediaField];
        if ($index === 0 && trim($caption) !== '') {
            $entry['caption'] = mb_substr($caption, 0, 1024, 'UTF-8');
            if ($parseMode !== '') $entry['parse_mode'] = $parseMode;
        }
        $media[] = $entry;
        $index++;
        if ($index >= 10) break;
    }

    if (count($media) < 2) {
        throw new RuntimeException('Для галереи нужно минимум 2 изображения. Для одной картинки используйте карточку «Картинка».');
    }

    $payload['media'] = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return asr_tg_api_request($token, 'sendMediaGroup', $payload);
}

function asr_tg_api_send_video_note(string $token, int|string $chatId, array $card): array {
    $payload = ['chat_id' => (string)$chatId];
    if (!empty($card['protect_content'])) $payload['protect_content'] = '1';
    $replyMarkup = asr_tg_api_reply_markup_from_buttons((array)($card['buttons'] ?? []));
    if ($replyMarkup !== '') $payload['reply_markup'] = $replyMarkup;

    $fileValue = '';
    $localPath = trim((string)($card['local_file_path'] ?? ''));
    if ($localPath === '') {
        $relative = trim((string)($card['media_file_path'] ?? ''));
        if ($relative !== '' && function_exists('asr_tg_project_root_dir')) {
            $candidate = rtrim(asr_tg_project_root_dir(), '/') . '/' . ltrim($relative, '/');
            if (is_file($candidate) && is_readable($candidate)) $localPath = $candidate;
        }
    }
    if ($localPath !== '') $fileValue = asr_tg_api_local_file_field($localPath);
    if ($fileValue === '') $fileValue = trim((string)($card['media_url'] ?? ''));
    if ($fileValue === '') throw new RuntimeException('В карточке «Видео-заметка» не указан файл или ссылка на MP4/M4V.');

    $payload['video_note'] = $fileValue;
    return asr_tg_api_request($token, 'sendVideoNote', $payload);
}

function asr_tg_api_send_broadcast_card(string $token, int|string $chatId, array $card): array {
    $type = (string)($card['type'] ?? 'text');
    $text = (string)($card['text'] ?? '');
    $parseMode = (string)($card['parse_mode'] ?? 'HTML');
    $disablePreview = !empty($card['disable_web_page_preview']);
    if ($type === 'file') $type = 'document';
    if ($type === 'image') $type = 'photo';
    if ($type === 'gallery') {
        return asr_tg_api_send_media_group($token, $chatId, (array)($card['gallery_items'] ?? []), $text, $parseMode, !empty($card['protect_content']));
    }
    if ($type === 'video_note') {
        return asr_tg_api_send_video_note($token, $chatId, $card);
    }
    if (!in_array($type, ['text','photo','video','audio','document','voice'], true)) $type = 'text';
    if ($type === 'voice') $type = 'audio';
    $mediaUrl = (string)($card['media_url'] ?? '');
    $localPath = (string)($card['local_file_path'] ?? '');
    if ($type === 'text') {
        return asr_tg_api_send_broadcast_payload($token, $chatId, $text, $parseMode, '', '', $disablePreview, '', (array)($card['buttons'] ?? []), !empty($card['protect_content']));
    }
    return asr_tg_api_send_broadcast_payload($token, $chatId, $text, $parseMode, $type, $mediaUrl, $disablePreview, $localPath, (array)($card['buttons'] ?? []), !empty($card['protect_content']));
}
