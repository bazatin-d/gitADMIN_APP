# Модуль settings

Модуль отвечает за страницу `/admin.php?tab=settings` и сохранение системных настроек.

## Файлы

```text
admin_app/modules/settings/actions.php
admin_app/modules/settings/service.php
admin_app/modules/settings/repository.php
admin_app/modules/settings/pages/index.php
admin_app/modules/settings/module.php
```

## Что вынесено

- сохранение общих настроек;
- сохранение SMTP и шаблонов писем;
- страница настроек;
- очистка HTML-полей справки и инструкции URL Shortener.

## Что осталось совместимым

Файл `admin_app/lib/settings.php` пока остаётся общим библиотечным файлом, потому что его функции используются в разных частях проекта: письма, Bitrix24, публичный тест, короткие ссылки и layout.

## Таблица

```text
oca_settings
```

## POST-действия

```text
save_settings
save_email_settings
```
