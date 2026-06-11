<?php defined('ASR_ADMIN') || exit; ?>
<?php
if (!function_exists('asr_org_color_palette')) {
    function asr_org_color_palette(string $inputId, string $name, string $default = ''): string {
        $colors = [
            '#9FC5E8', '#B7D7F0', '#D9EAD3', '#BFE3D8', '#D9D2E9', '#CDB4DB',
            '#F4CCCC', '#FCE5CD', '#FFF2CC', '#FFF7B2', '#EFE8D8', '#E5E7EB', '#FFFFFF'
        ];
        $html = '<div class="org-color-picker" data-org-palette-for="' . asr_org_h($inputId) . '">';
        $html .= '<input type="hidden" name="' . asr_org_h($name) . '" id="' . asr_org_h($inputId) . '" value="' . asr_org_h($default) . '">';
        foreach ($colors as $color) {
            $html .= '<button type="button" class="org-color-dot" data-org-color="' . asr_org_h($color) . '" style="background:' . asr_org_h($color) . '" aria-label="Выбрать цвет ' . asr_org_h($color) . '"></button>';
        }
        $html .= '<button type="button" class="org-color-dot is-custom" data-org-custom-color aria-label="Свой цвет">' . asr_org_icon_svg('edit') . '</button>';
        $html .= '<div class="org-custom-color"><input type="text" id="' . asr_org_h($inputId) . 'Custom" placeholder="#9FC5E8" inputmode="text"></div>';
        $html .= '</div>';
        return $html;
    }
}
$orgAvailableDivisions = array_values(array_filter($divisions ?? [], static fn(array $node) => empty($node['managed_by_node_id'])));
$orgAllDivisions = array_values($divisions ?? []);
?>
<div id="orgRoleModal" class="org-modal no-print" aria-hidden="true">
    <div class="org-modal-backdrop" onclick="OrgStructure.closeRoleModal()"></div>
    <form method="post" class="org-modal-panel org-context-modal">
        <?php echo asr_org_csrf_input(); ?>
        <input type="hidden" name="org_action" value="update_node">
        <input type="hidden" name="scheme_id" value="<?php echo (int)$schemeId; ?>">
        <input type="hidden" name="node_id" value="" id="orgRoleId">
        <input type="hidden" name="type" value="" id="orgRoleType">
        <input type="hidden" name="parent_id" value="0" id="orgRoleParent">
        <input type="hidden" name="sort_order" value="100" id="orgRoleSort">
        <input type="hidden" name="planned_count" value="" id="orgRolePlanned">
        <div class="org-modal-head"><h2 id="orgRoleModalTitle">Редактировать блок</h2><button type="button" onclick="OrgStructure.closeRoleModal()">×</button></div>
        <label>Название<input type="text" name="title" id="orgRoleTitle" required></label>
        <label>ФИО<input type="text" name="person_name" id="orgRolePerson" placeholder="Например: Иван Петров"></label>
        <label>ЦКП<textarea name="ckp_text" id="orgRoleCkp" rows="3" placeholder="Ценный конечный продукт"></textarea></label>
        <label>Описание<textarea name="description" id="orgRoleDescription" rows="3" placeholder="Описание должности или блока"></textarea></label>
        <label class="org-managed-role-only" hidden>Подчинённые отделения<select name="managed_division_ids[]" id="orgRoleManagedDivisions" multiple size="7"><?php foreach ($orgAllDivisions as $division): ?><option value="<?php echo (int)$division['id']; ?>" data-manager-id="<?php echo (int)($division['managed_by_node_id'] ?? 0); ?>"><?php echo asr_org_h((string)$division['title']); ?></option><?php endforeach; ?></select><small>В списке доступны свободные отделения и отделения, уже закреплённые за этим топ-руководителем.</small></label>
        <label>Цвет заполнения блока<?php echo asr_org_color_palette('orgRoleColor', 'color'); ?></label>
        <div class="org-modal-actions"><button type="button" class="org-btn is-danger org-managed-role-only" id="orgRoleDeleteBtn" onclick="OrgStructure.deleteRoleNode()" hidden>Удалить</button><button type="submit" class="org-btn is-primary">Сохранить</button></div>
    </form>
</div>

<div id="orgDirectorAddModal" class="org-modal no-print" aria-hidden="true">
    <div class="org-modal-backdrop" onclick="OrgStructure.closeDirectorAdd()"></div>
    <form method="post" class="org-modal-panel org-context-modal">
        <?php echo asr_org_csrf_input(); ?>
        <input type="hidden" name="org_action" value="director_add">
        <input type="hidden" name="scheme_id" value="<?php echo (int)$schemeId; ?>">
        <input type="hidden" name="parent_id" value="0" id="orgDirectorAddParent">
        <div class="org-modal-head"><h2>Добавить отделение / топ-руководителя</h2><button type="button" onclick="OrgStructure.closeDirectorAdd()">×</button></div>
        <label>Что создаём<select name="create_kind" id="orgDirectorAddKind" onchange="OrgStructure.toggleDirectorAddKind()"><option value="division">Отделение</option><option value="top_manager">Топ-менеджер</option></select></label>

        <div class="org-dynamic-section" data-director-kind="division">
            <div class="org-modal-grid"><label>Порядковый номер<input type="number" name="division_number" id="orgDivisionNumber" min="1" placeholder="Напр. 3"></label><label>Порядок<input type="number" name="division_sort_order" id="orgDivisionSort" value="100"></label></div>
            <label>Название отделения<input type="text" name="division_title" id="orgDivisionTitle" placeholder="Например: Производственное отделение"></label>
            <label>Количество отделов<input type="number" name="department_count" id="orgDivisionDepartmentCount" min="0" max="40" value="0"></label>
            <label>Описание<textarea name="division_description" id="orgDivisionDescription" rows="3"></textarea></label>
            <label>ЦКП отделения<textarea name="division_ckp_text" id="orgDivisionCkp" rows="3"></textarea></label>
            <label>Цвет заполнения блока<?php echo asr_org_color_palette('orgDivisionColor', 'division_color', '#FFFFFF'); ?></label>
        </div>

        <div class="org-dynamic-section" data-director-kind="top_manager" hidden>
            <div class="org-modal-grid"><label>Название<input type="text" name="top_title" id="orgTopTitle" placeholder="Например: Технический директор"></label><label>Порядок<input type="number" name="top_sort_order" id="orgTopSort" value="100"></label></div>
            <label>ФИО<input type="text" name="top_person_name" id="orgTopPerson" placeholder="Например: Иван Петров"></label>
            <label>ЦКП<textarea name="top_ckp_text" id="orgTopCkp" rows="3"></textarea></label>
            <label>Описание<textarea name="top_description" id="orgTopDescription" rows="3" placeholder="Описание должности"></textarea></label>
            <label>Подчинённые отделения<select name="division_ids[]" id="orgTopDivisions" multiple size="6"><?php foreach ($orgAvailableDivisions as $division): ?><option value="<?php echo (int)$division['id']; ?>"><?php echo asr_org_h((string)$division['title']); ?></option><?php endforeach; ?></select><small>Показываются только отделения, которые ещё не закреплены за другим топ-руководителем.</small></label>
            <label>Цвет заполнения блока<?php echo asr_org_color_palette('orgTopColor', 'top_color', '#9FC5E8'); ?></label>
        </div>

        <div class="org-modal-actions"><button type="button" class="org-btn is-secondary" onclick="OrgStructure.closeDirectorAdd()">Отмена</button><button type="submit" class="org-btn is-primary">Сохранить</button></div>
    </form>
</div>

<div id="orgNodeModal" class="org-modal no-print" aria-hidden="true">
    <div class="org-modal-backdrop" onclick="OrgStructure.closeNodeModal()"></div>
    <form method="post" class="org-modal-panel">
        <?php echo asr_org_csrf_input(); ?>
        <input type="hidden" name="org_action" value="create_node" id="orgNodeAction">
        <input type="hidden" name="scheme_id" value="<?php echo (int)$schemeId; ?>">
        <input type="hidden" name="node_id" value="" id="orgNodeId">
        <div class="org-modal-head"><h2 id="orgNodeModalTitle">Добавить элемент</h2><button type="button" onclick="OrgStructure.closeNodeModal()">×</button></div>
        <label>Тип элемента<select name="type" id="orgNodeType"><option value="founder">Учредители</option><option value="top_manager">Топ-менеджмент</option><option value="deputy">Заместитель</option><option value="division">Отделение</option><option value="department">Отдел</option><option value="section">Секция</option><option value="position">Должность</option></select></label>
        <label>Родитель<select name="parent_id" id="orgNodeParent"><option value="0">Верхний уровень</option><?php foreach ($nodes as $node): ?><option value="<?php echo (int)$node['id']; ?>"><?php echo asr_org_h(asr_org_structure_node_type_label((string)$node['type']) . ' — ' . (string)$node['title']); ?></option><?php endforeach; ?></select></label>
        <label>Название должности / блока<input type="text" name="title" id="orgNodeTitle" required></label>
        <label>Имя и фамилия<input type="text" name="person_name" id="orgNodePerson" placeholder="Например: Иван Петров"></label>
        <label>Описание<textarea name="description" id="orgNodeDescription" rows="3"></textarea></label>
        <label>ЦКП<textarea name="ckp_text" id="orgNodeCkp" rows="3" placeholder="Ценный конечный продукт элемента"></textarea></label>
        <div class="org-modal-grid" id="orgNodeMetaGrid"><label>Порядок<input type="number" name="sort_order" id="orgNodeSort" value="100"></label><label>Ставок<input type="text" name="planned_count" id="orgNodePlanned" placeholder="Напр. 2"></label></div>
        <label id="orgNodeColorLabel">Цвет / метка<input type="text" name="color" id="orgNodeColor" placeholder="#FFA048"></label>
        <div class="org-modal-actions"><button type="button" class="org-btn is-secondary" onclick="OrgStructure.closeNodeModal()">Отмена</button><button type="button" class="org-btn is-danger" onclick="OrgStructure.deleteCurrentNode()">Удалить</button><button type="submit" class="org-btn is-primary">Сохранить</button></div>
    </form>
    <form method="post" id="orgNodeDeleteForm" class="org-delete-form">
        <?php echo asr_org_csrf_input(); ?>
        <input type="hidden" name="org_action" value="delete_node">
        <input type="hidden" name="scheme_id" value="<?php echo (int)$schemeId; ?>">
        <input type="hidden" name="node_id" value="" id="orgNodeDeleteId">
    </form>
</div>
