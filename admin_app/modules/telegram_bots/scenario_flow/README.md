# Scenario Flow — основной редактор сценариев

С версии v3.5.56 в модуле сценариев оставлена одна рабочая линия редактора: React Flow.

## Основные файлы

- `admin_app/modules/telegram_bots/pages/scenarios.php` — список сценариев.
- `admin_app/modules/telegram_bots/pages/scenario_flow.php` — основной холст редактора.
- `admin_app/modules/telegram_bots/pages/scenario_block_panel.php` — чистая правая панель редактирования блока.
- `admin_app/modules/telegram_bots/scenario_flow/dist/scenario-flow-cdn.js` — JS холста.

## Удалённая старая ветка

Старые файлы больше не использовать и не возвращать:

- `admin_app/modules/telegram_bots/pages/scenario.php`
- `admin_app/modules/telegram_bots/pages/scenario_editor.php`

Старые маршруты `page=scenario` и `page=scenario_editor` должны маршрутизироваться на `page=scenario_flow` в `pages/index.php`.

## Важно

- Не подгружать старую модалку из `scenario.php` в React Flow drawer.
- Все новые блоки делать через `scenario_block_panel.php` и текущие backend actions/repository.
- Runner, запуск сценариев и отправка сообщений подключаются отдельными шагами.
