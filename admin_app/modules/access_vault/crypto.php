<?php
defined('ASR_ADMIN') || exit;

function asr_access_vault_has_crypto_key(): bool {
    return defined('ACCESS_VAULT_KEY') && strlen((string)ACCESS_VAULT_KEY) >= 32;
}

function asr_access_vault_crypto_key(): string {
    if (!asr_access_vault_has_crypto_key()) {
        throw new RuntimeException('Ключ ACCESS_VAULT_KEY не настроен. Добавьте константу в config.php.');
    }
    return hash('sha256', (string)ACCESS_VAULT_KEY, true);
}

function asr_access_vault_encrypt(string $plain): string {
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', asr_access_vault_crypto_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Не удалось зашифровать пароль.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, asr_access_vault_crypto_key(), true);
    return 'v1:' . base64_encode($iv . $mac . $cipher);
}

function asr_access_vault_decrypt(string $encrypted): string {
    $encrypted = trim($encrypted);
    if ($encrypted === '') return '';
    if (str_starts_with($encrypted, 'v1:')) {
        $raw = base64_decode(substr($encrypted, 3), true);
        if ($raw === false || strlen($raw) < 49) return '';
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        $calc = hash_hmac('sha256', $iv . $cipher, asr_access_vault_crypto_key(), true);
        if (!hash_equals($mac, $calc)) return '';
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', asr_access_vault_crypto_key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
    return '';
}
