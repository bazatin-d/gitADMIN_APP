<?php defined('ASR_ADMIN') || exit; ?>
<div class="space-y-6">
<style>
.rs2-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;object-fit:contain;opacity:.95;flex:0 0 auto}
.rs2-icon-sm{width:16px;height:16px}
.rs2-icon-lg{width:32px;height:32px}
.rs2-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;line-height:1!important;white-space:nowrap}
.rs2-icon-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important}
.rs2-import-card-icon{width:56px!important;height:56px!important;border-radius:18px!important;background:#FFA048!important;box-shadow:0 12px 24px rgba(255,160,72,.18)!important}
.rs2-import-card-icon .rs2-icon{width:30px!important;height:30px!important;opacity:1!important}
.rs2-meta{display:inline-flex;align-items:center;gap:4px}
</style>
<style>
/* rs2 button icon alignment correction */
.rs2-btn{
  display:inline-flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:center!important;
  gap:7px!important;
  line-height:1!important;
  white-space:nowrap!important;
  vertical-align:middle!important;
}
.rs2-toolbar-btn{
  min-height:42px!important;
  height:42px!important;
  padding-top:0!important;
  padding-bottom:0!important;
}
.rs2-btn .rs2-icon,
.rs2-btn .rs2-icon-sm{
  width:14px!important;
  height:14px!important;
  display:inline-block!important;
  flex:0 0 14px!important;
}
.rs2-btn .rs2-icon-lg{
  width:18px!important;
  height:18px!important;
  flex:0 0 18px!important;
}
@media(max-width:768px){
  .rs2-toolbar-btn{
    min-height:40px!important;
    height:40px!important;
  }
  .rs2-btn{
    gap:6px!important;
  }
  .rs2-btn .rs2-icon,
  .rs2-btn .rs2-icon-sm{
    width:13px!important;
    height:13px!important;
    flex-basis:13px!important;
  }
}
</style>
    <?php if (isset($_GET['imported'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 rounded-2xl p-4 text-sm font-bold">
            Импорт завершён. Добавлено строк: <?php echo (int)$_GET['imported']; ?>. Пропущено: <?php echo (int)($_GET['skipped'] ?? 0); ?>.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['import_error'])): ?>
        <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-bold">
            <?php echo htmlspecialchars((string)$_GET['import_error']); ?>
        </div>
    <?php endif; ?>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
        <h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Импорт/экспорт</h3>
        <p class="text-sm text-gray-400 font-bold mt-1">
            Экспорт и импорт работают в одном формате CSV. Можно выгрузить текущие результаты, заполнить образец новыми строками и импортировать обратно.
        </p>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 space-y-4">
            <div class="w-12 h-12 rounded-2xl bg-green-50 text-green-600 flex items-center justify-center">
                <img class="rs2-icon rs2-icon-lg" src="/assets/admin/icons/rs2-download-white.svg" alt="" aria-hidden="true">
            </div>
            <h4 class="text-base font-black text-gray-800 uppercase">Экспорт результатов</h4>
            <p class="text-sm text-gray-500 font-bold">CSV в UTF-8 с BOM. Порядок столбцов такой же, как в файле импорта.</p>
            <a href="admin.php?action=export_results" class="rs2-btn inline-flex px-5 py-3 bg-[#FFA048] text-white rounded-xl text-xs font-black uppercase tracking-widest"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-download-white.svg" alt="" aria-hidden="true">Скачать CSV</a>
        </div>

        <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 space-y-4">
            <div class="w-12 h-12 rounded-2xl bg-orange-50 text-orange-500 flex items-center justify-center">
                <img class="rs2-icon rs2-icon-lg" src="/assets/admin/icons/rs2-upload-white.svg" alt="" aria-hidden="true">
            </div>
            <h4 class="text-base font-black text-gray-800 uppercase">Импорт результатов</h4>
            <p class="text-sm text-gray-500 font-bold">Загрузите CSV в том же формате. Для графика нужны ровно 10 чисел A–J через запятую.</p>
            <form method="post" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="import_results_csv">
                <input type="file" name="results_csv" accept=".csv,text/csv" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-sm font-bold text-gray-600">
                <button type="submit" class="rs2-btn inline-flex px-5 py-3 bg-[#FFA048] text-white rounded-xl text-xs font-black uppercase tracking-widest"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-upload-white.svg" alt="" aria-hidden="true">Импортировать CSV</button>
            </form>
        </div>
    </section>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
        <div>
            <h4 class="text-base font-black text-gray-800 uppercase">Образец файла для импорта</h4>
            <p class="text-sm text-gray-500 font-bold mt-1">
                Скачайте шаблон, заполните новые строки и загрузите его через импорт. Главное — не менять названия столбцов, чтобы CSV не устроил квест «угадай колонку».
            </p>
        </div>
        <a href="admin.php?action=download_import_sample" class="inline-flex justify-center px-5 py-3 bg-gray-900 text-white rounded-xl text-xs font-black uppercase tracking-widest whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-file-white.svg" alt="" aria-hidden="true">Скачать образец CSV</a>
    </section>

    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-gray-100">
            <h4 class="text-base font-black text-gray-800 uppercase">Формат CSV</h4>
            <p class="text-sm text-gray-500 font-bold mt-1">Импорт и экспорт используют одинаковый порядок столбцов.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 text-gray-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-5 py-3">№</th>
                        <th class="px-5 py-3">Столбец</th>
                        <th class="px-5 py-3">Что писать</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-600 font-bold">
                    <?php
                    $rows = [
                        ['Дата заполнения', 'Например: 2026-05-26 13:30:00 или 26.05.2026 13:30'],
                        ['Статус заполнения', 'completed или in_progress. Если пусто — будет completed.'],
                        ['ФИО', 'Имя респондента.'],
                        ['Телефон', 'Телефон респондента.'],
                        ['Email', 'Email респондента.'],
                        ['Город', 'Город из формы начала теста.'],
                        ['Пол', 'Мужской / Женский.'],
                        ['Возраст', 'Старше 18 лет / Младше 18 лет.'],
                        ['Роль', 'Роль или должность из формы начала теста.'],
                        ['Ответ на вопрос 197', '+, ? или -. Плюс включает облачко на точке B.'],
                        ['Ответ на вопрос 22', '+, ? или -. Плюс включает облачко на точке E.'],
                        ['Значения графика A-J', '10 чисел через запятую: 36, 16, -64, 30, -12, 8, -72, 48, 60, -46.'],
                        ['UTM-метка', 'Вся UTM-строка целиком.'],
                    ];
                    foreach ($rows as $i => $row):
                    ?>
                        <tr>
                            <td class="px-5 py-3 text-gray-400"><?php echo $i + 1; ?></td>
                            <td class="px-5 py-3 text-gray-800"><?php echo htmlspecialchars($row[0]); ?></td>
                            <td class="px-5 py-3"><?php echo htmlspecialchars($row[1]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
