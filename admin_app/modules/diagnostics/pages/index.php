<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/../service.php';
require_once __DIR__ . '/../repository.php';

$checks = asr_diagnostics_collect_checks($pdo);
?>
<div class="space-y-6">
    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
        <h3 class="text-lg font-bold text-gray-800 uppercase tracking-tight">Диагностика</h3>
        <p class="text-sm text-gray-400 font-bold mt-1">Быстрая проверка окружения, файлов и миграций. Это не полноценный доктор Хаус, но температуру меряет.</p>
    </section>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="divide-y divide-gray-100">
            <?php foreach ($checks as $check): ?>
                <div class="p-4 flex items-center justify-between gap-4">
                    <div class="font-black text-gray-700"><?php echo htmlspecialchars((string)$check[0]); ?></div>
                    <div class="flex items-center gap-3 text-sm font-bold <?php echo !empty($check[2]) ? 'text-green-600' : 'text-red-500'; ?>">
                        <span><?php echo htmlspecialchars((string)$check[1]); ?></span>
                        <span class="w-3 h-3 rounded-full <?php echo !empty($check[2]) ? 'bg-green-500' : 'bg-red-500'; ?>"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
