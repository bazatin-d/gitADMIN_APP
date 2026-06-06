# Модуль import_export

Отвечает за импорт и экспорт результатов АСР в CSV.

## Файлы

- `actions.php` — обработка скачивания CSV, образца и загрузки CSV.
- `service.php` — формат CSV, нормализация дат, графика и ответов.
- `pages/index.php` — страница `/admin.php?tab=import_export`.
- `repository.php` — место для будущего SQL-слоя.

## Действия

- `GET action=export_results`
- `GET action=download_import_sample`
- `POST action=import_results_csv`
