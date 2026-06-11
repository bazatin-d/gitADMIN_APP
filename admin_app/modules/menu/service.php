<?php
/**
 * Аварийно-стабильный сервис меню.
 * Важно: здесь нет зависимостей от asr_admin_icon(), SVG-helper'ов и внешних функций иконок.
 */

defined('ASR_ADMIN') || exit;

if (!function_exists('asr_menu_icon')) {
    function asr_menu_icon(string $iconKey): string {
        $map = [
            'tests' => 'tests',
            'marketing' => 'marketing',
            'settings' => 'settings',
            'link' => 'link',
            'key' => 'lock',
            'telegram' => 'telegram',
            'org' => 'marketing',
            'dot' => 'chevron-right',
        ];
        $name = $map[$iconKey] ?? 'link';

        /*
         * Безопасный вариант:
         * - не меняем логику меню;
         * - не используем helper-функции;
         * - не читаем SVG через PHP;
         * - если картинка не загрузится, меню не падает.
         */
        return '<img class="mn4-menu-icon" src="/assets/admin/icons/mn4-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '-gray.svg" alt="" aria-hidden="true" style="width:18px;height:18px;display:inline-block;vertical-align:middle;object-fit:contain;flex:0 0 18px;opacity:.92;">';
    }
}

if (!function_exists('asr_menu_item_target_attr')) {
    function asr_menu_item_target_attr(string $target): string {
        if ($target !== '_blank') return '';
        return ' target="_blank" rel="noopener noreferrer"';
    }
}

if (!function_exists('asr_menu_link')) {
    function asr_menu_link(string $href, string $label, bool $active = false, string $icon = '', string $target = '_self'): string {
        $classes = $active
            ? 'bg-orange-50 text-[#C96F2B] border border-orange-100 shadow-sm shadow-orange-500/5'
            : 'text-gray-500 hover:bg-orange-50 hover:text-[#C96F2B] border border-transparent';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . asr_menu_item_target_attr($target) . ' class="drawer-link flex items-center gap-3 rounded-2xl uppercase transition-all ' . $classes . '">' . $icon . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
}

if (!function_exists('asr_drawer_group')) {
    function asr_drawer_group(string $label, string $icon, array $items, bool $open = false): string {
        if (!$items) return '';
        $html = '<details class="drawer-group"' . ($open ? ' open' : '') . '>';
        $html .= '<summary class="drawer-group-summary flex items-center gap-3 rounded-2xl uppercase transition-all">' . $icon . '<span class="flex-1">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><span class="drawer-chevron aa-icon aa-icon-sm" aria-hidden="true">⌄</span></summary>';
        $html .= '<div class="drawer-subnav">';
        foreach ($items as $item) {
            $html .= asr_menu_link(
                (string)($item['href'] ?? '#'),
                (string)($item['title'] ?? ''),
                (bool)($item['active'] ?? false),
                asr_menu_icon((string)($item['icon_key'] ?? 'dot')),
                (string)($item['target'] ?? '_self')
            );
        }
        $html .= '</div></details>';
        return $html;
    }
}


if (!function_exists('asr_menu_href_is_active')) {
    function asr_menu_href_is_active(string $href): bool {
        $href = trim($href);
        if ($href === '' || stripos($href, 'http://') === 0 || stripos($href, 'https://') === 0) {
            return false;
        }

        $currentPath = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $hrefPath = basename((string)parse_url($href, PHP_URL_PATH));
        if ($hrefPath === '') $hrefPath = 'admin.php';
        if ($currentPath === '') $currentPath = 'admin.php';
        if ($hrefPath !== $currentPath && !($hrefPath === 'admin.php' && $currentPath === 'admin.php')) {
            return false;
        }

        parse_str((string)parse_url($href, PHP_URL_QUERY), $targetQuery);
        if (!$targetQuery) return false;

        $currentQuery = $_GET;
        if (isset($targetQuery['spam'])) {
            return (string)($currentQuery['spam'] ?? '') === (string)$targetQuery['spam'];
        }

        if (isset($targetQuery['tab']) && (string)($currentQuery['tab'] ?? '') !== (string)$targetQuery['tab']) {
            return false;
        }

        foreach (['page', 'category'] as $strictKey) {
            if (array_key_exists($strictKey, $targetQuery)) {
                return (string)($currentQuery[$strictKey] ?? '') === (string)$targetQuery[$strictKey];
            }
        }

        return isset($targetQuery['tab']);
    }
}


if (!function_exists('asr_menu_is_footer_settings_item')) {
    function asr_menu_is_footer_settings_item(array $item): bool {
        $title = trim(mb_strtolower((string)($item['title'] ?? ''), 'UTF-8'));
        $href = trim(mb_strtolower((string)($item['href'] ?? ''), 'UTF-8'));
        $type = (string)($item['item_type'] ?? '');

        if ($type === 'group' && in_array($title, ['настройки', 'настройки системы'], true)) {
            return true;
        }

        return in_array($href, ['admin.php?tab=settings', '/admin.php?tab=settings', 'admin.php?tab=users', '/admin.php?tab=users'], true)
            || in_array($title, ['настройки системы', 'пользователи', 'сотрудники'], true);
    }
}

if (!function_exists('asr_render_admin_drawer_menu')) {
    function asr_render_admin_drawer_menu(PDO $pdo): string {
        if (!function_exists('asr_get_admin_menu_items')) {
            return asr_menu_fallback_html();
        }

        try {
            $items = asr_get_admin_menu_items($pdo, false);
        } catch (Throwable $e) {
            return asr_menu_fallback_html();
        }

        $parents = [];
        $children = [];

        foreach ($items as $item) {
            try {
                $permission = (string)($item['permission_key'] ?? 'any');
                if (function_exists('asr_menu_permission_allowed') && !asr_menu_permission_allowed($permission)) {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }

            if (function_exists('asr_menu_is_footer_settings_item') && asr_menu_is_footer_settings_item($item)) {
                continue;
            }

            $item['id'] = (int)($item['id'] ?? 0);
            $parentId = $item['parent_id'] === null ? null : (int)$item['parent_id'];

            if ($parentId === null || $parentId === 0) {
                $parents[] = $item;
            } else {
                $children[$parentId][] = $item;
            }
        }

        usort($parents, static function($a, $b) {
            return ((int)($a['sort_order'] ?? 100) <=> (int)($b['sort_order'] ?? 100)) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });

        $html = '';

        foreach ($parents as $parent) {
            $id = (int)($parent['id'] ?? 0);
            $type = (string)($parent['item_type'] ?? 'link');
            $icon = asr_menu_icon((string)($parent['icon_key'] ?? ($type === 'group' ? 'link' : 'dot')));
            $childItems = $children[$id] ?? [];

            usort($childItems, static function($a, $b) {
                return ((int)($a['sort_order'] ?? 100) <=> (int)($b['sort_order'] ?? 100)) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
            });

            if ($type === 'group') {
                $renderChildren = [];
                $open = false;

                foreach ($childItems as $child) {
                    $href = (string)($child['href'] ?? '');
                    $active = false;
                    try {
                        $active = function_exists('asr_menu_href_is_active') ? asr_menu_href_is_active($href) : false;
                    } catch (Throwable $e) {
                        $active = false;
                    }
                    $open = $open || $active;
                    $child['active'] = $active;
                    $renderChildren[] = $child;
                }

                $html .= asr_drawer_group((string)($parent['title'] ?? ''), $icon, $renderChildren, $open);
            } else {
                $href = (string)($parent['href'] ?? '#');
                $active = false;
                try {
                    $active = function_exists('asr_menu_href_is_active') ? asr_menu_href_is_active($href) : false;
                } catch (Throwable $e) {
                    $active = false;
                }
                $html .= asr_menu_link($href, (string)($parent['title'] ?? ''), $active, $icon, (string)($parent['target'] ?? '_self'));
            }
        }

        return $html !== '' ? $html : asr_menu_fallback_html();
    }
}

if (!function_exists('asr_menu_fallback_html')) {
    function asr_menu_fallback_html(): string {
        $items = [
            ['admin.php', 'Общая панель'],
            ['admin.php?tab=results', 'Результаты АСР'],
            ['admin.php?tab=access_vault', 'Доступы'],
            ['admin.php?tab=telegram_bots&page=messages', 'Чат-боты'],
            ['admin.php?tab=settings', 'Настройки'],
        ];

        $html = '';
        foreach ($items as [$href, $label]) {
            $html .= asr_menu_link($href, $label, false, asr_menu_icon('dot'));
        }
        return $html;
    }
}

if (!function_exists('asr_normalize_menu_title')) {
    function asr_normalize_menu_title(string $title): string {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            throw new InvalidArgumentException('Укажите название пункта меню.');
        }
        if (mb_strlen($title, 'UTF-8') > 80) {
            throw new InvalidArgumentException('Название пункта меню слишком длинное. Максимум 80 символов.');
        }
        return $title;
    }
}

if (!function_exists('asr_normalize_menu_href')) {
    function asr_normalize_menu_href(string $href, string $itemType): string {
        $href = trim($href);
        if ($itemType === 'group') return '';
        if ($href === '') {
            throw new InvalidArgumentException('Укажите ссылку для пункта меню.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $href)) {
            throw new InvalidArgumentException('В ссылке есть недопустимые символы.');
        }
        $lower = mb_strtolower($href, 'UTF-8');
        if (preg_match('/^(javascript|data|vbscript):/i', $lower)) {
            throw new InvalidArgumentException('Такой тип ссылки запрещён.');
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (preg_match('#^admin\.php(\?.*)?$#i', $href) || preg_match('#^/admin\.php(\?.*)?$#i', $href)) {
            return ltrim($href, '/');
        }
        throw new InvalidArgumentException('Для внешних ресурсов используйте ссылки с http:// или https://. Для внутренних — admin.php или admin.php?tab=...');
    }
}

if (!function_exists('asr_normalize_menu_type')) {
    function asr_normalize_menu_type(string $type): string {
        return in_array($type, ['group', 'internal', 'external'], true) ? $type : 'external';
    }
}

if (!function_exists('asr_normalize_menu_target')) {
    function asr_normalize_menu_target(string $target): string {
        return $target === '_blank' ? '_blank' : '_self';
    }
}

if (!function_exists('asr_normalize_menu_permission')) {
    function asr_normalize_menu_permission(string $permission): string {
        if (in_array($permission, ['any', 'can_view_results', 'can_work_results', 'admin'], true)) return $permission;
        if (preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $permission)) return $permission;
        return 'any';
    }
}

if (!function_exists('asr_normalize_menu_icon_key')) {
    function asr_normalize_menu_icon_key(string $iconKey): string {
        return in_array($iconKey, ['tests', 'marketing', 'settings', 'link', 'dot', 'key', 'telegram'], true) ? $iconKey : 'link';
    }
}
