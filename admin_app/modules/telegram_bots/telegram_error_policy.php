<?php
defined('ASR_ADMIN') || exit;

/**
 * Единая классификация ошибок отправки Telegram.
 *
 * Этот слой не отправляет сообщения и не меняет БД. Он только переводит текст ошибки
 * Telegram/cURL в понятное решение для очередей: повторять, считать постоянной ошибкой
 * или пометить подписчика заблокированным.
 */
function asr_tg_telegram_error_message(Throwable $e): string {
    return trim((string)$e->getMessage());
}

function asr_tg_telegram_error_retry_after(string $message): int {
    if (preg_match('/retry after\s+(\d+)/i', $message, $m)) {
        return max(0, (int)$m[1]);
    }
    return 0;
}

function asr_tg_classify_telegram_send_error(Throwable $e): array {
    $message = asr_tg_telegram_error_message($e);
    $lower = mb_strtolower($message, 'UTF-8');
    $retryAfter = asr_tg_telegram_error_retry_after($message);

    $isRateLimit = $retryAfter > 0
        || strpos($lower, 'too many requests') !== false
        || strpos($lower, '429') !== false;

    $isBlocked = strpos($lower, 'bot was blocked') !== false
        || strpos($lower, 'blocked by the user') !== false
        || strpos($lower, 'user is deactivated') !== false;

    $isChatNotFound = strpos($lower, 'chat not found') !== false
        || strpos($lower, 'user not found') !== false;

    $isForbidden = strpos($lower, 'forbidden') !== false;

    $isTemporary = $isRateLimit
        || strpos($lower, 'timed out') !== false
        || strpos($lower, 'timeout') !== false
        || strpos($lower, 'temporarily') !== false
        || strpos($lower, 'telegram api недоступен') !== false
        || strpos($lower, 'ошибка curl') !== false
        || strpos($lower, 'could not resolve') !== false
        || strpos($lower, 'failed to connect') !== false
        || strpos($lower, 'connection') !== false;

    if ($isRateLimit) {
        return [
            'kind' => 'rate_limit',
            'retry_after' => $retryAfter,
            'is_retryable' => true,
            'mark_subscriber_blocked' => false,
            'message' => $message,
        ];
    }

    if ($isBlocked) {
        return [
            'kind' => 'blocked',
            'retry_after' => 0,
            'is_retryable' => false,
            'mark_subscriber_blocked' => true,
            'message' => $message,
        ];
    }

    if ($isChatNotFound) {
        return [
            'kind' => 'chat_not_found',
            'retry_after' => 0,
            'is_retryable' => false,
            'mark_subscriber_blocked' => true,
            'message' => $message,
        ];
    }

    if ($isForbidden) {
        return [
            'kind' => 'forbidden',
            'retry_after' => 0,
            'is_retryable' => false,
            'mark_subscriber_blocked' => true,
            'message' => $message,
        ];
    }

    if ($isTemporary) {
        return [
            'kind' => 'temporary',
            'retry_after' => $retryAfter,
            'is_retryable' => true,
            'mark_subscriber_blocked' => false,
            'message' => $message,
        ];
    }

    return [
        'kind' => 'permanent',
        'retry_after' => 0,
        'is_retryable' => false,
        'mark_subscriber_blocked' => false,
        'message' => $message,
    ];
}

function asr_tg_telegram_retry_delay_seconds(array $policy, int $attempts): int {
    $retryAfter = max(0, (int)($policy['retry_after'] ?? 0));
    $attempts = max(1, $attempts);

    if ($retryAfter > 0) {
        return min(3600, max(1, $retryAfter));
    }

    if (($policy['kind'] ?? '') === 'rate_limit') {
        return min(900, 60 * $attempts);
    }

    return min(300, 30 * $attempts);
}
