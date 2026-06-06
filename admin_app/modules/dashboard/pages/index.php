<?php
defined('ASR_ADMIN') || exit;

function asr_dashboard_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_dashboard_count(PDO $pdo, string $table, string $where = '1=1'): int {
    if (!asr_dashboard_table_exists($pdo, $table)) {
        return 0;
    }
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where}")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_dashboard_safe_rows(PDO $pdo, string $sql, int $limit = 5): array {
    try {
        $stmt = $pdo->query($sql . ' LIMIT ' . max(1, min(20, $limit)));
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

$resultsTotal = asr_dashboard_count($pdo, 'oca_results');
$resultsToday = asr_dashboard_count($pdo, 'oca_results', "DATE(created_at) = CURDATE()");
$resultsWeek = asr_dashboard_count($pdo, 'oca_results', "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

$shortUrlsTotal = asr_dashboard_count($pdo, 'oca_short_urls');
$usersTotal = asr_dashboard_count($pdo, 'oca_users');
$tgBotsTotal = asr_dashboard_count($pdo, 'oca_telegram_bots');
$tgSubscribersTotal = asr_dashboard_count($pdo, 'oca_telegram_bot_subscribers');
$tgBroadcastsQueued = asr_dashboard_count($pdo, 'oca_telegram_bot_broadcasts', "status IN ('queued','processing')");

$recentResults = asr_dashboard_table_exists($pdo, 'oca_results')
    ? asr_dashboard_safe_rows($pdo, "SELECT id, name, phone, email, created_at FROM oca_results ORDER BY created_at DESC", 5)
    : [];

$recentTg = asr_dashboard_table_exists($pdo, 'oca_telegram_bot_logs')
    ? asr_dashboard_safe_rows($pdo, "SELECT level, event_type, message, created_at FROM oca_telegram_bot_logs ORDER BY id DESC", 5)
    : [];

$quickLinks = [
    ['title' => 'Результаты АСР', 'text' => 'Последние заполненные тесты и карточки клиентов.', 'href' => 'admin.php?tab=results'],
    ['title' => 'Telegram-боты', 'text' => 'Боты, подписчики, рассылки и очередь отправки.', 'href' => 'admin.php?tab=telegram_bots&page=bots'],
    ['title' => 'Маркетинг', 'text' => 'Короткие ссылки и подготовка промо-материалов.', 'href' => 'admin.php?tab=url_shortener'],
    ['title' => 'Пользователи', 'text' => 'Доступы сотрудников и права модулей.', 'href' => 'admin.php?tab=users'],
];
?>
<style>
.asr-dashboard h2,.asr-dashboard h3{color:#2f343d;font-weight:700;letter-spacing:.01em}.asr-dashboard .dash-card{background:#fff;border:1px solid #eef0f3;border-radius:24px;box-shadow:0 12px 34px rgba(15,23,42,.04)}.asr-dashboard .dash-muted{color:#737b86}.asr-dashboard .dash-accent{color:#d98537}.asr-dashboard .dash-btn{background:#FFA048;color:#fff}.asr-dashboard .dash-btn:hover{background:#ec8f33}.asr-dashboard .dash-soft-btn{background:#fff7ed;color:#b96b25;border:1px solid #fed7aa}.asr-dashboard .dash-soft-btn:hover{background:#ffedd5}.asr-dashboard .dash-olive{background:#f4f6ec;color:#687731;border:1px solid #dde6bf}@media(max-width:767px){.asr-dashboard .dash-grid{grid-template-columns:1fr!important}.asr-dashboard .dash-pad{padding:18px!important}}
</style>
<div class="asr-dashboard text-left space-y-6">
    <div class="dash-card dash-pad p-7 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Admin App ABM</div>
            <h2 class="mt-2 text-2xl md:text-3xl">Общая панель</h2>
            <p class="mt-2 dash-muted font-semibold max-w-3xl">Стартовая страница для быстрых проверок: результаты тестов, боты, рассылки, пользователи и ближайшие технические хвосты.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="admin.php?tab=results" class="dash-btn px-5 py-3 rounded-2xl text-sm font-bold">Открыть результаты</a>
            <a href="admin.php?tab=telegram_bots&page=broadcasts" class="dash-soft-btn px-5 py-3 rounded-2xl text-sm font-bold">Рассылки</a>
        </div>
    </div>

    <div class="dash-grid grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="dash-card p-5"><div class="text-xs font-bold text-gray-400 uppercase">Тестов всего</div><div class="mt-3 text-3xl font-semibold text-gray-800"><?php echo number_format($resultsTotal, 0, '.', ' '); ?></div><div class="mt-2 text-xs font-semibold dash-muted">Сегодня: <?php echo (int)$resultsToday; ?> · 7 дней: <?php echo (int)$resultsWeek; ?></div></div>
        <div class="dash-card p-5"><div class="text-xs font-bold text-gray-400 uppercase">Telegram</div><div class="mt-3 text-3xl font-semibold text-gray-800"><?php echo number_format($tgSubscribersTotal, 0, '.', ' '); ?></div><div class="mt-2 text-xs font-semibold dash-muted">Ботов: <?php echo (int)$tgBotsTotal; ?> · Рассылок в работе: <?php echo (int)$tgBroadcastsQueued; ?></div></div>
        <div class="dash-card p-5"><div class="text-xs font-bold text-gray-400 uppercase">Короткие ссылки</div><div class="mt-3 text-3xl font-semibold text-gray-800"><?php echo number_format($shortUrlsTotal, 0, '.', ' '); ?></div><div class="mt-2 text-xs font-semibold dash-muted">Для маркетинга и быстрых переходов.</div></div>
        <div class="dash-card p-5"><div class="text-xs font-bold text-gray-400 uppercase">Пользователи</div><div class="mt-3 text-3xl font-semibold text-gray-800"><?php echo number_format($usersTotal, 0, '.', ' '); ?></div><div class="mt-2 text-xs font-semibold dash-muted">Сотрудники и уровни доступа.</div></div>
    </div>

    <div class="dash-grid grid grid-cols-1 xl:grid-cols-3 gap-5">
        <div class="xl:col-span-2 dash-card p-6">
            <div class="flex items-center justify-between gap-4 mb-4"><h3 class="text-lg">Последние результаты</h3><a href="admin.php?tab=results" class="text-sm font-bold dash-accent">Все результаты</a></div>
            <?php if ($recentResults): ?>
                <div class="space-y-3">
                    <?php foreach ($recentResults as $row): ?>
                        <a href="admin.php?view=<?php echo (int)($row['id'] ?? 0); ?>" class="block rounded-2xl border border-gray-100 bg-gray-50/70 px-4 py-3 hover:bg-orange-50/60 transition-colors">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                <div class="font-semibold text-gray-800"><?php echo htmlspecialchars((string)($row['name'] ?? 'Без имени')); ?></div>
                                <div class="text-xs font-semibold text-gray-400"><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></div>
                            </div>
                            <div class="mt-1 text-xs font-semibold dash-muted"><?php echo htmlspecialchars(trim(((string)($row['phone'] ?? '')) . ' ' . ((string)($row['email'] ?? '')))); ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rounded-2xl bg-gray-50 border border-gray-100 px-4 py-6 text-sm font-semibold dash-muted">Пока нет данных для вывода.</div>
            <?php endif; ?>
        </div>

        <div class="dash-card p-6">
            <h3 class="text-lg mb-4">Быстрые переходы</h3>
            <div class="space-y-3">
                <?php foreach ($quickLinks as $link): ?>
                    <a href="<?php echo htmlspecialchars($link['href']); ?>" class="block rounded-2xl border border-gray-100 px-4 py-3 hover:border-orange-200 hover:bg-orange-50/50 transition-colors">
                        <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($link['title']); ?></div>
                        <div class="mt-1 text-xs font-semibold dash-muted"><?php echo htmlspecialchars($link['text']); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="dash-card p-6">
        <div class="flex items-center justify-between gap-4 mb-4"><h3 class="text-lg">Telegram: последние события</h3><a href="admin.php?tab=telegram_bots&page=logs" class="text-sm font-bold dash-accent">Открыть журнал</a></div>
        <?php if ($recentTg): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <?php foreach ($recentTg as $event): ?>
                    <div class="rounded-2xl bg-gray-50 border border-gray-100 px-4 py-3">
                        <div class="text-xs font-bold uppercase tracking-widest text-gray-400"><?php echo htmlspecialchars((string)($event['level'] ?? 'info')); ?> · <?php echo htmlspecialchars((string)($event['event_type'] ?? 'event')); ?></div>
                        <div class="mt-2 text-sm font-semibold text-gray-700"><?php echo htmlspecialchars((string)($event['message'] ?? '')); ?></div>
                        <div class="mt-2 text-xs font-semibold dash-muted"><?php echo htmlspecialchars((string)($event['created_at'] ?? '')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="rounded-2xl bg-gray-50 border border-gray-100 px-4 py-6 text-sm font-semibold dash-muted">Событий Telegram пока нет.</div>
        <?php endif; ?>
    </div>
</div>
