# Frontend assets

Публичные CSS/JS-файлы админки лежат в `assets/admin/`.

## Общие файлы

- `assets/admin/css/admin.css` — общие стили админки, графиков, мобильного меню и тёмной темы.
- `assets/admin/js/admin.js` — общие скрипты админки: тема, модальные окна, drawer-меню, свайпы, CSRF для POST-форм и PWA service worker.

## Модульные файлы

Модульные скрипты лежат рядом по смыслу, но в публичной папке:

- `assets/admin/modules/url_shortener/url_shortener.js`
- `assets/admin/modules/manual_input/manual_input.js`

Подключение модульных скриптов задаётся в `admin_app/modules/{module}/module.php` через ключ `assets_js`.

## Правило разработки

- Общий CSS/JS — только в `assets/admin/css/admin.css` и `assets/admin/js/admin.js`.
- JS конкретного модуля — в `assets/admin/modules/{module}/`.
- В `admin_app/modules/*/pages/*.php` не добавляем большие inline-скрипты. Допустимы только короткие PHP-конфиги для передачи данных в JS.
- Не кладём публичные assets внутрь `admin_app/`: этот каталог закрыт `.htaccess`.
