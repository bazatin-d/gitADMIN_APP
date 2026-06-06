<?php
defined('ASR_ADMIN') || exit;
?>
            <style>.mi2-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;line-height:1!important}.mi2-icon{width:16px;height:16px;display:inline-block;vertical-align:middle;object-fit:contain;opacity:.95}</style>
<style>
/* mi2 button icon alignment correction */
.mi2-btn{
  display:inline-flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:center!important;
  gap:7px!important;
  line-height:1!important;
  white-space:nowrap!important;
}
.mi2-btn .mi2-icon{
  width:14px!important;
  height:14px!important;
  flex:0 0 14px!important;
}
</style>
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden text-left">
                <div class="p-6 border-b border-gray-50 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h3 style="color: #FFA048;" class="font-black uppercase tracking-widest text-sm">Ввод результата вручную</h3>
                        <p class="text-xs text-gray-400 font-semibold mt-1">
                            Для бумажных анкет: можно ввести все 200 ответов или сразу готовые показатели графика A–J.
                        </p>
                    </div>

                    <a href="admin.php?tab=results" class="mi2-btn px-5 py-2.5 bg-gray-100 text-gray-500 font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-gray-200 transition-all">
                        <img class="mi2-icon" src="/assets/admin/icons/rs2-back-gray.svg" alt="" aria-hidden="true"> К списку результатов
                    </a>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="mx-8 mt-6 p-4 bg-red-50 border border-red-100 text-red-600 rounded-2xl text-sm font-bold">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mobile-p-4 p-8 space-y-8" id="manualInputForm">
                    <input type="hidden" name="action" value="manual_save_result">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">ФИО</label>
                            <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Email</label>
                            <input type="email" name="email" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Телефон</label>
                            <input type="text" name="phone" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>



                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Город</label>
                            <input type="text" name="city" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Должность</label>
                            <input type="text" name="role" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['role'] ?? ''); ?>">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Дата заполнения</label>
                            <input type="date" name="created_date" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold" value="<?php echo htmlspecialchars($_POST['created_date'] ?? ''); ?>">
                            <p class="text-[10px] text-gray-400 font-semibold mt-1">Если не указать, будет текущая дата.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-2">Возраст</label>
                            <div class="flex gap-4 text-sm font-semibold text-gray-600">
                                <label><input type="radio" name="age" value="Старше 18 лет" <?php echo (($_POST['age'] ?? 'Старше 18 лет') === 'Старше 18 лет') ? 'checked' : ''; ?>> Старше 18</label>
                                <label><input type="radio" name="age" value="Младше 18 лет" <?php echo (($_POST['age'] ?? '') === 'Младше 18 лет') ? 'checked' : ''; ?>> Младше 18</label>
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase block mb-2">Пол</label>
                            <div class="flex gap-4 text-sm font-semibold text-gray-600">
                                <label><input type="radio" name="gender" value="Мужской" <?php echo (($_POST['gender'] ?? 'Мужской') === 'Мужской') ? 'checked' : ''; ?>> Мужской</label>
                                <label><input type="radio" name="gender" value="Женский" <?php echo (($_POST['gender'] ?? '') === 'Женский') ? 'checked' : ''; ?>> Женский</label>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-8">
                        <label class="text-[10px] font-bold text-gray-400 uppercase block mb-4">Способ ввода</label>

                        <?php $manualMode = $_POST['manual_mode'] ?? 'answers'; ?>
                        <div class="flex flex-col md:flex-row gap-4 text-sm font-bold text-gray-600">
                            <label class="flex items-center gap-2 bg-gray-50 rounded-xl px-5 py-4 border border-gray-100 cursor-pointer">
                                <input type="radio" name="manual_mode" value="answers" onchange="toggleManualMode()" <?php echo $manualMode === 'answers' ? 'checked' : ''; ?>>
                                Ввести ответы на 200 вопросов
                            </label>

                            <label class="flex items-center gap-2 bg-gray-50 rounded-xl px-5 py-4 border border-gray-100 cursor-pointer">
                                <input type="radio" name="manual_mode" value="scores" onchange="toggleManualMode()" <?php echo $manualMode === 'scores' ? 'checked' : ''; ?>>
                                Ввести готовые показатели A–J
                            </label>
                        </div>
                    </div>

                    <div id="manualAnswersBlock" class="space-y-5">
                        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
                            <div>
                                <h4 class="font-black text-gray-700 uppercase tracking-widest text-xs">Ответы на 200 вопросов</h4>
                                <p class="text-[11px] text-gray-400 font-semibold mt-1">Можно пользоваться клавиатурой: 1 = «+», 2 = «?», 3 = «-». После выбора курсор переходит к следующему вопросу.</p>
                            </div>
                            <button type="button" onclick="clearManualAnswers()" class="px-4 py-2 bg-gray-100 text-gray-500 font-bold text-[10px] uppercase tracking-widest rounded-xl hover:bg-gray-200">Очистить ответы</button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                            <?php for($i = 1; $i <= 200; $i++): $savedAnswer = $_POST['answers'][$i] ?? ''; ?>
                                <div class="manual-answer-row flex items-center gap-2 bg-gray-50 rounded-xl px-3 py-2 border border-gray-100" data-question="<?php echo $i; ?>">
                                    <span class="w-8 text-xs font-black text-gray-400"><?php echo $i; ?>.</span>
                                    <label class="text-xs font-bold"><input type="radio" name="answers[<?php echo $i; ?>]" value="+" <?php echo $savedAnswer === '+' ? 'checked' : ''; ?>> +</label>
                                    <label class="text-xs font-bold"><input type="radio" name="answers[<?php echo $i; ?>]" value="?" <?php echo $savedAnswer === '?' ? 'checked' : ''; ?>> ?</label>
                                    <label class="text-xs font-bold"><input type="radio" name="answers[<?php echo $i; ?>]" value="-" <?php echo $savedAnswer === '-' ? 'checked' : ''; ?>> -</label>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="manualScoresBlock" class="hidden space-y-5">

    <h4 class="font-black text-gray-700 uppercase tracking-widest text-xs">
        Показатели графика
    </h4>

    <div class="grid grid-cols-2 md:grid-cols-5 lg:grid-cols-10 gap-4">
        <?php foreach(['A','B','C','D','E','F','G','H','I','J'] as $letter): ?>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">
                    <?php echo $letter; ?>
                </label>

                <input
                    type="number"
                    name="scores[<?php echo $letter; ?>]"
                    class="w-full px-3 py-3 rounded-xl border border-gray-200 outline-none text-sm font-bold"
                >
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 space-y-4">

        <div>
            <h5 class="font-black text-xs uppercase tracking-widest text-amber-700">
                Ложные точки (маники)
            </h5>

            <p class="text-xs text-amber-800 mt-2 leading-relaxed">
                Используется только при ручном вводе готовых значений A–J.<br>
                Если в бумажном тесте были ответы, создающие ложные точки,
                отметьте соответствующий чекбокс.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <label class="flex items-start gap-3 bg-white border border-amber-200 rounded-xl p-4 cursor-pointer">
                <input
                    type="checkbox"
                    name="false_points[]"
                    value="B"
                    class="mt-1"
                >

                <div>
                    <div class="font-bold text-sm text-gray-800">
                        Ложная точка B
                    </div>

                    <div class="text-xs text-gray-500 mt-1">
                        Ставится, если на вопрос №197 выбран ответ «+»
                    </div>
                </div>
            </label>

            <label class="flex items-start gap-3 bg-white border border-amber-200 rounded-xl p-4 cursor-pointer">
                <input
                    type="checkbox"
                    name="false_points[]"
                    value="E"
                    class="mt-1"
                >

                <div>
                    <div class="font-bold text-sm text-gray-800">
                        Ложная точка E
                    </div>

                    <div class="text-xs text-gray-500 mt-1">
                        Ставится, если на вопрос №22 выбран ответ «+»
                    </div>
                </div>
            </label>

        </div>

    </div>

</div>

                    <div class="pt-4 flex justify-end">
                        <button type="submit" class="px-8 py-4 bg-[#FFA048] text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-lg hover:bg-[#f28d35] transition-all">
                            Сохранить результат
                        </button>
                    </div>
                </form>
            </div>
