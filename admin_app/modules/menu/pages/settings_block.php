<?php
/**
 * Блок настроек редактируемого меню.
 * Подключается из общей страницы настроек, чтобы не раздувать settings.php.
 */

defined('ASR_ADMIN') || exit;
?>
<?php if (!empty($_GET['menu_saved'])): ?>
    <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Меню сохранено.</div>
<?php endif; ?>
<?php if (!empty($_GET['menu_error'])): ?>
    <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['menu_error']); ?></div>
<?php endif; ?>

<?php
$menuItemsForSettings = asr_get_admin_menu_items($pdo, true);
$menuGroupsForSettings = array_values(array_filter($menuItemsForSettings, static function(array $item) {
    return (string)($item['item_type'] ?? '') === 'group';
}));

$menuById = [];
$menuChildren = [];
$menuRoots = [];
foreach ($menuItemsForSettings as $item) {
    $item['id'] = (int)($item['id'] ?? 0);
    $item['parent_id'] = empty($item['parent_id']) ? null : (int)$item['parent_id'];
    $menuById[$item['id']] = $item;
}
foreach ($menuById as $item) {
    $parentId = $item['parent_id'];
    if ($parentId !== null && isset($menuById[$parentId])) {
        $menuChildren[$parentId][] = $item;
    } else {
        $menuRoots[] = $item;
    }
}
$sortMenuItems = static function(array &$items): void {
    usort($items, static function($a, $b) {
        return ((int)($a['sort_order'] ?? 100) <=> (int)($b['sort_order'] ?? 100)) ?: ((int)$a['id'] <=> (int)$b['id']);
    });
};
$sortMenuItems($menuRoots);
foreach ($menuChildren as &$childList) {
    $sortMenuItems($childList);
}
unset($childList);

$menuTypeLabels = ['group' => 'Группа', 'internal' => 'Внутренняя', 'external' => 'Внешняя'];
$menuPermissionLabels = ['any' => 'Все сотрудники', 'can_view_results' => 'Кто видит результаты', 'can_work_results' => 'Кто работает с результатами', 'admin' => 'Только админ'];
$menuIconLabels = ['tests' => 'Тесты', 'marketing' => 'Маркетинг', 'settings' => 'Настройки', 'link' => 'Ссылка', 'dot' => 'Стрелка', 'key' => 'Ключ', 'telegram' => 'Telegram'];
$firstMenuId = $menuItemsForSettings ? (int)$menuItemsForSettings[0]['id'] : 0;

if (!function_exists('asr_menu_settings_badge')) {
    function asr_menu_settings_badge(string $text, string $tone = 'gray'): string {
        $map = [
            'green' => 'bg-green-50 text-green-700 border-green-100',
            'orange' => 'bg-orange-50 text-orange-600 border-orange-100',
            'red' => 'bg-red-50 text-red-600 border-red-100',
            'blue' => 'bg-orange-50 text-[#f97316] border-orange-100',
            'gray' => 'bg-gray-50 text-gray-500 border-gray-100',
        ];
        return '<span class="asr-menu-badge ' . ($map[$tone] ?? $map['gray']) . '">' . htmlspecialchars($text) . '</span>';
    }
}

if (!function_exists('asr_menu_settings_render_tree_item')) {
    function asr_menu_settings_render_tree_item(array $item, array $childrenByParent, array $menuTypeLabels, int $level = 0): void {
        $id = (int)$item['id'];
        $children = $childrenByParent[$id] ?? [];
        $type = (string)($item['item_type'] ?? 'internal');
        $isActive = (int)($item['is_active'] ?? 1) === 1;
        $isSystem = (int)($item['is_system'] ?? 0) === 1;
        $typeLabel = $menuTypeLabels[$type] ?? 'Пункт';
        ?>
        <div class="asr-menu-tree-node <?php echo $level > 0 ? 'asr-menu-tree-child' : ''; ?>" style="--menu-level: <?php echo (int)$level; ?>;">
            <button type="button" class="asr-menu-tree-card" data-menu-select="<?php echo $id; ?>">
                <span class="asr-menu-tree-icon"><?php echo asr_menu_icon((string)($item['icon_key'] ?? 'link')); ?></span>
                <span class="asr-menu-tree-main">
                    <span class="asr-menu-tree-title"><?php echo htmlspecialchars((string)$item['title']); ?></span>
                    <span class="asr-menu-tree-meta">
                        <?php echo htmlspecialchars($typeLabel); ?> · порядок <?php echo (int)($item['sort_order'] ?? 100); ?>
                        <?php if (!$isActive): ?> · отключён<?php endif; ?>
                    </span>
                </span>
                <?php if ($isSystem): ?><span class="asr-menu-tree-system">системный</span><?php endif; ?>
                <span class="asr-menu-tree-status <?php echo $isActive ? 'is-active' : 'is-disabled'; ?>"></span>
            </button>
            <?php if ($children): ?>
                <div class="asr-menu-tree-children">
                    <?php foreach ($children as $child): ?>
                        <?php asr_menu_settings_render_tree_item($child, $childrenByParent, $menuTypeLabels, $level + 1); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('asr_menu_settings_render_editor')) {
    function asr_menu_settings_render_editor(array $item, array $groups, array $typeLabels, array $permissionLabels, array $iconLabels): void {
        $id = (int)$item['id'];
        $type = (string)($item['item_type'] ?? 'internal');
        $parentId = (int)($item['parent_id'] ?? 0);
        $isActive = (int)($item['is_active'] ?? 1) === 1;
        $isSystem = (int)($item['is_system'] ?? 0) === 1;
        ?>
        <section class="asr-menu-editor-panel" data-menu-editor="<?php echo $id; ?>">
            <div class="asr-menu-editor-head">
                <div>
                    <div class="asr-menu-kicker">Редактирование пункта</div>
                    <h4><?php echo htmlspecialchars((string)$item['title']); ?></h4>
                    <div class="asr-menu-editor-badges">
                        <?php echo asr_menu_settings_badge($typeLabels[$type] ?? 'Пункт', 'blue'); ?>
                        <?php echo asr_menu_settings_badge($isActive ? 'Активен' : 'Отключён', $isActive ? 'green' : 'red'); ?>
                        <?php if ($isSystem): ?><?php echo asr_menu_settings_badge('Системный', 'orange'); ?><?php endif; ?>
                    </div>
                </div>
            </div>

            <form method="POST" class="asr-menu-edit-form">
                <input type="hidden" name="action" value="save_admin_menu">
                <input type="hidden" name="menu_id[]" value="<?php echo $id; ?>">

                <div class="asr-menu-form-grid">
                    <label class="asr-menu-field asr-menu-field-wide">
                        <span>Название</span>
                        <input name="menu_title[]" value="<?php echo htmlspecialchars((string)$item['title']); ?>" required>
                    </label>

                    <label class="asr-menu-field">
                        <span>Тип</span>
                        <select name="menu_item_type[]">
                            <?php foreach ($typeLabels as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $type === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="asr-menu-field">
                        <span>Родитель</span>
                        <select name="menu_parent_id[]">
                            <option value="0">Верхний уровень</option>
                            <?php foreach ($groups as $group): if ((int)$group['id'] === $id) continue; ?>
                                <option value="<?php echo (int)$group['id']; ?>" <?php echo $parentId === (int)$group['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$group['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="asr-menu-field asr-menu-field-wide">
                        <span>Ссылка</span>
                        <input name="menu_href[]" value="<?php echo htmlspecialchars((string)($item['href'] ?? '')); ?>" placeholder="https://... или admin.php?tab=...">
                    </label>

                    <label class="asr-menu-field">
                        <span>Открывать</span>
                        <select name="menu_target[]">
                            <option value="_self" <?php echo (($item['target'] ?? '_self') !== '_blank') ? 'selected' : ''; ?>>В этом окне</option>
                            <option value="_blank" <?php echo (($item['target'] ?? '_self') === '_blank') ? 'selected' : ''; ?>>В новом окне</option>
                        </select>
                    </label>

                    <label class="asr-menu-field">
                        <span>Доступ</span>
                        <select name="menu_permission_key[]">
                            <?php foreach ($permissionLabels as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($item['permission_key'] ?? 'any') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="asr-menu-field">
                        <span>Порядок</span>
                        <input type="number" min="0" max="9999" name="menu_sort_order[]" value="<?php echo (int)($item['sort_order'] ?? 100); ?>">
                    </label>

                    <label class="asr-menu-field">
                        <span>Иконка</span>
                        <select name="menu_icon_key[]">
                            <?php foreach ($iconLabels as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($item['icon_key'] ?? 'link') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <label class="asr-menu-switch">
                    <input type="checkbox" name="menu_is_active[<?php echo $id; ?>]" value="1" <?php echo $isActive ? 'checked' : ''; ?>>
                    <span></span>
                    <b>Показывать этот пункт в меню</b>
                </label>

                <div class="asr-menu-actions">
                    <?php if (!$isSystem && asr_menu_table_exists($GLOBALS['pdo'])): ?>
                        <button type="submit" name="delete_menu_id" value="<?php echo $id; ?>" onclick="return confirm('Удалить этот пункт меню?');" class="asr-menu-btn asr-menu-btn-danger">Удалить</button>
                    <?php endif; ?>
                    <button type="submit" class="asr-menu-btn asr-menu-btn-primary">Сохранить пункт</button>
                </div>
            </form>
        </section>
        <?php
    }
}
?>

<style>
.asr-menu-editor-shell{display:grid;grid-template-columns:minmax(320px,.9fr) minmax(420px,1.1fr);gap:18px;align-items:start}.asr-menu-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}.asr-menu-toolbar-actions{display:flex;flex-wrap:wrap;gap:10px}.asr-menu-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:16px;padding:12px 18px;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;transition:.18s ease}.asr-menu-btn-primary{background:#ffa048;color:white;box-shadow:0 18px 30px rgba(255,160,72,.22)}.asr-menu-btn-primary:hover{transform:translateY(-1px);box-shadow:0 20px 34px rgba(255,160,72,.28)}.asr-menu-btn-secondary{background:#f3f4f6;color:#2E3784}.asr-menu-btn-danger{background:#fff1f2;color:#e11d48}.asr-menu-card{border:1px solid #eef0f4;background:#fff;border-radius:28px;box-shadow:0 14px 40px rgba(46,55,132,.06);overflow:hidden}.asr-menu-card-head{padding:22px 24px;border-bottom:1px solid #f1f3f6}.asr-menu-kicker{font-size:10px;text-transform:uppercase;letter-spacing:.16em;font-weight:900;color:#9ca3af}.asr-menu-title{font-size:18px;font-weight:1000;color:#374151;letter-spacing:-.02em;margin-top:4px}.asr-menu-note{font-size:13px;font-weight:700;color:#9ca3af;margin-top:5px;line-height:1.45}.asr-menu-tree{padding:16px;max-height:760px;overflow:auto}.asr-menu-tree-node{margin-top:10px}.asr-menu-tree-node:first-child{margin-top:0}.asr-menu-tree-card{width:100%;border:1px solid #eef0f4;background:#f9fafb;border-radius:20px;padding:13px 14px;display:flex;align-items:center;gap:12px;text-align:left;cursor:pointer;transition:.16s ease}.asr-menu-tree-card:hover,.asr-menu-tree-card.is-selected{background:#fff7ed;border-color:#fed7aa;box-shadow:0 14px 24px rgba(255,160,72,.12)}.asr-menu-tree-icon{width:34px;height:34px;border-radius:14px;background:white;color:#374151;display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px #edf0f5;flex:0 0 auto}.asr-menu-tree-main{min-width:0;flex:1}.asr-menu-tree-title{display:block;font-size:14px;font-weight:1000;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.asr-menu-tree-meta{display:block;font-size:11px;font-weight:800;color:#9ca3af;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.asr-menu-tree-system{font-size:9px;font-weight:1000;text-transform:uppercase;letter-spacing:.08em;color:#f97316;background:#fff7ed;border:1px solid #fed7aa;padding:5px 7px;border-radius:999px}.asr-menu-tree-status{width:10px;height:10px;border-radius:999px;flex:0 0 auto}.asr-menu-tree-status.is-active{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12)}.asr-menu-tree-status.is-disabled{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.12)}.asr-menu-tree-child{padding-left:calc(22px + var(--menu-level) * 10px);position:relative}.asr-menu-tree-child:before{content:"";position:absolute;left:12px;top:-10px;bottom:18px;width:1px;background:#e5e7eb}.asr-menu-tree-children{margin-top:10px}.asr-menu-preview{padding:16px;border-top:1px solid #f1f3f6;background:#fafafa}.asr-menu-preview-box{border:1px dashed #d8dce5;border-radius:22px;padding:14px;background:#fff}.asr-menu-preview-title{font-size:11px;text-transform:uppercase;letter-spacing:.14em;font-weight:1000;color:#9ca3af;margin-bottom:8px}.asr-menu-preview-list{display:flex;flex-wrap:wrap;gap:8px}.asr-menu-preview-pill{border:1px solid #edf0f5;background:#f9fafb;border-radius:999px;padding:8px 11px;font-size:11px;font-weight:900;color:#4b5563}.asr-menu-editor-area{position:sticky;top:18px}.asr-menu-editor-panel{display:none;padding:22px}.asr-menu-editor-panel.is-visible{display:block}.asr-menu-editor-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.asr-menu-editor-head h4{font-size:22px;font-weight:1000;color:#111827;letter-spacing:-.03em;margin:4px 0 0}.asr-menu-editor-badges{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.asr-menu-badge{display:inline-flex;border:1px solid;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:1000;text-transform:uppercase;letter-spacing:.06em}.asr-menu-edit-form{display:block}.asr-menu-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.asr-menu-field{display:block}.asr-menu-field-wide{grid-column:1/-1}.asr-menu-field span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.11em;font-weight:1000;color:#9ca3af;margin-bottom:6px}.asr-menu-field input,.asr-menu-field select{width:100%;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:13px 14px;font-size:14px;font-weight:800;color:#111827;outline:none;transition:.16s ease}.asr-menu-field input:focus,.asr-menu-field select:focus{border-color:#ffa048;box-shadow:0 0 0 4px rgba(255,160,72,.13)}.asr-menu-switch{margin-top:16px;display:flex;align-items:center;gap:12px;border:1px solid #eef0f4;background:#f9fafb;border-radius:18px;padding:13px 14px;cursor:pointer}.asr-menu-switch input{position:absolute;opacity:0;pointer-events:none}.asr-menu-switch span{width:44px;height:26px;border-radius:999px;background:#d1d5db;position:relative;transition:.16s ease;flex:0 0 auto}.asr-menu-switch span:before{content:"";position:absolute;width:20px;height:20px;border-radius:999px;background:#fff;left:3px;top:3px;transition:.16s ease;box-shadow:0 2px 8px rgba(0,0,0,.18)}.asr-menu-switch input:checked+span{background:#22c55e}.asr-menu-switch input:checked+span:before{transform:translateX(18px)}.asr-menu-switch b{font-size:13px;font-weight:900;color:#374151}.asr-menu-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;flex-wrap:wrap}.asr-menu-create{padding:20px 22px;border-top:1px solid #f1f3f6;background:#fff7ed}.asr-menu-create-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:13px}.asr-menu-empty{padding:24px;font-size:14px;font-weight:800;color:#9ca3af}.asr-menu-helper{font-size:12px;font-weight:700;color:#9ca3af;line-height:1.45;margin-top:10px}.asr-menu-mobile-hint{display:none;margin-top:12px;border-radius:18px;background:#f9fafb;border:1px solid #eef0f4;padding:12px 14px;font-size:12px;font-weight:800;color:#6b7280}.asr-menu-help-list{margin-top:10px;display:grid;gap:7px}.asr-menu-help-list div{font-size:12px;font-weight:800;color:#6b7280;background:#f9fafb;border:1px solid #eef0f4;border-radius:14px;padding:10px 12px}@media(max-width:1100px){.asr-menu-editor-shell{grid-template-columns:1fr}.asr-menu-editor-area{position:static}.asr-menu-tree{max-height:none}.asr-menu-mobile-hint{display:block}}@media(max-width:720px){.asr-menu-card-head{padding:18px}.asr-menu-tree{padding:12px}.asr-menu-editor-panel{padding:16px}.asr-menu-form-grid,.asr-menu-create-grid{grid-template-columns:1fr}.asr-menu-tree-system{display:none}.asr-menu-actions{justify-content:stretch}.asr-menu-actions .asr-menu-btn{width:100%}.asr-menu-toolbar{align-items:stretch}.asr-menu-toolbar-actions,.asr-menu-toolbar-actions .asr-menu-btn{width:100%}.asr-menu-title{font-size:16px}.asr-menu-editor-head h4{font-size:18px}}
</style>

<details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group" open>
    <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
        <div>
            <h3 class="text-lg font-bold text-gray-800 uppercase tracking-tight">Меню админки</h3>
            <p class="text-sm text-gray-400 font-bold mt-1">Структура меню, вложенность, порядок, права доступа и быстрые ссылки.</p>
        </div>
        <span class="shrink-0 px-4 py-2 rounded-xl bg-gray-100 text-gray-500 text-[11px] font-black uppercase tracking-widest group-open:hidden">Развернуть</span>
        <span class="shrink-0 px-4 py-2 rounded-xl bg-orange-50 text-[#FFA048] text-[11px] font-black uppercase tracking-widest hidden group-open:inline-block">Свернуть</span>
    </summary>

    <div class="p-6 space-y-6 asr-menu-editor">
        <?php if (!asr_menu_table_exists($pdo)): ?>
            <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold">
                Таблица меню пока не создана. Выполните SQL из файла <code>migration_admin_menu.sql</code>. До этого меню будет работать на старой структуре, но редактирование не сохранится.
            </div>
        <?php endif; ?>

        <div class="asr-menu-toolbar">
            <div>
                <div class="asr-menu-kicker">Новый редактор</div>
                <div class="asr-menu-title">Дерево меню + карточка настройки</div>
                <div class="asr-menu-note">Слева выбираем пункт, справа редактируем. Так видно структуру, а не склад полей в одну строку.</div>
            </div>
            <div class="asr-menu-toolbar-actions">
                <button type="button" class="asr-menu-btn asr-menu-btn-secondary" data-menu-open-create>Добавить пункт</button>
            </div>
        </div>

        <div class="asr-menu-editor-shell">
            <div class="asr-menu-card">
                <div class="asr-menu-card-head">
                    <div class="asr-menu-kicker">Структура</div>
                    <div class="asr-menu-title">Пункты меню</div>
                    <div class="asr-menu-mobile-hint">На смартфоне нажми пункт меню — форма редактирования откроется ниже.</div>
                </div>
                <div class="asr-menu-tree" data-menu-tree>
                    <?php if ($menuRoots): ?>
                        <?php foreach ($menuRoots as $rootItem): ?>
                            <?php asr_menu_settings_render_tree_item($rootItem, $menuChildren, $menuTypeLabels, 0); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="asr-menu-empty">Пунктов меню пока нет.</div>
                    <?php endif; ?>
                </div>
                <div class="asr-menu-preview">
                    <div class="asr-menu-preview-box">
                        <div class="asr-menu-preview-title">Быстрый обзор верхнего уровня</div>
                        <div class="asr-menu-preview-list">
                            <?php foreach ($menuRoots as $rootItem): ?>
                                <span class="asr-menu-preview-pill"><?php echo htmlspecialchars((string)$rootItem['title']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="asr-menu-card asr-menu-editor-area">
                <?php foreach ($menuItemsForSettings as $item): ?>
                    <?php asr_menu_settings_render_editor($item, $menuGroupsForSettings, $menuTypeLabels, $menuPermissionLabels, $menuIconLabels); ?>
                <?php endforeach; ?>

                <section class="asr-menu-create" data-menu-create>
                    <div class="asr-menu-kicker">Создание</div>
                    <div class="asr-menu-title">Добавить новый пункт</div>
                    <div class="asr-menu-helper">Для группы ссылку можно оставить пустой. Для внутренней страницы используй формат <b>admin.php?tab=...</b>, для внешней — полный адрес с <b>https://</b>.</div>

                    <form method="POST">
                        <input type="hidden" name="action" value="create_admin_menu_item">
                        <div class="asr-menu-create-grid">
                            <label class="asr-menu-field">
                                <span>Название</span>
                                <input name="new_menu_title" required placeholder="Например, Telegram-боты">
                            </label>
                            <label class="asr-menu-field">
                                <span>Тип</span>
                                <select name="new_menu_item_type">
                                    <option value="group">Группа</option>
                                    <option value="internal" selected>Внутренняя ссылка</option>
                                    <option value="external">Внешняя ссылка</option>
                                </select>
                            </label>
                            <label class="asr-menu-field">
                                <span>Родитель</span>
                                <select name="new_menu_parent_id">
                                    <option value="0">Верхний уровень</option>
                                    <?php foreach ($menuGroupsForSettings as $group): ?>
                                        <option value="<?php echo (int)$group['id']; ?>"><?php echo htmlspecialchars((string)$group['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="asr-menu-field">
                                <span>Открывать</span>
                                <select name="new_menu_target">
                                    <option value="_self" selected>В этом окне</option>
                                    <option value="_blank">В новом окне</option>
                                </select>
                            </label>
                            <label class="asr-menu-field asr-menu-field-wide">
                                <span>Ссылка</span>
                                <input name="new_menu_href" placeholder="admin.php?tab=telegram_bots">
                            </label>
                            <label class="asr-menu-field">
                                <span>Доступ</span>
                                <select name="new_menu_permission_key">
                                    <?php foreach ($menuPermissionLabels as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="asr-menu-field">
                                <span>Иконка</span>
                                <select name="new_menu_icon_key">
                                    <?php foreach ($menuIconLabels as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="asr-menu-field">
                                <span>Порядок</span>
                                <input type="number" name="new_menu_sort_order" value="100" min="0" max="9999">
                            </label>
                        </div>
                        <div class="asr-menu-actions">
                            <button type="submit" class="asr-menu-btn asr-menu-btn-primary">Добавить</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <div class="asr-menu-card">
            <div class="asr-menu-card-head">
                <div class="asr-menu-kicker">Подсказка</div>
                <div class="asr-menu-title">Как этим пользоваться</div>
                <div class="asr-menu-help-list">
                    <div>Группы — это верхние разделы меню. Внутренние пункты лучше вкладывать внутрь групп.</div>
                    <div>Поле «Порядок» пока отвечает за сортировку. Drag-and-drop добавим следующим шагом, если этот интерфейс зайдёт.</div>
                    <div>Системные пункты можно переименовывать и отключать, но нельзя удалять.</div>
                </div>
            </div>
        </div>
    </div>
</details>

<script>
(function(){
    const root = document.querySelector('.asr-menu-editor');
    if (!root) return;
    const cards = Array.from(root.querySelectorAll('[data-menu-select]'));
    const panels = Array.from(root.querySelectorAll('[data-menu-editor]'));
    const createPanel = root.querySelector('[data-menu-create]');
    const createButton = root.querySelector('[data-menu-open-create]');

    function showPanel(id) {
        panels.forEach(panel => panel.classList.toggle('is-visible', panel.getAttribute('data-menu-editor') === String(id)));
        cards.forEach(card => card.classList.toggle('is-selected', card.getAttribute('data-menu-select') === String(id)));
        if (createPanel) createPanel.style.display = 'none';
        const activePanel = root.querySelector('[data-menu-editor="' + String(id).replace(/"/g, '') + '"]');
        if (window.innerWidth <= 1100 && activePanel) activePanel.scrollIntoView({behavior:'smooth', block:'start'});
    }

    cards.forEach(card => card.addEventListener('click', function(){ showPanel(this.getAttribute('data-menu-select')); }));

    if (createButton && createPanel) {
        createButton.addEventListener('click', function(){
            panels.forEach(panel => panel.classList.remove('is-visible'));
            cards.forEach(card => card.classList.remove('is-selected'));
            createPanel.style.display = '';
            if (window.innerWidth <= 1100) createPanel.scrollIntoView({behavior:'smooth', block:'start'});
        });
    }

    const firstId = <?php echo (int)$firstMenuId; ?>;
    if (firstId > 0) showPanel(firstId);
})();
</script>
