# Database Map

В этот файл заносятся таблицы проекта и модули, которые их используют.

Не хранить здесь пароли, токены, webhook, `ACCESS_VAULT_KEY`.

---

## Основные таблицы

### `oca_results`

Модуль: `results`, `manual_input`, `import_export`, `bitrix24`  
Назначение: результаты тестов АСР.

### `oca_users`

Модуль: `users`  
Назначение: пользователи админки.

### `oca_settings`

Модуль: `settings`, `emails`, `bitrix24`  
Назначение: системные настройки, SMTP, шаблоны писем, настройки интеграций.

### `oca_short_urls`

Модуль: `url_shortener`  
Назначение: короткие ссылки, slug, целевые URL, счётчики переходов.

### `oca_utm_presets`

Модуль: `url_shortener`  
Назначение: справочник UTM-значений.

### `oca_admin_menu_items`

Модуль: `menu`  
Назначение: редактируемая структура меню админки.

### `oca_user_permissions`

Модуль: `users`, `access_vault`, будущие модули  
Назначение: универсальные права пользователей по ключам.

Примеры ключей:

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

---

## Access Vault

### `oca_access_groups`

Модуль: `access_vault`  
Назначение: группы внутри разделов «Наши сайты», «Сервисы», «Соц. сети», «Почта».

### `oca_access_resources`

Модуль: `access_vault`  
Назначение: ресурсы: сайт, сервис, соцсеть, почтовый адрес.

### `oca_access_credentials`

Модуль: `access_vault`  
Назначение: логины и зашифрованные пароли к ресурсам.

Важное:
- пароль хранится только в зашифрованном виде;
- расшифровка зависит от `ACCESS_VAULT_KEY`;
- ключ нельзя менять после начала использования.

### `oca_access_audit_log`

Модуль: `access_vault`  
Назначение: журнал действий.

Пароли в журнал не пишутся.

---

## Миграции

Миграции хранятся в:

```text
database/migrations/
```

Сиды/стартовые справочники:

```text
database/seeds/
```

Рекомендуемый формат имени:

```text
YYYY_MM_DD_XXX_description.sql
```
