<?php
defined('ASR_ADMIN') || exit;
require_once __DIR__ . '/../repository.php';
asr_org_structure_ensure_schema($pdo);

$orgError = (string)($_SESSION['org_structure_error'] ?? '');
unset($_SESSION['org_structure_error']);
$orgPage = (string)($_GET['page'] ?? '');
$schemeId = (int)($_GET['id'] ?? 0);

function asr_org_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function asr_org_csrf_input(): string {
    $token = function_exists('asr_csrf_token') ? asr_csrf_token() : (string)($_SESSION['csrf_token'] ?? '');
    return '<input type="hidden" name="csrf_token" value="' . asr_org_h($token) . '">';
}
function asr_org_render_ckp_bar(?string $text, string $label, string $emptyLabel, string $class = '', string $onclick = ''): string {
    $value = trim((string)$text);
    $isEmpty = $value === '';
    $classes = trim('org-ckp-bar ' . $class . ($isEmpty ? ' is-empty' : ''));
    $content = $isEmpty ? 'ЦКП:' : ('ЦКП: ' . $value);
    $action = $onclick !== '' ? ' onclick="' . asr_org_h($onclick) . '"' : '';
    return '<button type="button" class="' . asr_org_h($classes) . '"' . $action . '><span class="org-ckp-edit-icon no-print" data-org-edit-only="1" aria-hidden="true">' . asr_org_icon_svg('edit') . '</span><strong>' . asr_org_h($content) . '</strong></button>';
}
function asr_org_node_tooltip_text(array $node): string {
    $parts = [];
    $ckp = trim((string)($node['ckp_text'] ?? ''));
    $description = trim((string)($node['description'] ?? ''));
    if ($ckp !== '') $parts[] = 'ЦКП: ' . $ckp;
    if ($description !== '') $parts[] = 'Описание: ' . $description;
    return implode("\n", $parts);
}
function asr_org_render_node_label(array $node, bool $showEmptyPerson = true): string {
    $title = trim((string)($node['title'] ?? ''));
    $person = trim((string)($node['person_name'] ?? ''));
    $tooltip = asr_org_node_tooltip_text($node);
    $titleAttr = $tooltip !== '' ? ' data-org-tooltip="' . asr_org_h($tooltip) . '"' : '';
    $html = '<span class="org-node-title"' . $titleAttr . '>' . asr_org_h($title) . '</span>';
    if ($person !== '') {
        $person = preg_replace('/\s+/u', ' ', $person) ?: $person;
        $html .= '<span class="org-node-person">' . asr_org_h(trim($person)) . '</span>';
    } elseif ($showEmptyPerson) {
        $html .= '<span class="org-node-person is-empty">ФИО</span>';
    }
    return $html;
}
function asr_org_color_adjust(string $hex, int $amount): string {
    $hex = trim($hex);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) return '#9FC5E8';
    $out = '#';
    for ($i = 1; $i <= 5; $i += 2) {
        $value = hexdec(substr($hex, $i, 2));
        $value = max(0, min(255, $value + $amount));
        $out .= str_pad(dechex($value), 2, '0', STR_PAD_LEFT);
    }
    return strtoupper($out);
}
function asr_org_node_effective_color(array $node, ?array $parentNode = null): string {
    $color = trim((string)($node['color'] ?? ''));
    if ((string)($node['type'] ?? '') === 'deputy') {
        $parentColor = trim((string)($parentNode['color'] ?? ''));
        if ($parentColor === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $parentColor)) $parentColor = '#9FC5E8';
        return asr_org_color_adjust($parentColor, 24);
    }
    if ($color === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return '';
    return strtoupper($color);
}
function asr_org_node_style(array $node, ?array $parentNode = null): string {
    $color = asr_org_node_effective_color($node, $parentNode);
    if ($color === '') return '';
    $border = asr_org_color_adjust($color, -46);
    return ' style="--org-node-color:' . asr_org_h($color) . ';--org-node-border:' . asr_org_h($border) . ';background:' . asr_org_h($color) . '!important;border-color:' . asr_org_h($border) . '!important;"';
}
function asr_org_node_tooltip_attr(array $node): string {
    $tooltip = asr_org_node_tooltip_text($node);
    if ($tooltip === '') return '';
    return ' title="' . asr_org_h($tooltip) . '"';
}
function asr_org_deputy_row_style(array $parentNode): string {
    $base = trim((string)($parentNode['color'] ?? ''));
    if ($base === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $base)) $base = '#9FC5E8';
    $color = asr_org_color_adjust($base, 24);
    $border = asr_org_color_adjust($color, -46);
    return ' style="--org-node-color:' . asr_org_h($color) . ';--org-node-border:' . asr_org_h($border) . ';background:' . asr_org_h($color) . '!important;border-color:' . asr_org_h($border) . '!important;"';
}
function asr_org_icon_svg(string $type): string {
    if ($type === 'add') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4zM13.5 6.5l4 4"/></svg>';
}
function asr_org_action_button(string $type, string $class, string $onclick, string $title): string {
    $kind = $type === 'add' ? 'is-add' : 'is-edit';
    $js = trim($onclick);
    if ($js !== '') {
        $js = "event.stopPropagation();" . $js;
    }
    return '<button type="button" data-org-edit-only="1" class="org-action-icon ' . asr_org_h($kind . ' ' . $class) . ' no-print" title="' . asr_org_h($title) . '" aria-label="' . asr_org_h($title) . '" onclick="' . asr_org_h($js) . '">' . asr_org_icon_svg($type) . '</button>';
}
function asr_org_render_scheme_tree_rows(array $schemes, ?int $parentId = null, int $level = 0): string {
    $html = '';
    foreach ($schemes as $scheme) {
        $schemeParent = $scheme['parent_id'] === null ? null : (int)$scheme['parent_id'];
        if ($schemeParent !== $parentId) continue;
        $id = (int)$scheme['id'];
        $status = (string)($scheme['status'] ?? 'draft');
        $statusClass = $status === 'active' ? 'is-active' : ($status === 'archived' ? 'is-archived' : 'is-draft');
        $indent = max(0, $level) * 26;
        $html .= '<div class="org-scheme-row" style="--org-indent:' . $indent . 'px">';
        $html .= '<a class="org-scheme-main" href="admin.php?tab=org_structure&page=scheme&id=' . $id . '">';
        $html .= '<span class="org-scheme-branch" aria-hidden="true">' . ($level > 0 ? '└' : '') . '</span>';
        $html .= '<span class="org-scheme-icon" aria-hidden="true">▦</span>';
        $html .= '<span class="org-scheme-info"><span class="org-scheme-title">' . asr_org_h((string)$scheme['title']) . '</span>';
        $desc = trim((string)($scheme['description'] ?? ''));
        $meta = (int)($scheme['divisions_count'] ?? 0) . ' отделений · ' . (int)($scheme['departments_count'] ?? 0) . ' отделов · ' . (int)($scheme['positions_count'] ?? 0) . ' должностей';
        $html .= '<span class="org-scheme-meta">' . asr_org_h($desc !== '' ? $desc : $meta) . '</span></span>';
        $html .= '</a>';
        $html .= '<div class="org-scheme-stats"><span>' . (int)($scheme['divisions_count'] ?? 0) . ' отдел.</span><span>' . (int)($scheme['positions_count'] ?? 0) . ' должн.</span></div>';
        $html .= '<span class="org-status ' . $statusClass . '">' . asr_org_h(asr_org_structure_status_label($status)) . '</span>';
        $html .= '<details class="org-row-menu"><summary>⋯</summary><div><button type="button" onclick="OrgStructure.openSchemeEdit(' . $id . ')">Редактировать</button><form method="post" onsubmit="return confirm(\'Архивировать оргсхему?\')">' . asr_org_csrf_input() . '<input type="hidden" name="org_action" value="archive_scheme"><input type="hidden" name="scheme_id" value="' . $id . '"><button type="submit">Архивировать</button></form></div></details>';
        $html .= '</div>';
        $html .= asr_org_render_scheme_tree_rows($schemes, $id, $level + 1);
    }
    return $html;
}

if ($orgPage === 'scheme' && $schemeId > 0) {
    $scheme = asr_org_structure_fetch_scheme($pdo, $schemeId);
    if (!$scheme) {
        echo '<div class="org-page"><div class="org-alert is-error">Оргсхема не найдена.</div><a class="org-btn is-secondary" href="admin.php?tab=org_structure">← К списку оргсхем</a></div>';
        return;
    }
    asr_org_structure_ensure_default_management_nodes($pdo, $schemeId);
    $nodes = asr_org_structure_fetch_nodes($pdo, $schemeId);
    $byParent = asr_org_structure_nodes_by_parent($nodes);
    $founderNodes = array_values(array_filter($byParent[0] ?? [], static fn(array $node) => (string)$node['type'] === 'founder'));
    $topNodes = array_values(array_filter($byParent[0] ?? [], static fn(array $node) => (string)$node['type'] === 'top_manager'));
    $divisions = array_values(array_filter($nodes, static fn(array $node) => (string)$node['type'] === 'division'));
    ?>
    <link rel="stylesheet" href="/assets/admin/modules/org_structure/org_structure.css?v=3.7.34">
    <div class="org-page org-scheme-page" data-org-scheme="<?php echo (int)$schemeId; ?>" data-org-mode="view">
        <?php if ($orgError !== ''): ?><div class="org-alert is-error"><?php echo asr_org_h($orgError); ?></div><?php endif; ?>
        <div class="org-scheme-toolbar no-print">
            <div>
                <a class="org-back" href="admin.php?tab=org_structure">← Оргсхемы</a>
                <h1><?php echo asr_org_h((string)$scheme['title']); ?></h1>
            </div>
            <div class="org-toolbar-actions">
                <button type="button" class="org-btn is-secondary org-stats-btn" onclick="OrgStructure.openStats()">Статистики</button>
                <button type="button" class="org-icon-btn" title="Уместить оргсхему на экране" aria-label="Уместить оргсхему на экране" onclick="OrgStructure.fitCanvas()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M4 4l6 6M20 9V4h-5M20 4l-6 6M4 15v5h5M4 20l6-6M20 15v5h-5M20 20l-6-6"/></svg>
                </button>
                <button type="button" class="org-icon-btn" title="Скачать оргсхему" aria-label="Скачать оргсхему" onclick="OrgStructure.openDownload()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11M8 10l4 4 4-4M5 19h14"/></svg>
                </button>
                <button type="button" class="org-icon-btn org-edit-toggle" title="Редактировать оргсхему" aria-label="Редактировать оргсхему" onclick="OrgStructure.toggleEditMode()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4zM13.5 6.5l4 4"/></svg>
                </button>
            </div>
        </div>

        <div class="org-desktop-view">
            <div class="org-canvas-shell" id="orgCanvasShell">
                <div class="org-canvas" id="orgCanvas">
                    <svg class="org-connector-svg" id="orgConnectorSvg" aria-hidden="true"></svg>
                    <div class="org-management-layer">
                        <div class="org-founder-zone">
                            <?php if ($founderNodes): ?>
                                <?php foreach ($founderNodes as $node): ?>
                                    <?php $founderId = (int)$node['id']; ?>
                                    <div class="org-founder-item">
                                        <button type="button" class="org-management-card org-founder-card"<?php echo asr_org_node_tooltip_attr($node); ?><?php echo asr_org_node_style($node); ?> onclick="OrgStructure.openRoleEdit(<?php echo $founderId; ?>, 'founder')"><?php echo asr_org_render_node_label($node); ?></button>
                                        <?php echo asr_org_action_button('edit', 'org-founder-edit', 'OrgStructure.openRoleEdit(' . $founderId . ', \'founder\')', 'Редактировать учредителя'); ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <button type="button" class="org-management-card org-founder-card is-empty" onclick="OrgStructure.openNodeCreate(0, 'founder')">+ Учредители</button>
                            <?php endif; ?>
                        </div>
                        <div class="org-top-zone">
                            <?php if ($topNodes): ?>
                                <?php foreach ($topNodes as $node): ?>
                                    <?php $topId = (int)$node['id']; $deputies = array_values(array_filter($byParent[$topId] ?? [], static fn(array $child) => (string)$child['type'] === 'deputy')); $managedTops = array_values(array_filter($byParent[$topId] ?? [], static fn(array $child) => (string)$child['type'] === 'top_manager')); ?>
                                    <div class="org-top-group">
                                        <?php echo asr_org_action_button('add', 'org-top-add', 'OrgStructure.openDirectorAdd(' . $topId . ')', 'Добавить отделение / топ-руководителя'); ?>
                                        <button type="button" class="org-management-card org-top-card"<?php echo asr_org_node_tooltip_attr($node); ?><?php echo asr_org_node_style($node); ?> onclick="OrgStructure.openRoleEdit(<?php echo $topId; ?>, 'top_manager')"><?php echo asr_org_render_node_label($node); ?></button>
                                        <?php echo asr_org_action_button('edit', 'org-top-edit', 'OrgStructure.openRoleEdit(' . $topId . ', \'top_manager\')', 'Редактировать топ-менеджера'); ?>
                                        <div class="org-deputy-row <?php echo $deputies ? '' : 'is-empty'; ?>"<?php echo asr_org_deputy_row_style($node); ?>>
                                            <?php foreach ($deputies as $deputy): ?>
                                                <?php $deputyId = (int)$deputy['id']; ?>
                                                <div class="org-deputy-item">
                                                    <button type="button" class="org-deputy-card"<?php echo asr_org_node_tooltip_attr($deputy); ?><?php echo asr_org_node_style($deputy, $node); ?> onclick="OrgStructure.openNodeEdit(<?php echo $deputyId; ?>)"><?php echo asr_org_render_node_label($deputy); ?></button>
                                                    <?php echo asr_org_action_button('edit', 'org-deputy-edit', 'OrgStructure.openNodeEdit(' . $deputyId . ')', 'Редактировать заместителя'); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="org-deputy-item org-deputy-add-item no-print" data-org-edit-only="1">
                                                <button type="button" class="org-deputy-card org-deputy-add-card is-empty" onclick="OrgStructure.openNodeCreate(<?php echo $topId; ?>, 'deputy')">+ Заместитель</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                    $allManagedTops = [];
                    foreach ($topNodes as $topNodeForManaged) {
                        foreach (array_values(array_filter($byParent[(int)$topNodeForManaged['id']] ?? [], static fn(array $child) => (string)$child['type'] === 'top_manager')) as $managedTopNode) {
                            $allManagedTops[(int)$managedTopNode['id']] = $managedTopNode;
                        }
                    }
                    $managedTopSpans = [];
                    $managedTopFallbackIndex = 0;
                    $divisionLastIndex = max(0, count($divisions) - 1);
                    foreach ($allManagedTops as $managedTopId => $managedTopNode) {
                        $indexes = [];
                        foreach ($divisions as $idx => $divisionForManagedTop) {
                            if ((int)($divisionForManagedTop['managed_by_node_id'] ?? 0) === (int)$managedTopId) {
                                $indexes[] = $idx;
                            }
                        }
                        if ($indexes) {
                            $managedTopSpans[(int)$managedTopId] = [min($indexes), max($indexes)];
                        } else {
                            // Если у топ-руководителя ещё нет закреплённых отделений,
                            // не растягиваем его на всю схему: иначе следующий топ-руководитель
                            // уезжает на вторую строку CSS-grid и уровни становятся разными.
                            $fallback = min($divisionLastIndex, $managedTopFallbackIndex);
                            $managedTopSpans[(int)$managedTopId] = [$fallback, $fallback];
                            $managedTopFallbackIndex++;
                        }
                    }
                    ?>
                    <?php if ($allManagedTops): ?>
                        <div class="org-managed-top-row" style="--org-division-count:<?php echo max(1, count($divisions)); ?>">
                            <?php foreach ($allManagedTops as $managedTop): ?>
                                <?php
                                $managedTopId = (int)$managedTop['id'];
                                $span = $managedTopSpans[$managedTopId] ?? [0, max(0, count($divisions) - 1)];
                                $managedTopDeputies = array_values(array_filter($byParent[$managedTopId] ?? [], static fn(array $child) => (string)$child['type'] === 'deputy'));
                                ?>
                                <div class="org-managed-top-item" data-managed-top-id="<?php echo $managedTopId; ?>" data-managed-start="<?php echo (int)$span[0]; ?>" data-managed-end="<?php echo (int)$span[1]; ?>" style="grid-column:<?php echo ((int)$span[0] + 1); ?> / <?php echo ((int)$span[1] + 2); ?>;grid-row:1;">
                                    <div class="org-managed-top-group org-top-group" data-managed-top-id="<?php echo $managedTopId; ?>">
                                        <?php echo asr_org_action_button('add', 'org-managed-top-add', 'OrgStructure.openNodeCreate(' . $managedTopId . ', \'deputy\')', 'Добавить заместителя'); ?>
                                        <button type="button" class="org-management-card org-managed-top-card" data-managed-top-id="<?php echo $managedTopId; ?>"<?php echo asr_org_node_tooltip_attr($managedTop); ?><?php echo asr_org_node_style($managedTop); ?> onclick="OrgStructure.openRoleEdit(<?php echo $managedTopId; ?>, 'managed_top')"><?php echo asr_org_render_node_label($managedTop); ?></button>
                                        <div class="org-deputy-row <?php echo $managedTopDeputies ? '' : 'is-empty'; ?>"<?php echo asr_org_deputy_row_style($managedTop); ?>>
                                            <?php foreach ($managedTopDeputies as $managedTopDeputy): ?>
                                                <?php $managedTopDeputyId = (int)$managedTopDeputy['id']; ?>
                                                <div class="org-deputy-item">
                                                    <button type="button" class="org-deputy-card"<?php echo asr_org_node_tooltip_attr($managedTopDeputy); ?><?php echo asr_org_node_style($managedTopDeputy, $managedTop); ?> onclick="OrgStructure.openNodeEdit(<?php echo $managedTopDeputyId; ?>)"><?php echo asr_org_render_node_label($managedTopDeputy); ?></button>
                                                    <?php echo asr_org_action_button('edit', 'org-deputy-edit', 'OrgStructure.openNodeEdit(' . $managedTopDeputyId . ')', 'Редактировать заместителя'); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="org-deputy-item org-deputy-add-item no-print" data-org-edit-only="1">
                                                <button type="button" class="org-deputy-card org-deputy-add-card is-empty" onclick="OrgStructure.openNodeCreate(<?php echo $managedTopId; ?>, 'deputy')">+ Заместитель</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="org-management-division-link" aria-hidden="true"></div>
                    <div class="org-division-row" style="--org-division-count:<?php echo max(1, count($divisions)); ?>">
                        <?php foreach ($divisions as $divisionIndex => $division): ?>
                            <?php $divisionId = (int)$division['id']; $departments = array_values(array_filter($byParent[$divisionId] ?? [], static fn(array $node) => (string)$node['type'] === 'department')); $directPositions = array_values(array_filter($byParent[$divisionId] ?? [], static fn(array $node) => (string)$node['type'] === 'position')); ?>
                            <section class="org-division-card" data-division-index="<?php echo $divisionIndex; ?>" data-manager-id="<?php echo (int)($division['managed_by_node_id'] ?? 0); ?>">
                                <header class="org-division-head"<?php echo asr_org_node_style($division); ?>>
                                    <button type="button" onclick="OrgStructure.openNodeEdit(<?php echo $divisionId; ?>)"><span class="org-division-number"><?php echo $divisionIndex + 1; ?></span><?php echo asr_org_render_node_label($division); ?></button>
                                    <?php echo asr_org_action_button('edit', 'org-division-edit', 'OrgStructure.openNodeEdit(' . $divisionId . ')', 'Редактировать отделение'); ?>
                                </header>
                                <div class="org-departments-row">
                                    <?php foreach ($departments as $department): ?>
                                        <?php $departmentId = (int)$department['id']; $sections = array_values(array_filter($byParent[$departmentId] ?? [], static fn(array $node) => (string)$node['type'] === 'section')); $positions = array_values(array_filter($byParent[$departmentId] ?? [], static fn(array $node) => (string)$node['type'] === 'position')); ?>
                                        <article class="org-department-col">
                                            <div class="org-department-head"><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo $departmentId; ?>)"><?php echo asr_org_render_node_label($department); ?></button><?php echo asr_org_action_button('edit', 'org-department-edit', 'OrgStructure.openNodeEdit(' . $departmentId . ')', 'Редактировать отдел'); ?></div>
                                            <?php foreach ($positions as $position): ?><button type="button" class="org-position" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?>
                                            <?php foreach ($sections as $section): ?>
                                                <?php $sectionId = (int)$section['id']; $sectionPositions = array_values(array_filter($byParent[$sectionId] ?? [], static fn(array $node) => (string)$node['type'] === 'position')); ?>
                                                <div class="org-section-box"><div class="org-section-head"><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo $sectionId; ?>)"><?php echo asr_org_render_node_label($section); ?></button><?php echo asr_org_action_button('edit', 'org-section-edit', 'OrgStructure.openNodeEdit(' . $sectionId . ')', 'Редактировать секцию'); ?></div><?php foreach ($sectionPositions as $position): ?><button type="button" class="org-position" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?><?php echo asr_org_action_button('add', 'org-section-add', 'OrgStructure.openNodeCreate(' . $sectionId . ', \'position\')', 'Добавить должность'); ?></div>
                                            <?php endforeach; ?>
                                            <?php echo asr_org_action_button('add', 'org-department-add', 'OrgStructure.openNodeCreate(' . $departmentId . ', \'section\')', 'Добавить элемент в отдел'); ?>
                                            <?php echo asr_org_render_ckp_bar((string)($department['ckp_text'] ?? ''), 'Отдел', 'Добавить ЦКП отдела', 'org-department-ckp', 'OrgStructure.openNodeEdit(' . $departmentId . ')'); ?>
                                        </article>
                                    <?php endforeach; ?>
                                    <?php if (!$departments && !$directPositions): ?>
                                        <div class="org-empty-inside no-print">Добавьте отделы или должности внутри отделения.</div>
                                    <?php endif; ?>
                                    <?php foreach ($directPositions as $position): ?><button type="button" class="org-position is-direct" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?>
                                </div>
                                <?php echo asr_org_render_ckp_bar((string)($division['ckp_text'] ?? ''), 'Отделение', 'Добавить ЦКП отделения', 'org-division-ckp', 'OrgStructure.openNodeEdit(' . $divisionId . ')'); ?>
                            </section>
                        <?php endforeach; ?>
                        <?php if (!$divisions): ?><button type="button" class="org-empty-division no-print" onclick="OrgStructure.openNodeCreate(0, 'division')">+ Добавить первое отделение</button><?php endif; ?>
                    </div>
                    <?php echo asr_org_render_ckp_bar((string)($scheme['ckp_text'] ?? ''), 'Организация', 'Добавить ЦКП организации', 'org-scheme-ckp', 'OrgStructure.openSchemeCkp()'); ?>
                </div>
            </div>
        </div>

        <div class="org-mobile-view" id="orgMobileView">
            <div class="org-mobile-top">
                <div class="org-mobile-top-title">Управление</div>
                <div class="org-mobile-top-list"><?php foreach ($founderNodes as $node): ?><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo (int)$node['id']; ?>)"><?php echo asr_org_render_node_label($node, false); ?></button><?php endforeach; ?><?php foreach ($topNodes as $node): ?><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo (int)$node['id']; ?>)"><?php echo asr_org_render_node_label($node, false); ?></button><?php endforeach; ?></div>
            </div>
            <div class="org-mobile-nav no-print"><button type="button" onclick="OrgStructure.mobilePrev()">‹</button><span id="orgMobileCounter">1 из <?php echo max(1, count($divisions)); ?></span><button type="button" onclick="OrgStructure.mobileNext()">›</button></div>
            <div class="org-mobile-slides" id="orgMobileSlides">
                <?php foreach ($divisions as $divisionIndex => $division): ?>
                    <?php $divisionId = (int)$division['id']; $departments = array_values(array_filter($byParent[$divisionId] ?? [], static fn(array $node) => (string)$node['type'] === 'department')); $directPositions = array_values(array_filter($byParent[$divisionId] ?? [], static fn(array $node) => (string)$node['type'] === 'position')); ?>
                    <section class="org-mobile-division <?php echo $divisionIndex === 0 ? 'is-active' : ''; ?>" data-mobile-division>
                        <h2><span><?php echo ($divisionIndex + 1) . '. '; ?></span><?php echo asr_org_render_node_label($division); ?></h2>
                        <?php foreach ($departments as $department): ?>
                            <?php $departmentId = (int)$department['id']; $sections = array_values(array_filter($byParent[$departmentId] ?? [], static fn(array $node) => (string)$node['type'] === 'section')); $positions = array_values(array_filter($byParent[$departmentId] ?? [], static fn(array $node) => (string)$node['type'] === 'position')); ?>
                            <details class="org-mobile-department" open><summary><?php echo asr_org_render_node_label($department); ?></summary><?php foreach ($positions as $position): ?><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?><?php foreach ($sections as $section): ?><div class="org-mobile-section"><strong><?php echo asr_org_render_node_label($section); ?></strong><?php foreach (array_values(array_filter($byParent[(int)$section['id']] ?? [], static fn(array $node) => (string)$node['type'] === 'position')) as $position): ?><button type="button" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?></div><?php endforeach; ?><?php echo asr_org_render_ckp_bar((string)($department['ckp_text'] ?? ''), 'Отдел', 'Добавить ЦКП отдела', 'org-mobile-ckp org-department-ckp', 'OrgStructure.openNodeEdit(' . $departmentId . ')'); ?></details>
                        <?php endforeach; ?>
                        <?php foreach ($directPositions as $position): ?><button type="button" class="org-mobile-position" onclick="OrgStructure.openNodeEdit(<?php echo (int)$position['id']; ?>)"><?php echo asr_org_render_node_label($position); ?></button><?php endforeach; ?>
                        <?php echo asr_org_render_ckp_bar((string)($division['ckp_text'] ?? ''), 'Отделение', 'Добавить ЦКП отделения', 'org-mobile-ckp org-division-ckp', 'OrgStructure.openNodeEdit(' . $divisionId . ')'); ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="button" class="org-floating-save no-print" onclick="OrgStructure.saveEditing()">Сохранить</button>

        <script id="orgNodesData" type="application/json"><?php echo json_encode(array_values($nodes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
        <div id="orgSchemeCkpModal" class="org-modal no-print" aria-hidden="true">
            <div class="org-modal-backdrop" onclick="OrgStructure.closeSchemeCkp()"></div>
            <form method="post" class="org-modal-panel">
                <?php echo asr_org_csrf_input(); ?>
                <input type="hidden" name="org_action" value="update_scheme">
                <input type="hidden" name="scheme_id" value="<?php echo (int)$schemeId; ?>">
                <input type="hidden" name="return_to_scheme" value="1">
                <input type="hidden" name="title" value="<?php echo asr_org_h((string)$scheme['title']); ?>">
                <input type="hidden" name="description" value="<?php echo asr_org_h((string)($scheme['description'] ?? '')); ?>">
                <input type="hidden" name="parent_id" value="<?php echo (int)($scheme['parent_id'] ?? 0); ?>">
                <input type="hidden" name="status" value="<?php echo asr_org_h((string)($scheme['status'] ?? 'draft')); ?>">
                <div class="org-modal-head"><h2>ЦКП организации</h2><button type="button" onclick="OrgStructure.closeSchemeCkp()">×</button></div>
                <label>Формулировка ЦКП<textarea name="ckp_text" rows="4" placeholder="Ценный конечный продукт всей организации"><?php echo asr_org_h((string)($scheme['ckp_text'] ?? '')); ?></textarea></label>
                <div class="org-modal-actions"><button type="button" class="org-btn is-secondary" onclick="OrgStructure.closeSchemeCkp()">Отмена</button><button type="submit" class="org-btn is-primary">Сохранить</button></div>
            </form>
        </div>
        <?php require __DIR__ . '/partials_node_modal.php'; ?>
    </div>
    <script src="/assets/admin/modules/org_structure/org_structure.js?v=3.7.34"></script>
    <?php
    return;
}

$schemes = asr_org_structure_fetch_schemes($pdo, false);
?>
<link rel="stylesheet" href="/assets/admin/modules/org_structure/org_structure.css?v=3.7.34">
<div class="org-page org-list-page">
    <?php if ($orgError !== ''): ?><div class="org-alert is-error"><?php echo asr_org_h($orgError); ?></div><?php endif; ?>
    <div class="org-list-head">
        <div>
            <h1>Оргсхемы</h1>
            <p>Отдельный модуль для структуры компании, филиалов и направлений. Модуль изолирован от чат-ботов, рассылок и сценариев.</p>
        </div>
        <button type="button" class="org-btn is-primary" onclick="OrgStructure.openSchemeCreate()">+ Создать оргсхему</button>
    </div>

    <div class="org-schemes-panel">
        <?php if ($schemes): ?>
            <?php echo asr_org_render_scheme_tree_rows($schemes, null, 0); ?>
        <?php else: ?>
            <div class="org-empty-state"><div>▦</div><h2>Оргсхем пока нет</h2><p>Создайте первую схему: основную компанию, филиал или отдельное направление.</p><button type="button" class="org-btn is-primary" onclick="OrgStructure.openSchemeCreate()">+ Создать оргсхему</button></div>
        <?php endif; ?>
    </div>
</div>

<div id="orgSchemeModal" class="org-modal no-print" aria-hidden="true">
    <div class="org-modal-backdrop" onclick="OrgStructure.closeSchemeModal()"></div>
    <form method="post" class="org-modal-panel">
        <?php echo asr_org_csrf_input(); ?>
        <input type="hidden" name="org_action" value="create_scheme" id="orgSchemeAction">
        <input type="hidden" name="scheme_id" value="" id="orgSchemeId">
        <div class="org-modal-head"><h2 id="orgSchemeModalTitle">Создать оргсхему</h2><button type="button" onclick="OrgStructure.closeSchemeModal()">×</button></div>
        <label>Название<input type="text" name="title" id="orgSchemeTitle" required placeholder="Например: Основная оргсхема компании"></label>
        <label>Описание<textarea name="description" id="orgSchemeDescription" rows="3" placeholder="Коротко: для чего эта схема"></textarea></label>
        <label>ЦКП организации<textarea name="ckp_text" id="orgSchemeCkp" rows="3" placeholder="Ценный конечный продукт всей организации"></textarea></label>
        <label>Родительская оргсхема<select name="parent_id" id="orgSchemeParent"><option value="0">Без родителя</option><?php foreach ($schemes as $scheme): ?><option value="<?php echo (int)$scheme['id']; ?>"><?php echo asr_org_h((string)$scheme['title']); ?></option><?php endforeach; ?></select></label>
        <div class="org-modal-grid"><label>Отделений на старте<input type="number" min="0" max="30" name="division_count" value="7"></label><label>Отделов в отделении<input type="number" min="0" max="12" name="department_count" value="3"></label></div>
        <label>Статус<select name="status" id="orgSchemeStatus"><option value="draft">Черновик</option><option value="active">Активная</option></select></label>
        <div class="org-modal-actions"><button type="button" class="org-btn is-secondary" onclick="OrgStructure.closeSchemeModal()">Отмена</button><button type="submit" class="org-btn is-primary">Сохранить</button></div>
    </form>
</div>
<script>
window.OrgStructureSchemes = <?php echo json_encode(array_values($schemes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<script src="/assets/admin/modules/org_structure/org_structure.js?v=3.7.34"></script>
