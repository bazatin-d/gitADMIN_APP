# Module: access_vault

Назначение: корпоративное хранилище доступов.

## Страницы

- `/admin.php?tab=access_vault&category=sites` — Наши сайты
- `/admin.php?tab=access_vault&category=services` — Сервисы
- `/admin.php?tab=access_vault&category=social` — Соц. сети
- `/admin.php?tab=access_vault&category=email` — Почта
- `/admin.php?tab=access_vault&category=archive` — Архив
- `/admin.php?tab=access_vault&category=audit` — Журнал
- `/admin.php?tab=access_vault&category=import_export` — Импорт/экспорт

## Файлы

```text
admin_app/modules/access_vault/actions.php
admin_app/modules/access_vault/service.php
admin_app/modules/access_vault/repository.php
admin_app/modules/access_vault/crypto.php
admin_app/modules/access_vault/module.php
admin_app/modules/access_vault/pages/index.php
assets/admin/modules/access_vault/access_vault.js
```

## Таблицы

```text
oca_access_groups
oca_access_resources
oca_access_credentials
oca_access_audit_log
oca_user_permissions
```

## Actions

```text
av_create_group
av_update_group
av_delete_group
av_create_resource
av_update_resource
av_archive_resource
av_restore_resource
av_create_credential
av_update_credential
av_archive_credential
av_restore_credential
av_send_email
av_import_csv
av_export_csv
av_copy_credential
av_share_messenger
```

## Права

```text
access_vault.view
access_vault.create
access_vault.edit
access_vault.archive
access_vault.restore
access_vault.copy
access_vault.share
access_vault.import_export
access_vault.audit
```

## Зависимости

- `users` — пользователи и права.
- `emails` — отправка доступов по SMTP.
- `menu` — пункты меню.
- `core/permissions.php` — проверка прав.
- `config.php` — `ACCESS_VAULT_KEY`.

## Важное

Пароли хранятся только в зашифрованном виде.

Ключ `ACCESS_VAULT_KEY` нельзя менять после начала использования модуля.

Пароли не пишутся в журнал действий.
