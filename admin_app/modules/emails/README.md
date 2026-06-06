# Модуль emails

Модуль отвечает за страницу «Письма и SMTP» и сохранение почтовых настроек.

## Файлы

- `actions.php` — обработка `save_email_settings`.
- `service.php` — список почтовых ключей и сбор данных формы.
- `repository.php` — сохранение настроек через общий слой `admin_app/lib/settings.php`.
- `pages/index.php` — страница `/admin.php?tab=emails`.
- `module.php` — описание модуля.

## Таблицы

Использует общую таблицу настроек через `asr_save_settings()` и `asr_get_all_settings()`.

## Проверка

Открыть `/admin.php?tab=emails`, изменить любое тестовое значение и сохранить.
