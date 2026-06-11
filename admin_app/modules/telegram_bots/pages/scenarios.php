<?php
/** @var PDO $pdo */
defined('ASR_ADMIN') || exit;

$h = $h ?? static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (!asr_tg_can('flows')): ?>
<section class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-semibold">Недостаточно прав для управления сценариями.</section>
<?php return; endif;

try {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioLabels = asr_tg_scenario_status_labels();
    if (function_exists('asr_tg_scenario_normalize_all_single_bot')) {
        asr_tg_scenario_normalize_all_single_bot($pdo);
    }
    $scenarioStatus = trim((string)($_GET['status'] ?? ''));
    $allowedWorkStatuses = ['active' => true, 'draft' => true, 'paused' => true];
    if ($scenarioStatus !== '' && !isset($allowedWorkStatuses[$scenarioStatus])) $scenarioStatus = '';

    $isArchive = (string)($_GET['view'] ?? '') === 'archive';
    if ($isArchive) {
        $scenarios = asr_tg_scenarios_all($pdo, 'archived');
    } elseif ($scenarioStatus !== '') {
        $scenarios = asr_tg_scenarios_all($pdo, $scenarioStatus);
    } else {
        $scenarios = array_values(array_filter(asr_tg_scenarios_all($pdo, ''), static fn($row) => (string)($row['status'] ?? '') !== 'archived'));
    }

    $botsLight = asr_tg_bots_all_light($pdo);
    $scenarioBotIdMap = [];
    foreach ($scenarios as $row) {
        $sid = (int)($row['id'] ?? 0);
        if ($sid > 0) {
            $scenarioBotIdMap[$sid] = asr_tg_scenario_default_bot_id($pdo, $sid);
        }
    }
} catch (Throwable $e) { ?>
<section class="bg-white rounded-3xl border border-red-100 p-8 text-left">
    <h3 class="text-lg font-semibold text-red-700">Раздел «Сценарии» не смог загрузиться</h3>
    <p class="mt-3 text-sm font-semibold text-gray-600"><?php echo $h($e->getMessage()); ?></p>
    <p class="mt-3 text-sm font-semibold text-gray-500">Проверьте миграцию <code>database/migrations/2026_06_03_003_telegram_scenarios_foundation.sql</code>.</p>
</section>
<?php return; }

$statusClass = static function(string $status): string {
    return match ($status) {
        'active' => 'tg-scenario-status tg-scenario-status--active',
        'paused' => 'tg-scenario-status tg-scenario-status--paused',
        'archived' => 'tg-scenario-status tg-scenario-status--archived',
        default => 'tg-scenario-status tg-scenario-status--draft',
    };
};

$workFilters = [
    '' => 'Все рабочие',
    'active' => 'Активные',
    'draft' => 'Черновики',
    'paused' => 'На паузе',
];
?>

<style>
.tg-scenarios-page{background:#fff;border:1px solid #edf0f2;border-radius:24px;box-shadow:0 12px 34px rgba(15,23,42,.04);overflow:visible}.tg-scenarios-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:22px 24px;border-bottom:1px solid #edf0f2}.tg-scenarios-title{margin:0;font-size:22px;font-weight:700;color:#1f2937}.tg-scenarios-note{margin-top:5px;font-size:12px;font-weight:600;color:#9ca3af;line-height:1.45}.tg-scenarios-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.tg-scenario-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:0;border-radius:14px;padding:0 15px;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer;white-space:nowrap}.tg-scenario-btn--main{background:#e98222;color:#fff}.tg-scenario-btn--ghost{background:#f3f4f6;color:#6b7280}.tg-scenario-btn--danger{background:#fff1f2;color:#e11d48}.tg-scenario-btn--small{min-height:34px;padding:0 11px;border-radius:12px;font-size:11px}.tg-scenarios-filter{display:flex;gap:8px;flex-wrap:wrap;padding:14px 20px;background:#fbfbfc;border-bottom:1px solid #edf0f2}.tg-scenarios-filter a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;border-radius:14px;padding:0 12px;font-size:12px;font-weight:650;text-decoration:none}.tg-scenarios-filter a.is-active{background:#fff4e8;color:#e98222;border-color:#ffedd5}.tg-scenario-table{width:100%;border-collapse:collapse}.tg-scenario-table th{padding:11px 20px;background:#fff;text-align:left;font-size:11px;font-weight:700;color:#9ca3af;border-bottom:1px solid #f3f4f6}.tg-scenario-table td{padding:15px 20px;border-bottom:1px solid #f3f4f6;vertical-align:middle;overflow:visible}.tg-scenario-table tr{position:relative}.tg-scenario-table tr:hover td{background:#fffaf4}.tg-scenario-table tr:has(.tg-scenario-menu.is-open){z-index:50}.tg-scenario-name{font-size:14px;font-weight:750;color:#1f2937}.tg-scenario-desc{margin-top:4px;max-width:580px;font-size:12px;font-weight:600;color:#9ca3af;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tg-scenario-meta{font-size:12px;font-weight:650;color:#6b7280}.tg-scenario-muted{font-size:11px;font-weight:650;color:#9ca3af}.tg-scenario-channels{max-width:360px;font-size:12px;font-weight:600;color:#6b7280;line-height:1.45;white-space:pre-line}.tg-scenario-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:750;border:1px solid;white-space:nowrap}.tg-scenario-status--active{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.tg-scenario-status--paused{background:#f3f4f6;color:#6b7280;border-color:#e5e7eb}.tg-scenario-status--archived{background:#f8fafc;color:#94a3b8;border-color:#e2e8f0}.tg-scenario-status--draft{background:#fff7ed;color:#e98222;border-color:#ffedd5}.tg-scenario-row-actions{position:relative;text-align:right;overflow:visible}.tg-scenario-more{width:34px;height:34px;border:1px solid #e5e7eb;background:#fff;border-radius:12px;color:#6b7280;font-size:20px;line-height:1;cursor:pointer}.tg-scenario-menu{position:absolute;right:20px;top:38px;z-index:200;display:none;min-width:190px;padding:7px;background:#fff;border:1px solid #edf0f2;border-radius:16px;box-shadow:0 18px 42px rgba(15,23,42,.13);text-align:left}.tg-scenario-menu.is-open{display:block}.tg-scenario-menu button,.tg-scenario-menu a{width:100%;display:flex;align-items:center;gap:8px;border:0;background:transparent;border-radius:11px;padding:10px 11px;font-size:12px;font-weight:700;color:#4b5563;text-decoration:none;cursor:pointer;text-align:left}.tg-scenario-menu button:hover,.tg-scenario-menu a:hover{background:#fff7ed;color:#e98222}.tg-scenario-menu .is-danger{color:#e11d48}.tg-scenario-menu .is-danger:hover{background:#fff1f2;color:#e11d48}.tg-scenario-empty{padding:36px 24px;text-align:center;color:#9ca3af;font-size:13px;font-weight:650}.tg-scenario-archive-tools{display:flex;gap:9px;justify-content:flex-end;align-items:center}.tg-scenario-icon-btn{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e5e7eb;background:#fff;border-radius:12px;color:#6b7280;text-decoration:none;cursor:pointer}.tg-scenario-icon-btn:hover{background:#fff7ed;color:#e98222}.tg-scenario-icon-btn.is-danger{color:#e11d48}.tg-scenario-icon-btn.is-danger:hover{background:#fff1f2;color:#e11d48}.tg-scenario-modal-backdrop{position:fixed;inset:0;z-index:9998;display:none;background:rgba(15,23,42,.38);backdrop-filter:blur(2px)}.tg-scenario-modal-backdrop.is-open{display:block}.tg-scenario-modal{position:fixed;left:50%;top:50%;z-index:9999;display:none;width:min(720px,calc(100vw - 28px));max-height:calc(100vh - 44px);overflow:auto;transform:translate(-50%,-50%);background:#fff;border-radius:24px;border:1px solid #edf0f2;box-shadow:0 26px 70px rgba(15,23,42,.22)}.tg-scenario-modal.is-open{display:block}.tg-scenario-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:20px 22px;border-bottom:1px solid #edf0f2}.tg-scenario-modal-title{font-size:20px;font-weight:750;color:#1f2937}.tg-scenario-close{width:34px;height:34px;border:0;border-radius:12px;background:#f3f4f6;color:#6b7280;font-size:20px;cursor:pointer}.tg-scenario-form{padding:20px 22px;display:grid;gap:14px}.tg-scenario-field span{display:block;margin-bottom:6px;font-size:11px;font-weight:650;color:#9ca3af}.tg-scenario-field input,.tg-scenario-field textarea,.tg-scenario-field select{width:100%;border:1px solid #e5e7eb;background:#fff;border-radius:16px;padding:12px 13px;font-size:13px;font-weight:650;color:#374151;outline:none}.tg-scenario-field textarea{min-height:92px;resize:vertical;line-height:1.45}.tg-scenario-bots{display:grid;gap:9px}.tg-scenario-bot-row{display:grid;grid-template-columns:minmax(0,1fr);gap:9px;align-items:center;border:1px solid #edf0f2;border-radius:16px;padding:10px 12px;background:#fbfbfc}.tg-scenario-bot-title{font-size:13px;font-weight:700;color:#374151}.tg-scenario-bot-meta{font-size:11px;font-weight:600;color:#9ca3af;margin-top:2px}.tg-scenario-bot-select{width:100%;border:1px solid #e5e7eb;background:#fff;border-radius:16px;padding:12px 13px;font-size:13px;font-weight:650;color:#374151;outline:none}.tg-scenario-bot-row.is-selected{background:#fff7ed;border-color:#fed7aa}.tg-scenario-help{border:1px dashed #fed7aa;background:#fff7ed;border-radius:18px;padding:13px 14px;color:#9a5a16;font-size:12px;font-weight:650;line-height:1.5}.tg-scenario-form-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding-top:4px}@media(max-width:980px){.tg-scenarios-top{flex-direction:column}.tg-scenarios-actions{width:100%;justify-content:flex-start}.tg-scenario-table thead{display:none}.tg-scenario-table,.tg-scenario-table tbody,.tg-scenario-table tr,.tg-scenario-table td{display:block;width:100%}.tg-scenario-table tr{border-bottom:1px solid #f3f4f6}.tg-scenario-table td{border-bottom:0;padding:8px 18px}.tg-scenario-table td:first-child{padding-top:15px}.tg-scenario-table td:last-child{padding-bottom:15px}.tg-scenario-row-actions{text-align:left}.tg-scenario-menu{left:18px;right:auto;top:40px}.tg-scenario-bot-row{grid-template-columns:1fr}}
.tg-scenario-name-link{display:inline-flex;text-decoration:none;color:#1f2937}.tg-scenario-name-link:hover{color:#e98222}
</style>

<style>
html.asr-dark .tg-scenarios-page,
html.asr-dark .tg-scenario-menu,
html.asr-dark .tg-scenario-modal{background:#191D23!important;border-color:#2B313A!important;box-shadow:0 18px 48px rgba(0,0,0,.28)!important}
html.asr-dark .tg-scenarios-top,
html.asr-dark .tg-scenarios-filter,
html.asr-dark .tg-scenario-table th,
html.asr-dark .tg-scenario-table td,
html.asr-dark .tg-scenario-modal-head{border-color:#2B313A!important}
html.asr-dark .tg-scenarios-filter{background:#222832!important}
html.asr-dark .tg-scenarios-title,
html.asr-dark .tg-scenario-name,
html.asr-dark .tg-scenario-modal-title{color:#F3F4F6!important}
html.asr-dark .tg-scenarios-note,
html.asr-dark .tg-scenario-desc,
html.asr-dark .tg-scenario-muted,
html.asr-dark .tg-scenario-meta,
html.asr-dark .tg-scenario-channels,
html.asr-dark .tg-scenario-field span,
html.asr-dark .tg-scenario-bot-meta{color:#A9B4C2!important}
html.asr-dark .tg-scenarios-filter a,
html.asr-dark .tg-scenario-more,
html.asr-dark .tg-scenario-icon-btn,
html.asr-dark .tg-scenario-close,
html.asr-dark .tg-scenario-btn--ghost,
html.asr-dark .tg-scenario-menu button,
html.asr-dark .tg-scenario-menu a{background:#222832!important;border-color:#303844!important;color:#D7DEE8!important}
html.asr-dark .tg-scenario-table tr:hover td{background:#222832!important}
html.asr-dark .tg-scenario-bot-row{background:#222832!important;border-color:#303844!important}
html.asr-dark .tg-scenario-bot-title{color:#E5E7EB!important}
html.asr-dark .tg-scenario-help{background:rgba(255,160,72,.10)!important;border-color:rgba(255,160,72,.24)!important;color:#FDBA74!important}
</style>


<section class="tg-scenarios-page">
    <div class="tg-scenarios-top">
        <div>
            <h3 class="tg-scenarios-title"><?php echo $isArchive ? 'Архив сценариев' : 'Сценарии'; ?></h3>
            <div class="tg-scenarios-note"><?php echo $isArchive ? 'Здесь лежат архивные сценарии. Их можно восстановить или удалить навсегда.' : 'Сценарии могут быть подключены к одному или нескольким каналам. Блоки и запуск подключаем следующими шагами.'; ?></div>
        </div>
        <div class="tg-scenarios-actions">
            <?php if ($isArchive): ?>
                <a class="tg-scenario-btn tg-scenario-btn--ghost" href="admin.php?tab=telegram_bots&page=scenarios">← К сценариям</a>
            <?php else: ?>
                <a class="tg-scenario-btn tg-scenario-btn--ghost" href="admin.php?tab=telegram_bots&page=scenarios&view=archive">Архив</a>
                <button class="tg-scenario-btn tg-scenario-btn--main" type="button" data-scenario-open-create>+ Создать сценарий</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isArchive): ?>
        <div class="tg-scenarios-filter">
            <?php foreach ($workFilters as $code => $label): ?>
                <a href="admin.php?tab=telegram_bots&page=scenarios<?php echo $code !== '' ? '&status=' . urlencode($code) : ''; ?>" class="<?php echo $scenarioStatus === $code ? 'is-active' : ''; ?>"><?php echo $h($label); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$scenarios): ?>
        <div class="tg-scenario-empty"><?php echo $isArchive ? 'В архиве пока пусто.' : 'Сценариев пока нет. Нажмите «Создать сценарий» и задайте базовые настройки.'; ?></div>
    <?php else: ?>
        <table class="tg-scenario-table">
            <thead>
                <tr>
                    <th>Сценарий</th>
                    <th>Статус</th>
                    <th>Канал</th>
                    <th>Блоки</th>
                    <th>Обновлён</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scenarios as $row):
                $rowId = (int)($row['id'] ?? 0);
                $channels = trim((string)($row['enabled_bot_titles'] ?? ''));
                $rowStatus = (string)($row['status'] ?? 'draft');
                $selectedBotId = (int)($scenarioBotIdMap[$rowId] ?? 0);
                $rowData = [
                    'id' => $rowId,
                    'title' => (string)($row['title'] ?? ''),
                    'description' => (string)($row['description'] ?? ''),
                    'status' => $rowStatus,
                    'bot_id' => $selectedBotId,
                ];
                ?>
                <tr>
                    <td>
                        <a class="tg-scenario-name tg-scenario-name-link" href="admin.php?tab=telegram_bots&page=scenario_flow&scenario_id=<?php echo $rowId; ?>"><?php echo $h($row['title'] ?? 'Без названия'); ?></a>
                        <div class="tg-scenario-desc"><?php echo $h($row['description'] ?? ''); ?></div>
                        <div class="tg-scenario-muted">ID <?php echo $rowId; ?></div>
                    </td>
                    <td><span class="<?php echo $statusClass($rowStatus); ?>"><?php echo $h($scenarioLabels[$rowStatus] ?? 'Черновик'); ?></span></td>
                    <td><div class="tg-scenario-channels"><?php echo $channels !== '' ? $h(strtok($channels, "\n") ?: $channels) : 'Канал пока не выбран'; ?></div></td>
                    <td><div class="tg-scenario-meta"><?php echo (int)($row['blocks_count'] ?? 0); ?> блоков</div></td>
                    <td><div class="tg-scenario-muted"><?php echo $h($row['updated_at'] ?? ''); ?></div></td>
                    <td class="tg-scenario-row-actions">
                        <?php if ($isArchive): ?>
                            <div class="tg-scenario-archive-tools">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="tg_scenario_restore">
                                    <input type="hidden" name="scenario_id" value="<?php echo $rowId; ?>">
                                    <button class="tg-scenario-btn tg-scenario-btn--ghost tg-scenario-btn--small" type="submit">Вернуть</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить сценарий навсегда? Это действие нельзя отменить.');">
                                    <input type="hidden" name="action" value="tg_scenario_delete">
                                    <input type="hidden" name="scenario_id" value="<?php echo $rowId; ?>">
                                    <button class="tg-scenario-icon-btn is-danger" type="submit" title="Удалить навсегда">🗑</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <button class="tg-scenario-more" type="button" data-scenario-menu-toggle aria-label="Действия">⋯</button>
                            <div class="tg-scenario-menu">
                                <button type="button" data-scenario-edit='<?php echo $h(json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'>Редактировать</button>
                                <?php if ($rowStatus === 'active'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="tg_scenario_pause">
                                        <input type="hidden" name="scenario_id" value="<?php echo $rowId; ?>">
                                        <button type="submit">Остановить</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="tg_scenario_resume">
                                        <input type="hidden" name="scenario_id" value="<?php echo $rowId; ?>">
                                        <button type="submit">Запустить</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Перенести сценарий в архив?');">
                                    <input type="hidden" name="action" value="tg_scenario_archive">
                                    <input type="hidden" name="scenario_id" value="<?php echo $rowId; ?>">
                                    <button class="is-danger" type="submit">В архив</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<div class="tg-scenario-modal-backdrop" data-scenario-modal-backdrop></div>
<div class="tg-scenario-modal" data-scenario-modal>
    <div class="tg-scenario-modal-head">
        <div>
            <div class="tg-scenario-modal-title" data-scenario-modal-title>Новый сценарий</div>
            <div class="tg-scenarios-note">Базовые настройки сценария: название, описание, статус и один канал.</div>
        </div>
        <button class="tg-scenario-close" type="button" data-scenario-close>×</button>
    </div>
    <form method="POST" class="tg-scenario-form" data-scenario-form>
        <input type="hidden" name="action" value="tg_scenario_save">
        <input type="hidden" name="scenario_id" value="0" data-scenario-id>

        <label class="tg-scenario-field">
            <span>Название</span>
            <input type="text" name="title" value="" placeholder="Например: Приветствие нового подписчика" required data-scenario-title>
        </label>

        <label class="tg-scenario-field">
            <span>Описание</span>
            <textarea name="description" placeholder="Для чего нужен сценарий и откуда он запускается" data-scenario-description></textarea>
        </label>

        <label class="tg-scenario-field">
            <span>Статус</span>
            <select name="status" data-scenario-status>
                <option value="draft">Черновик</option>
                <option value="active">Активен</option>
                <option value="paused">Пауза</option>
            </select>
        </label>

        <div class="tg-scenario-field">
            <span>Канал сценария</span>
            <?php if (!$botsLight): ?>
                <div class="tg-scenario-help">Сначала добавьте канал в разделе «Каналы».</div>
            <?php else: ?>
                <select name="scenario_bot_id" class="tg-scenario-bot-select" data-scenario-bot-select>
                    <option value="0">Без канала</option>
                    <?php foreach ($botsLight as $bot):
                        $botId = (int)($bot['id'] ?? 0);
                        $channelType = function_exists('asr_tg_channel_label') ? asr_tg_channel_label((string)($bot['channel_type'] ?? 'telegram')) : 'Telegram';
                        $label = (string)($bot['title'] ?? ('Канал #' . $botId));
                        $meta = $channelType . (!empty($bot['bot_username']) ? ' · @' . (string)$bot['bot_username'] : '');
                        ?>
                        <option value="<?php echo $botId; ?>"><?php echo $h($label . ' — ' . $meta); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="tg-scenario-form-actions">
            <button class="tg-scenario-btn tg-scenario-btn--ghost" type="button" data-scenario-close>Отмена</button>
            <button class="tg-scenario-btn tg-scenario-btn--main" type="submit">Сохранить</button>
        </div>
    </form>
</div>

<script>
(function(){
    const modal = document.querySelector('[data-scenario-modal]');
    const backdrop = document.querySelector('[data-scenario-modal-backdrop]');
    const form = document.querySelector('[data-scenario-form]');
    if (!modal || !backdrop || !form) return;

    const titleNode = document.querySelector('[data-scenario-modal-title]');
    const idInput = form.querySelector('[data-scenario-id]');
    const titleInput = form.querySelector('[data-scenario-title]');
    const descInput = form.querySelector('[data-scenario-description]');
    const statusInput = form.querySelector('[data-scenario-status]');
    const botSelect = form.querySelector('[data-scenario-bot-select]');

    function setOpen(open){
        modal.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    function resetBot(){
        if (botSelect) botSelect.value = '0';
    }
    function openCreate(){
        titleNode.textContent = 'Новый сценарий';
        idInput.value = '0';
        titleInput.value = '';
        descInput.value = '';
        statusInput.value = 'draft';
        resetBot();
        setOpen(true);
        setTimeout(() => titleInput.focus(), 60);
    }
    function openEdit(data){
        titleNode.textContent = 'Редактировать сценарий';
        idInput.value = data.id || 0;
        titleInput.value = data.title || '';
        descInput.value = data.description || '';
        statusInput.value = ['active','draft','paused'].includes(data.status) ? data.status : 'draft';
        resetBot();
        if (botSelect) botSelect.value = String(Number(data.bot_id || 0));
        setOpen(true);
        setTimeout(() => titleInput.focus(), 60);
    }

    document.querySelectorAll('[data-scenario-open-create]').forEach(btn => btn.addEventListener('click', openCreate));
    document.querySelectorAll('[data-scenario-close]').forEach(btn => btn.addEventListener('click', () => setOpen(false)));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });

    document.querySelectorAll('[data-scenario-menu-toggle]').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            const menu = this.parentElement.querySelector('.tg-scenario-menu');
            document.querySelectorAll('.tg-scenario-menu.is-open').forEach(m => { if (m !== menu) m.classList.remove('is-open'); });
            if (menu) menu.classList.toggle('is-open');
        });
    });
    document.addEventListener('click', () => document.querySelectorAll('.tg-scenario-menu.is-open').forEach(m => m.classList.remove('is-open')));
    document.querySelectorAll('[data-scenario-edit]').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            let data = {};
            try { data = JSON.parse(this.getAttribute('data-scenario-edit') || '{}'); } catch(err) { data = {}; }
            document.querySelectorAll('.tg-scenario-menu.is-open').forEach(m => m.classList.remove('is-open'));
            openEdit(data);
        });
    });
})();
</script>

<style>
/* v3.6.55 dark residual fix: scenarios table header */
html.asr-dark .tg-scenario-table thead,
html.asr-dark .tg-scenario-table th { background: #222832 !important; color: #A9B4C2 !important; border-color: #2B313A !important; }
html.asr-dark .tg-scenario-table tr,
html.asr-dark .tg-scenario-table td { background: transparent !important; border-color: #2B313A !important; }
html.asr-dark .tg-scenario-table tr:hover td { background: #222832 !important; }
html.asr-dark .tg-scenario-status--active { background: rgba(16,185,129,.14) !important; color: #86EFAC !important; border-color: rgba(74,222,128,.28) !important; }
</style>

