# Admin App — file map

Фиксация: v3.5.09.8.

## Telegram Bots — основные файлы

| Файл | Назначение | Статус |
|---|---|---|
| `admin_app/modules/telegram_bots/module.php` | Описание модуля, точка подключения страницы | Не трогать без необходимости |
| `admin_app/modules/telegram_bots/pages/index.php` | Основной роутер страниц Telegram Bots | Рабочий вход модуля |
| `admin_app/modules/telegram_bots/actions.php` | POST-действия Telegram Bots | Используется сценариями, рассылками, диалогами |
| `admin_app/modules/telegram_bots/service.php` | Сервисные функции, права, подготовка данных | Активно используется |
| `admin_app/modules/telegram_bots/repository.php` | SQL-операции, ensure-схемы | Активно используется |
| `admin_app/modules/telegram_bots/telegram_api.php` | Вызовы Telegram API | Не трогать без задачи по отправке |
| `admin_app/modules/telegram_bots/cron_send_queue.php` | Очередь рассылок | Не трогать при работе со сценариями MVP |

## Telegram Bots — страницы

| Файл | Назначение | Важные правила |
|---|---|---|
| `pages/subscribers.php` | Список подписчиков | Не возвращать старую тяжёлую версию |
| `pages/subscriber.php` | Карточка подписчика | Использует единые custom fields по `telegram_user_id` |
| `pages/index.php` | Роутер и часть старых страниц | Проверять аккуратно, не ломать маршруты |
| `pages/broadcasts.php` | Рассылки | Стабилизировано, не трогать без отдельной задачи |
| `pages/scenarios.php` | Список сценариев | Текущий фокус, вид строками + модальное создание |
| `pages/scenario_editor.php` | Будущий редактор блоков | Делать отдельным файлом, не вмешивать в список |

## Текущее состояние страницы `pages/scenarios.php`

Должно быть:

```text
- общий список на всю ширину;
- кнопка «+ Создать сценарий» справа вверху;
- рядом серая кнопка «Архив»;
- фильтры: все рабочие / активные / черновики / на паузе;
- сценарии строками;
- в конце строки только кнопка ⋯;
- меню ⋯: «Редактировать», «В архив»;
- создание и редактирование — через модальное окно;
- архив — отдельный view=archive;
- в архиве можно восстановить или удалить навсегда.
```

## Последний UI-фикс

Файл:

```text
admin_app/modules/telegram_bots/pages/scenarios.php
```

Исправление:

```text
- меню ⋯ больше не должно обрезаться границей блока;
- `tg-scenarios-page` должен иметь `overflow: visible`;
- ячейки и строка действий должны не обрезать абсолютное меню;
- z-index меню повышен.
```

## БД сценариев

Миграция:

```text
database/migrations/2026_06_03_003_telegram_scenarios_foundation.sql
```

Таблицы:

```text
oca_telegram_bot_scenarios
oca_telegram_bot_scenario_bots
oca_telegram_bot_scenario_blocks
oca_telegram_bot_scenario_links
oca_telegram_bot_scenario_block_cards
oca_telegram_bot_scenario_card_buttons
oca_telegram_bot_scenario_deeplinks
oca_telegram_bot_subscriber_scenarios
oca_telegram_bot_scenario_events
```

## Документация

| Файл | Назначение |
|---|---|
| `docs/project_graph.md` | Общий граф проекта и текущая архитектура |
| `docs/file_map.md` | Карта важных файлов |
| `docs/generated_file_tree.md` | Сжатое дерево проекта |
| `docs/scenarios_handoff.md` | Передача контекста по разделу «Сценарии» в новый чат |

## Нельзя возвращать

```text
- старую тяжёлую страницу подписчиков;
- COUNT/MAX сообщений в списках;
- тяжёлые JOIN/подзапросы по сообщениям на каждую строку;
- runtime ALTER TABLE при открытии страниц;
- canvas-редактор внутрь основной страницы `scenarios.php`;
- двухколоночный вид списка сценариев с постоянной формой справа.
```
