# Modules

Краткий список модулей Admin App.

| Модуль | Назначение | Основные таблицы |
|---|---|---|
| `results` | Результаты АСР | `oca_results` |
| `url_shortener` | Короткие ссылки и UTM | `oca_short_urls`, `oca_utm_presets` |
| `menu` | Редактируемое меню | `oca_admin_menu_items` |
| `users` | Пользователи и роли | `oca_users`, `oca_user_permissions` |
| `settings` | Системные настройки | `oca_settings` |
| `import_export` | Импорт/экспорт результатов | `oca_results` |
| `manual_input` | Ручной ввод результатов | `oca_results` |
| `emails` | SMTP и шаблоны писем | `oca_settings` |
| `diagnostics` | Диагностика проекта | читает структуру БД и файлов |
| `bitrix24` | Интеграция с Bitrix24 | настройки + поля `oca_results` |
| `access_vault` | Хранилище доступов | `oca_access_*`, `oca_user_permissions` |

## Стандартная структура модуля

```text
admin_app/modules/{module}/
├── actions.php
├── service.php
├── repository.php
├── module.php
├── README.md
└── pages/
    └── index.php
```

## Правило

Новая логика не добавляется в legacy-файлы:

- `admin_app/actions/admin.php`
- `admin_app/views/pages/*.php`

Новая логика добавляется только в модуль.
