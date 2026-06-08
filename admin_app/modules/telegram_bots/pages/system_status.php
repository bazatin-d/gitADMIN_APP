<?php
defined('ASR_ADMIN') || exit;

$h = isset($h) && is_callable($h) ? $h : static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$systemCurrentRole = function_exists('asr_current_role') ? (string)asr_current_role() : (string)($_SESSION['user_role'] ?? '');
$isSuper = $systemCurrentRole === 'superadmin';
if (!$isSuper && function_exists('asr_is_protected_user')) {
    $isSuper = (bool)asr_is_protected_user([
        'id' => (int)($_SESSION['user_id'] ?? 0),
        'role' => $systemCurrentRole,
    ]);
}
if (!$isSuper): ?>
<section class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-semibold">Раздел доступен только суперадминистратору.</section>
<?php return; endif;

require_once dirname(__DIR__) . '/system_status_service.php';
$sys = asr_tg_system_collect($pdo);
$statusClass = static function(string $status): string {
    return [
        'ok' => 'tg-system-badge tg-system-badge--ok',
        'warning' => 'tg-system-badge tg-system-badge--warning',
        'danger' => 'tg-system-badge tg-system-badge--danger',
        'neutral' => 'tg-system-badge tg-system-badge--neutral',
    ][$status] ?? 'tg-system-badge tg-system-badge--neutral';
};
$fmt = static function($value): string { return number_format((int)$value, 0, '.', ' '); };
$cardStatus = static function(array $part): string { return (string)($part['status'] ?? 'neutral'); };
$shortDate = static function($value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : $value;
};
$renderStatus = static function(string $status) use ($h, $statusClass): string {
    return '<span class="' . $statusClass($status) . '">' . $h(asr_tg_system_status_label($status)) . '</span>';
};
$renderCounts = static function(array $counts) use ($h, $fmt): string {
    if (!$counts) return '<span class="tg-system-muted">нет данных</span>';
    $labels = [
        'pending' => 'ожидает', 'processing' => 'выполняется', 'retry' => 'повтор', 'sent' => 'отправлено', 'failed' => 'ошибка', 'skipped' => 'пропущено',
        'active' => 'активен', 'running' => 'выполняется', 'waiting_answer' => 'ждёт ответ', 'delayed' => 'задержка', 'scheduled' => 'расписание', 'finished' => 'завершён', 'stopped' => 'остановлен', 'error' => 'ошибка', 'queued' => 'очередь',
    ];
    $html = '<div class="tg-system-chips">';
    foreach ($counts as $k => $v) {
        $label = $labels[(string)$k] ?? (string)$k;
        $html .= '<span class="tg-system-chip"><b>' . $h($label) . '</b>' . $h($fmt($v)) . '</span>';
    }
    return $html . '</div>';
};
?>
<style>
.tg-system-page{display:grid;gap:18px}.tg-system-head{background:#fff;border:1px solid #edf0f2;border-radius:24px;padding:22px;box-shadow:0 10px 28px rgba(15,23,42,.04);display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.tg-system-title{font-size:22px;font-weight:600;color:#1f2937;margin:0}.tg-system-sub{margin-top:6px;font-size:13px;font-weight:500;color:#8a94a6;line-height:1.45}.tg-system-refresh{height:42px;border:1px solid #fed7aa;background:#fff7ed;color:#c2410c;border-radius:14px;padding:0 15px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.tg-system-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.tg-system-card{background:#fff;border:1px solid #edf0f2;border-radius:24px;padding:18px;box-shadow:0 10px 28px rgba(15,23,42,.035);min-width:0}.tg-system-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.tg-system-card h4{margin:0;font-size:16px;font-weight:600;color:#1f2937}.tg-system-card p{margin:5px 0 0;font-size:12px;font-weight:500;color:#9ca3af;line-height:1.45}.tg-system-badge{border-radius:999px;padding:7px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}.tg-system-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.tg-system-badge--warning{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}.tg-system-badge--danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.tg-system-badge--neutral{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}.tg-system-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.tg-system-metric{background:#f9fafb;border:1px solid #f1f3f5;border-radius:16px;padding:12px}.tg-system-metric-value{font-size:20px;font-weight:600;color:#111827}.tg-system-metric-label{margin-top:4px;font-size:11px;font-weight:600;color:#9ca3af}.tg-system-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.tg-system-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #edf0f2;background:#f9fafb;color:#6b7280;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:600}.tg-system-chip b{color:#374151;font-weight:600}.tg-system-list{display:grid;gap:8px;margin-top:13px}.tg-system-row{border:1px solid #f1f3f5;background:#fbfbfc;border-radius:16px;padding:11px 12px}.tg-system-row-top{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:11px;font-weight:700;color:#9ca3af}.tg-system-row-text{margin-top:6px;font-size:12px;font-weight:500;color:#4b5563;line-height:1.45;word-break:break-word}.tg-system-muted{font-size:12px;font-weight:600;color:#9ca3af}.tg-system-command{margin-top:12px;background:#111827;color:#f9fafb;border-radius:16px;padding:12px 14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;overflow:auto}.tg-system-note{border:1px solid #fed7aa;background:#fff7ed;color:#7c2d12;border-radius:18px;padding:13px 15px;font-size:12px;font-weight:600;line-height:1.45}@media(max-width:980px){.tg-system-grid{grid-template-columns:1fr}.tg-system-head{flex-direction:column}.tg-system-metrics{grid-template-columns:1fr 1fr}}@media(max-width:560px){.tg-system-metrics{grid-template-columns:1fr}}
</style>
<section class="tg-system-page">
    <div class="tg-system-head">
        <div>
            <h3 class="tg-system-title">Состояние системы</h3>
            <div class="tg-system-sub">Безопасная диагностика Чат-ботов: только чтение, без изменения runtime, webhook, очередей и сценариев.</div>
            <div class="tg-system-sub">Обновлено: <?php echo $h($shortDate($sys['generated_at'] ?? '')); ?></div>
        </div>
        <a class="tg-system-refresh" href="admin.php?tab=telegram_bots&page=system_status">Обновить</a>
    </div>

    <div class="tg-system-note">Этот экран не запускает cron и не чистит базу. Он показывает, где накопились задачи, ошибки или зависшие состояния. Кнопки вмешательства добавим отдельным шагом, когда убедимся, что диагностика показывает правильную картину.</div>

    <div class="tg-system-grid">
        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Каналы</h4><p>Подключённые боты и активность каналов.</p></div><?php echo $renderStatus($cardStatus($sys['bots'])); ?></div>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['total'] ?? 0)); ?></div><div class="tg-system-metric-label">всего</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['active'] ?? 0)); ?></div><div class="tg-system-metric-label">активные</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['bots']['inactive'] ?? 0)); ?></div><div class="tg-system-metric-label">неактивные</div></div></div>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Cron и очереди</h4><p>Универсальная очередь сценариев, задержек, вопросов и служебных задач.</p></div><?php echo $renderStatus($cardStatus($sys['send_queue'])); ?></div>
            <?php $sq = $sys['send_queue']['stats'] ?? []; ?>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['due_pending'] ?? $sq['pending'] ?? 0)); ?></div><div class="tg-system-metric-label">пора выполнить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['retry'] ?? 0)); ?></div><div class="tg-system-metric-label">повтор</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sq['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div></div>
            <?php echo $renderCounts(['pending'=>$sq['pending'] ?? 0,'processing'=>$sq['processing'] ?? 0,'retry'=>$sq['retry'] ?? 0,'failed'=>$sq['failed'] ?? 0,'skipped'=>$sq['skipped'] ?? 0]); ?>
            <div class="tg-system-command">*/1 * * * * /usr/bin/php /path/to/project/admin_app/modules/telegram_bots/cron_send_queue.php 30</div>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Рассылки</h4><p>Очередь получателей рассылок и проблемные отправки.</p></div><?php echo $renderStatus($cardStatus($sys['broadcast'])); ?></div>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['due'] ?? 0)); ?></div><div class="tg-system-metric-label">к отправке</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['retry_due'] ?? 0)); ?></div><div class="tg-system-metric-label">повторить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['broadcast']['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div></div>
            <?php echo $renderCounts($sys['broadcast']['counts'] ?? []); ?>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Сценарии</h4><p>Активные прохождения, ожидания, задержки и ошибки runtime.</p></div><?php echo $renderStatus($cardStatus($sys['scenarios'])); ?></div>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['scenarios']['active'] ?? 0)); ?></div><div class="tg-system-metric-label">активные</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['scenarios']['due'] ?? 0)); ?></div><div class="tg-system-metric-label">пора продолжить</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt(($sys['scenarios']['errors'] ?? 0) + ($sys['scenarios']['stale_active'] ?? 0))); ?></div><div class="tg-system-metric-label">проблемы</div></div></div>
            <?php echo $renderCounts($sys['scenarios']['counts'] ?? []); ?>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Журнал сценариев</h4><p>Размер истории прохождения сценариев и кандидаты на очистку.</p></div><?php echo $renderStatus($cardStatus($sys['journal'])); ?></div>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['total'] ?? 0)); ?></div><div class="tg-system-metric-label">записей</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['older_180'] ?? 0)); ?></div><div class="tg-system-metric-label">старше 180 дней</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['journal']['older_365'] ?? 0)); ?></div><div class="tg-system-metric-label">старше 365 дней</div></div></div>
            <p>Последнее событие: <?php echo $h($shortDate($sys['journal']['last_event_at'] ?? '')); ?></p>
            <div class="tg-system-command">php admin_app/modules/telegram_bots/cron_cleanup_scenario_journal.php --dry-run</div>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Яндекс.Метрика</h4><p>Очередь передачи событий офлайн-конверсий.</p></div><?php echo $renderStatus($cardStatus($sys['metrika'])); ?></div>
            <?php echo $renderCounts($sys['metrika']['counts'] ?? []); ?>
            <div class="tg-system-metrics"><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['metrika']['stale_processing'] ?? 0)); ?></div><div class="tg-system-metric-label">зависли</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt(($sys['metrika']['counts']['failed'] ?? 0) + ($sys['metrika']['counts']['retry'] ?? 0))); ?></div><div class="tg-system-metric-label">ошибки/повтор</div></div><div class="tg-system-metric"><div class="tg-system-metric-value"><?php echo $h($fmt($sys['metrika']['counts']['sent'] ?? 0)); ?></div><div class="tg-system-metric-label">отправлено</div></div></div>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Последние ошибки сценариев</h4><p>Ошибки состояния подписчиков в сценариях.</p></div></div>
            <div class="tg-system-list"><?php foreach (($sys['scenarios']['recent_errors'] ?? []) as $row): ?><div class="tg-system-row"><div class="tg-system-row-top"><span>#<?php echo (int)$row['id']; ?> · <?php echo $h($row['scenario_title'] ?? ('сценарий #' . ($row['scenario_id'] ?? ''))); ?></span><span><?php echo $h($shortDate($row['updated_at'] ?? '')); ?></span></div><div class="tg-system-row-text"><?php echo $h($row['last_error'] ?? 'Без текста ошибки'); ?><?php if (!empty($row['current_block_id'])): ?> · блок #<?php echo (int)$row['current_block_id']; ?><?php endif; ?></div></div><?php endforeach; ?><?php if (empty($sys['scenarios']['recent_errors'])): ?><div class="tg-system-muted">Ошибок сценариев не найдено.</div><?php endif; ?></div>
        </article>

        <article class="tg-system-card">
            <div class="tg-system-card-head"><div><h4>Последние ошибки Telegram/API</h4><p>Ошибки рассылок, очереди и служебных логов.</p></div><?php echo $renderStatus($cardStatus($sys['logs'])); ?></div>
            <div class="tg-system-list"><?php $errors = array_merge($sys['send_queue']['recent_errors'] ?? [], $sys['broadcast']['recent_errors'] ?? [], $sys['metrika']['recent_errors'] ?? [], $sys['logs']['recent_errors'] ?? []); $errors = array_slice($errors, 0, 12); foreach ($errors as $row): ?><div class="tg-system-row"><div class="tg-system-row-top"><span>#<?php echo (int)($row['id'] ?? 0); ?> · <?php echo $h($row['status'] ?? $row['level'] ?? 'ошибка'); ?></span><span><?php echo $h($shortDate($row['updated_at'] ?? $row['created_at'] ?? '')); ?></span></div><div class="tg-system-row-text"><?php echo $h($row['last_error'] ?? $row['message'] ?? 'Без текста ошибки'); ?></div></div><?php endforeach; ?><?php if (!$errors): ?><div class="tg-system-muted">Свежих ошибок не найдено.</div><?php endif; ?></div>
        </article>
    </div>
</section>
