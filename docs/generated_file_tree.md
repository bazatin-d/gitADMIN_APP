# Admin App — generated file tree, актуальная сжатая карта

Фиксация: v3.5.09.8.

```text
/
├─ admin.php
├─ config.php
├─ index.html
├─ telegram_bot_webhook.php
├─ tg_broadcast_cron_run.php
├─ tg_broadcast_cron_status.php
├─ admin_app/
│  ├─ bootstrap.php
│  ├─ views/
│  │  └─ layout.php
│  ├─ lib/
│  │  └─ settings.php
│  └─ modules/
│     ├─ telegram_bots/
│     │  ├─ module.php
│     │  ├─ actions.php
│     │  ├─ service.php
│     │  ├─ repository.php
│     │  ├─ telegram_api.php
│     │  ├─ cron_send_queue.php
│     │  └─ pages/
│     │     ├─ index.php
│     │     ├─ subscribers.php
│     │     ├─ subscriber.php
│     │     ├─ broadcasts.php
│     │     ├─ scenarios.php
│     │     └─ scenario_editor.php   # следующий шаг, не ломать список сценариев
│     ├─ users/
│     ├─ settings/
│     ├─ url_shortener/
│     ├─ access_vault/
│     ├─ notebooks/
│     └─ dictionaries/
├─ database/
│  └─ migrations/
│     └─ 2026_06_03_003_telegram_scenarios_foundation.sql
├─ docs/
│  ├─ project_graph.md
│  ├─ file_map.md
│  ├─ generated_file_tree.md
│  └─ scenarios_handoff.md
└─ telegram/
   ├─ access_vault_bot.php
   └─ broadcast_test_bot.php
```

## Активная ветка разработки

```text
admin_app/modules/telegram_bots/pages/scenarios.php
```

Текущий статус:

```text
- список сценариев строками;
- создание/редактирование в модальном окне;
- фильтры рабочих сценариев;
- отдельный архив;
- меню строки ⋯;
- multi-channel сценарии заложены;
- таблицы под блоки, карточки, кнопки, диплинки, прохождения и события заложены.
```
