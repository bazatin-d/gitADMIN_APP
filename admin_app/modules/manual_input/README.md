# Модуль manual_input

Модуль ручного ввода результатов АСР из админки.

## Файлы

- `actions.php` — обработка POST `manual_save_result`.
- `service.php` — валидация формы, расчёт графика по 200 ответам, обработка готовых A–J, ложные точки, синхронизация с Bitrix24.
- `repository.php` — запись результата в `oca_results`, обновление CRM-ссылок.
- `pages/index.php` — страница `/admin.php?tab=manual_input`.
- `module.php` — описание модуля.

## Таблицы

- `oca_results`

## Внешние зависимости

- `osaPHPavs.php` — функция расчёта `OcaCalk()`.
- `b24_integration/sync_test.php` — отправка результата в Bitrix24.
