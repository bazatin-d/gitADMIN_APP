# Модуль users

Модуль отвечает за пользователей админки:

- создание пользователя;
- редактирование ФИО, логина, роли и пароля;
- временная блокировка/разблокировка;
- удаление пользовательских записей;
- защита текущего пользователя и суперадмина от случайного удаления/блокировки.

Файлы:

```text
admin_app/modules/users/actions.php
admin_app/modules/users/service.php
admin_app/modules/users/repository.php
admin_app/modules/users/pages/index.php
admin_app/modules/users/module.php
```

Старый путь `admin_app/views/pages/users.php` оставлен как прокладка.

SQL не требуется: модуль использует существующую таблицу `oca_users`.
