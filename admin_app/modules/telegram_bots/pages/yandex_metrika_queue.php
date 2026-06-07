<?php
defined('ASR_ADMIN') || exit;

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

try {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stats = function_exists('asr_tg_yandex_metrika_queue_stats') ? asr_tg_yandex_metrika_queue_stats($pdo) : [];
    $rows = function_exists('asr_tg_yandex_metrika_queue_recent') ? asr_tg_yandex_metrika_queue_recent($pdo, 80) : [];
} catch (Throwable $e) {
    $stats = [];
    $rows = [];
    $error = $e->getMessage();
}

$statusLabels = [
    'pending' => 'Ожидает',
    'retry' => 'Повтор',
    'sent' => 'Отправлено',
    'skipped' => 'Пропущено',
    'failed' => 'Ошибка',
];
$statusClasses = [
    'pending' => 'background:#fff8e8;color:#8a5a00;border-color:#f1d48a;',
    'retry' => 'background:#eef6ff;color:#2563eb;border-color:#bfdbfe;',
    'sent' => 'background:#ecfdf3;color:#15803d;border-color:#bbf7d0;',
    'skipped' => 'background:#f3f4f6;color:#6b7280;border-color:#e5e7eb;',
    'failed' => 'background:#fff1f2;color:#dc2626;border-color:#fecdd3;',
];
?>
<div class="tg-ymq-page">
    <div class="tg-ymq-head">
        <div>
            <div class="tg-ymq-kicker">Чат-боты / Сценарии</div>
            <h2>Очередь Яндекс.Метрики</h2>
            <p>Диагностика событий, которые блок «Действия» поставил на отправку в Яндекс.Метрику.</p>
        </div>
        <div class="tg-ymq-actions">
            <a href="admin.php?tab=telegram_bots&page=scenarios" class="tg-ymq-btn">К сценариям</a>
            <a href="admin.php?tab=telegram_bots&page=yandex_metrika_queue" class="tg-ymq-btn tg-ymq-btn--primary">Обновить</a>
        </div>
    </div>

    <?php if (!empty($error ?? '')): ?>
        <div class="tg-ymq-error">Не удалось открыть очередь: <?php echo $h($error); ?></div>
    <?php endif; ?>

    <div class="tg-ymq-stats">
        <?php foreach (['pending','retry','sent','skipped','failed'] as $code): ?>
            <div class="tg-ymq-stat">
                <div class="tg-ymq-stat-num"><?php echo (int)($stats[$code] ?? 0); ?></div>
                <div class="tg-ymq-stat-label"><?php echo $h($statusLabels[$code] ?? $code); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="tg-ymq-card">
        <div class="tg-ymq-card-title">Последние события</div>
        <?php if (!$rows): ?>
            <div class="tg-ymq-empty">Событий пока нет. Они появятся после запуска сценария с действием «Передать данные о событии в Яндекс.Метрику».</div>
        <?php else: ?>
            <div class="tg-ymq-table-wrap">
                <table class="tg-ymq-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Статус</th>
                            <th>Счётчик / цель</th>
                            <th>Client ID</th>
                            <th>Сценарий / блок</th>
                            <th>Создано</th>
                            <th>Отправлено</th>
                            <th>Ошибка</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $status = (string)($row['status'] ?? 'pending');
                            $style = $statusClasses[$status] ?? 'background:#f3f4f6;color:#6b7280;border-color:#e5e7eb;';
                        ?>
                            <tr>
                                <td>#<?php echo (int)($row['id'] ?? 0); ?></td>
                                <td><span class="tg-ymq-badge" style="<?php echo $h($style); ?>"><?php echo $h($statusLabels[$status] ?? $status); ?></span></td>
                                <td>
                                    <strong><?php echo $h($row['counter_id'] ?? ''); ?></strong><br>
                                    <span><?php echo $h($row['target'] ?? ''); ?></span>
                                </td>
                                <td><code><?php echo $h($row['client_id'] ?? ''); ?></code></td>
                                <td>
                                    <strong><?php echo $h($row['scenario_title'] ?? ('Сценарий #' . (int)($row['scenario_id'] ?? 0))); ?></strong><br>
                                    <span><?php echo $h(($row['block_title'] ?? '') !== '' ? $row['block_title'] : ('Блок #' . (int)($row['block_id'] ?? 0))); ?></span>
                                </td>
                                <td><?php echo $h($row['queued_at'] ?? $row['created_at'] ?? ''); ?></td>
                                <td><?php echo $h($row['sent_at'] ?? ''); ?></td>
                                <td class="tg-ymq-error-cell"><?php echo $h($row['last_error'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
.tg-ymq-page{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#1f2937}.tg-ymq-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;background:#fff;border:1px solid #eef0f3;border-radius:24px;padding:24px;margin-bottom:16px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.tg-ymq-kicker{font-size:12px;font-weight:700;color:#9ca3af;margin-bottom:6px}.tg-ymq-head h2{margin:0;color:#1f2937;font-size:24px;font-weight:700}.tg-ymq-head p{margin:6px 0 0;color:#6b7280;font-size:14px;line-height:1.45}.tg-ymq-actions{display:flex;gap:10px;flex-wrap:wrap}.tg-ymq-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:14px;padding:0 16px;background:#f3f4f6;color:#4b5563;font-size:13px;font-weight:650;text-decoration:none}.tg-ymq-btn--primary{background:#FFA048;color:#fff}.tg-ymq-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}.tg-ymq-stat{background:#fff;border:1px solid #eef0f3;border-radius:20px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.tg-ymq-stat-num{font-size:28px;font-weight:760;color:#FFA048}.tg-ymq-stat-label{font-size:12px;font-weight:650;color:#6b7280;margin-top:3px}.tg-ymq-card{background:#fff;border:1px solid #eef0f3;border-radius:24px;padding:20px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.tg-ymq-card-title{font-size:18px;font-weight:700;margin-bottom:14px}.tg-ymq-empty,.tg-ymq-error{border:1px solid #f1e7c7;background:#fff8e8;color:#705d2d;border-radius:16px;padding:14px;font-size:14px;line-height:1.45}.tg-ymq-table-wrap{overflow:auto}.tg-ymq-table{width:100%;border-collapse:separate;border-spacing:0 8px;font-size:13px}.tg-ymq-table th{text-align:left;color:#9ca3af;font-size:11px;font-weight:750;padding:0 10px 6px}.tg-ymq-table td{background:#f9fafb;border-top:1px solid #eef0f3;border-bottom:1px solid #eef0f3;padding:12px 10px;vertical-align:top}.tg-ymq-table td:first-child{border-left:1px solid #eef0f3;border-radius:14px 0 0 14px}.tg-ymq-table td:last-child{border-right:1px solid #eef0f3;border-radius:0 14px 14px 0}.tg-ymq-table code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#4b5563;word-break:break-all}.tg-ymq-table span{color:#6b7280}.tg-ymq-badge{display:inline-flex;border:1px solid;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:750;white-space:nowrap}.tg-ymq-error-cell{max-width:280px;color:#6b7280;word-break:break-word}@media(max-width:900px){.tg-ymq-head{flex-direction:column}.tg-ymq-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.tg-ymq-stats{grid-template-columns:1fr}}
</style>
