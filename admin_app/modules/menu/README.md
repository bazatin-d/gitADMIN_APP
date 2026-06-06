# Модуль menu

Модуль отвечает за редактируемое левое меню админки.

## Файлы

- `actions.php` — обработка POST-действий `save_admin_menu`, `create_admin_menu_item`, `delete_admin_menu_item`.
- `service.php` — рендер меню, проверка прав, нормализация ссылок, иконок и типов.
- `repository.php` — чтение/запись таблицы `oca_admin_menu_items`.
- `pages/settings_block.php` — блок интерфейса на странице `/admin.php?tab=settings`.
- `module.php` — манифест модуля.

## Таблица

Используется `oca_admin_menu_items`. Если таблицы нет, меню работает на дефолтной структуре, но редактирование недоступно.
