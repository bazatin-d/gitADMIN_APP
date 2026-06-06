<?php
// Cron для напоминаний об оплате ресурсов.
// Рекомендуемый запуск: 1 раз в день утром.

define('ASR_ADMIN', true);
require_once __DIR__ . '/../admin_app/bootstrap.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/repository.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/crypto.php';
require_once __DIR__ . '/../admin_app/modules/access_vault/service.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $result = asr_av_run_payment_reminders($pdo);
    echo 'access_vault_payment_reminders: ok' . PHP_EOL;
    echo 'sent=' . (int)($result['sent'] ?? 0) . PHP_EOL;
    echo 'failed=' . (int)($result['failed'] ?? 0) . PHP_EOL;
    echo 'skipped=' . (int)($result['skipped'] ?? 0) . PHP_EOL;
    if (!empty($result['errors'])) {
        echo 'errors=' . implode(' | ', array_map('strval', (array)$result['errors'])) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'access_vault_payment_reminders: error' . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
    exit(1);
}
