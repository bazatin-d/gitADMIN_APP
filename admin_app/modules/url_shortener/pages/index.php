<?php
defined('ASR_ADMIN') || exit;
$settings = asr_get_all_settings();
$hasShortenerTable = asr_shortener_table_exists($pdo);
if ($hasShortenerTable) {
    asr_shortener_ensure_schema($pdo);
}
$hasUtmPresetTable = asr_utm_presets_table_exists($pdo);
$utmPresets = asr_get_utm_presets($pdo);
$utmTypeLabels = asr_utm_type_labels();
$utmRequiredTypes = asr_utm_required_types();
$shortRows = [];
$shortDefaultDomain = asr_shortener_default_domain();
$shortDefaultBaseUrl = function_exists('asr_config_shortener_base_url') ? asr_config_shortener_base_url() : ('https://' . $shortDefaultDomain . '/');
$marketingDomain = defined('MARKETING_DOMAIN') ? MARKETING_DOMAIN : 'localhost';
if ($hasShortenerTable) {
    if (isAdmin()) {
        $shortRows = $pdo->query("SELECT * FROM oca_short_urls WHERE is_permanent = 1 OR id IN (SELECT id FROM (SELECT id FROM oca_short_urls ORDER BY id DESC LIMIT 300) recent_short_urls) ORDER BY is_permanent DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM oca_short_urls WHERE created_by = ? AND (is_permanent = 1 OR id IN (SELECT id FROM (SELECT id FROM oca_short_urls WHERE created_by = ? ORDER BY id DESC LIMIT 300) recent_short_urls)) ORDER BY is_permanent DESC, id DESC");
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $stmt->execute([$uid, $uid]);
        $shortRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$renderUtmOptions = static function(array $items, string $type): void {
    foreach ($items as $item) {
        $display = asr_utm_preset_display($item, $type);
        $value = (string)($display['value'] ?? '');
        if ($value === '') continue;
        $description = trim((string)($display['description'] ?? ''));
        $label = $description !== '' ? $value . ' — ' . $description : $value;
        echo '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
};
?>
<div class="space-y-6">
<style>
    .sh2-section-title{display:flex;align-items:flex-start;gap:14px}
    .sh2-section-icon{width:32px;height:32px;flex:0 0 32px;margin-top:1px;opacity:.92}
    .sh2-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important}
    .sh2-icon{width:20px;height:20px;display:inline-block;vertical-align:middle;object-fit:contain;opacity:.95;flex:0 0 auto}
    .sh2-icon-sm{width:18px;height:18px}
    .sh2-icon-lg{width:24px;height:24px}
    .sh2-icon-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important}
    @media(max-width:768px){
        .sh2-section-title{gap:10px}
        .sh2-section-icon{width:28px;height:28px;flex-basis:28px}
    }
</style>
<style>
/* sh2 button icon alignment correction */
.sh2-btn{
  display:inline-flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:center!important;
  gap:7px!important;
  line-height:1!important;
  white-space:nowrap!important;
  vertical-align:middle!important;
}
.sh2-btn .sh2-icon,
.sh2-btn .sh2-icon-sm{
  width:14px!important;
  height:14px!important;
  display:inline-block!important;
  flex:0 0 14px!important;
}
.sh2-btn .sh2-icon-lg{
  width:18px!important;
  height:18px!important;
  flex:0 0 18px!important;
}
</style>
    <?php if (!empty($_GET['short_saved'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['short_saved']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['short_error'])): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['short_error']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['utm_saved'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['utm_saved']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['utm_error'])): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['utm_error']); ?></div>
    <?php endif; ?>
    <?php if (!$hasShortenerTable): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold">
            Таблица коротких ссылок пока не создана. Выполните SQL из файла <code>migration_url_shortener.sql</code>.
        </div>
    <?php endif; ?>
    <?php if (!$hasUtmPresetTable && isAdmin()): ?>
        <div class="bg-amber-50 border border-amber-100 text-amber-700 px-5 py-4 rounded-2xl text-sm font-bold">
            Таблица <code>oca_utm_presets</code> не найдена. Конструктор работает на встроенных значениях из примера, но добавлять новые значения можно только после выполнения SQL.
        </div>
    <?php endif; ?>

    <section id="utm-builder" class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Конструктор UTM-ссылки</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Соберите ссылку с метками, скопируйте её или сразу передайте ниже в URL Shortener.</p>
            </div>
            <span class="inline-flex items-center justify-center px-4 py-2 rounded-full bg-orange-50 text-[#FFA048] text-[10px] font-black uppercase tracking-widest w-fit">Надстройка над укорачивателем</span>
        </div>
        <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_.6fr] gap-4">
                <label class="block">
                    <span class="text-[10px] uppercase text-gray-400 font-black">Основная ссылка</span>
                    <input type="text" id="utmBaseUrl" placeholder="https://<?php echo htmlspecialchars($marketingDomain); ?>/services/analiz-sily-rukovoditelya/" class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-semibold text-sm outline-none focus:border-[#FFA048]">
                </label>
                <label class="block">
                    <span class="text-[10px] uppercase text-gray-400 font-black">Якорь, если нужен</span>
                    <input type="text" id="utmAnchor" placeholder="#form" class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-semibold text-sm outline-none focus:border-[#FFA048]">
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
                <?php foreach ($utmTypeLabels as $type => $label):
                    $isRequired = in_array($type, $utmRequiredTypes, true);
                ?>
                    <label class="block">
                        <span class="text-[10px] uppercase text-gray-400 font-black"><?php echo htmlspecialchars($label); ?><?php echo $isRequired ? ' *' : ''; ?></span>
                        <select id="utm_<?php echo htmlspecialchars($type); ?>" data-utm-type="<?php echo htmlspecialchars($type); ?>" <?php echo $isRequired ? 'data-required="1"' : ''; ?> class="utm-select mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-semibold text-sm outline-none focus:border-[#FFA048] bg-white">
                            <option value="">— выбрать —</option>
                            <?php $renderUtmOptions($utmPresets[$type] ?? [], $type); ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4 items-end">
                <label class="block">
                    <span class="text-[10px] uppercase text-gray-400 font-black">Готовая ссылка</span>
                    <input type="text" id="utmResultUrl" readonly placeholder="Здесь появится готовая ссылка с UTM" class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 bg-gray-50 font-bold text-sm text-gray-700 outline-none">
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3 gap-2">
                    <button type="button" id="copyUtmUrlBtn" class="px-5 py-4 rounded-2xl bg-gray-100 text-gray-500 text-[10px] font-black uppercase tracking-widest sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-copy-gray.svg" alt="" aria-hidden="true">Копировать</button>
                    <button type="button" id="sendUtmToShortenerBtn" class="px-5 py-4 rounded-2xl bg-orange-50 text-[#FFA048] text-[10px] font-black uppercase tracking-widest sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-send-gray.svg" alt="" aria-hidden="true">В укорачиватель</button>
                    <button type="button" id="clearUtmBuilderBtn" class="px-5 py-4 rounded-2xl bg-gray-50 text-gray-400 text-[10px] font-black uppercase tracking-widest sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-clear-gray.svg" alt="" aria-hidden="true">Очистить</button>
                </div>
            </div>
            <div id="utmBuilderNotice" class="hidden text-xs font-bold rounded-2xl px-4 py-3"></div>

            <?php if (isAdmin()): ?>
                <details class="rounded-3xl border border-gray-100 bg-gray-50/50 overflow-hidden">
                    <summary class="cursor-pointer px-5 py-4 text-xs font-black uppercase tracking-widest text-gray-500 select-none sh2-btn justify-start"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-dictionary-gray.svg" alt="" aria-hidden="true">Справочник UTM-значений</summary>
                    <div class="p-5 border-t border-gray-100 space-y-5">
                        <p class="text-xs text-gray-400 font-semibold leading-relaxed">Добавляйте значения латиницей, без пробелов. Для динамической сегодняшней даты в utm_term используйте служебное значение __today__.</p>
                        <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
                            <?php foreach ($utmTypeLabels as $type => $label): ?>
                                <div class="bg-white border border-gray-100 rounded-3xl p-4 space-y-3">
                                    <h4 class="text-[11px] font-black uppercase tracking-widest text-[#FFA048]"><?php echo htmlspecialchars($label); ?></h4>
                                    <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                                        <?php if (empty($utmPresets[$type])): ?>
                                            <div class="text-xs text-gray-300 font-bold">Пока пусто.</div>
                                        <?php endif; ?>
                                        <?php foreach (($utmPresets[$type] ?? []) as $item):
                                            $display = asr_utm_preset_display($item, $type);
                                            $displayValue = (string)($display['value'] ?? '');
                                            $displayDescription = (string)($display['description'] ?? '');
                                            $editValue = (string)($display['edit_value'] ?? $displayValue);
                                            $isToday = !empty($display['is_today']);
                                        ?>
                                            <div class="flex items-start justify-between gap-2 rounded-2xl bg-gray-50 px-3 py-2">
                                                <div class="min-w-0">
                                                    <div class="text-xs font-black text-gray-700 break-all">
                                                        <?php echo htmlspecialchars($displayValue); ?>
                                                        <?php if ($isToday): ?><span class="ml-1 align-middle text-[9px] text-[#FFA048] uppercase tracking-widest">auto</span><?php endif; ?>
                                                    </div>
                                                    <?php if ($displayDescription !== ''): ?>
                                                        <div class="text-[10px] font-semibold text-gray-400 leading-snug mt-1"><?php echo htmlspecialchars($displayDescription); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($hasUtmPresetTable && !empty($item['id'])): ?>
                                                    <div class="shrink-0 flex items-center gap-1">
                                                        <button type="button" onclick='openUtmEditModal(<?php echo htmlspecialchars(json_encode(["id" => (int)$item["id"], "type" => $type, "value" => $editValue, "description" => $displayDescription], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-white text-gray-300 hover:text-[#FFA048] hover:bg-orange-50 transition" title="Изменить">
                                                            <img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-edit-gray.svg" alt="" aria-hidden="true">
                                                        </button>
                                                        <form method="POST" action="admin.php?tab=url_shortener#utm-builder" onsubmit="return confirm('Удалить это UTM-значение из справочника? Старые уже созданные ссылки не пострадают.');" class="inline">
                                                            <input type="hidden" name="action" value="delete_utm_preset">
                                                            <input type="hidden" name="utm_preset_id" value="<?php echo (int)$item['id']; ?>">
                                                            <button type="submit" class="inline-flex items-center justify-center w-7 h-7 rounded-xl bg-white text-gray-300 hover:text-red-500 hover:bg-red-50 transition" title="Удалить">
                                                                <img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-delete-gray.svg" alt="" aria-hidden="true">
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <form method="POST" action="admin.php?tab=url_shortener#utm-builder" class="space-y-2 pt-2">
                                        <input type="hidden" name="action" value="create_utm_preset">
                                        <input type="hidden" name="utm_type" value="<?php echo htmlspecialchars($type); ?>">
                                        <input type="text" name="utm_value" required placeholder="new_value" class="w-full px-3 py-3 rounded-2xl border border-gray-200 text-xs font-bold outline-none focus:border-[#FFA048]" <?php echo $hasUtmPresetTable ? '' : 'disabled'; ?>>
                                        <input type="text" name="utm_description" placeholder="Описание" class="w-full px-3 py-3 rounded-2xl border border-gray-200 text-xs font-semibold outline-none focus:border-[#FFA048]" <?php echo $hasUtmPresetTable ? '' : 'disabled'; ?>>
                                        <button type="submit" class="w-full px-3 py-3 rounded-2xl bg-[#FFA048] text-white text-[10px] font-black uppercase tracking-widest disabled:opacity-40" <?php echo $hasUtmPresetTable ? '' : 'disabled'; ?>>Добавить</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex items-start justify-between gap-4">
            <div class="sh2-section-title">
                <img class="sh2-section-icon" src="/assets/admin/icons/sh2-link-gray.svg" alt="" aria-hidden="true">
                <div>
                    <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">URL Shortener</h3>
                    <p class="text-sm text-gray-400 font-bold mt-1">Короткие ссылки для рассылок, рекламы и страниц с UTM-метками.</p>
                    <p class="text-xs text-gray-400 font-semibold mt-2 leading-relaxed">Короткие ссылки создаются на поддомене <code><?php echo htmlspecialchars($shortDefaultDomain); ?></code> в формате <code><?php echo htmlspecialchars($shortDefaultBaseUrl); ?>код</code>.</p>
                </div>
            </div>
            <button type="button" onclick="openShortenerHelpModal()" class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-full border border-orange-100 bg-orange-50 text-[#FFA048] hover:bg-[#FFA048] hover:text-white transition shadow-sm" title="Справка по коротким ссылкам" aria-label="Справка по коротким ссылкам">
                <img class="sh2-icon sh2-icon-lg" src="/assets/admin/icons/sh2-help-gray.svg" alt="" aria-hidden="true">
            </button>
        </div>
        <form method="POST" id="shortenerCreateForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="create_short_url">
            <input type="hidden" name="short_domain" value="<?php echo htmlspecialchars($shortDefaultDomain, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4 items-end">
                <label class="block">
                    <span class="text-[10px] uppercase text-gray-400 font-black">Исходная ссылка</span>
                    <input type="text" id="shortSourceUrl" name="source_url" required placeholder="https://<?php echo htmlspecialchars($marketingDomain); ?>/web/probitrix/?utm_source=selzy&utm_medium=email..." class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-semibold text-sm outline-none focus:border-[#FFA048]">
                </label>
                <button type="submit" class="px-8 py-4 rounded-2xl bg-[#FFA048] text-white text-xs font-black uppercase tracking-widest shadow-lg shadow-orange-500/20 sh2-btn" <?php echo $hasShortenerTable ? '' : 'disabled'; ?>><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-plus-white.svg" alt="" aria-hidden="true">Сгенерировать</button>
            </div>
            <p class="text-xs text-gray-400 font-semibold leading-relaxed">Если ссылка введена в неверном порядке, например <code>#b20961?utm_source=...</code>, система перед сохранением поправит её на правильный формат: <code>?utm_source=...#b20961</code>.</p>
            <label class="flex items-start gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 px-4 py-3 cursor-pointer">
                <input type="checkbox" name="is_permanent" value="1" class="mt-1 rounded border-gray-300 text-[#FFA048] focus:ring-[#FFA048]">
                <span>
                    <span class="block text-sm font-bold text-gray-700">Не удалять при лимите 300 ссылок</span>
                    <span class="block text-xs text-gray-400 font-semibold mt-1">Важная ссылка всегда будет показываться в списке, даже если станет старой и ссылок накопится больше 300.</span>
                </span>
            </label>
        </form>
    </section>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between gap-4">
            <div class="sh2-section-title">
                <img class="sh2-section-icon" src="/assets/admin/icons/sh2-list-gray.svg" alt="" aria-hidden="true">
                <div>
                    <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Сгенерированные ссылки</h3>
                    <p class="text-sm text-gray-400 font-bold mt-1"><?php echo isAdmin() ? 'Последние 300 ссылок плюс закреплённые. На странице они подгружаются порциями по 20 при прокрутке.' : 'Ваши последние 300 ссылок плюс закреплённые. На странице они подгружаются порциями по 20 при прокрутке.'; ?></p>
                </div>
            </div>
            <?php if (count($shortRows) > 20): ?>
                <span id="shortLazyCounter" class="px-4 py-2 rounded-full bg-gray-50 text-gray-400 text-[10px] font-black uppercase tracking-widest">Показано 20 из <?php echo count($shortRows); ?></span>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[980px]">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-5 text-xs font-bold text-gray-400 uppercase tracking-widest">Дата</th>
                        <th class="p-5 text-xs font-bold text-gray-400 uppercase tracking-widest">Исходная ссылка</th>
                        <th class="p-5 text-xs font-bold text-gray-400 uppercase tracking-widest">Укороченная ссылка</th>
                        <th class="p-5 text-xs font-bold text-gray-400 uppercase tracking-widest text-center">Переходы</th>
                        <th class="p-5 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50" id="shortUrlsTbody">
                    <?php if (empty($shortRows)): ?>
                        <tr><td colspan="5" class="p-8 text-center text-gray-400 font-bold">Коротких ссылок пока нет.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($shortRows as $index => $row):
                        $shortUrl = asr_short_url($row['domain'], $row['slug']);
                        $canManageShortRow = isAdmin() || ((int)($row['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0));
                    ?>
                        <tr data-short-row data-short-index="<?php echo (int)$index; ?>" class="hover:bg-orange-50/30 transition <?php echo $index >= 20 ? 'hidden' : ''; ?>">
                            <td class="p-5 align-top text-sm font-bold text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($row['created_at']))); ?></td>
                            <td class="p-5 align-top max-w-[430px]">
                                <div class="text-sm font-semibold text-gray-700 break-all" title="<?php echo htmlspecialchars($row['normalized_url']); ?>"><?php echo htmlspecialchars($row['normalized_url']); ?></div>
                                <?php if (($row['source_url'] ?? '') !== ($row['normalized_url'] ?? '')): ?>
                                    <div class="mt-2 text-[11px] text-green-700 bg-green-50 rounded-xl px-3 py-2 font-bold">Ссылка была автоматически приведена к правильному формату.</div>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 align-top">
                                <div class="flex items-center gap-2">
                                    <input readonly value="<?php echo htmlspecialchars($shortUrl); ?>" class="short-url-input w-full min-w-[260px] px-3 py-2 rounded-xl border border-gray-100 bg-gray-50 text-sm font-bold text-[#FFA048]">
                                    <button type="button" onclick="copyShortUrl(this)" class="px-3 py-2 rounded-xl bg-gray-100 text-gray-500 text-[10px] font-black uppercase sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-copy-gray.svg" alt="" aria-hidden="true">Копировать</button>
                                </div>
                                <?php if ((int)($row['is_permanent'] ?? 0) === 1): ?>
                                    <div class="mt-2 inline-flex items-center rounded-full bg-orange-50 px-3 py-1 text-[10px] font-bold text-[#A65B16]">закреплена, не скрывать при лимите</div>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 align-top text-center"><span class="inline-flex items-center justify-center min-w-[52px] px-3 py-2 rounded-full bg-gray-50 text-gray-600 text-sm font-black"><?php echo (int)($row['clicks'] ?? 0); ?></span></td>
                            <td class="p-5 align-top text-right whitespace-nowrap">
                                <?php if ($canManageShortRow): ?>
                                    <button type="button" onclick='openShortEditModal(<?php echo htmlspecialchars(json_encode(["id" => (int)$row["id"], "domain" => asr_shortener_default_domain(), "slug" => $row["slug"], "is_permanent" => (int)($row["is_permanent"] ?? 0)], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)' class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-orange-50 text-[#FFA048] hover:bg-[#FFA048] hover:text-white transition" title="Редактировать хвостик">
                                        <img class="sh2-icon" src="/assets/admin/icons/sh2-edit-gray.svg" alt="" aria-hidden="true">
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить короткую ссылку?');">
                                        <input type="hidden" name="action" value="delete_short_url">
                                        <input type="hidden" name="short_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gray-50 text-gray-300 hover:bg-red-50 hover:text-red-500 transition" title="Удалить">
                                            <img class="sh2-icon" src="/assets/admin/icons/sh2-delete-gray.svg" alt="" aria-hidden="true">
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-gray-300 font-bold">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($shortRows) > 20): ?>
            <div id="shortLazySentinel" class="p-6 border-t border-gray-100 text-center text-xs text-gray-400 font-black uppercase tracking-widest">Прокрутите ниже, чтобы показать ещё ссылки</div>
        <?php endif; ?>
    </section>
</div>


<div id="utmEditModal" class="hidden fixed inset-0 bg-gray-900/60 z-[70] p-4 flex items-center justify-center">
    <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <div>
                <h3 class="text-lg font-black text-[#FFA048] uppercase tracking-tight">Изменить UTM-значение</h3>
                <p class="text-xs text-gray-400 font-bold mt-1" id="utmEditTypeLabel"></p>
            </div>
            <button type="button" onclick="closeUtmEditModal()" class="text-gray-400 hover:text-red-500 transition"><img class="sh2-icon sh2-icon-lg" src="/assets/admin/icons/sh2-close-gray.svg" alt="" aria-hidden="true"></button>
        </div>
        <form method="POST" action="admin.php?tab=url_shortener#utm-builder" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_utm_preset">
            <input type="hidden" name="utm_preset_id" id="utmEditId">
            <input type="hidden" name="utm_type" id="utmEditType">
            <label class="block">
                <span class="text-[10px] uppercase text-gray-400 font-black">Значение</span>
                <input type="text" name="utm_value" id="utmEditValue" required class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-black text-sm outline-none focus:border-[#FFA048]">
            </label>
            <label class="block">
                <span class="text-[10px] uppercase text-gray-400 font-black">Описание</span>
                <input type="text" name="utm_description" id="utmEditDescription" class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-semibold text-sm outline-none focus:border-[#FFA048]">
            </label>
            <p class="text-[11px] text-gray-400 font-semibold leading-relaxed">Для автоматической сегодняшней даты в utm_term укажи <code>__today__</code>. На странице оно будет показываться как текущая дата.</p>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeUtmEditModal()" class="px-5 py-3 text-xs font-black uppercase text-gray-400">Отмена</button>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-[#FFA048] text-white text-xs font-black uppercase tracking-widest sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-save-white.svg" alt="" aria-hidden="true">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div id="shortenerHelpModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-[70] p-4 flex items-center justify-center">
    <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[82vh] overflow-hidden flex flex-col">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between gap-4 bg-gray-50/50">
            <div>
                <h3 class="text-lg font-black text-[#FFA048] uppercase tracking-tight">Справка по URL Shortener</h3>
                <p class="text-xs text-gray-400 font-bold mt-1">Та самая инструкция, только теперь не живёт в подвале страницы.</p>
            </div>
            <button type="button" onclick="closeShortenerHelpModal()" class="text-gray-400 hover:text-red-500 transition">
                <img class="sh2-icon sh2-icon-lg" src="/assets/admin/icons/sh2-close-gray.svg" alt="" aria-hidden="true">
            </button>
        </div>
        <div class="p-6 overflow-y-auto help-content text-gray-700 font-semibold leading-relaxed">
            <?php echo $settings['shortener_instruction'] ?? ''; ?>
        </div>
    </div>
</div>

<div id="shortEditModal" class="hidden fixed inset-0 bg-gray-900/60 z-[70] p-4 flex items-center justify-center">
    <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <div>
                <h3 class="text-lg font-black text-[#FFA048] uppercase tracking-tight">Редактировать хвостик</h3>
                <p class="text-xs text-gray-400 font-bold mt-1" id="shortEditDomain"></p>
            </div>
            <button type="button" onclick="closeShortEditModal()" class="text-gray-400 hover:text-red-500 transition"><img class="sh2-icon sh2-icon-lg" src="/assets/admin/icons/sh2-close-gray.svg" alt="" aria-hidden="true"></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_short_url">
            <input type="hidden" name="short_id" id="shortEditId">
            <label class="block">
                <span class="text-[10px] uppercase text-gray-400 font-black">Код после домена</span>
                <input type="text" name="new_slug" id="shortEditSlug" required class="mt-1 w-full px-4 py-4 rounded-2xl border border-gray-200 font-black text-sm outline-none focus:border-[#FFA048]" pattern="[A-Za-z0-9_-]{3,64}">
            </label>
            <label class="flex items-start gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 px-4 py-3 cursor-pointer">
                <input type="checkbox" name="is_permanent" value="1" id="shortEditPermanent" class="mt-1 rounded border-gray-300 text-[#FFA048] focus:ring-[#FFA048]">
                <span>
                    <span class="block text-sm font-bold text-gray-700">Не удалять при лимите 300 ссылок</span>
                    <span class="block text-xs text-gray-400 font-semibold mt-1">Ссылка останется видимой в списке даже после появления новых ссылок.</span>
                </span>
            </label>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeShortEditModal()" class="px-5 py-3 text-xs font-black uppercase text-gray-400">Отмена</button>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-[#FFA048] text-white text-xs font-black uppercase tracking-widest sh2-btn"><img class="sh2-icon sh2-icon-sm" src="/assets/admin/icons/sh2-save-white.svg" alt="" aria-hidden="true">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/admin/modules/url_shortener/url_shortener.js?v=20260528_utmfix"></script>
