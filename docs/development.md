# Development Rules

## 1. Новые функции

Новые функции добавлять только в модуль:

```text
admin_app/modules/{module}/
```

Не добавлять новую логику в:

```text
admin_app/actions/admin.php
admin_app/views/pages/*.php
```

---

## 2. Распределение кода

- `actions.php` — обработка POST-действий.
- `service.php` — бизнес-логика, валидация, сборка данных.
- `repository.php` — SQL и работа с БД.
- `pages/*.php` — HTML страницы.
- `module.php` — регистрация страницы, actions, assets.
- `assets/admin/modules/{module}/` — JS/CSS конкретного модуля.

---

## 3. Безопасность

Для всех POST-форм нужен CSRF-токен.

Если CSRF добавляется автоматически — не ломать автоподстановку. Если форма создаётся динамически — проверить, что токен добавляется.

Не хранить в документации:

- DB-пароли;
- SMTP-пароли;
- webhook Bitrix24;
- `ACCESS_VAULT_KEY`;
- любые токены.

---

## 4. Access Vault

Пароли в модуле «Доступы» нельзя хранить открытым текстом.

Используется:

```text
ACCESS_VAULT_KEY
```

в корневом `config.php`.

Ключ нельзя менять после начала работы с модулем, иначе старые пароли не расшифруются.

---

## 5. После крупного изменения

Обновить:

```text
docs/project_graph.md
docs/modules.md
docs/file_map.md
docs/database.md
```

Если добавлен новый модуль — добавить локальный файл:

```text
admin_app/modules/{module}/module.md
```

---

## 6. Перед задачей для ИИ

1. Обновить `docs/generated_file_tree.md` через `tools/build_project_graph.php`.
2. Прислать:
   - `docs/project_graph.md`
   - `docs/file_map.md`
   - `docs/generated_file_tree.md`
   - файлы нужного модуля по `file_map.md`

Не присылать весь проект, если задача явно относится к одному модулю.
