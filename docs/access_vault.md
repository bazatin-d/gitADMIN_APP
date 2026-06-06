# Модуль «Доступы»

## Разделы

- Наши сайты — `category=sites`
- Сервисы — `category=services`
- Соц. сети — `category=social`
- Почта — `category=email`
- Архив — `category=archive`

## Таблицы

- `oca_user_permissions` — универсальные права пользователей.
- `oca_access_groups` — произвольные группы внутри разделов.
- `oca_access_resources` — ресурсы: сайт, сервис, соцсеть, почтовый ящик.
- `oca_access_credentials` — логины/пароли к ресурсу.
- `oca_access_audit_log` — журнал действий.

## Права

- `access_vault.view` — просмотр.
- `access_vault.create` — добавление.
- `access_vault.edit` — редактирование.
- `access_vault.archive` — архивирование.
- `access_vault.restore` — восстановление.
- `access_vault.copy` — копирование.
- `access_vault.share` — отправка по SMTP и поделиться через системное меню.
- `access_vault.import_export` — импорт и экспорт CSV.
- `access_vault.audit` — журнал действий.

Администраторы имеют полный доступ по умолчанию. Менеджерам права включаются в карточке пользователя.

## Ключ шифрования

В `config.php` нужно добавить:

```php
define('ACCESS_VAULT_KEY', 'сюда_длинный_случайный_ключ_минимум_32_символа');
```

Ключ нельзя терять: без него сохранённые пароли не расшифровать.

## CSV

Колонки импорта/экспорта:

```text
category;group;title;url;resource_comment;login;password;credential_comment;status
```

Экспорт содержит расшифрованные пароли, поэтому доступ к нему закрыт отдельным правом.


## Индивидуальная видимость доступов

Таблица: `oca_access_credential_users`.

Логика:
- если для доступа нет записей в `oca_access_credential_users`, доступ виден всем пользователям, у кого есть право просмотра модуля;
- если записи есть, доступ видят только выбранные пользователи, администраторы и суперадминистраторы;
- серверная фильтрация выполняется в `repository.php`, а не только в интерфейсе.

Право в карточке пользователя:

```text
access_vault.individual_access
```

## Оплата ресурсов и уведомления

Таблицы:
- `oca_access_resource_payments` — дата оплаты, дни напоминаний, повтор, сообщение;
- `oca_access_payment_recipients` — получатели;
- `oca_access_payment_notifications` — журнал отправки, чтобы не было дублей.

Каналы уведомлений:
- Telegram — только если у сотрудника заполнен `oca_users.telegram_chat_id`;
- email — если логин пользователя похож на email и настроен SMTP.

Список получателей в окне оплаты показывает только сотрудников с подключённым Telegram. Email при этом отправляется дополнительно тем же получателям.

Telegram-привязка хранится в `oca_users`:
- `telegram_chat_id`;
- `telegram_username`;
- `telegram_bind_token`;
- `telegram_bound_at`.

## Правило сохранения вёрстки

Компактная вёрстка модуля находится в:

```text
admin_app/modules/access_vault/pages/index.php
assets/admin/modules/access_vault/access_vault.css
```

При исправлении SQL, Telegram, email, cron или прав эти файлы не менять.
