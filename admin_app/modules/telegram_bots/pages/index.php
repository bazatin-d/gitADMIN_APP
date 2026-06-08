<?php
defined('ASR_ADMIN') || exit;

if (!asr_tg_can('view')): ?>
<div class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-bold">Недостаточно прав для просмотра модуля «Чат-боты».</div>
<?php return; endif;

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$tgMessageVariables = [
    ['group' => 'Системные', 'title' => 'Полное имя', 'token' => '{{full_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Имя', 'token' => '{{first_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Фамилия', 'token' => '{{last_name}}', 'icon' => 'Т'],
    ['group' => 'Системные', 'title' => 'Username', 'token' => '{{username}}', 'icon' => '@'],
    ['group' => 'Системные', 'title' => 'Телефон', 'token' => '{{phone}}', 'icon' => '☎'],
    ['group' => 'Системные', 'title' => 'E-mail', 'token' => '{{email}}', 'icon' => '✉'],
    ['group' => 'Системные', 'title' => 'Канал', 'token' => '{{bot_title}}', 'icon' => '#'],
    ['group' => 'Системные', 'title' => 'Username бота', 'token' => '{{bot_username}}', 'icon' => '@'],
];
try {
    foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
        $code = trim((string)($field['code'] ?? ''));
        $title = trim((string)($field['title'] ?? ''));
        if ($code === '') continue;
        $tgMessageVariables[] = [
            'group' => 'Пользовательские',
            'title' => $title !== '' ? $title : $code,
            'token' => '{{custom.' . $code . '}}',
            'icon' => '★',
        ];
    }
} catch (Throwable $e) {}
$tgMessageVariablesJson = json_encode($tgMessageVariables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($tgMessageVariablesJson === false) $tgMessageVariablesJson = '[]';
$page = (string)($_GET['page'] ?? 'bots');

// Legacy scenario editors were removed. Keep old URLs working by routing them
// to the single React Flow editor. Do not include scenario.php/scenario_editor.php.
if ($page === 'scenario' || $page === 'scenario_editor') {
    $page = 'scenario_flow';
}

// Clean React Flow drawer endpoint. It must return only the panel partial,
// without the module chrome; otherwise the drawer can fall back to the channels page.
if ($page === 'scenario_block_panel') {
    if (!asr_tg_can('flows')) {
        http_response_code(403);
        echo '<section id="tg-flow-panel" class="tg-flow-panel"><div class="tg-flow-panel-body">Недостаточно прав для редактирования сценария.</div></section>';
        return;
    }
    require __DIR__ . '/scenario_block_panel.php';
    return;
}

$allowedPages = ['bots','subscribers','subscriber','messages','broadcasts','flows','scenarios','scenario_flow','logs'];
if (!in_array($page, $allowedPages, true)) $page = 'bots';
if ($page === 'logs') $page = 'messages';
if ($page === 'flows') $page = 'scenarios';
if ($page === 'subscriber' && !asr_tg_can('view')) $page = 'bots';
if ($page === 'broadcasts' && !asr_tg_can('broadcast')) $page = 'bots';
if (in_array($page, ['scenarios','scenario_flow'], true) && !asr_tg_can('flows')) $page = 'bots';
if ($page === 'logs' && !asr_tg_can('logs')) $page = 'bots';

if ($page === 'subscribers') {
    require __DIR__ . '/subscribers.php';
    return;
}

if ($page !== 'subscribers') {
    try {
        asr_tg_repository_ensure_schema($pdo);
    } catch (Throwable $e) {
        ?>
        <div class="bg-white rounded-3xl border border-red-100 p-8 text-left">
            <h3 class="text-lg font-semibold text-red-700 ">Модуль «Чат-боты» не смог подготовить таблицы</h3>
            <p class="mt-3 text-sm font-semibold text-gray-600"><?php echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="mt-3 text-sm font-semibold text-gray-500">Запасной вариант: выполните SQL из файла <code>database/migrations/2026_05_28_008_create_telegram_bots.sql</code>.</p>
        </div>
        <?php return;
    }
}

$bots = $page === 'subscribers' ? asr_tg_bots_all_light($pdo) : asr_tg_bots_all($pdo);
$selectedBotId = (int)($_GET['bot_id'] ?? 0);
$selectedBot = $selectedBotId > 0 ? ($page === 'subscribers' ? asr_tg_bot_find_light($pdo, $selectedBotId) : asr_tg_bot_find($pdo, $selectedBotId)) : null;
if (!$selectedBot && $bots && !in_array($page, ['subscribers','messages','scenarios','scenario_flow'], true)) {
    $selectedBot = $bots[0];
    $selectedBotId = (int)$selectedBot['id'];
}

$canManage = asr_tg_can('manage');
$canBroadcast = asr_tg_can('broadcast');
$statusLabels = [
    'created' => 'Создан',
    'active' => 'Активен',
    'paused' => 'Пауза',
    'inactive' => 'Не активен',
    'disabled' => 'Отключён',
    'webhook_error' => 'Ошибка webhook',
    'draft' => 'Черновик',
];
$statusClasses = [
    'active' => 'bg-green-50 text-green-700 border-green-100',
    'paused' => 'bg-gray-50 text-gray-600 border-gray-100',
    'inactive' => 'bg-gray-50 text-gray-600 border-gray-100',
    'disabled' => 'bg-gray-50 text-gray-600 border-gray-100',
    'webhook_error' => 'bg-red-50 text-red-700 border-red-100',
    'created' => 'bg-gray-50 text-gray-600 border-gray-100',
    'draft' => 'bg-yellow-50 text-yellow-700 border-yellow-100',
];
$navItems = [
    'subscribers' => ['Подписчики', 'telegram_bots.view'],
    'messages' => ['Диалоги', 'telegram_bots.view'],
    'broadcasts' => ['Рассылки', 'telegram_bots.broadcast'],
    'scenarios' => ['Сценарии', 'telegram_bots.flows'],
    'bots' => ['Каналы', 'telegram_bots.view'],
];

$botSelect = static function(array $bots, int $selectedBotId, string $page) use ($h): void { ?>
    <form method="GET" class="flex flex-col sm:flex-row gap-3 sm:items-end">
        <input type="hidden" name="tab" value="telegram_bots">
        <input type="hidden" name="page" value="<?php echo $h($page); ?>">
        <label class="block min-w-[260px]">
            <span class="text-[10px]  text-gray-400 font-semibold">Бот</span>
            <select name="bot_id" class="mt-1 w-full px-4 py-3 rounded-2xl border border-gray-200 font-bold text-sm">
                <?php foreach ($bots as $bot): ?>
                    <option value="<?php echo (int)$bot['id']; ?>" <?php echo (int)$bot['id'] === $selectedBotId ? 'selected' : ''; ?>><?php echo $h($bot['title'] . (($bot['bot_username'] ?? '') ? ' (@' . $bot['bot_username'] . ')' : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="tb2-btn px-5 py-3 rounded-2xl bg-gray-900 text-white text-xs font-semibold "><img class="tb2-icon" src="/assets/admin/icons/tb2-open-white.svg" alt="" aria-hidden="true">Открыть</button>
    </form>
<?php };
?>

<style>
.tg-module .tb2-btn,
.tg-module button,
.tg-module .tg-channel-action,
.tg-module .tg-channel-add-btn {
    display: inline-flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 7px;
    white-space: nowrap;
    line-height: 1.1;
}
.tg-module button img,
.tg-module .tb2-btn img,
.tg-module .tg-channel-action img,
.tg-module .tg-channel-add-btn img {
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
}

.tg-toast-stack{position:fixed;right:22px;top:22px;z-index:160;display:flex;flex-direction:column;gap:10px;max-width:min(420px,calc(100vw - 32px));pointer-events:none}.tg-toast{border-radius:18px;padding:14px 16px;box-shadow:0 18px 46px rgba(15,23,42,.18);font-size:13px;font-weight:600;line-height:1.4;opacity:1;transform:translateY(0);transition:opacity .45s ease,transform .45s ease}.tg-toast--success{background:#ecfdf5;border:1px solid #bbf7d0;color:#047857}.tg-toast--error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}.tg-toast.is-hiding{opacity:0;transform:translateY(-8px)}.tg-module-nav{display:flex;flex-wrap:wrap;gap:10px;padding:4px 0}.tg-module-nav-link{display:inline-flex;align-items:center;justify-content:center;border:1px solid #edf0f2;background:#fff;color:#6b7280;border-radius:18px;padding:13px 18px;font-size:13px;font-weight:600;text-decoration:none}.tg-module-nav-link:hover{background:#fff7ed;color:#e98222}.tg-module-nav-link.is-active{background:#fff4e8;color:#e98222;border-color:#ffedd5}

.tg-command-list { display: flex; flex-direction: column; gap: 10px; }
.tg-command-row {
    display: grid;
    grid-template-columns: minmax(120px, .8fr) minmax(170px, 1fr) minmax(170px, 1fr) minmax(150px, .9fr) 42px;
    gap: 10px;
    align-items: end;
    padding: 12px;
    border: 1px solid #edf0f2;
    border-radius: 18px;
    background: #f9fafb;
}
.tg-command-field span { display:block; font-size:10px; font-weight:500; color:#9ca3af; margin-bottom:6px; }
.tg-command-field input,
.tg-command-field select {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
    padding: 11px 12px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}
.tg-command-prefix { position: relative; }
.tg-command-prefix input { padding-left: 24px; }
.tg-command-prefix::before { content: '/'; position: absolute; left: 12px; bottom: 12px; color: #9ca3af; font-weight: 700; }
.tg-command-delete {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    border: 1px solid #fee2e2;
    background: #fff;
    color: #ef4444;
    font-weight: 800;
}
.tg-command-add-row {
    border: 1px dashed #fed7aa;
    background: #fff7ed;
    color: #c2410c;
    border-radius: 16px;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 700;
}
.tg-command-note {
    border: 1px solid #edf0f2;
    border-radius: 18px;
    background: #f9fafb;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
}
@media (max-width: 920px) {
    .tg-command-row { grid-template-columns: 1fr; }
    .tg-command-delete { width: 100%; }
}

.tg-channel-board {
    background: #fff;
    border: 1px solid #edf0f2;
    border-radius: 24px;
    overflow: visible;
    box-shadow: 0 10px 28px rgba(15, 23, 42, .04);
}
.tg-channel-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 22px;
    border-bottom: 1px solid #edf0f2;
}
.tg-channel-heading {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
.tg-channel-heading-icon {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    background: #fff7ed;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 38px;
}
.tg-channel-heading-icon img { width: 20px; height: 20px; }
.tg-channel-title {
    font-size: 22px;
    font-weight: 500;
    color: #2f3437;
    letter-spacing: .01em;
}
.tg-channel-count {
    color: #6b7280;
    margin-left: 6px;
}
.tg-channel-add-btn {
    border-radius: 14px;
    background: #FFA048;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .08em;
    padding: 14px 18px;
    min-height: 44px;
    text-transform: uppercase;
}
.tg-channel-table { width: 100%; }
.tg-channel-table-head,
.tg-channel-row {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(260px, 1.1fr) 52px;
    align-items: center;
    gap: 18px;
}
.tg-channel-table-head {
    padding: 16px 22px;
    color: #6b7280;
    font-size: 12px;
    font-weight: 700;
    border-bottom: 1px solid #edf0f2;
}
.tg-channel-row {
    position: relative;
    padding: 16px 22px;
    border-bottom: 1px solid #edf0f2;
    background: #fff;
}
.tg-channel-row:last-child { border-bottom: 0; }
.tg-channel-row.is-selected { background: #fffaf4; }
.tg-channel-row.is-disabled {
    background: #f6f7f8;
    color: #9ca3af;
}
.tg-channel-row.is-disabled .tg-channel-name,
.tg-channel-row.is-disabled .tg-channel-link { color: #9ca3af; }
.tg-channel-cell-name,
.tg-channel-cell-link {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 14px;
}
.tg-channel-service-icon {
    width: 46px;
    height: 46px;
    border-radius: 999px;
    background: #f3f4f6;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 46px;
}
.tg-channel-service-icon img { width: 22px; height: 22px; }
.tg-channel-service-icon.telegram { background: #eaf6fd; }
.tg-channel-service-icon.vk { background: #eef5ff; }
.tg-channel-name {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tg-channel-meta {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 700;
    color: #9ca3af;
}
.tg-channel-link {
    color: #F28C28;
    font-size: 14px;
    font-weight: 600;
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 3px;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tg-channel-username-muted {
    color: #a8b0b8;
    margin-left: 7px;
    font-size: 13px;
    font-weight: 600;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tg-channel-menu-wrap {
    position: relative;
    display: flex;
    justify-content: flex-end;
}
.tg-channel-dots {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    color: #9ca3af;
    font-size: 26px;
    line-height: 1;
    background: transparent;
}
.tg-channel-dots:hover { background: #f3f4f6; color: #4b5563; }
.tg-channel-menu {
    position: absolute;
    top: 42px;
    right: 0;
    z-index: 35;
    min-width: 220px;
    padding: 8px;
    border-radius: 18px;
    border: 1px solid #edf0f2;
    background: #fff;
    box-shadow: 0 22px 48px rgba(15, 23, 42, .16);
    display: none;
}
.tg-channel-menu.is-open { display: block; }
.tg-channel-menu .tg-channel-action,
.tg-channel-menu form button {
    width: 100%;
    justify-content: flex-start;
    border-radius: 12px;
    padding: 11px 12px;
    color: #374151;
    font-size: 13px;
    font-weight: 700;
    background: transparent;
    text-align: left;
}
.tg-channel-menu .tg-channel-action:hover,
.tg-channel-menu form button:hover { background: #f9fafb; }
.tg-channel-menu .danger { color: #c2410c; }
.tg-channel-empty {
    padding: 28px 22px;
    color: #6b7280;
    font-size: 14px;
    font-weight: 600;
}
.tg-channel-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 80;
    background: rgba(17, 24, 39, .42);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}
.tg-channel-modal-backdrop.is-open { display: flex; }
.tg-channel-modal {
    width: min(720px, 100%);
    max-height: min(86vh, 760px);
    overflow: auto;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 30px 90px rgba(15, 23, 42, .28);
}
.tg-channel-modal-wide { width: min(1060px, 100%); }
.tg-channel-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px;
    border-bottom: 1px solid #edf0f2;
}
.tg-channel-modal-title {
    font-size: 20px;
    font-weight: 600;
    color: #2f3437;
}
.tg-channel-modal-text {
    margin-top: 5px;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
}
.tg-channel-modal-close {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    background: #f3f4f6;
}
.tg-channel-modal-close img { width: 16px; height: 16px; }
.tg-channel-form {
    padding: 22px 24px 24px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 15px;
}
.tg-channel-field span {
    display: block;
    color: #6b7280;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 6px;
}
.tg-channel-field input,
.tg-channel-field textarea,
.tg-channel-field select {
    width: 100%;
    border: 1px solid #dfe4e8;
    border-radius: 18px;
    padding: 13px 15px;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    outline: none;
}
.tg-channel-field input:focus,
.tg-channel-field textarea:focus,
.tg-channel-field select:focus {
    border-color: #FFA048;
    box-shadow: 0 0 0 3px rgba(255, 160, 72, .15);
}
.tg-channel-note {
    border: 1px solid #f2e5d6;
    background: #fffaf4;
    border-radius: 18px;
    padding: 12px 14px;
    color: #8a5a28;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
}
.tg-channel-modal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 4px;
}
.tg-channel-secondary {
    border-radius: 16px;
    background: #f3f4f6;
    color: #374151;
    font-size: 12px;
    font-weight: 700;
    padding: 13px 16px;
}
.tg-channel-primary {
    border-radius: 16px;
    background: #FFA048;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 13px 16px;
}
@media (max-width: 760px) {
    .tg-channel-top { align-items: stretch; flex-direction: column; padding: 16px; }
    .tg-channel-add-btn { width: 100%; }
    .tg-channel-table-head { display: none; }
    .tg-channel-row {
        grid-template-columns: 1fr 42px;
        gap: 12px;
        padding: 14px 16px;
    }
    .tg-channel-cell-link {
        grid-column: 1 / -1;
        padding-left: 60px;
        align-items: flex-start;
        flex-direction: column;
        gap: 4px;
    }
    .tg-channel-username-muted { margin-left: 0; }
    .tg-channel-menu { right: 0; }
    .tg-channel-title { font-size: 20px; }
}
</style>

<div class="tg-module space-y-6 text-left">
    <div class="tg-toast-stack" id="tgToastStack" aria-live="polite" aria-atomic="true">
        <?php if (!empty($_GET['tg_msg'])): ?><div class="tg-toast tg-toast--success" data-tg-toast><?php echo $h($_GET['tg_msg']); ?></div><?php endif; ?>
        <?php if (!empty($_GET['tg_error'])): ?><div class="tg-toast tg-toast--error" data-tg-toast><?php echo $h($_GET['tg_error']); ?></div><?php endif; ?>
        <?php if (!asr_tg_has_crypto_key()): ?><div class="tg-toast tg-toast--error" data-tg-toast>Не задан ACCESS_VAULT_KEY. Без него нельзя безопасно хранить токены ботов.</div><?php endif; ?>
    </div>

<?php if ($page === 'bots'):
    $channelServiceOf = static function(array $bot): string {
        return function_exists('asr_tg_channel_type_of') ? asr_tg_channel_type_of($bot) : (strtolower((string)($bot['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram');
    };
    $channelIconOf = static function(string $service): string {
        return $service === 'vk' ? '/assets/admin/icons/tb2-vk-gray.svg' : '/assets/admin/icons/tb2-telegram-gray.svg';
    };
    $channelLabelOf = static function(string $service): string {
        return $service === 'vk' ? 'ВК' : 'Telegram';
    };
    $channelUrlOf = static function(array $bot, string $service): string {
        $username = trim((string)($bot['bot_username'] ?? ''));
        if ($username === '') return '';
        return $service === 'vk' ? 'https://vk.com/' . rawurlencode(ltrim($username, '@')) : 'https://t.me/' . rawurlencode(ltrim($username, '@'));
    };
?>
    <section class="tg-channel-board">
        <div class="tg-channel-top">
            <div class="tg-channel-heading">
                <span class="tg-channel-heading-icon"><img src="/assets/admin/icons/tb2-gear-gray.svg" alt="" aria-hidden="true"></span>
                <div>
                    <div class="tg-channel-title">Каналы <span class="tg-channel-count"><?php echo count($bots); ?></span></div>
                </div>
            </div>
            <?php if ($canManage): ?>
                <button type="button" class="tg-channel-add-btn" data-tg-modal-open="tg-channel-add-modal">
                    <img src="/assets/admin/icons/tb2-plus-white.svg" alt="" aria-hidden="true">Добавить новый канал
                </button>
            <?php endif; ?>
        </div>

        <div class="tg-channel-table">
            <div class="tg-channel-table-head">
                <div>Имя</div>
                <div>Канал</div>
                <div></div>
            </div>

            <?php if (!$bots): ?>
                <div class="tg-channel-empty">Пока нет подключённых каналов. Добавьте первый канал через кнопку справа вверху.</div>
            <?php endif; ?>

            <?php foreach ($bots as $bot):
                $botId = (int)$bot['id'];
                $status = (string)($bot['status'] ?? 'draft');
                $isDisabled = in_array($status, ['paused','disabled','inactive'], true);
                $isSelected = $botId === $selectedBotId;
                $service = $channelServiceOf($bot);
                $username = trim((string)($bot['bot_username'] ?? ''));
                $channelUrl = $channelUrlOf($bot, $service);
                $displayUsername = $username !== '' ? '@' . ltrim($username, '@') : ($service === 'vk' ? 'адрес ВК не указан' : 'username не получен');
            ?>
                <div class="tg-channel-row <?php echo $isSelected ? 'is-selected' : ''; ?> <?php echo $isDisabled ? 'is-disabled' : ''; ?>">
                    <div class="tg-channel-cell-name">
                        <span class="tg-channel-service-icon <?php echo $h($service); ?>">
                            <img src="<?php echo $h($channelIconOf($service)); ?>" alt="" aria-hidden="true">
                        </span>
                        <div class="min-w-0">
                            <div class="tg-channel-name"><?php echo $h($bot['title'] ?? 'Без названия'); ?></div>
                            <div class="tg-channel-meta"><?php echo $h($channelLabelOf($service)); ?> · <?php echo $h($statusLabels[$status] ?? $status); ?></div>
                        </div>
                    </div>

                    <div class="tg-channel-cell-link">
                        <?php if ($channelUrl !== ''): ?>
                            <a class="tg-channel-link" href="<?php echo $h($channelUrl); ?>" target="_blank" rel="noopener"><?php echo $h($displayUsername); ?></a>
                            <span class="tg-channel-username-muted"><?php echo $h($displayUsername); ?></span>
                        <?php else: ?>
                            <span class="tg-channel-link"><?php echo $h($displayUsername); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="tg-channel-menu-wrap">
                        <button type="button" class="tg-channel-dots" aria-label="Действия канала" data-tg-menu-toggle="tg-channel-menu-<?php echo $botId; ?>">⋮</button>
                        <div class="tg-channel-menu" id="tg-channel-menu-<?php echo $botId; ?>">
                            <?php if ($canManage): ?>
                                <button type="button" class="tg-channel-action" data-tg-modal-open="tg-channel-edit-modal-<?php echo $botId; ?>">
                                    <img src="/assets/admin/icons/tb2-gear-gray.svg" alt="" aria-hidden="true">Редактировать
                                </button>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                                <?php if ($isDisabled): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="tg_channel_enable">
                                        <input type="hidden" name="return_page" value="bots">
                                        <input type="hidden" name="bot_id" value="<?php echo $botId; ?>">
                                        <button type="submit"><img src="/assets/admin/icons/tb2-play-gray.svg" alt="" aria-hidden="true">Включить канал</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="tg_channel_disable">
                                        <input type="hidden" name="return_page" value="bots">
                                        <input type="hidden" name="bot_id" value="<?php echo $botId; ?>">
                                        <button type="submit"><img src="/assets/admin/icons/tb2-cancel-gray.svg" alt="" aria-hidden="true">Отключить канал</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Удалить канал и его локальные данные? Действие нельзя отменить.');">
                                    <input type="hidden" name="action" value="tg_bot_delete">
                                    <input type="hidden" name="return_page" value="bots">
                                    <input type="hidden" name="bot_id" value="<?php echo $botId; ?>">
                                    <button type="submit" class="danger"><img src="/assets/admin/icons/tg2-delete-gray.svg" alt="" aria-hidden="true">Удалить канал</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>


                <?php if ($canManage): ?>
                    <div class="tg-channel-modal-backdrop" id="tg-channel-edit-modal-<?php echo $botId; ?>" aria-hidden="true">
                        <div class="tg-channel-modal" role="dialog" aria-modal="true" aria-labelledby="tg-channel-edit-title-<?php echo $botId; ?>">
                            <div class="tg-channel-modal-head">
                                <div>
                                    <div class="tg-channel-modal-title" id="tg-channel-edit-title-<?php echo $botId; ?>">Редактировать канал</div>
                                    <div class="tg-channel-modal-text">Токен не показывается. Для замены вставьте новый токен.</div>
                                </div>
                                <button type="button" class="tg-channel-modal-close" data-tg-modal-close><img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true"></button>
                            </div>
                            <form method="POST" class="tg-channel-form">
                                <input type="hidden" name="action" value="tg_bot_update">
                                <input type="hidden" name="return_page" value="bots">
                                <input type="hidden" name="bot_id" value="<?php echo $botId; ?>">
                                <label class="tg-channel-field">
                                    <span>Тип канала</span>
                                    <select disabled>
                                        <option><?php echo $h($channelLabelOf($service)); ?></option>
                                    </select>
                                </label>
                                <label class="tg-channel-field">
                                    <span>Название в системе</span>
                                    <input type="text" name="title" value="<?php echo $h($bot['title'] ?? ''); ?>" required>
                                </label>
                                <?php if ($service === 'vk'): ?>
                                    <label class="tg-channel-field">
                                        <span>Короткий адрес сообщества ВК</span>
                                        <input type="text" name="vk_screen_name" value="<?php echo $h($bot['vk_screen_name'] ?? $bot['bot_username'] ?? ''); ?>" placeholder="Например: execbooster">
                                    </label>
                                    <label class="tg-channel-field">
                                        <span>ID сообщества ВК</span>
                                        <input type="text" name="vk_group_id" value="<?php echo $h($bot['vk_group_id'] ?? ''); ?>" placeholder="Например: 123456789">
                                    </label>
                                    <label class="tg-channel-field">
                                        <span>Ключ доступа сообщества, если уже есть</span>
                                        <input type="password" name="vk_api_token" autocomplete="new-password" placeholder="Оставьте пустым, если ключ не меняется">
                                    </label>
                                    <label class="tg-channel-field">
                                        <span>Строка подтверждения сервера ВК</span>
                                        <input type="text" name="vk_confirmation_code" value="<?php echo $h($bot['vk_confirmation_code'] ?? ''); ?>" placeholder="Позже понадобится для Callback API">
                                    </label>
                                    <label class="tg-channel-field">
                                        <span>Секретный ключ Callback API</span>
                                        <input type="password" name="vk_secret_key" placeholder="Оставьте пустым, если ключ не меняется">
                                    </label>
                                <?php else: ?>
                                    <label class="tg-channel-field">
                                        <span>Новый токен Telegram Bot API, если нужно заменить</span>
                                        <input type="password" name="bot_token" autocomplete="new-password" placeholder="Оставьте пустым, если токен не меняется">
                                    </label>
                                <?php endif; ?>
                                <label class="tg-channel-field">
                                    <span>Стартовое сообщение</span>
                                    <textarea name="welcome_text" rows="5"><?php echo $h($bot['welcome_text'] ?? ''); ?></textarea>
                                </label>
                                <?php if (!empty($bot['webhook_url'])): ?>
                                    <label class="tg-channel-field">
                                        <span>Webhook URL</span>
                                        <input type="text" value="<?php echo $h($bot['webhook_url']); ?>" readonly>
                                    </label>
                                <?php endif; ?>
                                <?php if (!empty($bot['last_error'])): ?>
                                    <div class="rounded-2xl bg-red-50 border border-red-100 p-4 text-sm font-semibold text-red-700"><?php echo $h($bot['last_error']); ?></div>
                                <?php endif; ?>
                                <div class="tg-channel-modal-actions">
                                    <button type="button" class="tg-channel-secondary" data-tg-modal-close>Отмена</button>
                                    <button type="submit" class="tg-channel-primary"><img src="/assets/admin/icons/tb2-save-white.svg" alt="" aria-hidden="true">Сохранить</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($canManage): ?>
        <div class="tg-channel-modal-backdrop" id="tg-channel-add-modal" aria-hidden="true">
            <div class="tg-channel-modal" role="dialog" aria-modal="true" aria-labelledby="tg-channel-add-title">
                <div class="tg-channel-modal-head">
                    <div>
                        <div class="tg-channel-modal-title" id="tg-channel-add-title">Добавить новый канал</div>
                        <div class="tg-channel-modal-text">Выберите Telegram или ВК. Сейчас ВК сохраняется как первичная настройка канала; механику webhook и сценариев подключим отдельным шагом.</div>
                    </div>
                    <button type="button" class="tg-channel-modal-close" data-tg-modal-close><img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true"></button>
                </div>
                <form method="POST" class="tg-channel-form">
                    <input type="hidden" name="action" value="tg_bot_create">
                    <input type="hidden" name="return_page" value="bots">
                    <label class="tg-channel-field">
                        <span>Тип канала</span>
                        <select name="channel_type" data-channel-type-select>
                            <option value="telegram">Telegram</option>
                            <option value="vk">ВК</option>
                        </select>
                    </label>
                    <label class="tg-channel-field">
                        <span>Название в системе</span>
                        <input type="text" name="title" placeholder="Например: Бот для заявок" required>
                    </label>
                    <div class="tg-channel-fields" data-channel-fields="telegram">
                        <label class="tg-channel-field">
                            <span>Токен Telegram Bot API</span>
                            <input type="password" name="bot_token" autocomplete="new-password" placeholder="123456789:AA..." data-telegram-token>
                        </label>
                    </div>
                    <div class="tg-channel-fields" data-channel-fields="vk" hidden>
                        <label class="tg-channel-field">
                            <span>Короткий адрес сообщества ВК</span>
                            <input type="text" name="vk_screen_name" placeholder="Например: execbooster">
                        </label>
                        <label class="tg-channel-field">
                            <span>ID сообщества ВК</span>
                            <input type="text" name="vk_group_id" placeholder="Например: 123456789">
                        </label>
                        <label class="tg-channel-field">
                            <span>Ключ доступа сообщества ВК</span>
                            <input type="password" name="vk_api_token" autocomplete="new-password" placeholder="Можно заполнить позже">
                        </label>
                        <label class="tg-channel-field">
                            <span>Строка подтверждения сервера ВК</span>
                            <input type="text" name="vk_confirmation_code" placeholder="Позже понадобится для Callback API">
                        </label>
                        <label class="tg-channel-field">
                            <span>Секретный ключ Callback API</span>
                            <input type="password" name="vk_secret_key" placeholder="Можно заполнить позже">
                        </label>
                        <div class="tg-channel-note">Пока ВК-канал только сохраняется в списке. Приём сообщений, webhook и запуск сценариев подключим отдельным безопасным шагом.</div>
                    </div>
                    <label class="tg-channel-field">
                        <span>Стартовое сообщение</span>
                        <textarea name="welcome_text" rows="4">Здравствуйте! Бот подключён и готов к работе.</textarea>
                    </label>
                    <div class="tg-channel-modal-actions">
                        <button type="button" class="tg-channel-secondary" data-tg-modal-close>Отмена</button>
                        <button type="submit" class="tg-channel-primary"><img src="/assets/admin/icons/tb2-save-white.svg" alt="" aria-hidden="true">Сохранить канал</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
    (function(){
        const root = document.querySelector('.tg-module');
        if (!root || root.dataset.channelUiReady === '1') return;
        root.dataset.channelUiReady = '1';

        function closeMenus() {
            root.querySelectorAll('.tg-channel-menu.is-open').forEach(menu => menu.classList.remove('is-open'));
        }
        function closeModals() {
            root.querySelectorAll('.tg-channel-modal-backdrop.is-open').forEach(modal => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            });
        }

        function syncChannelFields(scope) {
            const select = scope.querySelector('[data-channel-type-select]');
            if (!select) return;
            const type = select.value === 'vk' ? 'vk' : 'telegram';
            scope.querySelectorAll('[data-channel-fields]').forEach(block => {
                const active = block.getAttribute('data-channel-fields') === type;
                block.hidden = !active;
                block.querySelectorAll('input, textarea, select').forEach(field => {
                    field.disabled = !active;
                });
            });
            const telegramToken = scope.querySelector('[data-telegram-token]');
            if (telegramToken) telegramToken.required = type === 'telegram';
        }

        root.querySelectorAll('.tg-channel-form').forEach(syncChannelFields);
        root.addEventListener('change', function(event) {
            if (event.target.matches('[data-channel-type-select]')) {
                const form = event.target.closest('.tg-channel-form');
                if (form) syncChannelFields(form);
            }
        });

        root.addEventListener('click', function(event) {
            const addCommand = event.target.closest('[data-command-add]');
            if (addCommand) {
                event.preventDefault();
                const form = addCommand.closest('.tg-command-form');
                const list = form ? form.querySelector('[data-command-list]') : null;
                const tpl = form ? form.querySelector('template[data-command-template]') : null;
                if (list && tpl && tpl.content) {
                    list.appendChild(tpl.content.cloneNode(true));
                }
                return;
            }

            const deleteCommand = event.target.closest('[data-command-delete]');
            if (deleteCommand) {
                event.preventDefault();
                const row = deleteCommand.closest('[data-command-row]');
                const list = row ? row.closest('[data-command-list]') : null;
                if (row && list && list.querySelectorAll('[data-command-row]').length > 1) {
                    row.remove();
                } else if (row) {
                    row.querySelectorAll('input').forEach(input => input.value = '');
                }
                return;
            }

            const menuButton = event.target.closest('[data-tg-menu-toggle]');
            if (menuButton) {
                event.preventDefault();
                const id = menuButton.getAttribute('data-tg-menu-toggle');
                const menu = document.getElementById(id);
                const wasOpen = menu && menu.classList.contains('is-open');
                closeMenus();
                if (menu && !wasOpen) menu.classList.add('is-open');
                return;
            }

            const modalButton = event.target.closest('[data-tg-modal-open]');
            if (modalButton) {
                event.preventDefault();
                closeMenus();
                const modal = document.getElementById(modalButton.getAttribute('data-tg-modal-open'));
                if (modal) {
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                }
                return;
            }

            if (event.target.closest('[data-tg-modal-close]')) {
                event.preventDefault();
                closeModals();
                return;
            }

            if (event.target.classList.contains('tg-channel-modal-backdrop')) {
                closeModals();
                return;
            }

            if (!event.target.closest('.tg-channel-menu-wrap')) closeMenus();
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeMenus();
                closeModals();
            }
        });
    })();
    </script>

<?php elseif ($page === 'subscriber'):
    $subscriberStatusLabels = [
        'active' => 'Активен',
        'inactive' => 'Неактивен',
        'unsubscribed' => 'Отписан',
        'blocked' => 'Заблокирован',
    ];
    $subscriberId = (int)($_GET['subscriber_id'] ?? 0);
    $subscriber = $subscriberId > 0 ? asr_tg_subscriber_find($pdo, $subscriberId, $selectedBotId) : null;
    if (!$subscriber) { ?>
        <section class="bg-white rounded-3xl border border-gray-100 p-8"><h3 class="text-xl font-semibold text-gray-800">Подписчик не найден</h3><p class="mt-2 text-sm font-semibold text-gray-500">Вернитесь к списку подписчиков и выберите контакт ещё раз.</p><a class="mt-5 inline-flex rounded-2xl px-5 py-3 bg-gray-100 text-gray-700 font-bold" href="admin.php?tab=telegram_bots&page=subscribers&bot_id=<?php echo (int)$selectedBotId; ?>">К подписчикам</a></section>
    <?php } else {
        $subscriberTags = asr_tg_subscriber_tags_map($pdo, (int)$subscriber['bot_id'], [$subscriberId]);
        $rowTags = $subscriberTags[$subscriberId] ?? [];
        $allContactTags = asr_tg_tags_all($pdo, (int)$subscriber['bot_id']);
        $messages = asr_tg_dialog_messages($pdo, $subscriberId, 200);
        $status = (string)($subscriber['status'] ?? 'inactive');
        $statusLabel = $subscriberStatusLabels[$status] ?? $status;
        $name = trim((string)($subscriber['first_name'] ?? '') . ' ' . (string)($subscriber['last_name'] ?? ''));
        $displayName = $name !== '' ? $name : ('ID ' . (string)($subscriber['telegram_user_id'] ?? $subscriberId));
        $channelType = strtolower((string)($subscriber['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram';
        $channelLabel = $channelType === 'vk' ? 'ВК' : 'Telegram';
        $channelIcon = $channelType === 'vk' ? '/assets/admin/icons/tb2-vk-gray.svg' : '/assets/admin/icons/tb2-telegram-gray.svg';
        $backUrl = 'admin.php?tab=telegram_bots&page=subscribers&bot_id=' . (int)$selectedBotId;
        $utmRows = ['utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign', 'utm_content' => 'utm_content', 'utm_term' => 'utm_term'];
        $customFieldsActive = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, true) : [];
        $customValuesMap = function_exists('asr_tg_subscriber_custom_values_get') ? asr_tg_subscriber_custom_values_get($pdo, $subscriberId) : [];
    ?>
    <style>
    .tg-contact-page{background:#fff;border:1px solid #edf0f2;border-radius:24px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.04)}
    .tg-contact-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #edf0f2}.tg-contact-person{display:flex;align-items:center;gap:12px;min-width:0}.tg-contact-avatar{width:46px;height:46px;border-radius:16px;background:#fff7ed;display:inline-flex;align-items:center;justify-content:center;position:relative;flex:0 0 46px}.tg-contact-avatar img{width:22px;height:22px}.tg-contact-name{font-size:21px;font-weight:500;color:#20272d;line-height:1.15}.tg-contact-sub{font-size:13px;font-weight:500;color:#0073e6;margin-top:4px}.tg-contact-delete{border:1px solid #fed7aa;background:#fff;color:#c2410c;border-radius:14px;padding:12px 18px;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}.tg-contact-shell{display:grid;grid-template-columns:minmax(320px,390px) minmax(0,1fr);min-height:680px}.tg-contact-side{border-right:1px solid #edf0f2;max-height:calc(100vh - 250px);overflow:auto;background:#fff}.tg-contact-chat{display:flex;flex-direction:column;min-height:680px;background:#fff}.tg-info-list{padding:14px}.tg-info-row{display:grid;grid-template-columns:135px minmax(0,1fr);gap:10px;align-items:center;background:#f8fafc;border-radius:12px;padding:11px 12px;margin-bottom:8px;font-size:13px}.tg-info-row span:first-child{font-weight:500;color:#6b7280}.tg-info-row span:last-child{font-weight:500;color:#1f2937;text-align:right;word-break:break-word}.tg-info-section{padding:18px 14px 8px;font-size:13px;font-weight:600;color:#111827}.tg-info-form{padding:0 14px 16px;display:flex;flex-direction:column;gap:10px}.tg-info-form label span{display:block;font-size:11px;font-weight:600;color:#9ca3af;margin-bottom:5px}.tg-info-form input,.tg-info-form textarea,.tg-info-form select{width:100%;border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:11px 12px;font-size:13px;font-weight:650;color:#374151}.tg-info-form textarea{min-height:82px;resize:vertical}.tg-info-save{border:0;background:#FFA048;color:#fff;border-radius:14px;padding:12px 14px;font-size:12px;font-weight:600}.tg-tag-list{display:flex;flex-wrap:wrap;gap:7px}.tg-contact-tag{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border-radius:999px;padding:7px 9px;font-size:12px;font-weight:600;color:#374151}.tg-contact-tag i{width:8px;height:8px;border-radius:99px;display:inline-block}.tg-chat-day{text-align:center;font-size:12px;font-weight:500;color:#9ca3af;margin:18px 0 8px}.tg-chat-feed{flex:1;padding:20px;overflow:auto}.tg-chat-bubble{max-width:min(620px,75%);border-radius:16px;padding:11px 13px;margin:8px 0;font-size:14px;font-weight:600;line-height:1.45;white-space:pre-wrap;word-break:break-word}.tg-chat-bubble.in{background:#f3f4f6;color:#1f2937;margin-right:auto}.tg-chat-bubble.out{background:#fff7ed;color:#7c2d12;margin-left:auto;border:1px solid #fed7aa}.tg-chat-meta{display:block;margin-top:4px;font-size:11px;color:#9ca3af;font-weight:500}.tg-chat-system-note{max-width:82%;margin:18px auto;text-align:center;color:#a1a1aa;font-size:13px;font-weight:500;line-height:1.45}.tg-chat-system-note small{display:inline-block;margin-left:7px;color:#c4c7cc;font-size:11px;font-weight:600}.tg-chat-broadcast{max-width:min(620px,82%);margin:14px auto;border:1px solid #fed7aa;background:#fff7ed;border-radius:18px;padding:12px 14px;color:#7c2d12}.tg-chat-broadcast-title{font-size:12px;font-weight:700;color:#c2410c;margin-bottom:6px}.tg-chat-broadcast-text{font-size:13px;font-weight:500;line-height:1.45;white-space:pre-wrap;color:#7c2d12}.tg-chat-broadcast-meta{margin-top:7px;font-size:11px;font-weight:500;color:#c99665}.tg-chat-empty{padding:40px;text-align:center;color:#9ca3af;font-size:14px;font-weight:500}.tg-chat-compose{border-top:1px solid #edf0f2;padding:14px 18px;background:#fff}.tg-chat-compose textarea{width:100%;border:1px solid #e5e7eb;border-radius:18px;padding:14px 16px;min-height:90px;resize:vertical;font-size:14px;font-weight:600}.tg-chat-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:10px}.tg-chat-send{border:0;background:#111827;color:#fff;border-radius:14px;padding:12px 16px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}.tg-chat-send:disabled{background:#e5e7eb;color:#9ca3af}.tg-contact-back{display:inline-flex;margin:0 0 14px;color:#0073e6;font-size:13px;font-weight:600;text-decoration:none}.tg-readonly-note{font-size:12px;font-weight:650;color:#9ca3af;line-height:1.45}.tg-contact-muted{color:#9ca3af}.tg-contact-danger-form{margin:0}
    .tg-contact-page{min-height:calc(100vh - 145px)}
    .tg-contact-shell{min-height:calc(100vh - 230px)}
    .tg-contact-side{border-right:1px solid #dfe4e8;max-height:none;height:calc(100vh - 230px);overflow:auto;align-self:stretch}
    .tg-contact-chat{height:calc(100vh - 230px);min-height:560px;display:flex;flex-direction:column}
    .tg-chat-feed{flex:1;min-height:0;overflow:auto}
    .tg-chat-compose{position:sticky;bottom:0;z-index:5;box-shadow:0 -8px 18px rgba(15,23,42,.035)}
    .tg-contact-tag-remove{border:0;background:rgba(255,255,255,.62);color:#6b7280;border-radius:999px;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;line-height:1}
    .tg-contact-tag-remove:hover{background:#fee2e2;color:#dc2626}.tg-chat-tool-row{display:flex;align-items:center;gap:8px}.tg-chat-tool{height:38px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:12px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;padding:0 12px;white-space:nowrap}.tg-chat-tool:hover{background:#fff7ed;color:#e98222}.tg-chat-tool--icon{width:46px;min-width:46px;padding:0;border-radius:14px;font-size:0}.tg-chat-tool-glyph{display:inline-flex;align-items:center;justify-content:center;font-size:24px;line-height:1;color:inherit;opacity:.78}.tg-chat-tool--icon:hover .tg-chat-tool-glyph{opacity:1}.tg-chat-tool:disabled .tg-chat-tool-glyph{opacity:.35}.tg-chat-tools{position:relative}.tg-chat-emoji-menu,.tg-chat-macro-menu{position:absolute;left:0;bottom:46px;z-index:25;width:360px;max-width:calc(100vw - 36px);max-height:310px;overflow:auto;background:#fff;border:1px solid #edf0f2;border-radius:16px;box-shadow:0 18px 40px rgba(15,23,42,.18);padding:10px;display:none}.tg-chat-emoji-menu.is-open,.tg-chat-macro-menu.is-open{display:block}.tg-chat-emoji-section{margin:6px 2px 12px}.tg-chat-emoji-title{font-size:11px;font-weight:600;color:#9ca3af;margin:6px 4px}.tg-chat-emoji-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:4px}.tg-chat-emoji-grid button{border:0;background:#fff;border-radius:9px;padding:7px;font-size:20px;line-height:1.1}.tg-chat-emoji-grid button:hover{background:#f3f4f6}.tg-chat-macro-search{width:100%;border:0;background:#f7f7f8;border-radius:10px;padding:11px 12px;font-size:12px;font-weight:600;margin-bottom:8px}.tg-chat-macro-group{padding:8px 9px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af}.tg-chat-macro-item{display:grid!important;grid-template-columns:34px 1fr auto;align-items:center;gap:8px;width:100%;padding:10px;border-radius:10px;text-align:left;color:#374151;background:#fff}.tg-chat-macro-item:hover{background:#f3f4f6}.tg-chat-macro-token{color:#9ca3af;font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis}.tg-chat-file-name{font-size:12px;font-weight:600;color:#6b7280;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-chat-attachment{display:flex;align-items:center;gap:8px;margin-top:8px;border:1px solid #fed7aa;background:#fff7ed;border-radius:12px;padding:8px 10px;color:#9a3412;font-size:12px;font-weight:600}.tg-chat-attachment a{color:#c2410c;text-decoration:none}.tg-chat-attachment a:hover{text-decoration:underline}.tg-chat-attachment small{color:#9ca3af;font-size:11px;font-weight:600}.tg-custom-values-form{background:#fffaf5;border:1px solid #ffedd5;border-radius:18px;margin:0 14px 16px;padding:14px}.tg-custom-values-empty{padding:0 14px 16px;color:#9ca3af;font-size:13px;font-weight:500;line-height:1.45}.tg-custom-values-form label span small{font-weight:500;color:#cbd0d6;text-transform:none;letter-spacing:0;margin-left:4px}.tg-tag-actions{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.tg-tag-actions .tg-info-save{white-space:nowrap}.tg-tag-combo{position:relative}.tg-tag-combo-panel{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #edf0f2;border-radius:16px;box-shadow:0 18px 46px rgba(15,23,42,.16);padding:8px;z-index:30;max-height:260px;overflow:auto}.tg-tag-combo.is-open .tg-tag-combo-panel{display:block}.tg-tag-option{width:100%;border:0;background:#fff;border-radius:12px;padding:10px 12px;text-align:left;justify-content:flex-start;color:#374151;font-size:13px;font-weight:600}.tg-tag-option:hover{background:#f9fafb}.tg-tag-new-hint{border-top:1px solid #edf0f2;margin-top:6px;padding:10px 12px 6px;color:#e98222;font-size:12px;font-weight:600}.tg-tag-new-note{display:block;margin-top:4px;color:#9ca3af;font-size:11px;font-weight:500;line-height:1.35}
    @media(max-width:920px){.tg-contact-page,.tg-contact-shell,.tg-contact-side,.tg-contact-chat{height:auto;min-height:0}.tg-contact-shell{grid-template-columns:1fr}.tg-contact-side{max-height:none;border-right:0;border-bottom:1px solid #edf0f2}.tg-contact-chat{min-height:540px}.tg-contact-head{align-items:flex-start;flex-direction:column}.tg-contact-delete{width:100%}.tg-chat-bubble{max-width:92%}}
    </style>
    <a class="tg-contact-back" href="<?php echo $h($backUrl); ?>">← К списку подписчиков</a>
    <section class="tg-contact-page">
        <div class="tg-contact-head">
            <div class="tg-contact-person">
                <span class="tg-contact-avatar"><img src="<?php echo $h($channelIcon); ?>" alt="" aria-hidden="true"></span>
                <div class="min-w-0"><div class="tg-contact-name"><?php echo $h($displayName); ?></div><div class="tg-contact-sub"><?php echo !empty($subscriber['username']) ? '@' . $h($subscriber['username']) : 'username не указан'; ?> · <?php echo $h($channelLabel); ?></div></div>
            </div>
            <form method="POST" class="tg-contact-danger-form" onsubmit="return confirm('Удалить подписчика из базы? Если он снова напишет боту, запись создастся заново.');"><input type="hidden" name="action" value="tg_subscriber_delete"><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>"><button class="tg-contact-delete">Удалить</button></form>
        </div>
        <div class="tg-contact-shell">
            <aside class="tg-contact-side">
                <div class="tg-info-list">
                    <div class="tg-info-row"><span>Статус</span><span><?php echo $h($statusLabel); ?></span></div>
                    <div class="tg-info-row"><span>User ID</span><span><?php echo $h($subscriber['telegram_user_id'] ?? ''); ?></span></div>
                    <div class="tg-info-row"><span>Chat ID</span><span><?php echo $h($subscriber['chat_id'] ?? ''); ?></span></div>
                    <div class="tg-info-row"><span>Аккаунт</span><span><?php echo !empty($subscriber['username']) ? $h($subscriber['username']) : 'не указан'; ?></span></div>
                    <div class="tg-info-row"><span>Подписался</span><span><?php echo $h($subscriber['first_seen_at'] ?? ''); ?></span></div>
                    <div class="tg-info-row"><span>Посл. контакт</span><span><?php echo $h($subscriber['last_seen_at'] ?? ''); ?></span></div>
                </div>
                <div class="tg-info-section">Основная информация</div>
                <form method="POST" class="tg-info-form">
                    <input type="hidden" name="action" value="tg_subscriber_profile_save"><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>">
                    <label><span>Имя</span><input name="first_name" value="<?php echo $h($subscriber['first_name'] ?? ''); ?>" placeholder="Имя"></label>
                    <label><span>Фамилия</span><input name="last_name" value="<?php echo $h($subscriber['last_name'] ?? ''); ?>" placeholder="Фамилия"></label>
                    <label><span>Телефон</span><input name="phone" value="<?php echo $h($subscriber['phone'] ?? ''); ?>" placeholder="Добавить телефон"></label>
                    <label><span>Email</span><input name="email" type="email" value="<?php echo $h($subscriber['email'] ?? ''); ?>" placeholder="Добавить эл. почту"></label>
                    <label><span>Статус</span><select name="status"><?php foreach ($subscriberStatusLabels as $statusKey => $label): ?><option value="<?php echo $h($statusKey); ?>" <?php echo $status === $statusKey ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select></label>
                    <label><span>Заметка администратора</span><textarea name="admin_note" placeholder="Внутренняя заметка по контакту"><?php echo $h($subscriber['admin_note'] ?? ''); ?></textarea></label>
                    <button class="tg-info-save">Сохранить информацию</button>
                </form>
                <div class="tg-info-section">Настраиваемые поля</div>
                <?php if ($customFieldsActive): ?>
                    <form method="POST" class="tg-info-form tg-custom-values-form">
                        <input type="hidden" name="action" value="tg_subscriber_custom_values_save"><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>">
                        <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                        <?php foreach ($customFieldsActive as $customField): ?>
                            <?php
                                $customFieldId = (int)($customField['id'] ?? 0);
                                $customFieldType = (string)($customField['field_type'] ?? 'text');
                                if (!in_array($customFieldType, ['text','number','date','datetime'], true)) $customFieldType = 'text';
                                $customInputValue = function_exists('asr_tg_custom_value_for_input') ? asr_tg_custom_value_for_input($customField, $customValuesMap) : '';
                                $customInputName = 'custom_values[' . $customFieldId . ']';
                            ?>
                            <label><span><?php echo $h((string)($customField['title'] ?? 'Поле')); ?> <small><?php echo $h($customFieldType); ?></small></span>
                                <?php if ($customFieldType === 'text'): ?>
                                    <input name="<?php echo $h($customInputName); ?>" value="<?php echo $h($customInputValue); ?>" placeholder="Заполнить значение">
                                <?php elseif ($customFieldType === 'number'): ?>
                                    <input name="<?php echo $h($customInputName); ?>" type="number" step="any" value="<?php echo $h($customInputValue); ?>" placeholder="0">
                                <?php elseif ($customFieldType === 'date'): ?>
                                    <input name="<?php echo $h($customInputName); ?>" type="date" value="<?php echo $h($customInputValue); ?>">
                                <?php else: ?>
                                    <input name="<?php echo $h($customInputName); ?>" type="datetime-local" value="<?php echo $h($customInputValue); ?>">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <button class="tg-info-save">Сохранить поля</button>
                    </form>
                <?php else: ?>
                    <div class="tg-custom-values-empty">Настраиваемых полей пока нет. Создайте их на странице подписчиков через кнопку «Настраиваемые поля».</div>
                <?php endif; ?>
                <div class="tg-info-section">Теги</div>
                <div class="tg-info-form">
                    <div class="tg-tag-list">
                        <?php foreach ($rowTags as $tag): ?>
                            <span class="tg-contact-tag"><i style="background:<?php echo $h($tag['color']); ?>"></i><?php echo $h($tag['name']); ?><form method="POST" style="display:inline;margin:0"><input type="hidden" name="action" value="tg_subscriber_remove_tag"><input type="hidden" name="bot_id" value="<?php echo (int)$subscriber['bot_id']; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>"><input type="hidden" name="tag_id" value="<?php echo (int)$tag['id']; ?>"><input type="hidden" name="return_page" value="subscriber"><button class="tg-contact-tag-remove" title="Снять тег">×</button></form></span>
                        <?php endforeach; ?>
                        <?php if (!$rowTags): ?><span class="tg-contact-muted">Тегов нет</span><?php endif; ?>
                    </div>
                    <form method="POST" class="tg-tag-actions" data-tag-combo-form>
                        <input type="hidden" name="action" value="tg_subscriber_tag_add_or_create"><input type="hidden" name="bot_id" value="<?php echo (int)$subscriber['bot_id']; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>"><input type="hidden" name="return_page" value="subscriber">
                        <label class="tg-tag-combo" data-tag-combo><span>Тег</span><input name="tag_name" autocomplete="off" placeholder="Выберите или введите новый тег" data-tag-combo-input required><div class="tg-tag-combo-panel" data-tag-combo-panel><?php foreach ($allContactTags as $tag): ?><button type="button" class="tg-tag-option" data-tag-name="<?php echo $h($tag['name']); ?>"><?php echo $h($tag['name']); ?></button><?php endforeach; ?><div class="tg-tag-new-hint">+ добавить<span class="tg-tag-new-note">Введите название нового тега в поле выше и нажмите Enter.</span></div></div></label>
                        <button class="tg-info-save">Добавить</button>
                    </form>
                </div>
                <div class="tg-info-section">Детали</div>
                <div class="tg-info-list">
                    <?php foreach ($utmRows as $key => $label): ?>
                        <div class="tg-info-row"><span><?php echo $h($label); ?></span><span><?php echo trim((string)($subscriber[$key] ?? '')) !== '' ? $h($subscriber[$key]) : 'не указано'; ?></span></div>
                    <?php endforeach; ?>
                </div>
            </aside>
            <main class="tg-contact-chat" id="chat">
                <div class="tg-chat-feed">
                    <?php $lastDay = ''; foreach ($messages as $message): $day = substr((string)$message['created_at'], 0, 10); if ($day !== $lastDay): $lastDay = $day; ?><div class="tg-chat-day"><?php echo $h($day); ?></div><?php endif; $direction = (string)($message['direction'] ?? 'in'); ?>
                        <?php
                            $messagePayload = json_decode((string)($message['payload_json'] ?? ''), true);
                            if (!is_array($messagePayload)) $messagePayload = [];
                            $messageAttachment = is_array($messagePayload['attachment'] ?? null) ? $messagePayload['attachment'] : [];
                            $messageText = function_exists('asr_tg_plain_message_text') ? asr_tg_plain_message_text((string)($message['message_text'] ?? '')) : strip_tags((string)($message['message_text'] ?? ''));
                            $messageType = (string)($message['message_type'] ?? 'сообщение');
                            $isSystemMessage = in_array($messageType, ['system','service','event'], true) || !empty($messagePayload['system_event']) || !empty($messagePayload['dialog_event']);
                            $isBroadcastContext = $messageType === 'broadcast_context' || !empty($messagePayload['broadcast_context']);
                        ?>
                        <?php if ($isBroadcastContext): ?>
                            <div class="tg-chat-broadcast"><div class="tg-chat-broadcast-title">Рассылка: <?php echo $h((string)($messagePayload['title'] ?? 'Рассылка')); ?></div><div class="tg-chat-broadcast-text"><?php echo $h($messageText !== '' ? $messageText : '[рассылка]'); ?></div><div class="tg-chat-broadcast-meta"><?php echo $h($message['created_at'] ?? ''); ?></div></div>
                        <?php elseif ($isSystemMessage): ?>
                            <div class="tg-chat-system-note"><?php echo $h($messageText !== '' ? $messageText : '[' . $messageType . ']'); ?><small><?php echo $h(substr((string)($message['created_at'] ?? ''), 11, 5)); ?></small></div>
                        <?php else: ?>
                            <div class="tg-chat-bubble <?php echo $direction === 'out' ? 'out' : 'in'; ?>"><?php echo $h($messageText !== '' ? $messageText : ($messageAttachment ? '[' . $messageType . ']' : '[' . $messageType . ']')); ?><?php if ($messageAttachment): ?><div class="tg-chat-attachment"><span>📎</span><div><a href="/<?php echo $h(ltrim((string)($messageAttachment['file_path'] ?? ''), '/')); ?>" target="_blank" rel="noopener"><?php echo $h((string)($messageAttachment['file_name'] ?? 'файл')); ?></a><br><small><?php echo $h((string)($messageAttachment['media_type'] ?? 'файл')); ?></small></div></div><?php endif; ?><span class="tg-chat-meta"><?php echo $direction === 'out' ? 'исходящее' : 'входящее'; ?> · <?php echo $h($message['created_at'] ?? ''); ?></span></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$messages): ?><div class="tg-chat-empty">Истории сообщений пока нет.</div><?php endif; ?>
                </div>
                <form method="POST" class="tg-chat-compose" enctype="multipart/form-data" data-chat-compose>
                    <input type="hidden" name="action" value="tg_subscriber_send_message"><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$subscriberId; ?>">
                    <textarea name="message_text" placeholder="Введите сообщение..." data-chat-text <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>></textarea>
                    <input type="file" name="chat_attachment" class="hidden" data-chat-file accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.m4v,.webm,.mp3,.m4a,.ogg,.wav,.pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>>
                    <div class="tg-chat-actions"><div class="tg-chat-tool-row"><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-chat-emoji title="Эмодзи" aria-label="Эмодзи" <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>><span class="tg-chat-tool-glyph" aria-hidden="true">☺</span></button><div class="tg-chat-emoji-menu" data-chat-emoji-menu></div></div><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-chat-macro title="Переменные" aria-label="Переменные" <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>><span class="tg-chat-tool-glyph" aria-hidden="true">{}</span></button><div class="tg-chat-macro-menu" data-chat-macro-menu></div></div><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-chat-attach title="Прикрепить файл" aria-label="Прикрепить файл" <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>><span class="tg-chat-tool-glyph" aria-hidden="true">📎</span></button><span class="tg-chat-file-name" data-chat-file-name></span></div><button class="tg-chat-send" <?php echo $channelType !== 'telegram' ? 'disabled' : ''; ?>>Отправить</button></div>
                    <div class="tg-readonly-note"><?php echo $channelType === 'telegram' ? 'Можно отправить текст, эмодзи и один файл до 45 МБ.' : 'Отправка доступна только для Telegram-каналов.'; ?></div>
                </form>
            </main>
        </div>
    </section>
    <script>
    (function(){
        const form=document.querySelector('[data-chat-compose]');
        if(!form) return;
        const text=form.querySelector('[data-chat-text]');
        const file=form.querySelector('[data-chat-file]');
        const fileName=form.querySelector('[data-chat-file-name]');
        const attachBtn=form.querySelector('[data-chat-attach]');
        const emojiBtn=form.querySelector('[data-chat-emoji]');
        const emojiMenu=form.querySelector('[data-chat-emoji-menu]');
        const macroBtn=form.querySelector('[data-chat-macro]');
        const macroMenu=form.querySelector('[data-chat-macro-menu]');
        const macroCatalog=<?php echo $tgMessageVariablesJson; ?>;
        const groups={
            'Частые':['🙂','😊','😉','😍','👍','🙏','👏','🔥','✅','❌','⚠️','🎯','🚀','💡','📌','📎','⏰','📅','💬','❤️'],
            'Эмоции':['😀','😃','😄','😁','😆','😅','😂','🤣','😇','🙂','🙃','😌','😎','🤔','😐','😬','😔','😢','😡','🤯'],
            'Работа':['📌','📎','📄','📊','📈','📉','🧩','🛠️','⚙️','🔔','📣','✉️','📞','💼','📝','🔗','🔒','🔑','🗂️','📦'],
            'Жесты':['👍','👎','👌','✌️','🤝','👏','🙌','🙏','💪','👀','👉','👈','☝️','👇','✍️','🤌','🫶','👋','🤙','🖐️']
        };
        function esc(s){return String(s).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
        function macroMenuHtml(){
            const grouped={};
            (macroCatalog||[]).forEach(item=>{const group=item.group||'Переменные'; (grouped[group]=grouped[group]||[]).push(item);});
            let html='<input class="tg-chat-macro-search" placeholder="Найти переменную" data-chat-macro-search>';
            Object.keys(grouped).forEach(group=>{
                html+='<div class="tg-chat-macro-group">'+esc(group)+'</div>';
                grouped[group].forEach(item=>{
                    const token=String(item.token||''); if(!token) return;
                    html+='<button type="button" class="tg-chat-macro-item" data-chat-macro-insert="'+esc(token)+'" data-chat-macro-search-text="'+esc((item.title||'')+' '+token+' '+group)+'"><span>'+esc(item.icon||'Т')+'</span><span>'+esc(item.title||token)+'</span><span class="tg-chat-macro-token">'+esc(token)+'</span></button>';
                });
            });
            return html;
        }
    function insertAtCursor(el,value){
            if(!el) return;
            const start=el.selectionStart||0, end=el.selectionEnd||0;
            const before=el.value.slice(0,start), after=el.value.slice(end);
            el.value=before+value+after;
            const pos=start+value.length;
            el.focus(); el.setSelectionRange(pos,pos);
        }
        if(emojiMenu){
            emojiMenu.innerHTML=Object.entries(groups).map(([title,items])=>`<div class="tg-chat-emoji-section"><div class="tg-chat-emoji-title">${esc(title)}</div><div class="tg-chat-emoji-grid">${items.map(e=>`<button type="button" data-chat-emoji-insert="${e}">${e}</button>`).join('')}</div></div>`).join('');
        }
        if(macroMenu) macroMenu.innerHTML=macroMenuHtml();
        emojiBtn?.addEventListener('click',function(e){e.stopPropagation(); macroMenu?.classList.remove('is-open'); emojiMenu?.classList.toggle('is-open');});
        macroBtn?.addEventListener('click',function(e){e.stopPropagation(); emojiMenu?.classList.remove('is-open'); macroMenu?.classList.toggle('is-open');});
        emojiMenu?.addEventListener('click',function(e){
            e.stopPropagation();
            const btn=e.target.closest('[data-chat-emoji-insert]');
            if(!btn) return;
            insertAtCursor(text, btn.dataset.chatEmojiInsert || '');
            emojiMenu.classList.remove('is-open');
        });
        macroMenu?.addEventListener('click',function(e){
            e.stopPropagation();
            const btn=e.target.closest('[data-chat-macro-insert]');
            if(!btn) return;
            insertAtCursor(text, btn.dataset.chatMacroInsert || '');
            macroMenu.classList.remove('is-open');
        });
        macroMenu?.querySelector('[data-chat-macro-search]')?.addEventListener('input',function(e){
            const q=String(e.target.value||'').toLowerCase().trim();
            macroMenu.querySelectorAll('[data-chat-macro-search-text]').forEach(item=>{item.style.display=!q||String(item.dataset.chatMacroSearchText||'').toLowerCase().includes(q)?'grid':'none';});
        });
        attachBtn?.addEventListener('click',()=>file?.click());
        file?.addEventListener('change',function(){
            const f=file.files&&file.files[0]?file.files[0]:null;
            if(!fileName) return;
            if(!f){fileName.textContent=''; return;}
            const mb=(f.size/1024/1024).toFixed(f.size>1024*1024?1:2);
            fileName.textContent=f.name+' · '+mb+' МБ';
        });
        document.addEventListener('click',function(e){ if(!e.target.closest('.tg-chat-tools')){emojiMenu?.classList.remove('is-open');macroMenu?.classList.remove('is-open');} });
        form.addEventListener('submit',function(e){
            const hasText=(text?.value||'').trim()!=='';
            const hasFile=!!(file?.files&&file.files.length);
            if(!hasText && !hasFile){e.preventDefault(); text?.focus();}
        });
    })();
    </script>
    <?php } ?>

<?php elseif ($page === 'messages'):
    $dialogView = (string)($_GET['dialog_view'] ?? 'new');
    $allowedDialogViews = ['new','my','assigned','closed','spam'];
    if (!in_array($dialogView, $allowedDialogViews, true)) $dialogView = 'my';
    $dialogQ = trim((string)($_GET['dialog_q'] ?? ''));
    $dialogUnreadOnly = (int)($_GET['dialog_unread'] ?? 0) === 1;
    $dialogChannels = $_GET['dialog_channel'] ?? [];
    if (!is_array($dialogChannels)) $dialogChannels = [$dialogChannels];
    $dialogChannels = array_values(array_unique(array_filter(array_map('intval', $dialogChannels), static fn($v) => $v > 0)));
    $dialogTagIds = $_GET['dialog_tag'] ?? [];
    if (!is_array($dialogTagIds)) $dialogTagIds = [$dialogTagIds];
    $dialogTagIds = array_values(array_unique(array_filter(array_map('intval', $dialogTagIds), static fn($v) => $v > 0)));
    $dialogFilters = [
        'q' => $dialogQ,
        'view' => $dialogView,
        'unread_only' => $dialogUnreadOnly,
        'channel_ids' => $dialogChannels,
        'tag_ids' => $dialogTagIds,
    ];
    $dialogListBotId = 0; // Раздел «Диалоги» всегда показывает все каналы; bot_id в URL нужен только для открытия конкретной карточки.
    $dialogCounts = asr_tg_dialogs_counts($pdo, $dialogListBotId, $dialogFilters);
    $dialogRows = asr_tg_dialogs_recent($pdo, $dialogListBotId, 80, $dialogFilters);
    $dialogSettings = asr_tg_dialog_settings_get($pdo, $selectedBotId);
    $dialogAutoCloseEnabled = !empty($dialogSettings['auto_close_incoming']);
    $dialogAutoReplyEnabled = !empty($dialogSettings['auto_reply_enabled']);
    $dialogFilterIsOpen = $dialogUnreadOnly || $dialogChannels || $dialogTagIds;
    $dialogAllTags = asr_tg_tags_all($pdo, $selectedBotId);
    $dialogAssignableUsers = asr_tg_dialog_assignable_users($pdo);
    if (!$dialogAssignableUsers) {
        try {
            $assignSql = "SELECT id, full_name, username, role, is_active, archived_at, connect_to_dialogs, telegram_username FROM `oca_users` WHERE `connect_to_dialogs` = 1 ORDER BY id ASC";
            $assignStmt = $pdo->query($assignSql);
            $dialogAssignableUsers = $assignStmt ? $assignStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $dialogAssignableUsers = [];
        }
    }
    $selectedDialogSubscriberId = (int)($_GET['subscriber_id'] ?? 0);
    $dialogKeepUnread = (int)($_GET['no_auto_read'] ?? 0) === 1;
    if ($selectedDialogSubscriberId <= 0 && $dialogRows) {
        $selectedDialogSubscriberId = (int)$dialogRows[0]['subscriber_id'];
        $selectedBotId = (int)($dialogRows[0]['bot_id'] ?? $selectedBotId);
    }
    $dialogSubscriber = null;
    if ($selectedDialogSubscriberId > 0) {
        // В ссылках диалогов bot_id иногда остаётся от фильтра/предыдущего канала.
        // А в старых AJAX-ссылках в параметр subscriber_id мог попасть id диалога или устаревший id.
        // Поэтому сначала открываем подписчика по id, затем fallback-ом разрешаем id диалога/последнее входящее.
        if (function_exists('asr_tg_dialog_resolve_subscriber')) {
            $dialogSubscriber = asr_tg_dialog_resolve_subscriber($pdo, $selectedDialogSubscriberId, $selectedBotId);
        } else {
            $dialogSubscriber = asr_tg_subscriber_find($pdo, $selectedDialogSubscriberId, $selectedBotId);
            if (!$dialogSubscriber) $dialogSubscriber = asr_tg_subscriber_find($pdo, $selectedDialogSubscriberId, 0);
        }
        if (!$dialogSubscriber && function_exists('asr_tg_dialog_minimal_subscriber_from_messages')) {
            // Последний безопасный fallback: если ссылка/URL уже несёт subscriber_id,
            // по которому сообщения в диалоге есть, но запись подписчика не находится,
            // всё равно открываем ленту сообщений и контекст рассылки по этой связке.
            $dialogSubscriber = asr_tg_dialog_minimal_subscriber_from_messages($pdo, $selectedDialogSubscriberId, $selectedBotId);
        }
        if ($dialogSubscriber) {
            $selectedBotId = (int)($dialogSubscriber['bot_id'] ?? $selectedBotId);
            $selectedDialogSubscriberId = (int)($dialogSubscriber['id'] ?? $selectedDialogSubscriberId);
        }
    }
    // Открытие диалога больше не снимает индикатор внимания.
    // Красный сигнал держится до исходящего ответа сотрудника или системы.
    $dialogMessages = $dialogSubscriber ? asr_tg_dialog_messages($pdo, (int)$dialogSubscriber['id'], 200, (int)($dialogSubscriber['bot_id'] ?? 0)) : [];
$dialogBroadcastDiag = null;
    $dialogTagsMap = $dialogSubscriber ? asr_tg_subscriber_tags_map($pdo, (int)$dialogSubscriber['bot_id'], [(int)$dialogSubscriber['id']]) : [];
    $dialogTags = $dialogSubscriber ? ($dialogTagsMap[(int)$dialogSubscriber['id']] ?? []) : [];
    $dialogChannelMemberships = $dialogSubscriber ? asr_tg_subscriber_channel_memberships($pdo, $dialogSubscriber) : [];
    $dialogViewLabels = ['new' => 'Новые', 'my' => 'Мои', 'assigned' => 'Назначенные', 'closed' => 'Закрытые', 'spam' => 'Спам'];
    $dialogCountKeys = ['new' => 'new', 'my' => 'my', 'assigned' => 'assigned', 'closed' => 'closed', 'spam' => 'spam'];
    $dialogStatusLabels = ['new' => 'Новый', 'mine' => 'Мой', 'assigned' => 'Назначен', 'closed' => 'Закрыт', 'spam' => 'Спам'];
    $dialogSubscriberStatusLabels = ['active' => 'Активен', 'inactive' => 'Неактивен', 'unsubscribed' => 'Отписан', 'blocked' => 'Заблокирован'];
    $dialogUtmRows = ['utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign', 'utm_content' => 'utm_content', 'utm_term' => 'utm_term'];
    $dialogCustomFieldsActive = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, true) : [];
    $dialogCustomValuesMap = ($dialogSubscriber && function_exists('asr_tg_subscriber_custom_values_get')) ? asr_tg_subscriber_custom_values_get($pdo, (int)$dialogSubscriber['id']) : [];
    $dialogUrlBase = 'admin.php?tab=telegram_bots&page=messages';
    if ($dialogQ !== '') $dialogUrlBase .= '&dialog_q=' . rawurlencode($dialogQ);
    if ($dialogUnreadOnly) $dialogUrlBase .= '&dialog_unread=1';
    foreach ($dialogChannels as $channelId) $dialogUrlBase .= '&dialog_channel[]=' . (int)$channelId;
    foreach ($dialogTagIds as $tagId) $dialogUrlBase .= '&dialog_tag[]=' . (int)$tagId;
    $dialogResetUrl = 'admin.php?tab=telegram_bots&page=messages&dialog_view=' . rawurlencode($dialogView);
    if ($dialogQ !== '') $dialogResetUrl .= '&dialog_q=' . rawurlencode($dialogQ);
    $dialogChannelIcon = static function(array $row): string {
        $type = strtolower((string)($row['channel_type'] ?? 'telegram'));
        return $type === 'vk' ? '/assets/admin/icons/tb2-vk-gray.svg' : '/assets/admin/icons/tb2-telegram-gray.svg';
    };
    $dialogName = static function(array $row) use ($h): string {
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($name !== '') return $name;
        if (!empty($row['username'])) return '@' . (string)$row['username'];
        return 'Подписчик #' . (int)($row['subscriber_id'] ?? $row['id'] ?? 0);
    };
    $dialogUserName = static function(array $row): string {
        $name = trim((string)($row['full_name'] ?? $row['assigned_full_name'] ?? ''));
        if ($name !== '') return $name;
        $name = trim((string)($row['name'] ?? $row['assigned_name'] ?? ''));
        if ($name !== '') return $name;
        $firstName = trim((string)($row['first_name'] ?? $row['assigned_first_name'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? $row['assigned_last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        if ($name !== '') return $name;
        $username = trim((string)($row['username'] ?? $row['assigned_username'] ?? $row['telegram_username'] ?? $row['assigned_telegram_username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $email = trim((string)($row['email'] ?? $row['assigned_email'] ?? ''));
        if ($email !== '') return $email;
        $id = (int)($row['id'] ?? $row['assigned_user_id'] ?? 0);
        return $id > 0 ? 'Сотрудник #' . $id : '';
    };
    $dialogAssigneeNamesById = [];
    foreach ($dialogAssignableUsers as $dialogAssignableUser) {
        $dialogAssignableUserId = (int)($dialogAssignableUser['id'] ?? 0);
        if ($dialogAssignableUserId <= 0) continue;
        $dialogAssignableUserLabel = $dialogUserName($dialogAssignableUser);
        if ($dialogAssignableUserLabel !== '' && !preg_match('/^Сотрудник\s*[#№]?\s*\d+$/u', $dialogAssignableUserLabel)) {
            $dialogAssigneeNamesById[$dialogAssignableUserId] = $dialogAssignableUserLabel;
        }
    }
    $dialogAssigneeLabel = static function(array $row) use ($dialogUserName, $dialogAssigneeNamesById): string {
        $id = (int)($row['assigned_user_id'] ?? $row['id'] ?? 0);
        $label = function_exists('asr_tg_dialog_assignee_label') ? asr_tg_dialog_assignee_label($row) : $dialogUserName($row);
        $isTechnical = $label === '' || preg_match('/^Сотрудник\s*[#№]?\s*\d+$/u', $label);
        if ($id > 0 && $isTechnical && isset($dialogAssigneeNamesById[$id])) return $dialogAssigneeNamesById[$id];
        return $label;
    };
?>
    <style>
        .tg-dialogs-shell{background:#fff;border:1px solid #eef0f4;border-radius:28px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.04)}
        .tg-dialogs-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid #eef0f4;background:#fff}
        .tg-dialogs-title{display:flex;align-items:center;gap:12px;min-width:0}.tg-dialogs-title img{width:24px;height:24px;flex:0 0 24px}.tg-dialogs-title h3{margin:0;font-size:20px;font-weight:650;color:#1f2937}.tg-dialogs-title p{margin:4px 0 0;font-size:12px;font-weight:650;color:#9ca3af}
        .tg-dialog-tabs{display:flex;align-items:center;gap:8px;overflow:auto;padding:14px 18px;border-bottom:1px solid #eef0f4;background:#fbfbfc}.tg-dialog-mode-icons{display:inline-flex;align-items:center;gap:8px;margin-left:2px;flex:0 0 auto}.tg-dialog-mode-icon{width:38px;height:38px;border:1px solid #edf0f2;background:#fff;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 22px rgba(15,23,42,.035)}.tg-dialog-mode-icon img{width:19px;height:19px;display:block;object-fit:contain}.tg-dialog-mode-icon--close{background:#fff7ed;border-color:#ffedd5}.tg-dialog-mode-icon--auto{background:#f4f4f5;border-color:#eceef2}.tg-dialog-tab{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;border-radius:16px;border:1px solid #edf0f2;background:#fff;color:#6b7280;padding:10px 15px;font-size:13px;font-weight:600;white-space:nowrap;text-decoration:none}.tg-dialog-tab:hover{background:#fff7ed;color:#e98222}.tg-dialog-tab.is-active{background:#fff4e8;border-color:#ffedd5;color:#e98222}.tg-dialog-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:11px;font-weight:600;padding:0 6px}.tg-dialog-tab.is-active .tg-dialog-tab-count{background:#FFA048;color:#fff}.tg-dialog-tab-alert{position:absolute;right:-2px;top:-3px;width:10px;height:10px;border-radius:999px;background:#ef4444;border:2px solid #fff;box-shadow:0 0 0 2px rgba(239,68,68,.08)}.tg-dialog-desktop-menu-button{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;min-width:38px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;border-radius:14px;font-size:22px;font-weight:600;line-height:1;flex:0 0 38px}.tg-dialog-desktop-menu-button:hover{background:#fff7ed;border-color:#ffedd5;color:#e98222}.tg-dialog-unread-summary{margin:0 14px 10px;border:1px solid #ffedd5;background:#fff7ed;color:#9a5a17;border-radius:14px;padding:9px 12px;font-size:12px;font-weight:600}
        .tg-dialog-grid{display:grid;grid-template-columns:minmax(280px,360px) minmax(380px,1fr) minmax(260px,330px);min-height:640px}.tg-dialog-list{border-right:1px solid #eef0f4;background:#fff;min-width:0}.tg-dialog-main{background:#fff;min-width:0;display:flex;flex-direction:column}.tg-dialog-side{border-left:1px solid #eef0f4;background:#fff;min-width:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}.tg-dialog-list-tools{display:flex;align-items:center;gap:8px;padding:14px;border-bottom:1px solid #eef0f4}.tg-dialog-search{position:relative;flex:1}.tg-dialog-search input{width:100%;height:42px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:16px;padding:0 14px;font-size:13px;font-weight:650;color:#374151;outline:none}.tg-dialog-search input:focus{border-color:#fed7aa;background:#fff;box-shadow:0 0 0 4px rgba(255,160,72,.12)}.tg-dialog-tool-btn{width:42px;height:42px;border:1px solid #e5e7eb;background:#fff;border-radius:15px;padding:0}.tg-dialog-tool-btn img{width:20px!important;height:20px!important;flex:0 0 20px!important;opacity:.72}.tg-dialog-filter-panel{display:none;margin:0 14px 14px;padding:14px;border:1px solid #eef0f4;background:#fbfbfc;border-radius:18px}.tg-dialog-filter-panel.is-open{display:block}.tg-dialog-filter-section{padding:10px 0;border-bottom:1px solid #eef0f4}.tg-dialog-filter-section:last-child{border-bottom:0}.tg-dialog-filter-title{font-size:12px;font-weight:600;color:#111827;margin-bottom:9px}.tg-dialog-check{display:flex;align-items:center;gap:9px;margin:7px 0;font-size:13px;font-weight:500;color:#4b5563}.tg-dialog-check input{width:17px;height:17px;accent-color:#FFA048}.tg-dialog-filter-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px}.tg-dialog-filter-apply{border:0;background:#FFA048;color:#fff;border-radius:13px;padding:10px 13px;font-size:12px;font-weight:600}.tg-dialog-filter-reset{border:1px solid #e5e7eb;background:#fff;color:#6b7280;border-radius:13px;padding:10px 13px;font-size:12px;font-weight:600;text-decoration:none}.tg-dialog-filter-muted{font-size:12px;font-weight:650;color:#9ca3af}.tg-dialog-item{display:flex;gap:11px;padding:14px 15px;border-bottom:1px solid #f1f3f5;text-decoration:none;color:inherit;background:#fff}.tg-dialog-item:hover{background:#fff7ed}.tg-dialog-item.is-active{background:#fff4e8}.tg-dialog-avatar{width:42px;height:42px;border-radius:999px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;flex:0 0 42px;position:relative}.tg-dialog-avatar img{width:20px;height:20px}.tg-dialog-dot{position:absolute;right:0;bottom:1px;width:12px;height:12px;border-radius:999px;background:#22c55e;border:2px solid #fff}.tg-dialog-item-body{min-width:0;flex:1}.tg-dialog-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.tg-dialog-name{font-size:14px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-dialog-time{font-size:11px;font-weight:500;color:#9ca3af;white-space:nowrap}.tg-dialog-preview{margin-top:4px;font-size:12px;font-weight:650;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-dialog-channel{margin-top:6px;font-size:11px;font-weight:500;color:#a1a1aa;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-dialog-empty{margin:18px;border:1px dashed #e5e7eb;background:#fafafa;border-radius:20px;padding:18px;font-size:13px;font-weight:500;color:#9ca3af;text-align:center}
        .tg-dialog-chat-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid #eef0f4}.tg-dialog-chat-person{display:flex;align-items:center;gap:11px;min-width:0}.tg-dialog-chat-person h4{margin:0;font-size:16px;font-weight:600;color:#1f2937}.tg-dialog-chat-person p{margin:3px 0 0;font-size:12px;font-weight:650;color:#9ca3af}.tg-dialog-chat-body{flex:1;overflow:auto;padding:20px;background:linear-gradient(180deg,#fff,#fbfbfc)}.tg-dialog-day{text-align:center;font-size:11px;font-weight:600;color:#9ca3af;margin:12px 0}.tg-dialog-bubble-row{display:flex;margin:8px 0}.tg-dialog-bubble-row.is-out{justify-content:flex-end}.tg-dialog-bubble{max-width:min(620px,82%);border-radius:18px;padding:11px 13px;font-size:14px;line-height:1.45;white-space:pre-wrap;color:#1f2937;background:#f3f4f6}.tg-dialog-bubble-row.is-out .tg-dialog-bubble{background:#fff4e8}.tg-dialog-bubble-meta{margin-top:5px;font-size:10px;font-weight:500;color:#9ca3af;text-align:right}.tg-dialog-file{display:inline-flex;margin-top:6px;border:1px solid #e5e7eb;border-radius:10px;padding:6px 8px;font-size:12px;font-weight:500;color:#6b7280;background:#fff;text-decoration:none}.tg-dialog-compose{padding:14px 18px;border-top:1px solid #eef0f4;background:#fff}.tg-dialog-compose-box{border:1px solid #e5e7eb;border-radius:18px;padding:10px;background:#fff}.tg-dialog-compose textarea{width:100%;min-height:64px;resize:vertical;border:0;outline:none;font-size:14px;font-weight:600;color:#374151}.tg-dialog-compose-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px}.tg-dialog-attach{font-size:12px;font-weight:500;color:#6b7280}.tg-dialog-send{background:#FFA048;color:#fff;border-radius:14px;padding:11px 16px;font-size:12px;font-weight:600}.tg-dialog-send img{width:16px!important;height:16px!important}.tg-dialog-side-head{padding:18px;border-bottom:1px solid #eef0f4}.tg-dialog-side-head h4{font-size:16px;font-weight:600;color:#1f2937;margin:0}.tg-dialog-side-section{padding:18px;border-bottom:1px solid #eef0f4}.tg-dialog-side-label{font-size:12px;font-weight:600;color:#111827;margin-bottom:12px}.tg-dialog-info-row{display:grid;grid-template-columns:105px minmax(0,1fr);gap:10px;padding:9px 0;font-size:13px}.tg-dialog-info-row span:first-child{color:#9ca3af;font-weight:500}.tg-dialog-info-row span:last-child{color:#374151;font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis}.tg-dialog-tags{display:flex;flex-wrap:wrap;gap:7px}.tg-dialog-tag{border-radius:999px;background:#fff4e8;color:#9a5a17;padding:6px 9px;font-size:11px;font-weight:600}.tg-dialog-card-link{display:inline-flex;align-items:center;gap:7px;border-radius:14px;background:#f3f4f6;color:#374151;padding:10px 12px;font-size:12px;font-weight:600;text-decoration:none}.tg-dialog-card-link img{width:16px;height:16px}.tg-dialog-item.is-unread .tg-dialog-name{font-weight:600;color:#111827}.tg-dialog-unread-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;background:#FFA048;color:#fff;font-size:11px;font-weight:600;padding:0 6px}.tg-dialog-status-pill{display:inline-flex;align-items:center;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:11px;font-weight:600;padding:5px 8px}.tg-dialog-chat-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.tg-dialog-action-form{display:inline-flex;margin:0}.tg-dialog-action-btn{border:0;border-radius:13px;background:#f4f4f5;color:#52525b;padding:10px 12px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}.tg-dialog-action-btn.is-main{background:#FFA048;color:#fff}.tg-dialog-action-btn.is-soft{background:#fff7ed;color:#9a5a17}.tg-dialog-action-btn.is-danger{background:#fef2f2;color:#b91c1c}.tg-dialog-assign-form{display:inline-flex;align-items:center;gap:7px;margin:0}.tg-dialog-assign-select{height:38px;max-width:190px;border:1px solid #e5e7eb;background:#fff;border-radius:13px;padding:0 10px;font-size:12px;font-weight:600;color:#4b5563;outline:none}.tg-dialog-assign-select:focus{border-color:#fed7aa;box-shadow:0 0 0 4px rgba(255,160,72,.10)}.tg-dialog-assignee{display:inline-flex;align-items:center;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:11px;font-weight:600;padding:5px 8px}.tg-dialog-assigned-line{margin-top:5px;font-size:11px;font-weight:600;color:#a1a1aa;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tg-dialog-settings-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.48);z-index:120;display:none;align-items:center;justify-content:center;padding:18px}.tg-dialog-settings-backdrop.is-open{display:flex}.tg-dialog-settings-modal{width:780px;max-width:100%;max-height:calc(100vh - 36px);overflow:auto;background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.tg-dialog-settings-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:26px 30px 18px}.tg-dialog-settings-head h3{margin:0;font-size:22px;font-weight:650;color:#1f2937}.tg-dialog-settings-close{width:40px;height:40px;border:0;background:#f4f4f5;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:24px;font-weight:600;line-height:1;color:#6b7280}.tg-dialog-settings-close:hover{background:#fff7ed;color:#9a5a17}.tg-dialog-settings-body{padding:0 30px 22px}.tg-dialog-setting-row{display:grid;grid-template-columns:42px minmax(0,1fr);gap:18px;padding:18px 0;border-bottom:1px solid #eef0f4}.tg-dialog-setting-row:last-child{border-bottom:0}.tg-dialog-switch-input{position:absolute;opacity:0;pointer-events:none}.tg-dialog-switch{width:44px;height:25px;border-radius:999px;background:#d1d5db;position:relative;display:inline-block;margin-top:2px}.tg-dialog-switch:after{content:'';position:absolute;width:19px;height:19px;left:3px;top:3px;border-radius:999px;background:#fff;box-shadow:0 2px 7px rgba(0,0,0,.18)}.tg-dialog-switch-input:checked + .tg-dialog-switch{background:#FFA048}.tg-dialog-switch-input:checked + .tg-dialog-switch:after{left:22px}.tg-dialog-setting-title{display:block;font-size:15px;font-weight:600;color:#4b5563}.tg-dialog-setting-text{display:block;margin-top:6px;font-size:13px;line-height:1.55;font-weight:650;color:#7b8190}.tg-dialog-settings-textarea{margin-top:12px;width:100%;min-height:92px;border:1px solid #e5e7eb;background:#fbfbfc;border-radius:16px;padding:13px 14px;font-size:13px;font-weight:650;color:#374151;resize:vertical;outline:none}.tg-dialog-settings-textarea:focus{background:#fff;border-color:#fed7aa;box-shadow:0 0 0 4px rgba(255,160,72,.12)}.tg-dialog-settings-tools{display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap}.tg-dialog-settings-tool{width:42px;height:38px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:22px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;line-height:1}.tg-dialog-settings-tool:hover{background:#fff7ed;color:#e98222}.tg-dialog-settings-file-name{font-size:12px;font-weight:600;color:#6b7280;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-dialog-settings-attachment{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:12px;padding:9px 11px;font-size:12px;font-weight:600}.tg-dialog-settings-attachment a{color:#9a3412;text-decoration:none}.tg-dialog-settings-attachment a:hover{text-decoration:underline}.tg-dialog-settings-attachment label{display:inline-flex;align-items:center;gap:6px;color:#7b8190;font-size:11px;font-weight:600;white-space:nowrap}.tg-dialog-settings-emoji-menu{position:absolute;left:0;bottom:46px;z-index:140;width:360px;max-width:calc(100vw - 48px);max-height:310px;overflow:auto;background:#fff;border:1px solid #edf0f2;border-radius:16px;box-shadow:0 18px 40px rgba(15,23,42,.18);padding:10px;display:none}.tg-dialog-settings-emoji-wrap{position:relative}.tg-dialog-settings-emoji-menu.is-open{display:block}.tg-dialog-settings-sep{margin:2px 0 18px;border-top:1px solid #eef0f4}.tg-dialog-settings-danger-title{font-size:15px;font-weight:600;color:#1f2937;margin:0 0 8px}.tg-dialog-settings-danger-text{font-size:13px;font-weight:650;color:#7b8190;line-height:1.5}.tg-dialog-settings-danger-btn{margin-top:14px;border:1px solid #ffd7b5;background:#fff7ed;color:#9a5a17;border-radius:14px;padding:12px 18px;font-size:13px;font-weight:600;opacity:.72}.tg-dialog-settings-foot{display:flex;justify-content:flex-end;gap:12px;padding:18px 30px 26px;border-top:1px solid #eef0f4}.tg-dialog-settings-muted-btn{border:0;background:#f4f4f5;color:#52525b;border-radius:14px;padding:12px 18px;font-size:13px;font-weight:600}.tg-dialog-settings-save{border:0;background:#FFA048;color:#fff;border-radius:14px;padding:12px 22px;font-size:13px;font-weight:600}

        body.tg-dialogs-page .admin-main{height:calc(100dvh - 12px);display:flex;flex-direction:column;padding-bottom:0!important;overflow:hidden}
        body.tg-dialogs-page .admin-header{margin-bottom:8px!important;flex:0 0 auto}
        body.tg-dialogs-page .tg-dialogs-shell{flex:1 1 auto;height:auto!important;min-height:0!important;margin-bottom:0!important;border-radius:22px}
        body.tg-dialogs-page .tg-dialogs-head{display:none!important}
        body.tg-dialogs-page .tg-dialog-tabs{flex:0 0 auto;padding:8px 12px!important;gap:7px!important}
        body.tg-dialogs-page .tg-dialog-tab{min-height:34px!important;padding:7px 13px!important;border-radius:14px!important;font-size:12px!important}
        body.tg-dialogs-page .tg-dialog-grid{flex:1 1 auto;height:100%!important;min-height:0!important}
        body.tg-dialogs-page .tg-dialog-chat-head{min-height:54px!important;padding:7px 12px!important;gap:10px!important;align-items:center!important}
        body.tg-dialogs-page .tg-dialog-chat-person{gap:8px!important;min-width:180px!important}
        body.tg-dialogs-page .tg-dialog-chat-person .tg-dialog-avatar{width:34px!important;height:34px!important;flex-basis:34px!important}
        body.tg-dialogs-page .tg-dialog-chat-person .tg-dialog-avatar img{width:17px!important;height:17px!important}
        .tg-dialog-person-lines{display:grid;gap:1px;min-width:0;line-height:1.12}.tg-dialog-person-name{display:flex;align-items:center;gap:7px;min-width:0;font-size:14px;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tg-dialog-person-bot,.tg-dialog-person-user{font-size:11px;font-weight:650;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tg-dialog-status-dot2{width:9px;height:9px;border-radius:999px;display:inline-flex;flex:0 0 9px;background:#22c55e}.tg-dialog-status-dot2.is-red{background:#ef4444}.tg-dialog-compact-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:nowrap;min-width:0}.tg-dialog-desktop-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:6px;min-width:0}.tg-dialog-mobile-toolbar{display:none;align-items:center;gap:6px}.tg-dialog-dropdown{position:relative}.tg-dialog-icon-btn{width:36px;height:36px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;padding:0}.tg-dialog-icon-btn img{width:18px;height:18px;opacity:.78;object-fit:contain}.tg-dialog-icon-btn img[src$="users.png"]{width:19px;height:19px;opacity:.9}.tg-dialog-dropdown-menu{position:absolute;right:0;top:calc(100% + 8px);min-width:220px;background:#fff;border:1px solid #e8ebf0;border-radius:16px;box-shadow:0 18px 42px rgba(15,23,42,.16);padding:10px;display:none;z-index:40}.tg-dialog-dropdown.is-open .tg-dialog-dropdown-menu{display:block}.tg-dialog-dropdown-title{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;margin:0 0 8px}.tg-dialog-dropdown-menu .tg-dialog-assign-form{display:block}.tg-dialog-dropdown-menu .tg-dialog-assign-select{width:100%;max-width:none;min-width:0;height:38px}.tg-dialog-dropdown-actions{display:grid;gap:8px}.tg-dialog-dropdown-actions .tg-dialog-action-form{display:block}.tg-dialog-dropdown-actions .tg-dialog-action-btn{width:100%;justify-content:flex-start;padding:0 12px!important;height:38px!important;border-radius:12px!important}.tg-dialog-compact-actions .tg-dialog-action-btn{height:34px!important;min-width:34px!important;padding:0 10px!important;border-radius:12px!important;font-size:11px!important}.tg-dialog-compact-actions .tg-dialog-action-btn.is-icon{width:34px!important;padding:0!important;font-size:16px!important;line-height:1}.tg-dialog-compact-actions .tg-dialog-assign-form{margin:0!important}.tg-dialog-compact-actions .tg-dialog-assign-select{height:36px!important;max-width:230px!important;min-width:190px!important;border-radius:12px!important;font-size:12px!important}
        body.tg-dialogs-page .tg-dialog-chat-body{padding:12px 18px!important}
        body.tg-dialogs-page .tg-dialog-compose{padding:8px 12px!important;flex:0 0 auto!important}
        body.tg-dialogs-page .tg-dialog-compose textarea{min-height:54px!important;max-height:96px!important;padding:10px 12px!important;border-radius:15px!important}
        body.tg-dialogs-page .tg-chat-actions{margin-top:6px!important}.tg-dialog-compose-hint{display:none!important}

        /* Жёсткий режим высоты для рабочей страницы диалогов: без лишней внутренней шапки и белого подвала. */
        .tg-dialogs-head{display:none!important}
        .tg-dialogs-shell{height:var(--tg-dialog-shell-h,calc(100dvh - 188px))!important;min-height:560px!important;max-height:none!important;display:flex!important;flex-direction:column!important;margin:0!important;border-radius:22px!important;overflow:hidden!important}
        .tg-dialog-tabs{flex:0 0 auto!important;padding:9px 12px!important;gap:7px!important}.tg-dialog-tab{min-height:34px!important;padding:8px 13px!important;border-radius:14px!important;font-size:12px!important}.tg-dialog-tab-count{min-width:18px!important;height:18px!important;font-size:10px!important}
        .tg-dialog-grid{flex:1 1 auto!important;min-height:0!important;height:auto!important;display:grid!important}.tg-dialog-list,.tg-dialog-main,.tg-dialog-side{height:auto!important;min-height:0!important;overflow:hidden!important}.tg-dialog-list{display:flex!important;flex-direction:column!important}.tg-dialog-list-scroll{flex:1 1 auto!important;min-height:0!important;overflow:auto!important}.tg-dialog-main{display:flex!important;flex-direction:column!important}.tg-dialog-side{overflow:auto!important}.tg-dialog-chat-head{flex:0 0 auto!important;min-height:42px!important;padding:6px 12px!important}.tg-dialog-chat-body{flex:1 1 auto!important;min-height:0!important;overflow:auto!important;padding:10px 16px!important}.tg-dialog-compose{flex:0 0 auto!important;position:relative!important;bottom:auto!important;padding:7px 12px 8px!important}.tg-dialog-compose textarea{min-height:46px!important;max-height:88px!important;padding:9px 12px!important}.tg-chat-actions{margin-top:6px!important}.tg-dialog-compose-hint{display:none!important}
        @media(max-width:1180px){.tg-dialog-grid{grid-template-columns:minmax(260px,330px) minmax(360px,1fr)}.tg-dialog-side{grid-column:1 / -1;border-left:0;border-top:1px solid #eef0f4}.tg-dialog-side-section{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tg-dialog-side-section .tg-dialog-side-label{grid-column:1 / -1}}
        .tg-dialogs-shell{height:calc(100dvh - 118px);min-height:0;display:flex;flex-direction:column;margin-bottom:0}
        .tg-dialog-grid{flex:1;min-height:0;height:100%}.tg-dialog-list,.tg-dialog-main,.tg-dialog-side{min-height:0;height:100%;overflow:hidden}.tg-dialog-list{display:flex;flex-direction:column}.tg-dialog-list-scroll{flex:1;min-height:0;overflow:auto}.tg-dialog-main{height:100%;min-height:0;display:flex;flex-direction:column}.tg-dialog-side{overflow:auto}.tg-dialog-chat-head{flex:0 0 auto;background:#fff;z-index:4}.tg-dialog-chat-body{flex:1;min-height:0;overflow:auto;padding:20px 24px;background:#fff}.tg-dialog-compose{position:sticky;bottom:0;z-index:6;border-top:1px solid #edf0f2;padding:12px 18px 14px;background:#fff;box-shadow:0 -8px 18px rgba(15,23,42,.035)}
        .tg-dialog-system-note{max-width:82%;margin:18px auto;text-align:center;color:#a1a1aa;font-size:13px;font-weight:500;line-height:1.45}.tg-dialog-system-note small{display:inline-block;margin-left:7px;color:#c4c7cc;font-size:11px;font-weight:600}.tg-dialog-broadcast-context{max-width:min(620px,82%);margin:14px auto;border:1px solid #fed7aa;background:#fff7ed;border-radius:18px;padding:12px 14px;color:#7c2d12}.tg-dialog-broadcast-context-title{font-size:12px;font-weight:700;color:#c2410c;margin-bottom:6px}.tg-dialog-broadcast-context-text{font-size:13px;font-weight:500;line-height:1.45;white-space:pre-wrap;color:#7c2d12}.tg-dialog-broadcast-context-meta{margin-top:7px;font-size:11px;font-weight:500;color:#c99665}.tg-dialog-broadcast-diag{margin:12px 20px;border:1px dashed #f59e0b;background:#fffbeb;border-radius:16px;padding:12px 14px;color:#78350f;font-size:12px;font-weight:500}.tg-dialog-broadcast-diag h5{margin:0 0 8px;font-size:13px;font-weight:700;color:#92400e}.tg-dialog-broadcast-diag pre{white-space:pre-wrap;word-break:break-word;margin:8px 0 0;background:#fff7ed;border-radius:12px;padding:10px;font-size:11px;line-height:1.45}.tg-dialog-chat-body .tg-chat-bubble{max-width:min(620px,75%);border-radius:16px;padding:11px 13px;margin:8px 0;font-size:14px;font-weight:600;line-height:1.45;white-space:pre-wrap;word-break:break-word}.tg-dialog-chat-body .tg-chat-bubble.in{background:#f3f4f6;color:#1f2937;margin-right:auto}.tg-dialog-chat-body .tg-chat-bubble.out{background:#fff7ed;color:#7c2d12;margin-left:auto;border:1px solid #fed7aa}.tg-dialog-chat-body .tg-chat-meta{display:block;margin-top:4px;font-size:11px;color:#9ca3af;font-weight:500}.tg-dialog-chat-body .tg-chat-attachment{display:flex;align-items:center;gap:8px;margin-top:8px;border:1px solid #fed7aa;background:#fff7ed;border-radius:12px;padding:8px 10px;color:#9a3412;font-size:12px;font-weight:600}.tg-dialog-chat-body .tg-chat-attachment a{color:#c2410c;text-decoration:none}.tg-dialog-chat-body .tg-chat-attachment small{color:#9ca3af;font-size:11px;font-weight:600}
        .tg-dialog-compose textarea{width:100%;border:1px solid #e5e7eb;border-radius:18px;padding:14px 16px;min-height:88px;max-height:160px;resize:vertical;font-size:14px;font-weight:600;outline:none}.tg-dialog-compose textarea:focus{border-color:#fed7aa;box-shadow:0 0 0 4px rgba(255,160,72,.10)}.tg-chat-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:10px}.tg-chat-tool-row{display:flex;align-items:center;gap:8px;min-width:0}.tg-chat-tool{height:38px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:12px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;padding:0 12px;white-space:nowrap}.tg-chat-tool:hover{background:#fff7ed;color:#e98222}.tg-chat-tool--icon{width:46px;min-width:46px;padding:0;border-radius:14px;font-size:0}.tg-chat-tool-glyph{display:inline-flex;align-items:center;justify-content:center;font-size:24px;line-height:1;color:inherit;opacity:.78}.tg-chat-tool--icon:hover .tg-chat-tool-glyph{opacity:1}.tg-chat-tool:disabled .tg-chat-tool-glyph{opacity:.35}.tg-chat-send{border:0;background:#111827;color:#fff;border-radius:14px;padding:12px 16px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}.tg-chat-send:disabled{background:#e5e7eb;color:#9ca3af}.tg-chat-tools{position:relative}.tg-chat-emoji-menu,.tg-chat-macro-menu{position:absolute;left:0;bottom:46px;z-index:25;width:360px;max-width:calc(100vw - 36px);max-height:310px;overflow:auto;background:#fff;border:1px solid #edf0f2;border-radius:16px;box-shadow:0 18px 40px rgba(15,23,42,.18);padding:10px;display:none}.tg-chat-emoji-menu.is-open,.tg-chat-macro-menu.is-open{display:block}.tg-chat-emoji-section{margin:6px 2px 12px}.tg-chat-emoji-title{font-size:11px;font-weight:600;color:#9ca3af;margin:6px 4px}.tg-chat-emoji-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:4px}.tg-chat-emoji-grid button{border:0;background:#fff;border-radius:9px;padding:7px;font-size:20px;line-height:1.1}.tg-chat-emoji-grid button:hover{background:#f3f4f6}.tg-chat-macro-search{width:100%;border:0;background:#f7f7f8;border-radius:10px;padding:11px 12px;font-size:12px;font-weight:600;margin-bottom:8px}.tg-chat-macro-group{padding:8px 9px 4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af}.tg-chat-macro-item{display:grid!important;grid-template-columns:34px 1fr auto;align-items:center;gap:8px;width:100%;padding:10px;border-radius:10px;text-align:left;color:#374151;background:#fff}.tg-chat-macro-item:hover{background:#f3f4f6}.tg-chat-macro-token{color:#9ca3af;font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis}.tg-chat-file-name{font-size:12px;font-weight:600;color:#6b7280;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-readonly-note{font-size:12px;font-weight:650;color:#9ca3af;line-height:1.45;margin-top:8px}
        .tg-dialog-side-form{display:flex;flex-direction:column;gap:10px}.tg-dialog-side-form label span,.tg-dialog-tag-form label span{display:block;font-size:11px;font-weight:600;color:#9ca3af;margin-bottom:5px}.tg-dialog-side-form input,.tg-dialog-side-form textarea,.tg-dialog-side-form select,.tg-dialog-tag-form input{width:100%;border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:11px 12px;font-size:13px;font-weight:650;color:#374151;outline:none}.tg-dialog-side-form input:focus,.tg-dialog-side-form textarea:focus,.tg-dialog-side-form select:focus,.tg-dialog-tag-form input:focus{border-color:#fed7aa;box-shadow:0 0 0 4px rgba(255,160,72,.10)}.tg-dialog-side-form textarea{min-height:82px;resize:vertical}.tg-dialog-side-save{border:0;background:#FFA048;color:#fff;border-radius:14px;padding:12px 14px;font-size:12px;font-weight:600}.tg-dialog-custom-values-form{background:#fffaf5;border:1px solid #ffedd5;border-radius:18px;padding:14px}.tg-dialog-custom-values-empty{color:#9ca3af;font-size:13px;font-weight:500;line-height:1.45}.tg-dialog-custom-values-form label span small{font-weight:500;color:#cbd0d6;text-transform:none;letter-spacing:0;margin-left:4px}.tg-dialog-tag-list{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}.tg-dialog-tag-remove{border:0;background:rgba(255,255,255,.62);color:#6b7280;border-radius:999px;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;line-height:1}.tg-dialog-tag-form{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.tg-dialog-detail-block{display:flex;flex-direction:column;gap:8px}.tg-dialog-detail-block .tg-dialog-info-row{background:#f8fafc;border-radius:12px;padding:10px 12px}
        .tg-dialogs-shell{height:calc(100dvh - 250px);min-height:520px;margin-bottom:0}.tg-dialog-grid{min-height:0;height:100%}.tg-dialog-list,.tg-dialog-main,.tg-dialog-side{height:100%;min-height:0}.tg-dialog-chat-head{min-height:54px;padding:8px 14px}.tg-dialog-chat-body{padding:14px 18px}.tg-dialog-compose{padding:8px 14px 10px}.tg-dialog-compose textarea{min-height:48px;max-height:110px}.tg-chat-actions{margin-top:7px}.tg-dialog-member-list{display:flex;flex-direction:column;gap:8px;min-width:0;max-width:100%;overflow:hidden}.tg-dialog-member-item{display:grid;grid-template-columns:22px minmax(0,1fr);grid-template-areas:"icon text" "icon badge";align-items:center;gap:4px 9px;width:100%;max-width:100%;box-sizing:border-box;border:1px solid #eef0f4;background:#f8fafc;border-radius:14px;padding:9px 10px;text-decoration:none;color:#374151;overflow:hidden}.tg-dialog-member-item.is-current{border-color:#ffedd5;background:#fff7ed}.tg-dialog-member-icon{grid-area:icon;width:18px!important;height:18px!important;align-self:flex-start;margin-top:2px;opacity:.72}.tg-dialog-member-text{grid-area:text;min-width:0;display:block;overflow:hidden}.tg-dialog-member-title{display:block;max-width:100%;font-size:12px;font-weight:600;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.25}.tg-dialog-member-meta{display:block;max-width:100%;margin-top:2px;font-size:10.5px;font-weight:500;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.25}.tg-dialog-member-badge{grid-area:badge;justify-self:start;display:inline-flex;align-items:center;max-width:100%;border-radius:999px;background:#f4f4f5;color:#71717a;font-size:10px;font-weight:600;padding:4px 7px;white-space:nowrap;line-height:1}.tg-dialog-member-item.has-dialog .tg-dialog-member-badge{background:#fff4e8;color:#9a5a17}.tg-dialog-member-item.has-unread .tg-dialog-member-badge{background:#FFA048;color:#fff}
        .tg-dialog-mobile-switch{display:none;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid #eef0f4;background:#fff;position:sticky;top:0;z-index:18}.tg-dialog-mobile-switch button{flex:1;min-height:38px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;border-radius:14px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}.tg-dialog-mobile-switch button.is-active{background:#fff4e8;border-color:#ffedd5;color:#e98222}.tg-dialog-mobile-switch button:disabled{opacity:.45;cursor:not-allowed}.tg-dialog-mobile-back{display:none;align-items:center;gap:7px;border:0;background:#f4f4f5;color:#52525b;border-radius:12px;padding:9px 11px;font-size:12px;font-weight:600;white-space:nowrap}.tg-dialog-mobile-back img{width:15px;height:15px;opacity:.75}.tg-dialog-mobile-panel-dropdown{display:none;position:relative;flex:0 0 auto}.tg-dialog-mobile-panel-button{width:38px;height:36px;border:1px solid #e5e7eb;background:#fff;border-radius:13px;color:#6b7280;font-size:20px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;line-height:1}.tg-dialog-panel-menu{position:absolute;left:0;top:calc(100% + 8px);min-width:150px;background:#fff;border:1px solid #e8ebf0;border-radius:15px;box-shadow:0 18px 42px rgba(15,23,42,.16);padding:8px;display:none;z-index:60}.tg-dialog-mobile-panel-dropdown.is-open .tg-dialog-panel-menu{display:grid;gap:6px}.tg-dialog-panel-menu.is-fixed{position:fixed;left:8px;top:44px;z-index:9999}.tg-dialog-panel-menu button{height:36px;border:0;background:#fff;color:#52525b;border-radius:11px;font-size:12px;font-weight:600;text-align:left;padding:0 12px}.tg-dialog-panel-menu button.is-active{background:#fff4e8;color:#e98222}.tg-dialog-panel-menu button:disabled{opacity:.45;cursor:not-allowed}
        @media(max-width:820px){body.tg-dialogs-page{overflow-x:hidden}.tg-dialogs-shell{height:calc(100dvh - 96px)!important;min-height:0!important;border-radius:20px;margin:-2px -8px 0;display:flex;flex-direction:column;overflow:hidden}.tg-dialogs-head{display:none}.tg-dialog-tabs{padding:10px 12px;gap:7px;flex:0 0 auto}.tg-dialog-desktop-menu-button{display:none!important}.tg-dialog-tab{min-height:36px;padding:9px 12px;border-radius:14px;font-size:12px}.tg-dialog-mobile-switch{display:flex;flex:0 0 auto}.tg-dialog-grid{display:block;min-height:0;height:auto;flex:1;position:relative;overflow:hidden}.tg-dialog-list,.tg-dialog-main,.tg-dialog-side{position:absolute;inset:0;height:100%;min-height:0;border-left:0;border-right:0;background:#fff;display:flex;flex-direction:column;transform:translateX(110%);opacity:0;pointer-events:none;transition:transform .18s ease,opacity .18s ease}.tg-dialog-list{transform:translateX(-110%)}.tg-dialog-main{transform:translateX(110%)}.tg-dialog-side{transform:translateX(110%)}.tg-dialogs-shell[data-mobile-panel="list"] .tg-dialog-list,.tg-dialogs-shell[data-mobile-panel="chat"] .tg-dialog-main,.tg-dialogs-shell[data-mobile-panel="card"] .tg-dialog-side{transform:translateX(0);opacity:1;pointer-events:auto;z-index:3}.tg-dialog-list-tools{padding:12px;flex:0 0 auto}.tg-dialog-filter-panel{flex:0 0 auto}.tg-dialog-list-scroll{overflow:auto;flex:1;min-height:0}.tg-dialog-main{min-height:0}.tg-dialog-chat-head{padding:10px 12px;gap:8px;flex:0 0 auto}.tg-dialog-chat-person{gap:8px}.tg-dialog-chat-person h4,.tg-dialog-person-name{font-size:14px}.tg-dialog-chat-actions,.tg-dialog-compact-actions{gap:6px}.tg-dialog-desktop-toolbar{display:none}.tg-dialog-mobile-toolbar{display:flex;flex:0 0 auto}.tg-dialog-compact-actions{margin-left:auto}.tg-dialog-dropdown-menu{min-width:210px;max-width:min(260px,calc(100vw - 32px))}.tg-dialog-chat-person{min-width:0;flex:1}.tg-dialog-person-lines{min-width:0}.tg-dialog-assign-select{max-width:155px;height:36px;font-size:11px}.tg-dialog-action-btn{min-width:36px;height:36px;padding:8px 10px}.tg-dialog-mobile-back{display:inline-flex}.tg-dialog-chat-body{padding:12px;flex:1;min-height:0;overflow:auto}.tg-dialog-chat-body .tg-chat-bubble,.tg-dialog-bubble{max-width:92%;font-size:13px}.tg-dialog-system-note{max-width:94%;font-size:12px}.tg-dialog-compose{padding:9px 12px 10px;flex:0 0 auto}.tg-dialog-compose textarea{min-height:54px;max-height:105px}.tg-chat-actions{gap:8px;align-items:flex-start}.tg-chat-tool-row{flex-wrap:wrap;gap:6px}.tg-chat-tool{height:34px;padding:0 10px;font-size:11px}.tg-chat-tool--icon{width:42px;min-width:42px;padding:0}.tg-chat-tool--icon .tg-chat-tool-glyph{font-size:22px}.tg-chat-send{height:38px;padding:10px 13px;font-size:11px}.tg-chat-emoji-menu,.tg-chat-macro-menu{left:0;right:auto;bottom:42px;max-width:calc(100vw - 30px);width:330px}.tg-dialog-side{overflow:auto}.tg-dialog-side-head,.tg-dialog-side-section{padding:14px}.tg-dialog-info-row{grid-template-columns:96px minmax(0,1fr);font-size:12px}.tg-dialog-tag-form{grid-template-columns:1fr}.tg-dialog-member-list{gap:7px}.tg-dialog-empty{margin:14px}.tg-dialogs-shell[data-mobile-panel="list"] .tg-dialog-mobile-switch [data-mobile-panel="list"],.tg-dialogs-shell[data-mobile-panel="chat"] .tg-dialog-mobile-switch [data-mobile-panel="chat"],.tg-dialogs-shell[data-mobile-panel="card"] .tg-dialog-mobile-switch [data-mobile-panel="card"]{background:#fff4e8;border-color:#ffedd5;color:#e98222}}

        @media(max-width:820px){
            body.tg-dialogs-page{overflow:hidden!important}
            body.tg-dialogs-page .admin-header,body.tg-dialogs-page .app-header,body.tg-dialogs-page .topbar,body.tg-dialogs-page .admin-topbar{transition:transform .22s ease,opacity .22s ease,margin .22s ease!important}
            body.tg-dialogs-page.tg-dialogs-header-hidden .admin-header,body.tg-dialogs-page.tg-dialogs-header-hidden .app-header,body.tg-dialogs-page.tg-dialogs-header-hidden .topbar,body.tg-dialogs-page.tg-dialogs-header-hidden .admin-topbar{transform:translateY(-125%)!important;opacity:0!important;pointer-events:none!important;margin-bottom:-78px!important}
            body.tg-dialogs-page .admin-main,body.tg-dialogs-page .app-main,body.tg-dialogs-page main{padding-top:0!important}
            .tg-dialogs-shell{height:calc(100dvh - 78px)!important;margin:-8px -10px 0!important;border-radius:18px 18px 0 0!important}
            body.tg-dialogs-page.tg-dialogs-header-hidden .tg-dialogs-shell{height:calc(100dvh - 4px)!important;margin-top:0!important;border-radius:0!important}
            .tg-dialog-tabs{position:sticky!important;top:0!important;z-index:35!important;padding:7px 8px!important;gap:7px!important;align-items:center!important;background:#fbfbfc!important;box-shadow:0 1px 0 rgba(15,23,42,.07)}
            .tg-dialog-mobile-panel-dropdown{display:block!important}.tg-dialog-mobile-switch{display:none!important}

            .tg-dialog-tabs .tg-dialog-mobile-panel-dropdown{display:block!important;position:relative!important;z-index:80!important}
            .tg-dialog-panel-menu.is-fixed{position:fixed!important;left:8px!important;top:44px!important;z-index:9999!important}
            .tg-dialog-mobile-panel-dropdown.is-open .tg-dialog-panel-menu{display:grid!important}
            .tg-dialog-tab{min-height:34px!important;padding:8px 11px!important;border-radius:13px!important;font-size:11.5px!important;flex:0 0 auto}
            .tg-dialog-chat-head{padding:8px 10px!important;min-height:52px!important;gap:7px!important}.tg-dialog-chat-head>.tg-dialog-mobile-back{display:none!important}
            .tg-dialog-chat-person{justify-content:flex-start!important;margin-right:auto!important;min-width:0!important;flex:1 1 auto!important}.tg-dialog-avatar{width:36px!important;height:36px!important;flex-basis:36px!important}.tg-dialog-avatar img{width:18px!important;height:18px!important}.tg-dialog-person-name{font-size:13px!important;max-width:145px}.tg-dialog-person-bot,.tg-dialog-person-user{font-size:10px!important;max-width:150px}.tg-dialog-compact-actions{flex:0 0 auto!important;margin-left:4px!important}.tg-dialog-mobile-toolbar{display:flex!important;align-items:center!important;gap:5px!important}.tg-dialog-desktop-toolbar{display:none!important}.tg-dialog-icon-btn,.tg-dialog-mobile-toolbar .tg-dialog-action-btn.is-icon{width:34px!important;height:34px!important;min-width:34px!important;border-radius:11px!important;padding:0!important}.tg-dialog-mobile-toolbar .tg-dialog-action-form{display:inline-flex!important}.tg-dialog-mobile-toolbar .tg-dialog-action-btn{font-size:16px!important;line-height:1!important}.tg-dialog-dropdown-menu{right:0!important;left:auto!important;min-width:220px!important;max-width:calc(100vw - 20px)!important}
            .tg-dialog-grid{height:auto!important;flex:1 1 auto!important;min-height:0!important}.tg-dialog-main,.tg-dialog-list,.tg-dialog-side{height:100%!important}.tg-dialog-chat-body{padding:10px 12px!important}.tg-dialog-compose{padding:8px 10px calc(8px + env(safe-area-inset-bottom))!important}.tg-dialog-compose textarea{min-height:52px!important}.tg-chat-actions{align-items:center!important}.tg-chat-send{height:38px!important;padding:0 14px!important}.tg-readonly-note{font-size:10.5px!important;line-height:1.25!important}
        }
        body.theme-dark .tg-dialogs-shell,body.dark-theme .tg-dialogs-shell,body[data-theme="dark"] .tg-dialogs-shell,html[data-theme="dark"] .tg-dialogs-shell{background:#151a22;border-color:#273142;box-shadow:none}body.theme-dark .tg-dialogs-head,body.theme-dark .tg-dialog-tabs,body.theme-dark .tg-dialog-list,body.theme-dark .tg-dialog-main,body.theme-dark .tg-dialog-side,body.theme-dark .tg-dialog-list-tools,body.theme-dark .tg-dialog-chat-head,body.theme-dark .tg-dialog-compose,body.theme-dark .tg-dialog-mobile-switch,body.theme-dark .tg-dialog-side-head,body.theme-dark .tg-dialog-side-section,body.dark-theme .tg-dialogs-head,body.dark-theme .tg-dialog-tabs,body.dark-theme .tg-dialog-list,body.dark-theme .tg-dialog-main,body.dark-theme .tg-dialog-side,body.dark-theme .tg-dialog-list-tools,body.dark-theme .tg-dialog-chat-head,body.dark-theme .tg-dialog-compose,body.dark-theme .tg-dialog-mobile-switch,body.dark-theme .tg-dialog-side-head,body.dark-theme .tg-dialog-side-section,body[data-theme="dark"] .tg-dialogs-head,body[data-theme="dark"] .tg-dialog-tabs,body[data-theme="dark"] .tg-dialog-list,body[data-theme="dark"] .tg-dialog-main,body[data-theme="dark"] .tg-dialog-side,body[data-theme="dark"] .tg-dialog-list-tools,body[data-theme="dark"] .tg-dialog-chat-head,body[data-theme="dark"] .tg-dialog-compose,body[data-theme="dark"] .tg-dialog-mobile-switch,body[data-theme="dark"] .tg-dialog-side-head,body[data-theme="dark"] .tg-dialog-side-section,html[data-theme="dark"] .tg-dialogs-head,html[data-theme="dark"] .tg-dialog-tabs,html[data-theme="dark"] .tg-dialog-list,html[data-theme="dark"] .tg-dialog-main,html[data-theme="dark"] .tg-dialog-side,html[data-theme="dark"] .tg-dialog-list-tools,html[data-theme="dark"] .tg-dialog-chat-head,html[data-theme="dark"] .tg-dialog-compose,html[data-theme="dark"] .tg-dialog-mobile-switch,html[data-theme="dark"] .tg-dialog-side-head,html[data-theme="dark"] .tg-dialog-side-section{background:#151a22;border-color:#273142;color:#e5e7eb}body.theme-dark .tg-dialog-chat-body,body.dark-theme .tg-dialog-chat-body,body[data-theme="dark"] .tg-dialog-chat-body,html[data-theme="dark"] .tg-dialog-chat-body{background:linear-gradient(180deg,#151a22,#12171f)}body.theme-dark .tg-dialog-search input,body.theme-dark .tg-dialog-tool-btn,body.theme-dark .tg-dialog-item,body.theme-dark .tg-dialog-filter-panel,body.theme-dark .tg-dialog-bubble,body.theme-dark .tg-chat-bubble,body.theme-dark .tg-dialog-compose-box,body.theme-dark .tg-dialog-assign-select,body.theme-dark .tg-dialog-icon-btn,body.theme-dark .tg-dialog-dropdown-menu,body.theme-dark .tg-dialog-member-item,body.theme-dark .tg-dialog-card-link,body.theme-dark .tg-dialog-detail-block .tg-dialog-info-row,body.theme-dark .tg-dialog-empty,body.dark-theme .tg-dialog-search input,body.dark-theme .tg-dialog-tool-btn,body.dark-theme .tg-dialog-item,body.dark-theme .tg-dialog-filter-panel,body.dark-theme .tg-dialog-bubble,body.dark-theme .tg-chat-bubble,body.dark-theme .tg-dialog-compose-box,body.dark-theme .tg-dialog-assign-select,body.dark-theme .tg-dialog-icon-btn,body.dark-theme .tg-dialog-dropdown-menu,body.dark-theme .tg-dialog-member-item,body.dark-theme .tg-dialog-card-link,body.dark-theme .tg-dialog-detail-block .tg-dialog-info-row,body.dark-theme .tg-dialog-empty,body[data-theme="dark"] .tg-dialog-search input,body[data-theme="dark"] .tg-dialog-tool-btn,body[data-theme="dark"] .tg-dialog-item,body[data-theme="dark"] .tg-dialog-filter-panel,body[data-theme="dark"] .tg-dialog-bubble,body[data-theme="dark"] .tg-chat-bubble,body[data-theme="dark"] .tg-dialog-compose-box,body[data-theme="dark"] .tg-dialog-assign-select,body[data-theme="dark"] .tg-dialog-icon-btn,body[data-theme="dark"] .tg-dialog-dropdown-menu,body[data-theme="dark"] .tg-dialog-member-item,body[data-theme="dark"] .tg-dialog-card-link,body[data-theme="dark"] .tg-dialog-detail-block .tg-dialog-info-row,body[data-theme="dark"] .tg-dialog-empty,html[data-theme="dark"] .tg-dialog-search input,html[data-theme="dark"] .tg-dialog-tool-btn,html[data-theme="dark"] .tg-dialog-item,html[data-theme="dark"] .tg-dialog-filter-panel,html[data-theme="dark"] .tg-dialog-bubble,html[data-theme="dark"] .tg-chat-bubble,html[data-theme="dark"] .tg-dialog-compose-box,html[data-theme="dark"] .tg-dialog-assign-select,html[data-theme="dark"] .tg-dialog-icon-btn,html[data-theme="dark"] .tg-dialog-dropdown-menu,html[data-theme="dark"] .tg-dialog-member-item,html[data-theme="dark"] .tg-dialog-card-link,html[data-theme="dark"] .tg-dialog-detail-block .tg-dialog-info-row,html[data-theme="dark"] .tg-dialog-empty{background:#1b2430;border-color:#2c394a;color:#e5e7eb}body.theme-dark .tg-dialog-item:hover,body.theme-dark .tg-dialog-item.is-active,body.dark-theme .tg-dialog-item:hover,body.dark-theme .tg-dialog-item.is-active,body[data-theme="dark"] .tg-dialog-item:hover,body[data-theme="dark"] .tg-dialog-item.is-active,html[data-theme="dark"] .tg-dialog-item:hover,html[data-theme="dark"] .tg-dialog-item.is-active{background:#202b38}body.theme-dark .tg-dialog-name,body.theme-dark .tg-dialog-person-name,body.theme-dark .tg-dialog-side-head h4,body.theme-dark .tg-dialog-side-label,body.dark-theme .tg-dialog-name,body.dark-theme .tg-dialog-person-name,body.dark-theme .tg-dialog-side-head h4,body.dark-theme .tg-dialog-side-label,body[data-theme="dark"] .tg-dialog-name,body[data-theme="dark"] .tg-dialog-person-name,body[data-theme="dark"] .tg-dialog-side-head h4,body[data-theme="dark"] .tg-dialog-side-label,html[data-theme="dark"] .tg-dialog-name,html[data-theme="dark"] .tg-dialog-person-name,html[data-theme="dark"] .tg-dialog-side-head h4,html[data-theme="dark"] .tg-dialog-side-label{color:#f8fafc}body.theme-dark .tg-dialog-time,body.theme-dark .tg-dialog-preview,body.theme-dark .tg-dialog-channel,body.theme-dark .tg-dialog-person-bot,body.theme-dark .tg-dialog-person-user,body.theme-dark .tg-dialog-info-row span:first-child,body.dark-theme .tg-dialog-time,body.dark-theme .tg-dialog-preview,body.dark-theme .tg-dialog-channel,body.dark-theme .tg-dialog-person-bot,body.dark-theme .tg-dialog-person-user,body.dark-theme .tg-dialog-info-row span:first-child,body[data-theme="dark"] .tg-dialog-time,body[data-theme="dark"] .tg-dialog-preview,body[data-theme="dark"] .tg-dialog-channel,body[data-theme="dark"] .tg-dialog-person-bot,body[data-theme="dark"] .tg-dialog-person-user,body[data-theme="dark"] .tg-dialog-info-row span:first-child,html[data-theme="dark"] .tg-dialog-time,html[data-theme="dark"] .tg-dialog-preview,html[data-theme="dark"] .tg-dialog-channel,html[data-theme="dark"] .tg-dialog-person-bot,html[data-theme="dark"] .tg-dialog-person-user,html[data-theme="dark"] .tg-dialog-info-row span:first-child{color:#94a3b8}body.theme-dark .tg-dialog-tab,body.dark-theme .tg-dialog-tab,body[data-theme="dark"] .tg-dialog-tab,html[data-theme="dark"] .tg-dialog-tab{background:#1b2430;border-color:#2c394a;color:#cbd5e1}body.theme-dark .tg-dialog-tab.is-active,body.dark-theme .tg-dialog-tab.is-active,body[data-theme="dark"] .tg-dialog-tab.is-active,html[data-theme="dark"] .tg-dialog-tab.is-active{background:#3a2b1e;border-color:#7a4b1b;color:#ffb66d}body.theme-dark .tg-dialog-compose textarea,body.dark-theme .tg-dialog-compose textarea,body[data-theme="dark"] .tg-dialog-compose textarea,html[data-theme="dark"] .tg-dialog-compose textarea{background:transparent;color:#f8fafc}body.theme-dark .tg-chat-tool,body.theme-dark .tg-chat-send,body.dark-theme .tg-chat-tool,body.dark-theme .tg-chat-send,body[data-theme="dark"] .tg-chat-tool,body[data-theme="dark"] .tg-chat-send,html[data-theme="dark"] .tg-chat-tool,html[data-theme="dark"] .tg-chat-send{box-shadow:none}


        @media (min-width:821px){
            .tg-dialog-chat-head > .tg-dialog-mobile-back{display:none!important}
            .tg-dialog-side-head > .tg-dialog-mobile-back{display:none!important}
            .tg-dialog-compose .tg-readonly-note{display:none!important}
        }
        body.theme-dark .tg-dialog-desktop-menu-button,body.dark-theme .tg-dialog-desktop-menu-button,body[data-theme="dark"] .tg-dialog-desktop-menu-button,html[data-theme="dark"] .tg-dialog-desktop-menu-button{background:#1b2430;border-color:#2c394a;color:#cbd5e1}
        body.theme-dark .tg-dialog-desktop-menu-button:hover,body.dark-theme .tg-dialog-desktop-menu-button:hover,body[data-theme="dark"] .tg-dialog-desktop-menu-button:hover,html[data-theme="dark"] .tg-dialog-desktop-menu-button:hover{background:#3a2b1e;border-color:#7a4b1b;color:#ffb66d}
        body.asr-dialogs-fullscreen .tg-dialogs-shell{height:100dvh!important;min-height:0!important;max-height:100dvh!important;margin:0!important;border-radius:0!important;border-left:0!important;border-right:0!important;border-top:0!important;border-bottom:0!important;display:flex!important;flex-direction:column!important;overflow:hidden!important;box-shadow:none!important}
        body.asr-dialogs-fullscreen .tg-dialogs-head{display:none!important}
        body.asr-dialogs-fullscreen .tg-dialog-tabs{flex:0 0 auto!important;border-top:0!important}
        body.asr-dialogs-fullscreen .tg-dialog-grid{flex:1 1 auto!important;min-height:0!important;height:auto!important;overflow:hidden!important}
        body.asr-dialogs-fullscreen .tg-dialog-list,body.asr-dialogs-fullscreen .tg-dialog-main,body.asr-dialogs-fullscreen .tg-dialog-side{min-height:0!important;height:100%!important}
        body.asr-dialogs-fullscreen .tg-dialog-chat-head{flex:0 0 auto!important}
        body.asr-dialogs-fullscreen .tg-dialog-chat-body{flex:1 1 auto!important;min-height:0!important;overflow:auto!important}
        body.asr-dialogs-fullscreen .tg-dialog-compose{flex:0 0 auto!important;margin-bottom:0!important}
        @media(max-width:820px){body.asr-dialogs-fullscreen .tg-dialogs-shell{height:100dvh!important;max-height:100dvh!important;margin:0!important;border-radius:0!important}body.asr-dialogs-fullscreen .tg-dialog-tabs{padding-top:8px!important;padding-bottom:8px!important}body.asr-dialogs-fullscreen .tg-dialog-chat-head{padding-top:8px!important;padding-bottom:8px!important}body.asr-dialogs-fullscreen .tg-dialog-compose{padding-bottom:max(8px,env(safe-area-inset-bottom))!important}}

        /* Step 3.4.3: stable chat height and bottom compose bar in fullscreen dialogs */
        body.asr-dialogs-fullscreen{overflow:hidden!important;overscroll-behavior:none!important}
        body.asr-dialogs-fullscreen .tg-dialogs-shell{height:var(--tg-dialog-vh,100dvh)!important;max-height:var(--tg-dialog-vh,100dvh)!important;min-height:0!important;display:flex!important;flex-direction:column!important;overflow:hidden!important}
        body.asr-dialogs-fullscreen .tg-dialog-tabs{flex:0 0 auto!important;margin:0!important}
        body.asr-dialogs-fullscreen .tg-dialog-grid{flex:1 1 auto!important;min-height:0!important;height:auto!important;overflow:hidden!important}
        body.asr-dialogs-fullscreen .tg-dialog-list,body.asr-dialogs-fullscreen .tg-dialog-main,body.asr-dialogs-fullscreen .tg-dialog-side{min-height:0!important;height:100%!important;overflow:hidden!important}
        body.asr-dialogs-fullscreen .tg-dialog-main{display:flex!important;flex-direction:column!important}
        body.asr-dialogs-fullscreen .tg-dialog-chat-head{flex:0 0 auto!important}
        body.asr-dialogs-fullscreen .tg-dialog-chat-body{flex:1 1 auto!important;min-height:0!important;overflow:auto!important;-webkit-overflow-scrolling:touch!important}
        body.asr-dialogs-fullscreen .tg-dialog-compose{flex:0 0 auto!important;margin:0!important;margin-top:auto!important}
        body.asr-dialogs-fullscreen .tg-dialog-list-scroll,body.asr-dialogs-fullscreen .tg-dialog-side{overflow:auto!important;-webkit-overflow-scrolling:touch!important}
        @media(max-width:820px){body.asr-dialogs-fullscreen .tg-dialogs-shell{height:var(--tg-dialog-vh,100dvh)!important;max-height:var(--tg-dialog-vh,100dvh)!important}body.asr-dialogs-fullscreen .tg-dialog-grid{flex:1 1 auto!important;min-height:0!important}body.asr-dialogs-fullscreen .tg-dialog-compose{padding-bottom:max(8px,env(safe-area-inset-bottom))!important}body.asr-dialogs-fullscreen .tg-dialog-compose textarea{min-height:48px!important;max-height:82px!important}}
        .tg-dialog-dot.is-red{background:#ef4444}.tg-dialog-dot.is-green{background:#22c55e}.tg-dialog-dot.is-muted{background:#d4d4d8}.tg-dialogs-shell.is-loading{cursor:progress}.tg-dialog-main.is-ajax-loading{position:relative}.tg-dialog-main.is-ajax-loading:before{content:'';position:absolute;inset:0;z-index:12;background:rgba(255,255,255,.55);pointer-events:none}.tg-dialog-main.is-ajax-loading:after{content:'';position:absolute;left:50%;top:72px;z-index:13;width:28px;height:28px;margin-left:-14px;border-radius:999px;border:3px solid #e5e7eb;border-top-color:#FFA048;animation:tgDialogSpin .75s linear infinite}@keyframes tgDialogSpin{to{transform:rotate(360deg)}}.tg-dialog-ajax-toast{position:fixed;right:22px;bottom:22px;z-index:300;background:#111827;color:#fff;border-radius:15px;padding:12px 16px;font-size:13px;font-weight:600;box-shadow:0 18px 40px rgba(15,23,42,.22);transition:opacity .35s ease,transform .35s ease}.tg-dialog-ajax-toast.is-error{background:#991b1b}.tg-dialog-ajax-toast.is-out{opacity:0;transform:translateY(8px)}

    </style>

<script id="tg-dialogs-viewport-fit-js">
(function(){
  if(!document.querySelector('.tg-dialogs-shell')) return;
  function setVars(){
    var viewport=window.visualViewport;
    var h=Math.floor((viewport&&viewport.height)?viewport.height:window.innerHeight);
    if(!h || h<320) h=window.innerHeight||720;
    document.documentElement.style.setProperty('--tg-dialog-vh', h+'px');
    var tabs=document.querySelector('.tg-dialog-tabs');
    if(tabs){
      document.documentElement.style.setProperty('--tg-dialog-tabs-h', Math.ceil(tabs.getBoundingClientRect().height||0)+'px');
    }
    var shell=document.querySelector('.tg-dialogs-shell');
    if(shell && document.body.classList.contains('asr-dialogs-fullscreen')){
      shell.style.setProperty('height', h+'px', 'important');
      shell.style.setProperty('max-height', h+'px', 'important');
      shell.style.setProperty('min-height', '0', 'important');
    }
    var feed=document.querySelector('[data-dialog-chat-feed]');
    if(feed && feed.dataset.keepBottom==='1') feed.scrollTop=feed.scrollHeight;
  }
  window.asrTgDialogsFitViewport=setVars;
  setVars();
  window.addEventListener('load',setVars);
  window.addEventListener('resize',setVars);
  window.addEventListener('orientationchange',function(){setTimeout(setVars,120);setTimeout(setVars,420);});
  if(window.visualViewport){
    window.visualViewport.addEventListener('resize',setVars);
    window.visualViewport.addEventListener('scroll',setVars);
  }
  document.addEventListener('focusin',function(e){
    if(e.target && e.target.matches('[data-dialog-chat-text]')){
      var feed=document.querySelector('[data-dialog-chat-feed]');
      if(feed) feed.dataset.keepBottom='1';
      setTimeout(setVars,120);
      setTimeout(setVars,360);
    }
  });
  document.addEventListener('focusout',function(e){
    if(e.target && e.target.matches('[data-dialog-chat-text]')){
      var feed=document.querySelector('[data-dialog-chat-feed]');
      if(feed) feed.dataset.keepBottom='0';
      setTimeout(setVars,160);
    }
  });
  try{new MutationObserver(function(){setVars();}).observe(document.querySelector('.tg-dialogs-shell'),{childList:true,subtree:true});}catch(e){}
})();
</script>
    <section class="tg-dialogs-shell" data-mobile-panel="<?php echo $selectedDialogSubscriberId > 0 ? 'chat' : 'list'; ?>">
        <div class="tg-dialogs-head">
            <div class="tg-dialogs-title"><img src="/assets/admin/icons/tb2-chat-gray.svg" alt="" aria-hidden="true"><div><h3>Диалоги</h3><p>Рабочий центр переписок с клиентами по всем подключённым каналам.</p></div></div>
        </div>
        <div class="tg-dialog-tabs" data-needs-reply-total="<?php echo (int)($dialogCounts['needs_reply_total'] ?? 0); ?>">
            <button type="button" class="tg-dialog-desktop-menu-button" title="Открыть меню" aria-label="Открыть меню" onclick="return window.asrTgDialogsOpenMainMenu ? window.asrTgDialogsOpenMainMenu(event) : true;">☰</button>
            <div class="tg-dialog-mobile-panel-dropdown" data-dialog-panel-dropdown>
                <button type="button" class="tg-dialog-mobile-panel-button" data-dialog-panel-toggle title="Области диалогов" onclick="return window.asrTgDialogsTogglePanelMenu ? window.asrTgDialogsTogglePanelMenu(this,event) : false;">☰</button>
                <div class="tg-dialog-panel-menu" data-dialog-panel-menu>
                    <button type="button" data-mobile-panel="list" onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Список</button>
                    <button type="button" data-mobile-panel="chat" <?php echo $selectedDialogSubscriberId > 0 ? '' : 'disabled'; ?> onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Чат</button>
                    <button type="button" data-mobile-panel="card" <?php echo $selectedDialogSubscriberId > 0 ? '' : 'disabled'; ?> onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Карточка</button>
                </div>
            </div>
            <?php foreach ($dialogViewLabels as $viewKey => $viewLabel):
                $viewUrl = $dialogUrlBase . '&dialog_view=' . rawurlencode($viewKey);
            ?>
                <?php
                    $viewCount = (int)($dialogCounts[$dialogCountKeys[$viewKey] ?? $viewKey] ?? 0);
                    $showViewCount = !in_array($viewKey, ['closed','spam'], true);
                    $needsReplyCount = in_array($viewKey, ['new','my','assigned'], true) ? (int)($dialogCounts['needs_reply_' . $viewKey] ?? 0) : 0;
                ?>
                <a class="tg-dialog-tab <?php echo $dialogView === $viewKey ? 'is-active' : ''; ?>" href="<?php echo $h($viewUrl); ?>" data-dialog-view="<?php echo $h($viewKey); ?>" data-needs-reply="<?php echo $needsReplyCount; ?>" onclick="return window.asrTgDialogsTabFromLink ? window.asrTgDialogsTabFromLink(this,event) : true;"><?php echo $h($viewLabel); ?><?php if ($showViewCount && $viewCount > 0): ?><span class="tg-dialog-tab-count"><?php echo $viewCount; ?></span><?php endif; ?><?php if ($needsReplyCount > 0): ?><span class="tg-dialog-tab-alert" title="Есть диалоги без ответа"></span><?php endif; ?></a>
            <?php endforeach; ?>
            <?php if ($dialogAutoCloseEnabled || $dialogAutoReplyEnabled): ?>
                <span class="tg-dialog-mode-icons" aria-label="Активные автоматические настройки диалогов">
                    <?php if ($dialogAutoCloseEnabled): ?><span class="tg-dialog-mode-icon tg-dialog-mode-icon--close" title="Автоматически закрывать входящие диалоги"><img src="/assets/admin/icons/close-icon.png" alt="" aria-hidden="true"></span><?php endif; ?>
                    <?php if ($dialogAutoReplyEnabled): ?><span class="tg-dialog-mode-icon tg-dialog-mode-icon--auto" title="Автоматический ответ включён"><img src="/assets/admin/icons/autosend-icon.png" alt="" aria-hidden="true"></span><?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="tg-dialog-mobile-switch" aria-label="Переключение областей диалогов" hidden>
            <button type="button" data-mobile-panel="list" onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Список</button>
            <button type="button" data-mobile-panel="chat" <?php echo $selectedDialogSubscriberId > 0 ? '' : 'disabled'; ?> onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Чат</button>
            <button type="button" data-mobile-panel="card" <?php echo $selectedDialogSubscriberId > 0 ? '' : 'disabled'; ?> onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Карточка</button>
        </div>
        <div class="tg-dialog-grid">
            <aside class="tg-dialog-list">
                <form class="tg-dialog-list-tools" method="GET">
                    <input type="hidden" name="tab" value="telegram_bots">
                    <input type="hidden" name="page" value="messages">
                    <input type="hidden" name="dialog_view" value="<?php echo $h($dialogView); ?>">
                    <?php if ($selectedBotId > 0): ?><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><?php endif; ?>
                    <?php if ($dialogUnreadOnly): ?><input type="hidden" name="dialog_unread" value="1"><?php endif; ?>
                    <?php foreach ($dialogChannels as $channelId): ?><input type="hidden" name="dialog_channel[]" value="<?php echo (int)$channelId; ?>"><?php endforeach; ?>
                    <?php foreach ($dialogTagIds as $tagId): ?><input type="hidden" name="dialog_tag[]" value="<?php echo (int)$tagId; ?>"><?php endforeach; ?>
                    <div class="tg-dialog-search"><input type="search" name="dialog_q" value="<?php echo $h($dialogQ); ?>" placeholder="Поиск диалога"></div>
                    <button type="submit" class="tg-dialog-tool-btn" title="Найти"><img src="/assets/admin/icons/search.svg" alt="" aria-hidden="true"></button>
                    <button type="button" class="tg-dialog-tool-btn" data-tg-dialog-settings-open title="Настройки диалогов"><img src="/assets/admin/icons/tb2-gear-gray.svg" alt="" aria-hidden="true"></button>
                </form>
                <form class="tg-dialog-filter-panel <?php echo $dialogFilterIsOpen ? 'is-open' : ''; ?>" data-tg-dialog-filter-panel method="GET">
                    <input type="hidden" name="tab" value="telegram_bots">
                    <input type="hidden" name="page" value="messages">
                    <input type="hidden" name="dialog_view" value="<?php echo $h($dialogView); ?>">
                    <?php if ($selectedBotId > 0): ?><input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>"><?php endif; ?>
                    <?php if ($dialogQ !== ''): ?><input type="hidden" name="dialog_q" value="<?php echo $h($dialogQ); ?>"><?php endif; ?>
                    <div class="tg-dialog-filter-section">
                        <label class="tg-dialog-check"><input type="checkbox" name="dialog_unread" value="1" <?php echo $dialogUnreadOnly ? 'checked' : ''; ?>> Только непрочитанные</label>
                        <div class="tg-dialog-filter-muted">Работает по реальному счётчику непрочитанных входящих сообщений.</div>
                    </div>
                    <div class="tg-dialog-filter-section">
                        <div class="tg-dialog-filter-title">Каналы</div>
                        <?php foreach ($bots as $bot): ?>
                            <label class="tg-dialog-check"><input type="checkbox" name="dialog_channel[]" value="<?php echo (int)$bot['id']; ?>" <?php echo in_array((int)$bot['id'], $dialogChannels, true) ? 'checked' : ''; ?>> <?php echo $h((string)$bot['title']); ?></label>
                        <?php endforeach; ?>
                        <?php if (!$bots): ?><div class="tg-dialog-filter-muted">Каналов пока нет.</div><?php endif; ?>
                    </div>
                    <div class="tg-dialog-filter-section">
                        <div class="tg-dialog-filter-title">Метки</div>
                        <?php foreach ($dialogAllTags as $tag): ?>
                            <label class="tg-dialog-check"><input type="checkbox" name="dialog_tag[]" value="<?php echo (int)$tag['id']; ?>" <?php echo in_array((int)$tag['id'], $dialogTagIds, true) ? 'checked' : ''; ?>> <?php echo $h((string)$tag['name']); ?><?php echo !empty($tag['bot_title']) ? ' · ' . $h((string)$tag['bot_title']) : ''; ?></label>
                        <?php endforeach; ?>
                        <?php if (!$dialogAllTags): ?><div class="tg-dialog-filter-muted">Меток пока нет.</div><?php endif; ?>
                    </div>
                    <div class="tg-dialog-filter-actions"><a class="tg-dialog-filter-reset" href="<?php echo $h($dialogResetUrl); ?>">Сбросить</a><button type="submit" class="tg-dialog-filter-apply">Применить</button></div>
                </form>
                <div class="tg-dialog-list-scroll">
                <?php if ((int)($dialogCounts['unread_dialogs'] ?? 0) > 0): ?>
                    <div class="tg-dialog-unread-summary">Непрочитанные: <?php echo (int)$dialogCounts['unread_dialogs']; ?> диал. / <?php echo (int)$dialogCounts['unread_messages']; ?> сообщ.</div>
                <?php endif; ?>
                <?php if (!$dialogRows): ?>
                    <div class="tg-dialog-empty">Диалогов пока нет. Они появятся, когда клиент напишет в бот или ответит на рассылку/сценарий.</div>
                <?php endif; ?>
                <?php foreach ($dialogRows as $row):
                    $sid = (int)$row['subscriber_id'];
                    $active = $sid === $selectedDialogSubscriberId;
                    $url = $dialogUrlBase . '&dialog_view=' . rawurlencode($dialogView) . '&subscriber_id=' . $sid;
                    $preview = trim((string)($row['message_text'] ?? ''));
                    if ($preview === '') $preview = 'Файл / ' . (string)($row['message_type'] ?? 'сообщение');
                ?>
                    <?php
                        $unreadCount = $active ? 0 : (int)($row['unread_count'] ?? 0);
                        $dialogStatus = (string)($row['dialog_status'] ?? 'new');
                        $rowLastDirection = (string)($row['last_direction'] ?? $row['direction'] ?? '');
                        $rowNeedsReply = $rowLastDirection === 'in' && !in_array($dialogStatus, ['closed','spam'], true);
                        $rowDotClass = in_array($dialogStatus, ['closed','spam'], true) ? 'is-muted' : ($rowNeedsReply ? 'is-red' : 'is-green');
                    ?>
                    <a class="tg-dialog-item <?php echo $active ? 'is-active' : ''; ?> <?php echo $unreadCount > 0 ? 'is-unread' : ''; ?>" href="<?php echo $h($url); ?>" data-dialog-subscriber-id="<?php echo (int)$sid; ?>" data-dialog-bot-id="<?php echo (int)($row['bot_id'] ?? $selectedBotId); ?>" onclick="return window.asrTgDialogsOpenFromLink ? window.asrTgDialogsOpenFromLink(this,event) : true;">
                        <div class="tg-dialog-avatar"><img src="<?php echo $h($dialogChannelIcon($row)); ?>" alt="" aria-hidden="true"><span class="tg-dialog-dot <?php echo $rowDotClass; ?>"></span></div>
                        <div class="tg-dialog-item-body">
                            <div class="tg-dialog-item-top"><div class="tg-dialog-name"><?php echo $h($dialogName($row)); ?></div><div class="tg-dialog-time"><?php echo $h((string)($row['last_message_at'] ?? '')); ?></div></div>
                            <div class="tg-dialog-preview"><?php echo $h(((string)($row['direction'] ?? '') === 'out' ? 'Вы: ' : '') . $preview); ?></div>
                            <div class="tg-dialog-channel"><?php echo $h((string)($row['bot_title'] ?? 'Канал')); ?><?php echo !empty($row['username']) ? ' · @' . $h($row['username']) : ''; ?> · <?php echo $h($dialogStatusLabels[$dialogStatus] ?? $dialogStatus); ?><?php if ($unreadCount > 0): ?> · <span class="tg-dialog-unread-badge"><?php echo $unreadCount; ?></span><?php endif; ?></div>
                            <?php $assignedLine = $dialogAssigneeLabel($row); if ($assignedLine !== ''): ?><div class="tg-dialog-assigned-line">Ответственный: <?php echo $h($assignedLine); ?></div><?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            </aside>
            <main class="tg-dialog-main">
                <?php if (!$dialogSubscriber): ?>
                    <div class="tg-dialog-empty">Выберите диалог слева.</div>
                <?php else: ?>
                    <?php
                        $selectedDialogMeta = null;
                        foreach ($dialogRows as $dr) {
                            if ((int)($dr['subscriber_id'] ?? 0) === (int)$dialogSubscriber['id']) { $selectedDialogMeta = $dr; break; }
                        }
                        if ($selectedDialogMeta && !$dialogKeepUnread) $selectedDialogMeta['unread_count'] = 0;
                        $selectedDialogStatus = (string)($selectedDialogMeta['dialog_status'] ?? 'new');
                        $selectedAssignedUserId = (int)($selectedDialogMeta['assigned_user_id'] ?? 0);
                        $selectedAssigneeLabel = $selectedDialogMeta ? $dialogAssigneeLabel($selectedDialogMeta) : '';
                        $selectedDialogUnreadCount = (int)($selectedDialogMeta['unread_count'] ?? 0);
                    ?>
                    <?php
                        $selectedLastDirection = (string)($selectedDialogMeta['last_direction'] ?? $selectedDialogMeta['direction'] ?? '');
                        $needsReply = $selectedLastDirection === 'in' && !in_array($selectedDialogStatus, ['closed','spam'], true);
                    ?>
                    <div class="tg-dialog-chat-head">
                        <button type="button" class="tg-dialog-mobile-back" data-mobile-panel="list"><img src="/assets/admin/icons/tb2-back-gray.svg" alt="" aria-hidden="true">Список</button>
                        <div class="tg-dialog-chat-person">
                            <div class="tg-dialog-avatar"><img src="<?php echo $h($dialogChannelIcon($dialogSubscriber)); ?>" alt="" aria-hidden="true"></div>
                            <div class="tg-dialog-person-lines">
                                <div class="tg-dialog-person-name"><?php echo $h($dialogName($dialogSubscriber)); ?><span class="tg-dialog-status-dot2 <?php echo $needsReply ? 'is-red' : ''; ?>" title="<?php echo $needsReply ? 'Ждёт ответа' : 'Ответ дан'; ?>"></span></div>
                                <div class="tg-dialog-person-bot"><?php echo $h((string)($dialogSubscriber['bot_title'] ?? 'Канал')); ?></div>
                                <?php if (!empty($dialogSubscriber['username'])): ?><div class="tg-dialog-person-user">@<?php echo $h($dialogSubscriber['username']); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="tg-dialog-compact-actions">
                            <?php $dialogActionBaseInputs = function(string $status) use ($dialogSubscriber, $h): string { ob_start(); ?><input type="hidden" name="action" value="tg_dialog_status"><input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>"><input type="hidden" name="dialog_status" value="<?php echo $h($status); ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; return ob_get_clean(); }; ?>
                            <div class="tg-dialog-desktop-toolbar">
                                <form method="POST" class="tg-dialog-assign-form">
                                    <input type="hidden" name="action" value="tg_dialog_assign">
                                    <input type="hidden" name="return_page" value="messages">
                                    <input type="hidden" name="dialog_view" value="<?php echo $h($dialogView); ?>">
                                    <input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>">
                                    <input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>">
                                    <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                                    <select class="tg-dialog-assign-select" name="assigned_user_id">
                                        <option value="0">Без ответственного</option>
                                        <?php foreach ($dialogAssignableUsers as $u): $uid = (int)$u['id']; ?>
                                            <option value="<?php echo $uid; ?>" <?php echo $selectedAssignedUserId === $uid ? 'selected' : ''; ?>><?php echo $h($dialogUserName($u)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php if ($selectedDialogStatus !== 'closed'): ?><form method="POST" class="tg-dialog-action-form"><?php echo $dialogActionBaseInputs('closed'); ?><button class="tg-dialog-action-btn is-icon" type="submit" title="Закрыть">×</button></form><?php endif; ?>
                                <?php if ($selectedDialogStatus !== 'spam'): ?><form method="POST" class="tg-dialog-action-form" onsubmit="return confirm('Перенести диалог в спам?');"><?php echo $dialogActionBaseInputs('spam'); ?><button class="tg-dialog-action-btn is-icon is-danger" type="submit" title="В спам">!</button></form><?php endif; ?>
                                <?php if (in_array($selectedDialogStatus, ['closed','spam'], true)): ?><form method="POST" class="tg-dialog-action-form"><?php echo $dialogActionBaseInputs('new'); ?><button class="tg-dialog-action-btn is-icon is-soft" type="submit" title="Вернуть в новые">↺</button></form><?php endif; ?>
                            </div>
                            <div class="tg-dialog-mobile-toolbar">
                                <div class="tg-dialog-dropdown" data-dialog-dropdown>
                                    <button type="button" class="tg-dialog-icon-btn" data-dialog-dropdown-toggle title="Ответственный"><img src="/assets/admin/icons/users.png" alt="" aria-hidden="true"></button>
                                    <div class="tg-dialog-dropdown-menu" data-dialog-dropdown-menu>
                                        <div class="tg-dialog-dropdown-title">Ответственный</div>
                                        <form method="POST" class="tg-dialog-assign-form">
                                            <input type="hidden" name="action" value="tg_dialog_assign">
                                            <input type="hidden" name="return_page" value="messages">
                                            <input type="hidden" name="dialog_view" value="<?php echo $h($dialogView); ?>">
                                            <input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>">
                                            <input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>">
                                            <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                                            <select class="tg-dialog-assign-select" name="assigned_user_id">
                                                <option value="0">Без ответственного</option>
                                                <?php foreach ($dialogAssignableUsers as $u): $uid = (int)$u['id']; ?>
                                                    <option value="<?php echo $uid; ?>" <?php echo $selectedAssignedUserId === $uid ? 'selected' : ''; ?>><?php echo $h($dialogUserName($u)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($selectedDialogStatus !== 'closed'): ?><form method="POST" class="tg-dialog-action-form"><?php echo $dialogActionBaseInputs('closed'); ?><button class="tg-dialog-action-btn is-icon" type="submit" title="Закрыть">×</button></form><?php endif; ?>
                                <?php if ($selectedDialogStatus !== 'spam'): ?><form method="POST" class="tg-dialog-action-form" onsubmit="return confirm('Перенести диалог в спам?');"><?php echo $dialogActionBaseInputs('spam'); ?><button class="tg-dialog-action-btn is-icon is-danger" type="submit" title="В спам">!</button></form><?php endif; ?>
                                <?php if (in_array($selectedDialogStatus, ['closed','spam'], true)): ?><form method="POST" class="tg-dialog-action-form"><?php echo $dialogActionBaseInputs('new'); ?><button class="tg-dialog-action-btn is-icon is-soft" type="submit" title="Вернуть в новые">↺</button></form><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="tg-dialog-chat-body" data-dialog-chat-feed>
                        <?php $lastDay = ''; foreach ($dialogMessages as $message):
                            $day = substr((string)$message['created_at'], 0, 10);
                            if ($day !== $lastDay): $lastDay = $day; ?><div class="tg-dialog-day"><?php echo $h($day); ?></div><?php endif;
                            $direction = (string)($message['direction'] ?? 'in');
                            $messagePayload = json_decode((string)($message['payload_json'] ?? ''), true);
                            if (!is_array($messagePayload)) $messagePayload = [];
                            $messageAttachment = is_array($messagePayload['attachment'] ?? null) ? $messagePayload['attachment'] : [];
                            if (!$messageAttachment && (isset($messagePayload['file_path']) || isset($messagePayload['file_name']))) {
                                $messageAttachment = [
                                    'file_path' => (string)($messagePayload['file_path'] ?? ''),
                                    'file_name' => (string)($messagePayload['file_name'] ?? 'файл'),
                                    'media_type' => (string)($messagePayload['media_type'] ?? $messagePayload['message_type'] ?? 'файл'),
                                ];
                            }
                            if (!$messageAttachment && is_array($messagePayload['document'] ?? null)) {
                                $messageAttachment = [
                                    'file_path' => (string)($messagePayload['document']['file_path'] ?? ''),
                                    'file_name' => (string)($messagePayload['document']['file_name'] ?? 'файл'),
                                    'media_type' => 'document',
                                ];
                            }
                            $messageText = function_exists('asr_tg_plain_message_text') ? asr_tg_plain_message_text((string)($message['message_text'] ?? '')) : strip_tags((string)($message['message_text'] ?? ''));
                            $messageType = (string)($message['message_type'] ?? 'message');
                            $isBroadcastContext = $messageType === 'broadcast_context' || !empty($messagePayload['broadcast_context']);
                            $isSystemMessage = !$isBroadcastContext && (in_array($messageType, ['system','service','event'], true)
                                || !empty($messagePayload['system_event'])
                                || !empty($messagePayload['dialog_event'])
                                || (bool)preg_match('/^(Рассылка|Сценарий|Автоответ)\s*:/u', $messageText));
                            if ($isBroadcastContext):
                        ?>
                            <div class="tg-dialog-broadcast-context" data-broadcast-context-id="<?php echo (int)($message['id'] ?? 0); ?>"><div class="tg-dialog-broadcast-context-title">Рассылка: <?php echo $h((string)($messagePayload['title'] ?? 'Рассылка')); ?></div><div class="tg-dialog-broadcast-context-text"><?php echo $h($messageText !== '' ? $messageText : '[рассылка]'); ?></div><div class="tg-dialog-broadcast-context-meta"><?php echo $h($message['created_at'] ?? ''); ?></div></div>
                        <?php elseif ($isSystemMessage):
                                $systemText = $messageText !== '' ? $messageText : '[' . $messageType . ']';
                        ?>
                            <div class="tg-dialog-system-note"><?php echo $h($systemText); ?><small><?php echo $h(substr((string)$message['created_at'], 11, 5)); ?></small></div>
                        <?php else: ?>
                            <div class="tg-chat-bubble <?php echo $direction === 'out' ? 'out' : 'in'; ?>"><?php echo $h($messageText !== '' ? $messageText : '[' . $messageType . ']'); ?><?php if ($messageAttachment): ?><div class="tg-chat-attachment"><span>Файл</span><div><?php if (!empty($messageAttachment['file_path'])): ?><a href="/<?php echo $h(ltrim((string)$messageAttachment['file_path'], '/')); ?>" target="_blank" rel="noopener"><?php echo $h((string)($messageAttachment['file_name'] ?? 'файл')); ?></a><?php else: ?><?php echo $h((string)($messageAttachment['file_name'] ?? 'файл')); ?><?php endif; ?><br><small><?php echo $h((string)($messageAttachment['media_type'] ?? 'файл')); ?></small></div></div><?php endif; ?><span class="tg-chat-meta"><?php echo $direction === 'out' ? 'исходящее' : 'входящее'; ?> · <?php echo $h((string)($message['created_at'] ?? '')); ?></span></div>
                        <?php endif; endforeach; ?>
                        <?php if (!$dialogMessages): ?><div class="tg-dialog-empty">Истории сообщений пока нет.</div><?php endif; ?>
                    </div>
                    <?php if (strtolower((string)($dialogSubscriber['channel_type'] ?? 'telegram')) === 'vk'): ?>
                        <div class="tg-dialog-compose"><div class="tg-dialog-empty">Отправка доступна только для Telegram-каналов.</div></div>
                    <?php else: ?>
                        <form method="POST" class="tg-dialog-compose" enctype="multipart/form-data" data-dialog-compose>
                            <input type="hidden" name="action" value="tg_subscriber_send_message">
                            <input type="hidden" name="return_page" value="messages">
                            <input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>">
                            <input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>">
                            <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                            <textarea name="message_text" placeholder="Введите сообщение..." data-dialog-chat-text></textarea>
                            <input type="file" name="chat_attachment" class="hidden" data-dialog-chat-file accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.m4v,.webm,.mp3,.m4a,.ogg,.wav,.pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                            <div class="tg-chat-actions"><div class="tg-chat-tool-row"><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-emoji title="Эмодзи" aria-label="Эмодзи"><span class="tg-chat-tool-glyph" aria-hidden="true">☺</span></button><div class="tg-chat-emoji-menu" data-dialog-chat-emoji-menu></div></div><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-macro title="Переменные" aria-label="Переменные"><span class="tg-chat-tool-glyph" aria-hidden="true">{}</span></button><div class="tg-chat-macro-menu" data-dialog-chat-macro-menu></div></div><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-attach title="Прикрепить файл" aria-label="Прикрепить файл"><span class="tg-chat-tool-glyph" aria-hidden="true">📎</span></button><span class="tg-chat-file-name" data-dialog-chat-file-name></span></div><button class="tg-chat-send" type="submit">Отправить</button></div>
                            <div class="tg-readonly-note">Можно отправить текст, эмодзи и один файл до 45 МБ.</div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
            <aside class="tg-dialog-side">
                <?php if (!$dialogSubscriber): ?>
                    <div class="tg-dialog-empty">Карточка появится после выбора диалога.</div>
                <?php else: ?>
                    <div class="tg-dialog-side-head"><button type="button" class="tg-dialog-mobile-back" data-mobile-panel="chat"><img src="/assets/admin/icons/tb2-back-gray.svg" alt="" aria-hidden="true">Чат</button><h4><?php echo $h($dialogName($dialogSubscriber)); ?></h4></div>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Основная информация</div>
                        <div class="tg-dialog-detail-block">
                            <div class="tg-dialog-info-row"><span>Статус</span><span><?php echo $h($dialogSubscriberStatusLabels[(string)($dialogSubscriber['status'] ?? 'active')] ?? (string)($dialogSubscriber['status'] ?? 'active')); ?></span></div>
                            <div class="tg-dialog-info-row"><span>Диалог</span><span><?php echo $h($dialogStatusLabels[$selectedDialogStatus] ?? $selectedDialogStatus); ?></span></div>
                            <div class="tg-dialog-info-row"><span>Ответственный</span><span><?php echo $selectedAssigneeLabel !== '' ? $h($selectedAssigneeLabel) : 'не назначен'; ?></span></div>
                            <div class="tg-dialog-info-row"><span>User ID</span><span><?php echo $h((string)($dialogSubscriber['telegram_user_id'] ?? '')); ?></span></div>
                            <div class="tg-dialog-info-row"><span>Username</span><span><?php echo !empty($dialogSubscriber['username']) ? '@' . $h($dialogSubscriber['username']) : 'не указан'; ?></span></div>
                        </div>
                    </div>
                    <?php if ($dialogChannelMemberships): ?>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Подписан на каналы</div>
                        <div class="tg-dialog-member-list">
                            <?php foreach ($dialogChannelMemberships as $membership):
                                $membershipId = (int)($membership['id'] ?? 0);
                                $membershipBotId = (int)($membership['bot_id'] ?? 0);
                                $membershipHasDialog = (int)($membership['dialog_id'] ?? 0) > 0;
                                $membershipUnread = (int)($membership['unread_count'] ?? 0);
                                $membershipUrl = 'admin.php?tab=telegram_bots&page=messages&bot_id=' . $membershipBotId . '&subscriber_id=' . $membershipId;
                                $membershipTitle = (string)($membership['bot_title'] ?? 'Канал');
                                $membershipUser = trim((string)($membership['bot_username'] ?? ''));
                                $membershipStatus = (string)($membership['status'] ?? 'active');
                            ?>
                                <a class="tg-dialog-member-item <?php echo $membershipId === (int)$dialogSubscriber['id'] ? 'is-current' : ''; ?> <?php echo $membershipHasDialog ? 'has-dialog' : ''; ?> <?php echo $membershipUnread > 0 ? 'has-unread' : ''; ?>" href="<?php echo $h($membershipUrl); ?>" data-dialog-subscriber-id="<?php echo (int)$membershipId; ?>" data-dialog-bot-id="<?php echo (int)($m['bot_id'] ?? 0); ?>" onclick="return window.asrTgDialogsOpenFromLink ? window.asrTgDialogsOpenFromLink(this,event) : true;">
                                    <img class="tg-dialog-member-icon" src="<?php echo $h($dialogChannelIcon($membership)); ?>" alt="" aria-hidden="true">
                                    <span class="tg-dialog-member-text"><span class="tg-dialog-member-title"><?php echo $h($membershipTitle); ?></span><span class="tg-dialog-member-meta"><?php echo $membershipUser !== '' ? '@' . $h($membershipUser) . ' · ' : ''; ?><?php echo $h($dialogSubscriberStatusLabels[$membershipStatus] ?? $membershipStatus); ?></span></span>
                                    <span class="tg-dialog-member-badge"><?php echo $membershipUnread > 0 ? (string)$membershipUnread : ($membershipHasDialog ? 'диалог' : 'нет диалога'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Редактирование</div>
                        <form method="POST" class="tg-dialog-side-form">
                            <input type="hidden" name="action" value="tg_subscriber_profile_save">
                            <input type="hidden" name="return_page" value="messages">
                            <input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>">
                            <input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>">
                            <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                            <label><span>Имя</span><input name="first_name" value="<?php echo $h((string)($dialogSubscriber['first_name'] ?? '')); ?>" placeholder="Имя"></label>
                            <label><span>Фамилия</span><input name="last_name" value="<?php echo $h((string)($dialogSubscriber['last_name'] ?? '')); ?>" placeholder="Фамилия"></label>
                            <label><span>Телефон</span><input name="phone" value="<?php echo $h((string)($dialogSubscriber['phone'] ?? '')); ?>" placeholder="Добавить телефон"></label>
                            <label><span>Email</span><input name="email" value="<?php echo $h((string)($dialogSubscriber['email'] ?? '')); ?>" placeholder="Добавить эл. почту"></label>
                            <label><span>Статус</span><select name="status"><?php foreach ($dialogSubscriberStatusLabels as $statusKey => $statusLabel): ?><option value="<?php echo $h($statusKey); ?>" <?php echo (string)($dialogSubscriber['status'] ?? 'active') === $statusKey ? 'selected' : ''; ?>><?php echo $h($statusLabel); ?></option><?php endforeach; ?></select></label>
                            <label><span>Заметка администратора</span><textarea name="admin_note" placeholder="Внутренняя заметка по контакту"><?php echo $h((string)($dialogSubscriber['admin_note'] ?? '')); ?></textarea></label>
                            <button class="tg-dialog-side-save" type="submit">Сохранить информацию</button>
                        </form>
                    </div>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Настраиваемые поля</div>
                        <?php if ($dialogCustomFieldsActive): ?>
                            <form method="POST" class="tg-dialog-side-form tg-dialog-custom-values-form">
                                <input type="hidden" name="action" value="tg_subscriber_custom_values_save">
                                <input type="hidden" name="return_page" value="messages">
                                <input type="hidden" name="dialog_view" value="<?php echo $h($dialogView); ?>">
                                <input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>">
                                <input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>">
                                <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                                <?php foreach ($dialogCustomFieldsActive as $customField): ?>
                                    <?php
                                        $customFieldId = (int)($customField['id'] ?? 0);
                                        $customFieldType = (string)($customField['field_type'] ?? 'text');
                                        if (!in_array($customFieldType, ['text','number','date','datetime'], true)) $customFieldType = 'text';
                                        $customInputValue = function_exists('asr_tg_custom_value_for_input') ? asr_tg_custom_value_for_input($customField, $dialogCustomValuesMap) : '';
                                        $customInputName = 'custom_values[' . $customFieldId . ']';
                                    ?>
                                    <label><span><?php echo $h((string)($customField['title'] ?? 'Поле')); ?> <small><?php echo $h($customFieldType); ?></small></span>
                                        <?php if ($customFieldType === 'text'): ?>
                                            <input name="<?php echo $h($customInputName); ?>" value="<?php echo $h($customInputValue); ?>" placeholder="Заполнить значение">
                                        <?php elseif ($customFieldType === 'number'): ?>
                                            <input name="<?php echo $h($customInputName); ?>" type="number" step="any" value="<?php echo $h($customInputValue); ?>" placeholder="0">
                                        <?php elseif ($customFieldType === 'date'): ?>
                                            <input name="<?php echo $h($customInputName); ?>" type="date" value="<?php echo $h($customInputValue); ?>">
                                        <?php else: ?>
                                            <input name="<?php echo $h($customInputName); ?>" type="datetime-local" value="<?php echo $h($customInputValue); ?>">
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                                <button class="tg-dialog-side-save" type="submit">Сохранить поля</button>
                            </form>
                        <?php else: ?>
                            <div class="tg-dialog-custom-values-empty">Настраиваемых полей пока нет.</div>
                        <?php endif; ?>
                    </div>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Метки</div>
                        <div class="tg-dialog-tag-list">
                            <?php foreach ($dialogTags as $tag): ?><span class="tg-dialog-tag"><?php echo $h((string)$tag['name']); ?><form method="POST" style="display:inline;margin:0"><input type="hidden" name="action" value="tg_subscriber_remove_tag"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>"><input type="hidden" name="tag_id" value="<?php echo (int)$tag['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?><button class="tg-dialog-tag-remove" title="Снять тег">×</button></form></span><?php endforeach; ?>
                            <?php if (!$dialogTags): ?><span class="tg-dialog-filter-muted">Меток нет</span><?php endif; ?>
                        </div>
                        <form method="POST" class="tg-dialog-tag-form">
                            <input type="hidden" name="action" value="tg_subscriber_tag_add_or_create"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="bot_id" value="<?php echo (int)$dialogSubscriber['bot_id']; ?>"><input type="hidden" name="subscriber_id" value="<?php echo (int)$dialogSubscriber['id']; ?>"><?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
                            <label><span>Тег</span><input name="tag_name" autocomplete="off" placeholder="Выберите или введите новый тег" required></label><button class="tg-dialog-side-save" type="submit">Добавить</button>
                        </form>
                    </div>
                    <div class="tg-dialog-side-section">
                        <div class="tg-dialog-side-label">Детали</div>
                        <div class="tg-dialog-detail-block">
                            <div class="tg-dialog-info-row"><span>Канал</span><span><?php echo $h((string)($dialogSubscriber['bot_title'] ?? '')); ?></span></div>
                            <div class="tg-dialog-info-row"><span>Подписан</span><span><?php echo $h((string)($dialogSubscriber['first_seen_at'] ?? '')); ?></span></div>
                            <div class="tg-dialog-info-row"><span>Контакт</span><span><?php echo $h((string)($dialogSubscriber['last_seen_at'] ?? '')); ?></span></div>
                            <?php foreach ($dialogUtmRows as $utmKey => $utmLabel): ?><div class="tg-dialog-info-row"><span><?php echo $h($utmLabel); ?></span><span><?php echo trim((string)($dialogSubscriber[$utmKey] ?? '')) !== '' ? $h((string)$dialogSubscriber[$utmKey]) : 'не указано'; ?></span></div><?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </section>
    <?php $autoReplyAttachment = is_array($dialogSettings['auto_reply_attachment'] ?? null) ? $dialogSettings['auto_reply_attachment'] : []; ?>
    <form method="POST" enctype="multipart/form-data" class="tg-dialog-settings-backdrop" data-tg-dialog-settings-modal>
        <input type="hidden" name="action" value="tg_dialog_settings_save">
        <input type="hidden" name="return_page" value="messages">
        <input type="hidden" name="bot_id" value="<?php echo (int)$selectedBotId; ?>">
        <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?php echo $h(csrf_token()); ?>"><?php endif; ?>
        <div class="tg-dialog-settings-modal" role="dialog" aria-modal="true" aria-labelledby="tg-dialog-settings-title">
            <div class="tg-dialog-settings-head">
                <h3 id="tg-dialog-settings-title">Диалоги</h3>
                <button type="button" class="tg-dialog-settings-close" data-tg-dialog-settings-close title="Закрыть" aria-label="Закрыть">×</button>
            </div>
            <div class="tg-dialog-settings-body">
                <label class="tg-dialog-setting-row">
                    <span><input class="tg-dialog-switch-input" type="checkbox" name="auto_close_incoming" value="1" <?php echo !empty($dialogSettings['auto_close_incoming']) ? 'checked' : ''; ?>><span class="tg-dialog-switch"></span></span>
                    <span><span class="tg-dialog-setting-title">Автоматически закрывать входящие диалоги</span><span class="tg-dialog-setting-text">Все входящие диалоги будут переводиться в статус «Закрытые». Уведомления по новым входящим сообщениям можно будет не получать, если менеджер отвечает вручную редко или используется автоответ.</span></span>
                </label>
                <label class="tg-dialog-setting-row">
                    <span><input class="tg-dialog-switch-input" type="checkbox" name="auto_reply_enabled" value="1" <?php echo !empty($dialogSettings['auto_reply_enabled']) ? 'checked' : ''; ?>><span class="tg-dialog-switch"></span></span>
                    <span><span class="tg-dialog-setting-title">Автоматический ответ</span><span class="tg-dialog-setting-text">Автоответ отправляется подписчику после входящего сообщения. Используйте его для простого подтверждения получения обращения, а сложную логику лучше настраивать через сценарии.</span><textarea class="tg-dialog-settings-textarea" name="auto_reply_text" data-dialog-settings-auto-reply-text placeholder="Например: Спасибо за сообщение. Мы ответим в ближайшее время."><?php echo $h((string)($dialogSettings['auto_reply_text'] ?? '')); ?></textarea><input type="file" name="auto_reply_attachment" class="hidden" data-dialog-settings-auto-reply-file accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.m4v,.webm,.mp3,.m4a,.ogg,.wav,.pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><div class="tg-dialog-settings-tools"><span class="tg-dialog-settings-emoji-wrap"><button type="button" class="tg-dialog-settings-tool" data-dialog-settings-emoji title="Эмодзи" aria-label="Эмодзи">☺</button><div class="tg-dialog-settings-emoji-menu" data-dialog-settings-emoji-menu></div></span><button type="button" class="tg-dialog-settings-tool" data-dialog-settings-attach title="Прикрепить файл" aria-label="Прикрепить файл">📎</button><span class="tg-dialog-settings-file-name" data-dialog-settings-file-name></span></div><?php if (!empty($autoReplyAttachment['file_path'])): ?><div class="tg-dialog-settings-attachment"><a href="/<?php echo $h(ltrim((string)$autoReplyAttachment['file_path'], '/')); ?>" target="_blank" rel="noopener"><?php echo $h((string)($autoReplyAttachment['file_name'] ?? 'Файл автоответа')); ?></a><label><input type="checkbox" name="auto_reply_attachment_clear" value="1"> удалить</label></div><?php endif; ?></span>
                </label>
                <div class="tg-dialog-settings-sep"></div>
                <div>
                    <div class="tg-dialog-settings-danger-title">Закрытие всех диалогов</div>
                    <div class="tg-dialog-settings-danger-text">Эта кнопка будет переводить «Новые», «Назначенные» и «Мои» диалоги в «Закрытые». Будут закрыты все диалоги в статусах «Новые», «Мои» и «Назначенные» для выбранного канала. В режиме «Все каналы» закроются диалоги всех каналов.</div>
                    <button type="submit" name="action" value="tg_dialog_close_all" class="tg-dialog-settings-danger-btn" onclick="return confirm('Закрыть все активные диалоги?');">Закрыть диалоги</button>
                </div>
            </div>
            <div class="tg-dialog-settings-foot">
                <button type="button" class="tg-dialog-settings-muted-btn" data-tg-dialog-settings-close>Закрыть</button>
                <button type="submit" class="tg-dialog-settings-save">Сохранить</button>
            </div>
        </div>
    </form>
    <script>
    (function(){
        var btn=document.querySelector('[data-tg-dialog-filter]');
        var panel=document.querySelector('[data-tg-dialog-filter-panel]');
        if(btn&&panel){btn.addEventListener('click',function(){panel.classList.toggle('is-open');});}
        var settingsOpen=document.querySelector('[data-tg-dialog-settings-open]');
        var settingsModal=document.querySelector('[data-tg-dialog-settings-modal]');
        if(settingsOpen&&settingsModal){
            var close=function(){settingsModal.classList.remove('is-open');};
            settingsOpen.addEventListener('click',function(){settingsModal.classList.add('is-open');});
            settingsModal.querySelectorAll('[data-tg-dialog-settings-close]').forEach(function(item){item.addEventListener('click',close);});
            settingsModal.addEventListener('click',function(e){if(e.target===settingsModal) close();});
            document.addEventListener('keydown',function(e){if(e.key==='Escape') close();});
        }

        function fitDialogShell(){
            var shell=document.querySelector('.tg-dialogs-shell');
            if(!shell) return;
            if(window.innerWidth<=820){
                shell.style.height='';
                document.documentElement.style.removeProperty('--tg-dialog-shell-h');
                return;
            }
            var rect=shell.getBoundingClientRect();
            var top=Math.max(0,rect.top);
            var h=Math.floor(window.innerHeight-top-2);
            h=Math.max(560,h);
            document.documentElement.style.setProperty('--tg-dialog-shell-h',h+'px');
            shell.style.setProperty('height',h+'px','important');
            shell.style.setProperty('min-height','0','important');
            shell.style.setProperty('margin-bottom','0','important');
        }
        fitDialogShell();
        window.addEventListener('resize',fitDialogShell,{passive:true});
        window.addEventListener('scroll',fitDialogShell,{passive:true});
        setTimeout(fitDialogShell,60);
        setTimeout(fitDialogShell,250);
        setTimeout(fitDialogShell,800);

        var dialogState={
            view:new URLSearchParams(window.location.search).get('dialog_view')||'<?php echo $h($dialogView); ?>',
            subscriberId:parseInt(new URLSearchParams(window.location.search).get('subscriber_id')||'<?php echo (int)$selectedDialogSubscriberId; ?>',10)||0,
            botId:parseInt(new URLSearchParams(window.location.search).get('bot_id')||'<?php echo (int)$selectedBotId; ?>',10)||0,
            lastMessageId:0,
            loading:false,
            refreshingList:false,
            refreshingMessages:false,
            lastListRefreshAt:0,
            lastMessageRefreshAt:0
        };
        var csrfToken=<?php echo json_encode(function_exists('csrf_token') ? csrf_token() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var assignableUsersInitial=<?php echo json_encode(array_map(static function(array $u) use ($dialogUserName) { return ['id'=>(int)($u['id'] ?? 0),'label'=>$dialogUserName($u),'full_name'=>(string)($u['full_name'] ?? ''),'name'=>(string)($u['name'] ?? ''),'first_name'=>(string)($u['first_name'] ?? ''),'last_name'=>(string)($u['last_name'] ?? ''),'username'=>(string)($u['username'] ?? ''),'telegram_username'=>(string)($u['telegram_username'] ?? ''),'email'=>(string)($u['email'] ?? '')]; }, $dialogAssignableUsers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var labels={status:{new:'Новый',mine:'Мой',assigned:'Назначен',closed:'Закрыт',spam:'Спам'},subscriber:{active:'Активен',inactive:'Неактивен',unsubscribed:'Отписан',blocked:'Заблокирован'},views:{new:'Новые',my:'Мои',assigned:'Назначенные',closed:'Закрытые',spam:'Спам'}};
        var dialogAutomationIcons={autoClose:<?php echo $dialogAutoCloseEnabled ? 'true' : 'false'; ?>,autoReply:<?php echo $dialogAutoReplyEnabled ? 'true' : 'false'; ?>};
        var viewOrder=['new','my','assigned','closed','spam'];

        function esc(s){return String(s==null?'':s).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];});}
        function channelIcon(row){return (String((row&&row.channel_type)||'telegram').toLowerCase()==='vk')?'/assets/admin/icons/tb2-vk-gray.svg':'/assets/admin/icons/tb2-telegram-gray.svg';}
        function dialogAutomationIconsHtml(){
            var html='';
            if(dialogAutomationIcons.autoClose){html+='<span class="tg-dialog-mode-icon tg-dialog-mode-icon--close" title="Автоматически закрывать входящие диалоги"><img src="/assets/admin/icons/close-icon.png" alt="" aria-hidden="true"></span>';}
            if(dialogAutomationIcons.autoReply){html+='<span class="tg-dialog-mode-icon tg-dialog-mode-icon--auto" title="Автоматический ответ включён"><img src="/assets/admin/icons/autosend-icon.png" alt="" aria-hidden="true"></span>';}
            return html?'<span class="tg-dialog-mode-icons" aria-label="Активные автоматические настройки диалогов">'+html+'</span>':'';
        }
        function buildParams(extra){
            var params=new URLSearchParams(window.location.search);
            params.set('tab','telegram_bots');
            params.set('page','messages');
            params.set('dialog_view',dialogState.view||'new');
            if(dialogState.subscriberId>0) params.set('subscriber_id',String(dialogState.subscriberId));
            if(dialogState.botId>0) params.set('bot_id',String(dialogState.botId));
            Object.keys(extra||{}).forEach(function(k){
                if(extra[k]===null||extra[k]===undefined||extra[k]==='') params.delete(k); else params.set(k,String(extra[k]));
            });
            return params;
        }
        function ajaxUrl(kind,extra){var params=buildParams(extra||{});params.set('tg_ajax',kind);return 'admin.php?'+params.toString();}
        function asrStopNativeNavigation(event){
            if(event){event.preventDefault();event.stopPropagation();if(event.stopImmediatePropagation) event.stopImmediatePropagation();}
        }
        window.asrTgDialogsTabFromLink=function(link,event){
            var tabView=(link&&link.getAttribute('data-dialog-view'))||'';
            if(!tabView&&link&&link.getAttribute('href')){try{tabView=new URL(link.getAttribute('href'),window.location.href).searchParams.get('dialog_view')||'';}catch(_e){}}
            if(!tabView) return true;
            asrStopNativeNavigation(event);
            dialogState.view=tabView;dialogState.subscriberId=0;dialogState.botId=0;dialogState.lastMessageId=0;
            loadState({subscriber_id:null,bot_id:null});
            return false;
        };
        window.asrTgDialogsOpenFromLink=function(link,event){
            var sid=parseInt((link&&link.getAttribute('data-dialog-subscriber-id'))||'0',10)||0;
            var bid=parseInt((link&&link.getAttribute('data-dialog-bot-id'))||'0',10)||0;
            if((sid<=0||bid<=0)&&link&&link.getAttribute('href')){try{var u=new URL(link.getAttribute('href'),window.location.href);if(sid<=0)sid=parseInt(u.searchParams.get('subscriber_id')||'0',10)||0;if(bid<=0)bid=parseInt(u.searchParams.get('bot_id')||'0',10)||0;}catch(_e2){}}
            if(sid<=0) return true;
            asrStopNativeNavigation(event);
            dialogState.subscriberId=sid;dialogState.botId=bid;dialogState.lastMessageId=0;
            loadState({});
            return false;
        };
        function fetchDialogJson(url,options){
            options=options||{};
            options.headers=Object.assign({'X-Requested-With':'XMLHttpRequest'},options.headers||{});
            return fetch(url,options).then(function(r){
                return r.text().then(function(text){
                    var j=null;
                    try{j=JSON.parse(text);}catch(parseErr){throw new Error('Ajax API вернул не JSON. Проверьте актуальность actions.php.');}
                    if(!r.ok||!j.ok){throw new Error((j&&j.error)||'Ошибка запроса');}
                    return j;
                });
            });
        }
        function postForm(form){
            var fd=new FormData(form);
            fd.set('tg_ajax','1');
            fd.set('return_page','messages');
            if(!fd.get('dialog_view')) fd.set('dialog_view',dialogState.view||'new');
            return fetchDialogJson('admin.php?tab=telegram_bots&page=messages',{method:'POST',body:fd});
        }
        function isFeedNearBottom(feed){
            if(!feed) return true;
            return (feed.scrollHeight-feed.scrollTop-feed.clientHeight)<90;
        }
        function updateUrl(){
            var params=buildParams({tg_ajax:null});
            history.pushState({tgDialogs:true},'', 'admin.php?'+params.toString());
        }
        var tgDialogOriginalTitle=document.title.replace(/^●\s+/, '');
        function updateDialogBrowserTitle(totalNeeds){
            totalNeeds=parseInt(totalNeeds||0,10)||0;
            document.title=(totalNeeds>0?'● ':'')+tgDialogOriginalTitle;
        }
        function syncDialogTitleFromDom(){
            var tabs=document.querySelector('.tg-dialog-tabs');
            var total=tabs?parseInt(tabs.getAttribute('data-needs-reply-total')||'0',10)||0:0;
            updateDialogBrowserTitle(total);
        }
        function renderTabs(counts){
            var tabs=document.querySelector('.tg-dialog-tabs');
            if(!tabs) return;
            var totalNeeds=parseInt((counts&&counts.needs_reply_total)||0,10)||0;
            tabs.setAttribute('data-needs-reply-total', String(totalNeeds));
            var panelDisabled=(dialogState.subscriberId>0?'':' disabled');
            var desktopMenuHtml='<button type="button" class="tg-dialog-desktop-menu-button" title="Открыть меню" aria-label="Открыть меню" onclick="return window.asrTgDialogsOpenMainMenu ? window.asrTgDialogsOpenMainMenu(event) : true;">☰</button>';
            var panelHtml=desktopMenuHtml+'<div class="tg-dialog-mobile-panel-dropdown" data-dialog-panel-dropdown><button type="button" class="tg-dialog-mobile-panel-button" data-dialog-panel-toggle title="Области диалогов" onclick="return window.asrTgDialogsTogglePanelMenu ? window.asrTgDialogsTogglePanelMenu(this,event) : false;">☰</button><div class="tg-dialog-panel-menu" data-dialog-panel-menu><button type="button" data-mobile-panel="list" onclick="return window.asrTgDialogsSwitchPanel ? window.asrTgDialogsSwitchPanel(this,event) : false;">Список</button><button type="button" data-mobile-panel="chat"'+panelDisabled+'>Чат</button><button type="button" data-mobile-panel="card"'+panelDisabled+'>Карточка</button></div></div>';
            tabs.innerHTML=panelHtml+viewOrder.map(function(v){
                var count=parseInt((counts&&counts[v])||0,10)||0;
                var needs=parseInt((counts&&counts['needs_reply_'+v])||0,10)||0;
                var show=(v!=='closed'&&v!=='spam'&&count>0);
                var params=buildParams({dialog_view:v,subscriber_id:null,tg_ajax:null});
                return '<a class="tg-dialog-tab '+(dialogState.view===v?'is-active':'')+'" href="admin.php?'+esc(params.toString())+'" data-dialog-view="'+esc(v)+'" data-needs-reply="'+needs+'" onclick="return window.asrTgDialogsTabFromLink ? window.asrTgDialogsTabFromLink(this,event) : true;">'+esc(labels.views[v]||v)+(show?'<span class="tg-dialog-tab-count">'+count+'</span>':'')+(needs>0?'<span class="tg-dialog-tab-alert" title="Есть диалоги без ответа"></span>':'')+'</a>';
            }).join('')+dialogAutomationIconsHtml();
            updateDialogBrowserTitle(totalNeeds);
        }
        function userLabel(u){
            u=u||{};
            var label=String(u.label||u.full_name||u.name||'').trim();
            if(label) return label;
            var name=[u.first_name||'',u.last_name||''].join(' ').trim();
            if(name) return name;
            var username=String(u.username||u.telegram_username||'').trim();
            if(username) return '@'+username.replace(/^@+/, '');
            var email=String(u.email||'').trim();
            if(email) return email;
            var id=parseInt(u.id||u.assigned_user_id||0,10)||0;
            return id>0?'Сотрудник #'+id:'';
        }
        function assigneeLabel(row,panelPayload){
            row=row||{};
            var label=String(row.assignee_label||'').trim();
            var id=parseInt(row.assigned_user_id||0,10)||0;
            var technical=!label || /^Сотрудник\s*[#№]?\s*\d+$/i.test(label);
            if(id>0 && technical){
                var users=(panelPayload&&panelPayload.assignable_users)||row.assignable_users||assignableUsersInitial||[];
                for(var i=0;i<users.length;i++){
                    if(parseInt(users[i].id||0,10)===id){
                        var user=userLabel(users[i]);
                        if(user && !/^Сотрудник\s*[#№]?\s*\d+$/i.test(user)) return user;
                    }
                }
            }
            return label;
        }
        function dialogHref(row){
            var params=buildParams({subscriber_id:row.subscriber_id||0,bot_id:row.bot_id||0,tg_ajax:null});
            return 'admin.php?'+params.toString();
        }
        function renderDialogList(payload){
            var box=document.querySelector('.tg-dialog-list-scroll');
            if(!box) return;
            var counts=payload.counts||{};
            var html='';
            if((parseInt(counts.unread_dialogs||0,10)||0)>0){html+='<div class="tg-dialog-unread-summary">Непрочитанные: '+(parseInt(counts.unread_dialogs||0,10)||0)+' диал. / '+(parseInt(counts.unread_messages||0,10)||0)+' сообщ.</div>';}
            var dialogs=payload.dialogs||[];
            if(!dialogs.length){html+='<div class="tg-dialog-empty">Диалогов пока нет. Они появятся, когда клиент напишет в бот или ответит на рассылку/сценарий.</div>';}
            dialogs.forEach(function(row){
                var active=(parseInt(row.subscriber_id||0,10)===dialogState.subscriberId && (!dialogState.botId || parseInt(row.bot_id||0,10)===dialogState.botId));
                var unread=active?0:(parseInt(row.unread_count||0,10)||0);
                var preview=(row.preview||row.message_text||'');
                if(!preview) preview='Файл / '+(row.message_type||'сообщение');
                if(row.last_direction==='out') preview='Вы: '+preview;
                html+='<a class="tg-dialog-item '+(active?'is-active ':'')+(unread>0?'is-unread':'')+'" href="'+esc(dialogHref(row))+'" data-dialog-subscriber-id="'+esc(row.subscriber_id||0)+'" data-dialog-bot-id="'+esc(row.bot_id||0)+'" onclick="return window.asrTgDialogsOpenFromLink ? window.asrTgDialogsOpenFromLink(this,event) : true;">';
                html+='<div class="tg-dialog-avatar"><img src="'+esc(channelIcon(row))+'" alt="" aria-hidden="true"><span class="tg-dialog-dot is-'+esc(row.dot||'green')+'"></span></div>';
                html+='<div class="tg-dialog-item-body"><div class="tg-dialog-item-top"><div class="tg-dialog-name">'+esc(row.display_name||('Подписчик #'+(row.subscriber_id||0)))+'</div><div class="tg-dialog-time">'+esc(row.last_message_at||'')+'</div></div>';
                html+='<div class="tg-dialog-preview">'+esc(preview)+'</div><div class="tg-dialog-channel">'+esc(row.channel_label||row.bot_title||'Канал')+' · '+esc(labels.status[row.status]||row.status||'')+(unread>0?' · <span class="tg-dialog-unread-badge">'+unread+'</span>':'')+'</div>';
                var rowAssignee=assigneeLabel(row,null); if(rowAssignee){html+='<div class="tg-dialog-assigned-line">Ответственный: '+esc(rowAssignee)+'</div>';}
                html+='</div></a>';
            });
            box.innerHTML=html;
        }
        function renderMessages(payload,append){
            var main=document.querySelector('.tg-dialog-main');
            var feed=document.querySelector('[data-dialog-chat-feed]');
            var panelPayload=payload.panel_payload||payload.panelPayload||null;
            var subscriber=(panelPayload&&panelPayload.subscriber)||payload.subscriber||{};
            var dialogs=(payload.dialogs||[]);
            var activeRow=dialogs.find(function(d){return parseInt(d.subscriber_id||0,10)===dialogState.subscriberId && (!dialogState.botId || parseInt(d.bot_id||0,10)===dialogState.botId);})||dialogs.find(function(d){return parseInt(d.subscriber_id||0,10)===dialogState.subscriberId;})||{};
            if(!feed||!append){
                if(!main) return;
                main.innerHTML=renderMainShell(subscriber,activeRow,panelPayload);
                feed=document.querySelector('[data-dialog-chat-feed]');
                bindDialogLocalTools();
            }
            if(!feed) return;
            var messages=payload.messages||((payload.messages_payload&&payload.messages_payload.messages)||[]);
            var shouldStick=!append||isFeedNearBottom(feed);
            if(!append){feed.innerHTML='';}
            if(append&&messages.length){
                feed.querySelectorAll('.tg-dialog-empty').forEach(function(el){el.remove();});
            }
            var currentDay=append?feed.getAttribute('data-last-day')||'':'';
            messages.forEach(function(msg){
                var mid=parseInt(msg.id||0,10)||0;
                if(mid<0 && feed.querySelector('[data-broadcast-context-id="'+mid+'"]')) return;
                var day=String(msg.created_at||'').slice(0,10);
                if(day&&day!==currentDay){feed.insertAdjacentHTML('beforeend','<div class="tg-dialog-day">'+esc(day)+'</div>');currentDay=day;}
                feed.insertAdjacentHTML('beforeend',renderMessage(msg));
                if(mid>0) dialogState.lastMessageId=Math.max(dialogState.lastMessageId,mid);
            });
            feed.setAttribute('data-last-day',currentDay);
            if(!messages.length&&!append){feed.innerHTML='<div class="tg-dialog-empty">Истории сообщений пока нет.</div>';}
            if(shouldStick){feed.scrollTop=feed.scrollHeight;}
            else if(append&&messages.length){showDialogNotice('В диалоге появились новые сообщения ниже',false);}
        }
        function renderMessage(msg){
            var text=String(msg.message_text||'').trim();
            var type=String(msg.message_type||'message');
            if(!!msg.is_broadcast_context||type==='broadcast_context'){
                var title=String(msg.broadcast_title||'Рассылка').trim()||'Рассылка';
                var bid=parseInt(msg.id||0,10)||0;
                return '<div class="tg-dialog-broadcast-context" data-broadcast-context-id="'+esc(bid)+'"><div class="tg-dialog-broadcast-context-title">Рассылка: '+esc(title)+'</div><div class="tg-dialog-broadcast-context-text">'+esc(text||'[рассылка]')+'</div><div class="tg-dialog-broadcast-context-meta">'+esc(msg.created_at||'')+'</div></div>';
            }
            var isSystem=!!msg.is_system||['system','service','event'].indexOf(type)>=0||/^(Рассылка|Сценарий|Автоответ)\s*:/u.test(text);
            if(isSystem){
                var time=String(msg.created_at||'').slice(11,16);
                return '<div class="tg-dialog-system-note">'+esc(text||('['+type+']'))+(time?'<small>'+esc(time)+'</small>':'')+'</div>';
            }
            var attachment=msg.attachment||null;
            var html='<div class="tg-chat-bubble '+(msg.direction==='out'?'out':'in')+'">'+esc(text||('['+type+']'));
            if(attachment){
                var path=attachment.file_path||'';
                var name=attachment.file_name||'файл';
                html+='<div class="tg-chat-attachment"><span>Файл</span><div>'+(path?'<a href="/'+esc(String(path).replace(/^\/+/,''))+'" target="_blank" rel="noopener">'+esc(name)+'</a>':esc(name))+'<br><small>'+esc(attachment.media_type||type||'файл')+'</small></div></div>';
            }
            html+='<span class="tg-chat-meta">'+(msg.direction==='out'?'исходящее':'входящее')+' · '+esc(msg.created_at||'')+'</span></div>';
            return html;
        }
        function renderMainShell(subscriber,row,panelPayload){
            if(!subscriber||!subscriber.id){return '<div class="tg-dialog-empty">Выберите диалог слева.</div>';}
            var needs=!!row.needs_reply;
            var status=row.status||'new';
            var assignable=(panelPayload&&panelPayload.assignable_users)||[];
            var assigned=parseInt(row.assigned_user_id||0,10)||0;
            var isVk=String(subscriber.channel_type||'telegram').toLowerCase()==='vk';
            var html='<div class="tg-dialog-chat-head"><div class="tg-dialog-chat-person"><div class="tg-dialog-avatar"><img src="'+esc(channelIcon(subscriber))+'" alt="" aria-hidden="true"></div><div class="tg-dialog-person-lines"><div class="tg-dialog-person-name">'+esc(subscriber.display_name||'Подписчик')+'<span class="tg-dialog-status-dot2 '+(needs?'is-red':'')+'" title="'+(needs?'Ждёт ответа':'Ответ дан')+'"></span></div><div class="tg-dialog-person-bot">'+esc(subscriber.bot_title||'Канал')+'</div>'+(subscriber.username?'<div class="tg-dialog-person-user">@'+esc(subscriber.username)+'</div>':'')+'</div></div>';
            var assignOptions='<option value="0">Без ответственного</option>'+assignable.map(function(u){return '<option value="'+esc(u.id)+'" '+(assigned===parseInt(u.id||0,10)?'selected':'')+'>'+esc(userLabel(u)||('Сотрудник #'+u.id))+'</option>';}).join('');
            html+='<div class="tg-dialog-compact-actions">';
            html+='<div class="tg-dialog-desktop-toolbar">';
            html+='<form method="POST" class="tg-dialog-assign-form"><input type="hidden" name="action" value="tg_dialog_assign"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="dialog_view" value="'+esc(dialogState.view)+'"><input type="hidden" name="bot_id" value="'+esc(subscriber.bot_id)+'"><input type="hidden" name="subscriber_id" value="'+esc(subscriber.id)+'">'+csrfInput()+'<select class="tg-dialog-assign-select" name="assigned_user_id">'+assignOptions+'</select></form>';
            if(status!=='closed') html+=statusForm(subscriber,'closed','×','Закрыть','is-icon');
            if(status!=='spam') html+=statusForm(subscriber,'spam','!','В спам','is-icon is-danger',true);
            if(status==='closed'||status==='spam') html+=statusForm(subscriber,'new','↺','Вернуть в новые','is-icon is-soft');
            html+='</div>';
            html+='<div class="tg-dialog-mobile-toolbar">';
            html+='<div class="tg-dialog-dropdown" data-dialog-dropdown><button type="button" class="tg-dialog-icon-btn" data-dialog-dropdown-toggle title="Ответственный"><img src="/assets/admin/icons/users.png" alt="" aria-hidden="true"></button><div class="tg-dialog-dropdown-menu" data-dialog-dropdown-menu><div class="tg-dialog-dropdown-title">Ответственный</div><form method="POST" class="tg-dialog-assign-form"><input type="hidden" name="action" value="tg_dialog_assign"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="dialog_view" value="'+esc(dialogState.view)+'"><input type="hidden" name="bot_id" value="'+esc(subscriber.bot_id)+'"><input type="hidden" name="subscriber_id" value="'+esc(subscriber.id)+'">'+csrfInput()+'<select class="tg-dialog-assign-select" name="assigned_user_id">'+assignOptions+'</select></form></div></div>';
            if(status!=='closed') html+=statusForm(subscriber,'closed','×','Закрыть','is-icon');
            if(status!=='spam') html+=statusForm(subscriber,'spam','!','В спам','is-icon is-danger',true);
            if(status==='closed'||status==='spam') html+=statusForm(subscriber,'new','↺','Вернуть в новые','is-icon is-soft');
            html+='</div>';
            html+='</div></div><div class="tg-dialog-chat-body" data-dialog-chat-feed></div>';
            if(isVk){html+='<div class="tg-dialog-compose"><div class="tg-dialog-empty">Отправка доступна только для Telegram-каналов.</div></div>';}
            else{html+='<form method="POST" class="tg-dialog-compose" enctype="multipart/form-data" data-dialog-compose><input type="hidden" name="action" value="tg_subscriber_send_message"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="dialog_view" value="'+esc(dialogState.view)+'"><input type="hidden" name="bot_id" value="'+esc(subscriber.bot_id)+'"><input type="hidden" name="subscriber_id" value="'+esc(subscriber.id)+'">'+csrfInput()+'<textarea name="message_text" placeholder="Введите сообщение..." data-dialog-chat-text></textarea><input type="file" name="chat_attachment" class="hidden" data-dialog-chat-file accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.m4v,.webm,.mp3,.m4a,.ogg,.wav,.pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><div class="tg-chat-actions"><div class="tg-chat-tool-row"><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-emoji title="Эмодзи" aria-label="Эмодзи"><span class="tg-chat-tool-glyph" aria-hidden="true">☺</span></button><div class="tg-chat-emoji-menu" data-dialog-chat-emoji-menu></div></div><div class="tg-chat-tools"><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-macro title="Переменные" aria-label="Переменные"><span class="tg-chat-tool-glyph" aria-hidden="true">{}</span></button><div class="tg-chat-macro-menu" data-dialog-chat-macro-menu></div></div><button type="button" class="tg-chat-tool tg-chat-tool--icon" data-dialog-chat-attach title="Прикрепить файл" aria-label="Прикрепить файл"><span class="tg-chat-tool-glyph" aria-hidden="true">📎</span></button><span class="tg-chat-file-name" data-dialog-chat-file-name></span></div><button class="tg-chat-send" type="submit">Отправить</button></div><div class="tg-readonly-note">Можно отправить текст, эмодзи и один файл до 45 МБ.</div></form>';}
            return html;
        }
        function csrfInput(){return csrfToken?'<input type="hidden" name="csrf_token" value="'+esc(csrfToken)+'">':'';}
        function statusForm(subscriber,status,label,title,classes,confirmNeeded){return '<form method="POST" class="tg-dialog-action-form" '+(confirmNeeded?'data-confirm="Перенести диалог в спам?"':'')+'><input type="hidden" name="action" value="tg_dialog_status"><input type="hidden" name="bot_id" value="'+esc(subscriber.bot_id)+'"><input type="hidden" name="subscriber_id" value="'+esc(subscriber.id)+'"><input type="hidden" name="dialog_status" value="'+esc(status)+'"><input type="hidden" name="dialog_view" value="'+esc(status==='new'?'new':status)+'">'+csrfInput()+'<button class="tg-dialog-action-btn '+esc(classes||'')+'" type="submit" title="'+esc(title)+'">'+esc(label)+'</button></form>';}
        function renderSide(panelPayload,row){
            var side=document.querySelector('.tg-dialog-side');
            if(!side) return;
            var s=(panelPayload&&panelPayload.subscriber)||{};
            if(!s.id){side.dataset.dialogSideSubscriberId='0';side.innerHTML='<div class="tg-dialog-empty">Карточка появится после выбора диалога.</div>';return;}
            var sameSubscriber=String(side.dataset.dialogSideSubscriberId||'')===String(s.id||'');
            var previousScroll=sameSubscriber?side.scrollTop:0;
            var html='<div class="tg-dialog-side-head"><h4>'+esc(s.display_name||'Подписчик')+'</h4></div>';
            html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Основная информация</div><div class="tg-dialog-detail-block">';
            html+=infoRow('Статус',labels.subscriber[s.status]||s.status||'');
            html+=infoRow('Диалог',labels.status[(row&&row.status)||'new']||((row&&row.status)||'new'));
            html+=infoRow('Ответственный',assigneeLabel(row,panelPayload)||'не назначен');
            html+=infoRow('User ID',s.telegram_user_id||'');
            html+=infoRow('Chat ID',s.chat_id||'');
            html+=infoRow('Username',s.username?'@'+s.username:'не указан');
            html+=infoRow('Подписался',s.first_seen_at||'');
            html+=infoRow('Посл. контакт',s.last_seen_at||'');
            html+='</div></div>';
            if(s.memberships&&s.memberships.length){html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Подписан на каналы</div><div class="tg-dialog-member-list">'+s.memberships.map(function(m){var has=parseInt(m.dialog_id||0,10)>0;var unread=parseInt(m.unread_count||0,10)||0;var href='admin.php?tab=telegram_bots&page=messages&bot_id='+encodeURIComponent(m.bot_id||0)+'&subscriber_id='+encodeURIComponent(m.subscriber_id||0);return '<a class="tg-dialog-member-item '+(parseInt(m.subscriber_id||0,10)===parseInt(s.id||0,10)?'is-current ':'')+(has?'has-dialog ':'')+(unread>0?'has-unread':'')+'" href="'+esc(href)+'" data-dialog-subscriber-id="'+esc(m.subscriber_id||0)+'" data-dialog-bot-id="'+esc(m.bot_id||0)+'" onclick="return window.asrTgDialogsOpenFromLink ? window.asrTgDialogsOpenFromLink(this,event) : true;"><img class="tg-dialog-member-icon" src="'+esc(channelIcon(m))+'" alt="" aria-hidden="true"><span class="tg-dialog-member-text"><span class="tg-dialog-member-title">'+esc(m.bot_title||'Канал')+'</span><span class="tg-dialog-member-meta">'+(m.bot_username?'@'+esc(m.bot_username)+' · ':'')+esc(labels.subscriber[m.status]||m.status||'')+'</span></span><span class="tg-dialog-member-badge">'+(unread>0?unread:(has?'диалог':'нет диалога'))+'</span></a>';}).join('')+'</div></div>';}
            html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Редактирование</div><form method="POST" class="tg-dialog-side-form"><input type="hidden" name="action" value="tg_subscriber_profile_save"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="dialog_view" value="'+esc(dialogState.view||'new')+'"><input type="hidden" name="bot_id" value="'+esc(s.bot_id||0)+'"><input type="hidden" name="subscriber_id" value="'+esc(s.id||0)+'">'+csrfInput()+'<label><span>Имя</span><input name="first_name" value="'+esc(s.first_name||'')+'" placeholder="Имя"></label><label><span>Фамилия</span><input name="last_name" value="'+esc(s.last_name||'')+'" placeholder="Фамилия"></label><label><span>Телефон</span><input name="phone" value="'+esc(s.phone||'')+'" placeholder="Добавить телефон"></label><label><span>Email</span><input name="email" type="email" value="'+esc(s.email||'')+'" placeholder="Добавить эл. почту"></label><label><span>Статус</span><select name="status">'+Object.keys(labels.subscriber).map(function(k){return '<option value="'+esc(k)+'" '+(String(s.status||'active')===k?'selected':'')+'>'+esc(labels.subscriber[k])+'</option>';}).join('')+'</select></label><label><span>Заметка администратора</span><textarea name="admin_note" placeholder="Внутренняя заметка по контакту">'+esc(s.admin_note||'')+'</textarea></label><button class="tg-dialog-side-save" type="submit">Сохранить информацию</button></form></div>';
            html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Настраиваемые поля</div>'+customFieldsFormHtml(s)+'</div>';
            html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Метки</div>';
            if(s.tags&&s.tags.length){html+='<div class="tg-dialog-tags">'+s.tags.map(function(t){return '<span class="tg-dialog-tag">'+esc(t.name||t.title||'Метка')+'</span>';}).join('')+'</div>';}
            else{html+='<div class="tg-dialog-filter-muted">Меток нет</div>';}
            html+='</div>';
            html+='<div class="tg-dialog-side-section"><div class="tg-dialog-side-label">Детали</div><div class="tg-dialog-detail-block">';
            html+=infoRow('Канал',s.bot_title||'');
            var utm=s.utm||{};
            ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(function(k){html+=infoRow(k,utm[k]||'не указано');});
            html+='</div></div>';
            side.innerHTML=html;
            side.dataset.dialogSideSubscriberId=String(s.id||0);
            if(sameSubscriber) side.scrollTop=previousScroll;
        }
        function customFieldInputValue(field, values){
            values=values||{};field=field||{};
            var row=values[String(field.id||0)]||values[parseInt(field.id||0,10)]||{};
            var type=String(field.field_type||'text');
            if(type==='number') return row.value_number==null?'':String(row.value_number).replace(/\.0+$/,'').replace(/(\.\d*?)0+$/,'$1');
            if(type==='date') return row.value_date||'';
            if(type==='datetime') return row.value_datetime?String(row.value_datetime).replace(' ','T').slice(0,16):'';
            return row.value_text||'';
        }
        function customFieldsFormHtml(s){
            var fields=s.custom_fields||[];
            if(!fields.length) return '<div class="tg-dialog-custom-values-empty">Настраиваемых полей пока нет.</div>';
            var html='<form method="POST" class="tg-dialog-side-form tg-dialog-custom-values-form"><input type="hidden" name="action" value="tg_subscriber_custom_values_save"><input type="hidden" name="return_page" value="messages"><input type="hidden" name="dialog_view" value="'+esc(dialogState.view||'new')+'"><input type="hidden" name="bot_id" value="'+esc(s.bot_id||0)+'"><input type="hidden" name="subscriber_id" value="'+esc(s.id||0)+'">'+csrfInput();
            fields.forEach(function(field){
                var id=parseInt(field.id||0,10)||0;if(id<=0)return;
                var type=String(field.field_type||'text');if(['text','number','date','datetime'].indexOf(type)===-1)type='text';
                var name='custom_values['+id+']';var val=customFieldInputValue(field,s.custom_values||{});
                html+='<label><span>'+esc(field.title||'Поле')+' <small>'+esc(type)+'</small></span>';
                if(type==='number') html+='<input name="'+esc(name)+'" type="number" step="any" value="'+esc(val)+'" placeholder="0">';
                else if(type==='date') html+='<input name="'+esc(name)+'" type="date" value="'+esc(val)+'">';
                else if(type==='datetime') html+='<input name="'+esc(name)+'" type="datetime-local" value="'+esc(val)+'">';
                else html+='<input name="'+esc(name)+'" value="'+esc(val)+'" placeholder="Заполнить значение">';
                html+='</label>';
            });
            html+='<button class="tg-dialog-side-save" type="submit">Сохранить поля</button></form>';
            return html;
        }
                function infoRow(k,v){return '<div class="tg-dialog-info-row"><span>'+esc(k)+'</span><span>'+esc(v||'')+'</span></div>';}
        function applyState(payload,options){
            options=options||{};
            if(payload.view&&!options.keepView){dialogState.view=payload.view;}
            if(!options.keepSelection){
                if(payload.selected_subscriber_id){dialogState.subscriberId=parseInt(payload.selected_subscriber_id,10)||dialogState.subscriberId;}
                if(payload.selected_bot_id){dialogState.botId=parseInt(payload.selected_bot_id,10)||dialogState.botId;}
            }
            renderTabs(payload.counts||{});
            renderDialogList(payload);
            dialogState.lastListRefreshAt=Date.now();
            if(options.listOnly){fitDialogShell();if(window.asrTgDialogsFitViewport) window.asrTgDialogsFitViewport();return;}
            var row=(payload.dialogs||[]).find(function(d){return parseInt(d.subscriber_id||0,10)===dialogState.subscriberId && (!dialogState.botId || parseInt(d.bot_id||0,10)===dialogState.botId);})||(payload.dialogs||[]).find(function(d){return parseInt(d.subscriber_id||0,10)===dialogState.subscriberId;})||{};
            var msgPayload=payload.messages_payload||null;
            var panelPayload=payload.panel_payload||null;
            var messagePromise=Promise.resolve(msgPayload);
            var panelPromise=Promise.resolve(panelPayload);
            if(dialogState.subscriberId>0&&!msgPayload) messagePromise=fetchDialogJson(ajaxUrl('dialog_messages',{after_message_id:null,limit:200}));
            if(dialogState.subscriberId>0&&!panelPayload) panelPromise=fetchDialogJson(ajaxUrl('dialog_panel',{}));
            Promise.all([messagePromise,panelPromise]).then(function(parts){
                var messages=parts[0]||{messages:[]};
                var panel=parts[1]||{subscriber:{}};
                renderMessages({messages:messages.messages||[],subscriber:messages.subscriber||{},dialogs:payload.dialogs||[],panel_payload:panel},false);
                renderSide(panel,row);
                updateUrl();
                fitDialogShell();
                if(window.asrTgDialogsFitViewport) window.asrTgDialogsFitViewport();
            }).catch(function(e){showDialogNotice(e.message||'Не удалось загрузить диалог',true);});
        }
        function loadState(extra){
            if(dialogState.loading) return;
            dialogState.loading=true;
            document.querySelector('.tg-dialogs-shell')?.classList.add('is-loading');
            document.querySelector('.tg-dialog-main')?.classList.add('is-ajax-loading');
            fetchDialogJson(ajaxUrl('dialogs_state',extra||{}))
                .then(function(j){applyState(j);})
                .catch(function(e){showDialogNotice(e.message||'Ошибка загрузки',true);})
                .finally(function(){dialogState.loading=false;document.querySelector('.tg-dialogs-shell')?.classList.remove('is-loading');document.querySelector('.tg-dialog-main')?.classList.remove('is-ajax-loading');});
        }
        function refreshDialogList(){
            if(dialogState.loading||dialogState.refreshingList) return;
            dialogState.refreshingList=true;
            fetchDialogJson(ajaxUrl('dialogs_state',{limit:80}))
                .then(function(j){applyState(j,{listOnly:true,keepSelection:true});})
                .catch(function(){})
                .finally(function(){dialogState.refreshingList=false;dialogState.lastListRefreshAt=Date.now();});
        }
        function refreshOpenMessages(){
            if(dialogState.loading||dialogState.refreshingMessages||dialogState.subscriberId<=0) return;
            var after=parseInt(dialogState.lastMessageId||0,10)||0;
            if(after<=0) return;
            dialogState.refreshingMessages=true;
            fetchDialogJson(ajaxUrl('dialog_messages',{after_message_id:after,limit:50}))
                .then(function(j){
                    var messages=j.messages||[];
                    if(messages.length){
                        renderMessages({messages:messages,subscriber:j.subscriber||{}},true);
                        dialogState.lastMessageRefreshAt=Date.now();
                        setTimeout(refreshDialogList,300);
                    }
                })
                .catch(function(){})
                .finally(function(){dialogState.refreshingMessages=false;dialogState.lastMessageRefreshAt=Date.now();});
        }
        function refreshTick(){
            var now=Date.now();
            var listEvery=document.hidden?30000:10000;
            var msgEvery=document.hidden?20000:5000;
            if(now-dialogState.lastMessageRefreshAt>=msgEvery) refreshOpenMessages();
            if(now-dialogState.lastListRefreshAt>=listEvery) refreshDialogList();
        }
        function showDialogNotice(text,isError){
            var old=document.querySelector('.tg-dialog-ajax-toast');if(old)old.remove();
            var div=document.createElement('div');div.className='tg-dialog-ajax-toast '+(isError?'is-error':'');div.textContent=text;document.body.appendChild(div);setTimeout(function(){div.classList.add('is-out');},2200);setTimeout(function(){div.remove();},2850);
        }
        function bindDialogLocalTools(){
            var chatFeed=document.querySelector('[data-dialog-chat-feed]');
            if(chatFeed&&!chatFeed.dataset.boundScroll){chatFeed.scrollTop=chatFeed.scrollHeight;chatFeed.dataset.boundScroll='1';}
            document.querySelectorAll('[data-dialog-dropdown]').forEach(function(drop){
                if(drop.dataset.boundDropdown==='1') return;
                drop.dataset.boundDropdown='1';
                var btn=drop.querySelector('[data-dialog-dropdown-toggle]');
                if(btn){btn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();document.querySelectorAll('[data-dialog-dropdown].is-open').forEach(function(other){if(other!==drop) other.classList.remove('is-open');});drop.classList.toggle('is-open');});}
            });
            var chatForm=document.querySelector('[data-dialog-compose]');
            if(!chatForm||chatForm.dataset.ajaxBound==='1') return;
            chatForm.dataset.ajaxBound='1';
            var text=chatForm.querySelector('[data-dialog-chat-text]');
            var file=chatForm.querySelector('[data-dialog-chat-file]');
            var fileName=chatForm.querySelector('[data-dialog-chat-file-name]');
            var attachBtn=chatForm.querySelector('[data-dialog-chat-attach]');
            var emojiBtn=chatForm.querySelector('[data-dialog-chat-emoji]');
            var emojiMenu=chatForm.querySelector('[data-dialog-chat-emoji-menu]');
            var groups={'Частые':['🙂','😊','😉','😍','👍','🙏','👏','🔥','✅','❌','⚠️','🎯','🚀','💡','📌','📎','⏰','📅','💬','❤️'],'Эмоции':['😀','😃','😄','😁','😆','😅','😂','🤣','😇','🙂','🙃','😌','😎','🤔','😐','😬','😔','😢','😡','🤯'],'Работа':['📌','📎','📄','📊','📈','📉','🧩','🛠️','⚙️','🔔','📣','✉️','📞','💼','📝','🔗','🔒','🔑','🗂️','📦'],'Жесты':['👍','👎','👌','✌️','🤝','👏','🙌','🙏','💪','👀','👉','👈','☝️','👇','✍️','🤌','🫶','👋','🤙','🖐️']};
            function insertAtCursor(el,value){if(!el)return;var start=el.selectionStart||0,end=el.selectionEnd||0;el.value=el.value.slice(0,start)+value+el.value.slice(end);var pos=start+value.length;el.focus();el.setSelectionRange(pos,pos);}
            if(emojiMenu){emojiMenu.innerHTML=Object.keys(groups).map(function(title){return '<div class="tg-chat-emoji-section"><div class="tg-chat-emoji-title">'+esc(title)+'</div><div class="tg-chat-emoji-grid">'+groups[title].map(function(e){return '<button type="button" data-dialog-chat-emoji-insert="'+esc(e)+'">'+esc(e)+'</button>';}).join('')+'</div></div>';}).join('');}
            if(emojiBtn&&emojiMenu){emojiBtn.addEventListener('click',function(e){e.stopPropagation();emojiMenu.classList.toggle('is-open');});}
            if(emojiMenu){emojiMenu.addEventListener('click',function(e){e.stopPropagation();var b=e.target.closest('[data-dialog-chat-emoji-insert]');if(!b)return;insertAtCursor(text,b.getAttribute('data-dialog-chat-emoji-insert')||'');emojiMenu.classList.remove('is-open');});}
            if(attachBtn&&file){attachBtn.addEventListener('click',function(){file.click();});}
            if(file&&fileName){file.addEventListener('change',function(){var f=file.files&&file.files[0]?file.files[0]:null;if(!f){fileName.textContent='';return;}var mb=(f.size/1024/1024).toFixed(f.size>1024*1024?1:2);fileName.textContent=f.name+' · '+mb+' МБ';});}
            document.addEventListener('click',function(e){if(!e.target.closest('[data-dialog-dropdown]')){document.querySelectorAll('[data-dialog-dropdown].is-open').forEach(function(drop){drop.classList.remove('is-open');});}if(!e.target.closest('[data-dialog-panel-dropdown]')){document.querySelectorAll('[data-dialog-panel-dropdown].is-open').forEach(function(drop){drop.classList.remove('is-open');});}if(!e.target.closest('.tg-chat-tools')&&emojiMenu){emojiMenu.classList.remove('is-open');}});
            chatForm.addEventListener('submit',function(e){
                var hasText=(text&&text.value.trim()!=='');var hasFile=!!(file&&file.files&&file.files.length);
                if(!hasText&&!hasFile){e.preventDefault();if(text)text.focus();return;}
                e.preventDefault();
                var send=chatForm.querySelector('.tg-chat-send'); if(send) send.disabled=true;
                postForm(chatForm).then(function(j){
                    if(text) text.value=''; if(file) file.value=''; if(fileName) fileName.textContent='';
                    applyState(j);
                    showDialogNotice('Сообщение отправлено',false);
                }).catch(function(err){showDialogNotice(err.message||'Не удалось отправить сообщение',true);}).finally(function(){if(send)send.disabled=false;});
            });
        }
        document.addEventListener('click',function(e){
            var tab=e.target.closest('.tg-dialog-tab');
            if(tab&&window.asrTgDialogsTabFromLink(tab,e)===false) return;
            var item=e.target.closest('[data-dialog-subscriber-id],.tg-dialog-item,.tg-dialog-member-item');
            if(item&&window.asrTgDialogsOpenFromLink(item,e)===false) return;
        },true);
        document.addEventListener('submit',function(e){
            var form=e.target.closest('.tg-dialog-action-form,.tg-dialog-assign-form');
            if(!form) return;
            var msg=form.getAttribute('data-confirm');
            if(msg&&!confirm(msg)){e.preventDefault();return;}
            e.preventDefault();
            postForm(form).then(function(j){document.querySelectorAll('[data-dialog-dropdown].is-open').forEach(function(drop){drop.classList.remove('is-open');});applyState(j);showDialogNotice('Диалог обновлён',false);}).catch(function(err){showDialogNotice(err.message||'Не удалось обновить диалог',true);});
        });
        document.addEventListener('change',function(e){
            var select=e.target.closest('.tg-dialog-assign-select');
            if(select&&select.form&&select.form.matches('.tg-dialog-assign-form')){select.form.dispatchEvent(new Event('submit',{cancelable:true,bubbles:true}));}
        });
        bindDialogLocalTools();
        var chatFeed=document.querySelector('[data-dialog-chat-feed]');
        if(chatFeed){chatFeed.scrollTop=chatFeed.scrollHeight;}
        dialogState.lastListRefreshAt=Date.now();
        dialogState.lastMessageRefreshAt=Date.now();
        setInterval(refreshTick,1500);
        document.addEventListener('visibilitychange',function(){
            dialogState.lastListRefreshAt=0;
            dialogState.lastMessageRefreshAt=0;
            refreshTick();
        });
        window.addEventListener('popstate',function(){var p=new URLSearchParams(window.location.search);dialogState.view=p.get('dialog_view')||'new';dialogState.subscriberId=parseInt(p.get('subscriber_id')||'0',10)||0;dialogState.botId=parseInt(p.get('bot_id')||'0',10)||0;loadState({});});
    })();
    </script>

    <script>
    // Rescue Ajax layer for «Диалоги»: не зависит от старого JSON-рендера и не даёт кликам «зависать».
    (function(){
        if(!document.querySelector('.tg-dialogs-shell')) return;
        var busy=false;
        var formBusy=false;
        var listBusy=false;
        var lastUrl=window.location.href;
        window.asrTgDialogsAjaxReady=true;
        window.asrTgDialogsAjaxRescueReady=true;

        function isMobileDialogs(){return window.matchMedia && window.matchMedia('(max-width: 820px)').matches;}
        function setMobilePanel(panel){
            var shell=document.querySelector('.tg-dialogs-shell');
            if(!shell) return;
            if(['list','chat','card'].indexOf(panel)===-1) panel='list';
            var hasDialog=!!document.querySelector('[data-dialog-compose], .tg-dialog-chat-head');
            if((panel==='chat'||panel==='card') && !hasDialog) panel='list';
            shell.setAttribute('data-mobile-panel',panel);
            shell.querySelectorAll('.tg-dialog-mobile-switch [data-mobile-panel], .tg-dialog-panel-menu [data-mobile-panel]').forEach(function(btn){
                var target=btn.getAttribute('data-mobile-panel');
                var blocked=(target==='chat'||target==='card') && !hasDialog;
                btn.disabled=blocked;
                btn.classList.toggle('is-active',target===panel && !blocked);
            });
        }
        function syncMobilePanel(preferred){
            var shell=document.querySelector('.tg-dialogs-shell');
            if(!shell) return;
            var current=preferred || shell.getAttribute('data-mobile-panel') || (document.querySelector('[data-dialog-compose], .tg-dialog-chat-head')?'chat':'list');
            setMobilePanel(current);
        }
        function positionPanelMenu(drop){
            if(!drop) return;
            var menu=drop.querySelector('[data-dialog-panel-menu]');
            var btn=drop.querySelector('[data-dialog-panel-toggle]');
            if(!menu||!btn) return;
            if(!drop.classList.contains('is-open')){menu.classList.remove('is-fixed');menu.style.left='';menu.style.top='';return;}
            var r=btn.getBoundingClientRect();
            menu.classList.add('is-fixed');
            menu.style.left=Math.max(8,Math.round(r.left))+'px';
            menu.style.top=Math.round(r.bottom+8)+'px';
        }
        window.asrTgDialogsOpenMainMenu=function(event){
            if(event){event.preventDefault();event.stopPropagation();if(event.stopImmediatePropagation)event.stopImmediatePropagation();}
            if(typeof window.openDrawer==='function'){
                window.openDrawer();
            }else{
                document.body.classList.add('drawer-open');
            }
            return false;
        };
        window.asrTgDialogsTogglePanelMenu=function(btn,event){
            stop(event);
            var drop=btn&&btn.closest?btn.closest('[data-dialog-panel-dropdown]'):null;
            if(!drop) return false;
            document.querySelectorAll('[data-dialog-panel-dropdown].is-open').forEach(function(other){if(other!==drop){other.classList.remove('is-open');positionPanelMenu(other);}});
            drop.classList.toggle('is-open');
            positionPanelMenu(drop);
            return false;
        };
        window.asrTgDialogsSwitchPanel=function(btn,event){
            stop(event);
            if(!btn || btn.disabled) return false;
            document.querySelectorAll('[data-dialog-panel-dropdown].is-open').forEach(function(drop){drop.classList.remove('is-open');positionPanelMenu(drop);});
            setMobilePanel(btn.getAttribute('data-mobile-panel')||'list');
            return false;
        };

        function stop(e){
            if(!e) return;
            e.preventDefault();
            e.stopPropagation();
            if(e.stopImmediatePropagation) e.stopImmediatePropagation();
        }
        function notice(text,isError){
            var old=document.querySelector('.tg-dialog-ajax-toast');
            if(old) old.remove();
            var div=document.createElement('div');
            div.className='tg-dialog-ajax-toast '+(isError?'is-error':'');
            div.textContent=text;
            document.body.appendChild(div);
            setTimeout(function(){div.classList.add('is-out');},2600);
            setTimeout(function(){div.remove();},3300);
        }
        function setLoading(on){
            var shell=document.querySelector('.tg-dialogs-shell');
            var main=document.querySelector('.tg-dialog-main');
            if(shell) shell.classList.toggle('is-loading',!!on);
            if(main) main.classList.toggle('is-ajax-loading',!!on);
        }
        function normalizeUrl(url){
            var u=new URL(url,window.location.href);
            u.searchParams.set('tab','telegram_bots');
            u.searchParams.set('page','messages');
            u.searchParams.delete('tg_ajax');
            return u.pathname.replace(/^\//,'')+'?'+u.searchParams.toString();
        }
        function fetchText(url,options){
            options=options||{};
            options.credentials='same-origin';
            options.headers=Object.assign({'X-Requested-With':'XMLHttpRequest'},options.headers||{});
            return fetch(url,options).then(function(r){
                return r.text().then(function(text){
                    if(!r.ok) throw new Error('Сервер вернул ошибку '+r.status);
                    return text;
                });
            });
        }
        function replaceNodeFromDoc(doc,selector,mode){
            var src=doc.querySelector(selector);
            var dst=document.querySelector(selector);
            if(!src||!dst) return false;
            if(mode==='inner') dst.innerHTML=src.innerHTML;
            else dst.replaceWith(src);
            return true;
        }
        function scrollChatBottom(){
            var feed=document.querySelector('[data-dialog-chat-feed]');
            if(feed) feed.scrollTop=feed.scrollHeight;
        }
        function fitShell(){
            var shell=document.querySelector('.tg-dialogs-shell');
            if(!shell || window.innerWidth<=820) return;
            var rect=shell.getBoundingClientRect();
            var h=Math.max(560,Math.floor(window.innerHeight-Math.max(0,rect.top)-2));
            document.documentElement.style.setProperty('--tg-dialog-shell-h',h+'px');
            shell.style.setProperty('height',h+'px','important');
            shell.style.setProperty('min-height','0','important');
            shell.style.setProperty('margin-bottom','0','important');
        }
        function syncDialogTitleFromDom(){
            var tabs=document.querySelector('.tg-dialog-tabs');
            var total=tabs?parseInt(tabs.getAttribute('data-needs-reply-total')||'0',10)||0:0;
            var base=(window.tgDialogOriginalTitle||document.title.replace(/^●\s+/, ''));
            document.title=(total>0?'● ':'')+base;
        }
        function applyHtml(html,url,opts){
            opts=opts||{};
            var doc=new DOMParser().parseFromString(html,'text/html');
            if(!doc.querySelector('.tg-dialogs-shell')) throw new Error('Сервер вернул страницу без блока диалогов. Проверьте сессию/права.');
            replaceNodeFromDoc(doc,'.tg-dialog-tabs');
            replaceNodeFromDoc(doc,'.tg-dialog-main');
            replaceNodeFromDoc(doc,'.tg-dialog-side');
            if(!opts.keepList) replaceNodeFromDoc(doc,'.tg-dialog-list-scroll','inner');
            if(url){
                lastUrl=url;
                history.pushState({tgDialogsRescue:true},'',url);
            }
            scrollChatBottom();
            fitShell();
            if(window.asrTgDialogsFitViewport) window.asrTgDialogsFitViewport();
            syncDialogTitleFromDom();
            syncMobilePanel(opts.mobilePanel || null);
            bindMobileHeaderScrollers();
            syncMobileHeaderCollapse();
        }
        function loadHtml(url,opts){
            if(busy) return Promise.resolve(false);
            busy=true;
            setLoading(true);
            var target=normalizeUrl(url);
            return fetchText(target)
                .then(function(html){applyHtml(html,target,opts||{}); return true;})
                .catch(function(err){console.error('[dialogs ajax]',err); notice(err.message||'Не удалось загрузить диалог',true); return false;})
                .finally(function(){busy=false;setLoading(false);});
        }
        function formTargetUrl(form){
            var fd=new FormData(form);
            var view=fd.get('dialog_view')||new URLSearchParams(window.location.search).get('dialog_view')||'new';
            var botId=fd.get('bot_id')||new URLSearchParams(window.location.search).get('bot_id')||'';
            var sid=fd.get('subscriber_id')||new URLSearchParams(window.location.search).get('subscriber_id')||'';
            var action=fd.get('action')||'';
            var assigned=parseInt(fd.get('assigned_user_id')||'0',10)||0;
            var status=fd.get('dialog_status')||'';
            if(action==='tg_dialog_assign') view=assigned>0?'assigned':'new';
            if(action==='tg_dialog_status') view=(status==='mine')?'my':(status||view);
            if(action==='tg_subscriber_send_message') view='my';
            var p=new URLSearchParams();
            p.set('tab','telegram_bots');p.set('page','messages');p.set('dialog_view',view);
            if(botId) p.set('bot_id',botId);
            if(sid) p.set('subscriber_id',sid);
            return 'admin.php?'+p.toString();
        }
        function submitAjax(form){
            var isDialogControl=form.matches('.tg-dialog-assign-form,.tg-dialog-action-form');
            if(form.dataset.submitting==='1') return;
            if((busy||formBusy) && !isDialogControl) return;
            var confirmText=form.getAttribute('data-confirm')||form.getAttribute('onsubmit');
            if(form.getAttribute('data-confirm') && !confirm(form.getAttribute('data-confirm'))) return;
            form.dataset.submitting='1';
            if(isDialogControl) formBusy=true; else busy=true;
            setLoading(true);
            var fd=new FormData(form);
            fd.set('tg_ajax','1');
            fd.set('return_page','messages');
            var target=formTargetUrl(form);
            fetchText('admin.php?tab=telegram_bots&page=messages',{method:'POST',body:fd})
                .then(function(text){
                    var payload=null;
                    try{payload=JSON.parse(text);}catch(e){}
                    if(payload && payload.ok){
                        var nextTarget=target;
                        try{
                            var u=new URL(target,window.location.href);
                            if(payload.view) u.searchParams.set('dialog_view',payload.view);
                            if(payload.selected_subscriber_id) u.searchParams.set('subscriber_id',payload.selected_subscriber_id);
                            if(payload.selected_bot_id) u.searchParams.set('bot_id',payload.selected_bot_id);
                            nextTarget=u.pathname.replace(/^\//,'')+'?'+u.searchParams.toString();
                        }catch(e){}
                        return fetchText(nextTarget).then(function(html){applyHtml(html,nextTarget,{mobilePanel:(form.matches('[data-dialog-compose]')?'chat':null)});});
                    }
                    // Если сервер вернул HTML после обычного редиректа/ошибки, тоже пробуем обновить рабочую область.
                    if(text.indexOf('tg-dialogs-shell')!==-1){applyHtml(text,target,{mobilePanel:(form.matches('[data-dialog-compose]')?'chat':null)});return;}
                    throw new Error((payload&&payload.error)||'Сервер не вернул корректный ответ');
                })
                .then(function(){
                    var ta=document.querySelector('[data-dialog-chat-text]');
                    if(ta && form.matches('[data-dialog-compose]')) ta.value='';
                })
                .catch(function(err){console.error('[dialogs ajax submit]',err); notice(err.message||'Не удалось выполнить действие',true);})
                .finally(function(){delete form.dataset.submitting;if(isDialogControl) formBusy=false; else busy=false;setLoading(false);});
        }
        function linkToDialogUrl(link){
            if(!link) return '';
            var href=link.getAttribute('href')||'';
            if(href) return href;
            var p=new URLSearchParams(window.location.search);
            p.set('tab','telegram_bots');p.set('page','messages');
            var view=link.getAttribute('data-dialog-view');
            if(view){p.set('dialog_view',view);p.delete('subscriber_id');p.delete('bot_id');}
            var sid=link.getAttribute('data-dialog-subscriber-id');
            if(sid) p.set('subscriber_id',sid);
            var bid=link.getAttribute('data-dialog-bot-id');
            if(bid) p.set('bot_id',bid);
            return 'admin.php?'+p.toString();
        }
        window.asrTgDialogsTabFromLink=function(link,event){
            stop(event);
            var url=linkToDialogUrl(link);
            if(url) loadHtml(url,{keepList:false,mobilePanel:'list'});
            return false;
        };
        window.asrTgDialogsOpenFromLink=function(link,event){
            stop(event);
            var url=linkToDialogUrl(link);
            if(url) loadHtml(url,{keepList:true,mobilePanel:'chat'});
            return false;
        };
        document.addEventListener('click',function(e){
            var desktopMenuBtn=e.target.closest('.tg-dialog-desktop-menu-button');
            if(desktopMenuBtn){if(window.asrTgDialogsOpenMainMenu) window.asrTgDialogsOpenMainMenu(e);return;}
            var panelToggle=e.target.closest('[data-dialog-panel-toggle]');
            if(panelToggle){window.asrTgDialogsTogglePanelMenu(panelToggle,e);return;}
            var mobileBtn=e.target.closest('.tg-dialog-mobile-switch [data-mobile-panel], .tg-dialog-panel-menu [data-mobile-panel], .tg-dialog-mobile-back[data-mobile-panel]');
            if(mobileBtn){window.asrTgDialogsSwitchPanel(mobileBtn,e);return;}
            var tab=e.target.closest('.tg-dialog-tab');
            if(tab){window.asrTgDialogsTabFromLink(tab,e);return;}
            var item=e.target.closest('.tg-dialog-item,.tg-dialog-member-item,[data-dialog-subscriber-id]');
            if(item && !e.target.closest('.tg-dialog-compose,.tg-dialog-action-form,.tg-dialog-assign-form')){window.asrTgDialogsOpenFromLink(item,e);return;}
        },true);
        document.addEventListener('submit',function(e){
            var form=e.target.closest('.tg-dialog-action-form,.tg-dialog-assign-form,[data-dialog-compose]');
            if(!form) return;
            e.preventDefault();
            e.stopPropagation();
            if(e.stopImmediatePropagation) e.stopImmediatePropagation();
            var text=form.querySelector('[data-dialog-chat-text]');
            var file=form.querySelector('[data-dialog-chat-file]');
            if(form.matches('[data-dialog-compose]') && (!text || text.value.trim()==='') && (!file || !file.files || !file.files.length)){
                if(text) text.focus();
                return;
            }
            submitAjax(form);
        },true);
        document.addEventListener('change',function(e){
            var select=e.target.closest('.tg-dialog-assign-select');
            if(!select || !select.form) return;
            e.preventDefault();
            e.stopPropagation();
            if(e.stopImmediatePropagation) e.stopImmediatePropagation();
            submitAjax(select.form);
        },true);
        function refreshListHtml(){
            if(listBusy||busy||document.hidden) return;
            listBusy=true;
            var url=normalizeUrl(window.location.href);
            fetchText(url)
                .then(function(html){
                    var doc=new DOMParser().parseFromString(html,'text/html');
                    replaceNodeFromDoc(doc,'.tg-dialog-tabs');
                    replaceNodeFromDoc(doc,'.tg-dialog-list-scroll','inner');
                    syncDialogTitleFromDom();
                })
                .catch(function(err){console.warn('[dialogs list refresh]',err);})
                .finally(function(){listBusy=false;});
        }
        function refreshOpenHtml(){
            if(busy||document.hidden) return;
            var p=new URLSearchParams(window.location.search);
            if(!p.get('subscriber_id')) return;
            fetchText(normalizeUrl(window.location.href))
                .then(function(html){
                    var doc=new DOMParser().parseFromString(html,'text/html');
                    replaceNodeFromDoc(doc,'.tg-dialog-tabs');
                    replaceNodeFromDoc(doc,'.tg-dialog-list-scroll','inner');
                    syncDialogTitleFromDom();
                    var oldFeed=document.querySelector('[data-dialog-chat-feed]');
                    var wasBottom=!oldFeed || (oldFeed.scrollHeight-oldFeed.scrollTop-oldFeed.clientHeight)<100;
                    replaceNodeFromDoc(doc,'.tg-dialog-main');
                    replaceNodeFromDoc(doc,'.tg-dialog-side');
                    if(wasBottom) scrollChatBottom();
                })
                .catch(function(err){console.warn('[dialogs open refresh]',err);});
        }
        scrollChatBottom();
        fitShell();
        syncDialogTitleFromDom();
        syncMobilePanel();
        setInterval(refreshListHtml,12000);
        setInterval(refreshOpenHtml,7000);
        function syncMobileHeaderCollapse(){
            if(!isMobileDialogs()){document.body.classList.remove('tg-dialogs-header-hidden');return;}
            var internal=0;
            document.querySelectorAll('.tg-dialog-list-scroll,[data-dialog-chat-feed],.tg-dialog-side').forEach(function(el){internal=Math.max(internal,el.scrollTop||0);});
            document.body.classList.toggle('tg-dialogs-header-hidden',((window.scrollY||0)>36 || internal>18));
        }
        function bindMobileHeaderScrollers(){
            document.querySelectorAll('.tg-dialog-list-scroll,[data-dialog-chat-feed],.tg-dialog-side').forEach(function(el){
                if(el.dataset.headerCollapseBound==='1') return;
                el.dataset.headerCollapseBound='1';
                el.addEventListener('scroll',syncMobileHeaderCollapse,{passive:true});
            });
        }
        window.addEventListener('scroll',syncMobileHeaderCollapse,{passive:true});
        window.addEventListener('resize',function(){fitShell();syncMobilePanel();syncMobileHeaderCollapse();},{passive:true});
        bindMobileHeaderScrollers();
        syncMobileHeaderCollapse();
        window.addEventListener('popstate',function(){loadHtml(window.location.href,{keepList:false});});
    })();
    </script>

<?php elseif ($page === 'broadcasts'):
    require __DIR__ . '/broadcasts.php';
?>

<?php elseif ($page === 'scenario_flow'): ?>
    <?php require __DIR__ . '/scenario_flow.php'; ?>

<?php elseif ($page === 'scenarios'): ?>
    <?php require __DIR__ . '/scenarios.php'; ?>

<?php elseif ($page === 'logs'):
    $rows = asr_tg_logs_recent($pdo, $selectedBotId, 120);
?>
    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4"><div class="tb2-title" style="display:flex;flex-direction:row;align-items:center;gap:12px;width:100%;"><img class="tb2-title-icon" style="width:24px;height:24px;min-width:24px;flex:0 0 24px;margin:0;display:inline-block;" src="/assets/admin/icons/tb2-log-gray.svg" alt="" aria-hidden="true"><div class="tb2-title-text"><h3 class="text-lg font-semibold text-gray-800">Журнал</h3><p class="text-xs text-gray-400 font-bold mt-1">Технические события модуля.</p></div></div><?php if ($bots) $botSelect($bots, $selectedBotId, 'logs'); ?></div>
        <div class="mt-5 space-y-3">
            <?php foreach ($rows as $log): ?><div class="rounded-2xl border border-gray-100 bg-gray-50 p-4"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"><div class="text-xs font-semibold  text-gray-600"><?php echo $h($log['level']); ?> · <?php echo $h($log['event_type']); ?></div><div class="text-xs text-gray-400 font-bold"><?php echo $h($log['created_at']); ?></div></div><div class="mt-2 text-sm font-semibold text-gray-700"><?php echo $h($log['message']); ?></div></div><?php endforeach; ?>
            <?php if (!$rows): ?><div class="rounded-2xl bg-gray-50 border border-dashed border-gray-200 p-5 text-sm font-semibold text-gray-500">Журнал пока пуст.</div><?php endif; ?>
        </div>
    </section>


    <script>
    (function(){
        var groups = {
            'Частые':['🙂','😊','😉','😍','👍','🙏','👏','🔥','✅','❌','⚠️','🎯','🚀','💡','📌','📎','⏰','📅','💬','❤️'],
            'Эмоции':['😀','😃','😄','😁','😆','😅','😂','🤣','😇','🙂','🙃','😌','😎','🤔','😐','😬','😔','😢','😡','🤯'],
            'Работа':['📌','📎','📄','📊','📈','📉','🧩','🛠️','⚙️','🔔','📣','✉️','📞','💼','📝','🔗','🔒','🔑','🗂️','📦']
        };
        function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
        function insertAtCursor(el,value){if(!el)return;var start=el.selectionStart||0,end=el.selectionEnd||0;el.value=el.value.slice(0,start)+value+el.value.slice(end);var pos=start+value.length;el.focus();el.setSelectionRange(pos,pos);}
        function ensureMenu(menu){
            if(!menu || menu.dataset.ready==='1') return;
            menu.innerHTML=Object.keys(groups).map(function(title){return '<div class="tg-chat-emoji-section"><div class="tg-chat-emoji-title">'+esc(title)+'</div><div class="tg-chat-emoji-grid">'+groups[title].map(function(e){return '<button type="button" data-dialog-settings-emoji-insert="'+esc(e)+'">'+esc(e)+'</button>';}).join('')+'</div></div>';}).join('');
            menu.dataset.ready='1';
        }
        document.addEventListener('click',function(e){
            var emojiBtn=e.target.closest('[data-dialog-settings-emoji]');
            if(emojiBtn){e.preventDefault();e.stopPropagation();var wrap=emojiBtn.closest('.tg-dialog-settings-emoji-wrap');var menu=wrap?wrap.querySelector('[data-dialog-settings-emoji-menu]'):null;ensureMenu(menu);if(menu)menu.classList.toggle('is-open');return;}
            var emoji=e.target.closest('[data-dialog-settings-emoji-insert]');
            if(emoji){e.preventDefault();e.stopPropagation();var modal=emoji.closest('[data-tg-dialog-settings-modal]')||document;var text=modal.querySelector('[data-dialog-settings-auto-reply-text]');insertAtCursor(text,emoji.getAttribute('data-dialog-settings-emoji-insert')||'');var menu2=emoji.closest('[data-dialog-settings-emoji-menu]');if(menu2)menu2.classList.remove('is-open');return;}
            var attach=e.target.closest('[data-dialog-settings-attach]');
            if(attach){e.preventDefault();var modal2=attach.closest('[data-tg-dialog-settings-modal]')||document;var file=modal2.querySelector('[data-dialog-settings-auto-reply-file]');if(file)file.click();return;}
            if(!e.target.closest('.tg-dialog-settings-emoji-wrap')){document.querySelectorAll('[data-dialog-settings-emoji-menu].is-open').forEach(function(m){m.classList.remove('is-open');});}
        },true);
        document.addEventListener('change',function(e){
            var file=e.target.closest('[data-dialog-settings-auto-reply-file]');
            if(!file)return;
            var f=file.files&&file.files[0]?file.files[0]:null;
            var modal=file.closest('[data-tg-dialog-settings-modal]')||document;
            var name=modal.querySelector('[data-dialog-settings-file-name]');
            if(!name)return;
            if(!f){name.textContent='';return;}
            var mb=(f.size/1024/1024).toFixed(f.size>1024*1024?1:2);
            name.textContent=f.name+' · '+mb+' МБ';
        },true);
    })();
    </script>

<?php elseif ($page === 'settings'): ?>
    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-5 sm:p-6"><div class="tb2-title" style="display:flex;flex-direction:row;align-items:center;gap:12px;width:100%;"><img class="tb2-title-icon" style="width:24px;height:24px;min-width:24px;flex:0 0 24px;margin:0;display:inline-block;" src="/assets/admin/icons/tb2-gear-gray.svg" alt="" aria-hidden="true"><h3 class="text-lg font-semibold text-gray-800">Настройки модуля</h3></div><div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-sm font-semibold text-gray-600"><div class="rounded-2xl bg-gray-50 border border-gray-100 p-4">Webhook-файл: <code>telegram_bot_webhook.php</code></div><div class="rounded-2xl bg-gray-50 border border-gray-100 p-4">Шифрование токенов: <?php echo asr_tg_has_crypto_key() ? 'ключ ACCESS_VAULT_KEY найден' : 'ключ ACCESS_VAULT_KEY не найден'; ?></div><div class="rounded-2xl bg-gray-50 border border-gray-100 p-4 lg:col-span-2">Следом сюда можно вынести лимиты рассылок, шаблоны сообщений, настройки очереди и тарифные ограничения по количеству ботов.</div></div></section>
<?php endif; ?>


<script>
(function(){
    if (!document.querySelector('.tg-dialogs-shell')) return;
    var macroCatalog = <?php echo $tgMessageVariablesJson; ?>;
    var esc = function(s){return String(s == null ? '' : s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};

    function stop(e){
        if (!e) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    }
    function closeMenus(except){
        document.querySelectorAll('.tg-chat-emoji-menu.is-open,.tg-chat-macro-menu.is-open,.tg-dialog-dropdown.is-open,.tg-dialog-panel-menu.is-fixed,.tg-dialog-mobile-panel-dropdown.is-open').forEach(function(el){
            if (except && (el === except || el.contains(except) || except.contains(el))) return;
            el.classList.remove('is-open');
            el.classList.remove('is-fixed');
        });
    }
    function ensureEmojiMenu(form){
        var menu = form ? form.querySelector('[data-dialog-chat-emoji-menu]') : null;
        if (!menu) return null;
        if (menu.dataset.dialogEmojiReady === '1') return menu;
        menu.dataset.dialogEmojiReady = '1';
        var groups = {
            'Частые':['🙂','😊','😉','😍','👍','🙏','👏','🔥','✅','❌','⚠️','🎯','🚀','💡','📌','📎','⏰','📅','💬','❤️'],
            'Эмоции':['😀','😃','😄','😁','😆','😅','😂','🤣','😇','🙂','🙃','😌','😎','🤔','😐','😬','😔','😢','😡','🤯'],
            'Работа':['📌','📎','📄','📊','📈','📉','🧩','🛠️','⚙️','🔔','📣','✉️','📞','💼','📝','🔗','🔒','🔑','🗂️','📦'],
            'Жесты':['👍','👎','👌','✌️','🤝','👏','🙌','🙏','💪','👀','👉','👈','☝️','👇','✍️','🤌','🫶','👋','🤙','🖐️']
        };
        var esc = function(s){return String(s == null ? '' : s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};
        menu.innerHTML = Object.keys(groups).map(function(title){
            return '<div class="tg-chat-emoji-section"><div class="tg-chat-emoji-title">'+esc(title)+'</div><div class="tg-chat-emoji-grid">'+groups[title].map(function(item){return '<button type="button" data-dialog-chat-emoji-insert="'+esc(item)+'">'+esc(item)+'</button>';}).join('')+'</div></div>';
        }).join('');
        return menu;
    }
    function ensureMacroMenu(form){
        var menu = form ? form.querySelector('[data-dialog-chat-macro-menu]') : null;
        if (!menu) return null;
        if (menu.dataset.dialogMacroReady === '1') return menu;
        menu.dataset.dialogMacroReady = '1';
        var grouped = {};
        (macroCatalog || []).forEach(function(item){var group=item.group || 'Переменные'; (grouped[group]=grouped[group]||[]).push(item);});
        var html = '<input class="tg-chat-macro-search" placeholder="Найти переменную" data-dialog-chat-macro-search>';
        Object.keys(grouped).forEach(function(group){
            html += '<div class="tg-chat-macro-group">'+esc(group)+'</div>';
            grouped[group].forEach(function(item){
                var token = String(item.token || ''); if (!token) return;
                html += '<button type="button" class="tg-chat-macro-item" data-dialog-chat-macro-insert="'+esc(token)+'" data-dialog-chat-macro-search-text="'+esc((item.title||'')+' '+token+' '+group)+'"><span>'+esc(item.icon||'Т')+'</span><span>'+esc(item.title||token)+'</span><span class="tg-chat-macro-token">'+esc(token)+'</span></button>';
            });
        });
        menu.innerHTML = html;
        var search = menu.querySelector('[data-dialog-chat-macro-search]');
        if (search) search.addEventListener('input', function(){
            var q = String(search.value || '').toLowerCase().trim();
            menu.querySelectorAll('[data-dialog-chat-macro-search-text]').forEach(function(item){
                item.style.display = !q || String(item.getAttribute('data-dialog-chat-macro-search-text') || '').toLowerCase().indexOf(q) !== -1 ? 'grid' : 'none';
            });
        });
        return menu;
    }
    function insertAtCursor(el,value){
        if (!el) return;
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        el.value = el.value.slice(0,start) + value + el.value.slice(end);
        var pos = start + value.length;
        el.focus();
        try { el.setSelectionRange(pos,pos); } catch(e) {}
    }

    document.addEventListener('click', function(e){
        var dropdownToggle = e.target.closest('[data-dialog-dropdown-toggle]');
        if (dropdownToggle) {
            stop(e);
            var dropdown = dropdownToggle.closest('[data-dialog-dropdown]');
            if (dropdown) {
                var willOpen = !dropdown.classList.contains('is-open');
                closeMenus(dropdown);
                dropdown.classList.toggle('is-open', willOpen);
            }
            return;
        }

        var filterBtn = e.target.closest('[data-tg-dialog-filter]');
        if (filterBtn) {
            stop(e);
            var panel = document.querySelector('[data-tg-dialog-filter-panel]');
            if (panel) panel.classList.toggle('is-open');
            return;
        }

        var settingsBtn = e.target.closest('[data-tg-dialog-settings-open]');
        if (settingsBtn) {
            stop(e);
            var modal = document.querySelector('[data-tg-dialog-settings-modal]');
            if (modal) modal.classList.add('is-open');
            return;
        }

        var settingsClose = e.target.closest('[data-tg-dialog-settings-close]');
        if (settingsClose) {
            stop(e);
            var modalClose = settingsClose.closest('[data-tg-dialog-settings-modal]') || document.querySelector('[data-tg-dialog-settings-modal]');
            if (modalClose) modalClose.classList.remove('is-open');
            return;
        }
        var modalBackdrop = e.target.matches('[data-tg-dialog-settings-modal]') ? e.target : null;
        if (modalBackdrop) {
            stop(e);
            modalBackdrop.classList.remove('is-open');
            return;
        }

        var emojiBtn = e.target.closest('[data-dialog-chat-emoji]');
        if (emojiBtn) {
            stop(e);
            var form = emojiBtn.closest('[data-dialog-compose]');
            var menu = ensureEmojiMenu(form);
            if (menu) {
                var willOpen = !menu.classList.contains('is-open');
                closeMenus(menu);
                menu.classList.toggle('is-open', willOpen);
            }
            return;
        }

        var macroBtn = e.target.closest('[data-dialog-chat-macro]');
        if (macroBtn) {
            stop(e);
            var macroForm = macroBtn.closest('[data-dialog-compose]');
            var macroMenu = ensureMacroMenu(macroForm);
            if (macroMenu) {
                var macroWillOpen = !macroMenu.classList.contains('is-open');
                closeMenus(macroMenu);
                macroMenu.classList.toggle('is-open', macroWillOpen);
            }
            return;
        }

        var macroItem = e.target.closest('[data-dialog-chat-macro-insert]');
        if (macroItem) {
            stop(e);
            var macroMenu2 = macroItem.closest('[data-dialog-chat-macro-menu]');
            var macroForm2 = macroItem.closest('[data-dialog-compose]') || (macroMenu2 ? macroMenu2.closest('[data-dialog-compose]') : null) || document.querySelector('[data-dialog-compose]');
            insertAtCursor(macroForm2 ? macroForm2.querySelector('[data-dialog-chat-text]') : null, macroItem.getAttribute('data-dialog-chat-macro-insert') || '');
            if (macroMenu2) macroMenu2.classList.remove('is-open');
            return;
        }

        var emojiItem = e.target.closest('[data-dialog-chat-emoji-insert]');
        if (emojiItem) {
            stop(e);
            var menu2 = emojiItem.closest('[data-dialog-chat-emoji-menu]');
            var form2 = emojiItem.closest('[data-dialog-compose]') || (menu2 ? menu2.closest('[data-dialog-compose]') : null) || document.querySelector('[data-dialog-compose]');
            insertAtCursor(form2 ? form2.querySelector('[data-dialog-chat-text]') : null, emojiItem.getAttribute('data-dialog-chat-emoji-insert') || '');
            if (menu2) menu2.classList.remove('is-open');
            return;
        }

        var attachBtn = e.target.closest('[data-dialog-chat-attach]');
        if (attachBtn) {
            stop(e);
            var form3 = attachBtn.closest('[data-dialog-compose]');
            var input = form3 ? form3.querySelector('[data-dialog-chat-file]') : null;
            if (input) input.click();
            return;
        }

        if (!e.target.closest('.tg-chat-tools,[data-dialog-dropdown],[data-dialog-panel-dropdown]')) {
            closeMenus();
        }
    }, true);

    document.addEventListener('change', function(e){
        var file = e.target.closest('[data-dialog-chat-file]');
        if (!file) return;
        var form = file.closest('[data-dialog-compose]');
        var label = form ? form.querySelector('[data-dialog-chat-file-name]') : null;
        if (!label) return;
        var f = file.files && file.files[0] ? file.files[0] : null;
        if (!f) { label.textContent = ''; return; }
        var mb = (f.size / 1024 / 1024).toFixed(f.size > 1024 * 1024 ? 1 : 2);
        label.textContent = f.name + ' · ' + mb + ' МБ';
    }, true);

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            document.querySelectorAll('[data-tg-dialog-settings-modal].is-open').forEach(function(modal){modal.classList.remove('is-open');});
            closeMenus();
        }
    });
})();
</script>

<script>
(function(){
    document.querySelectorAll('[data-tg-toast]').forEach(function(toast){
        window.setTimeout(function(){toast.classList.add('is-hiding');}, 2600);
        window.setTimeout(function(){toast.remove();}, 3150);
    });
    document.querySelectorAll('[data-tag-combo]').forEach(function(combo){
        const input = combo.querySelector('[data-tag-combo-input]');
        const panel = combo.querySelector('[data-tag-combo-panel]');
        if (!input || !panel) return;
        input.addEventListener('focus', function(){ combo.classList.add('is-open'); });
        input.addEventListener('input', function(){ combo.classList.add('is-open'); });
        panel.querySelectorAll('[data-tag-name]').forEach(function(btn){
            btn.addEventListener('click', function(){ input.value = btn.dataset.tagName || btn.textContent.trim(); combo.classList.remove('is-open'); input.focus(); });
        });
        document.addEventListener('click', function(e){ if (!combo.contains(e.target)) combo.classList.remove('is-open'); });
        input.addEventListener('keydown', function(e){ if (e.key === 'Escape') combo.classList.remove('is-open'); });
    });
})();
</script>
</div>
