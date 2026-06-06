<?php
defined('ASR_ADMIN') || exit;
$asrHelpVideoKey = 'help_video_' . (asr_current_role() === 'superadmin' ? 'admin' : asr_current_role());
$asrHelpVideo = trim((string)asr_get_setting($asrHelpVideoKey, ''));
?>
<style>
/* Modal icons cleanup */
.modal-panel button img[src*="/assets/admin/icons/mo2-close-gray.svg"]{
  opacity:.9;
  transition:opacity .15s ease, transform .15s ease;
}
.modal-panel button:hover img[src*="/assets/admin/icons/mo2-close-gray.svg"]{
  opacity:1;
  transform:scale(1.04);
}
</style>
    <!-- Модальное окно справки -->
    <div id="helpModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-5xl h-[85vh] overflow-hidden flex flex-col font-bold relative">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 shrink-0">
                <h3 style="color: #FFA048;" class="font-black text-gray-800 uppercase tracking-widest text-sm">Справочная информация</h3>
                <button onclick="document.getElementById('helpModal').classList.add('hidden')" class="text-gray-400 hover:text-red-500 transition-colors">
                    <img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true" style="width:22px;height:22px;display:block;object-fit:contain;">
                </button>
            </div>
            <div class="flex-1 w-full bg-gray-50 overflow-y-auto p-6 help-content text-gray-700 font-semibold leading-relaxed">
                <?php if ($asrHelpVideo !== ''): ?><div class="help-video-block"><?php echo $asrHelpVideo; ?></div><?php endif; ?>
                <?php echo asr_get_setting('help_content'); ?>
            </div>
        </div>
    </div>

    <!-- Модальное окно редактирования результатов теста -->
    <div id="editModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[calc(100vh-24px)] overflow-hidden font-bold my-3 sm:my-4 flex flex-col">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h3 style="color: #FFA048;" class="font-black text-gray-800 uppercase tracking-widest text-sm">Данные анкеты</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-red-500"><img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true" style="width:22px;height:22px;display:block;object-fit:contain;"></button>
            </div>
            <form method="POST" class="modal-form p-8 space-y-4">
                <input type="hidden" name="action" value="edit_result"><input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="current_page" value="<?php echo $page; ?>"><input type="hidden" name="search_query" value="<?php echo htmlspecialchars($search); ?>">
                <div class="mobile-grid-1 grid grid-cols-2 gap-4">
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">ФИО</label><input type="text" name="name" id="edit_name" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Телефон</label><input type="text" name="phone" id="edit_phone" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Email</label><input type="email" name="email" id="edit_email" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Город</label><input type="text" name="city" id="edit_city" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div class="md:col-span-2"><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Роль</label><input type="text" name="role" id="edit_role" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Сделка CRM</label><input type="text" name="crm_deal" id="edit_crm_deal" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                    <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Контакт CRM</label><input type="text" name="crm_contact" id="edit_crm_contact" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeEditModal()" class="px-6 py-3 text-xs font-bold uppercase text-gray-400">Отмена</button>
                    <button type="submit" class="px-8 py-3 bg-[#ffa048] text-white font-bold rounded-xl uppercase text-xs">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <!-- Модальное окно редактирования сотрудника -->
    <div id="editUserModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-start justify-center p-3 sm:p-4 overflow-y-auto">
        <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden font-bold">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h3 style="color: #FFA048;" class="font-black text-gray-800 uppercase tracking-widest text-sm">Изменить сотрудника</h3>
                <button onclick="closeEditUserModal()" class="text-gray-400 hover:text-red-500"><img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true" style="width:22px;height:22px;display:block;object-fit:contain;"></button>
            </div>
            <form method="POST" class="modal-form p-5 md:p-6 space-y-4 overflow-y-auto">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">ФИО</label><input type="text" name="full_name" id="edit_user_fullname" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Логин</label><input type="text" name="username" id="edit_user_username" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none font-bold text-gray-700"></div>
                <div><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Новый пароль (оставьте пустым, если не меняете)</label><input type="password" name="new_password" id="edit_user_new_password" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none text-sm font-bold text-gray-700"><p class="mt-1 text-[11px] text-gray-400 font-semibold">Если задать новый пароль, он появится в сообщении «Поделиться доступами».</p></div>
                <div id="editUserTelegramWrap" class="rounded-2xl border border-gray-100 bg-gray-50 px-4 py-4">
                    <div class="text-[10px] font-bold text-[#c96f2b] uppercase tracking-widest">Telegram-оповещения</div>
                    <div id="editUserTelegramStatus" class="mt-2 text-sm font-semibold text-gray-600">Статус не определён</div>
                    <div id="editUserTelegramLinkBox" class="mt-3 hidden">
                        <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Ссылка для подключения</label>
                        <div class="flex gap-2">
                            <input type="text" id="editUserTelegramLink" readonly class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none text-xs font-semibold text-gray-600 bg-white">
                            <button type="button" id="editUserTelegramCopyBtn" class="px-4 py-2.5 rounded-xl bg-[#FFA048] text-white text-xs font-bold uppercase">Копировать</button>
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400 font-semibold">Скопируйте ссылку и отправьте сотруднику. После перехода в бот и нажатия Start система привяжет Telegram автоматически.</p>
                    </div>
                    <p id="editUserTelegramNoLink" class="mt-2 text-[11px] text-gray-400 font-semibold hidden">Ссылка недоступна. Проверьте username бота оповещений в настройках.</p>
                </div>
                <div id="editUserBroadcastTestTelegramWrap" class="rounded-2xl border border-orange-100 bg-orange-50/30 px-4 py-4">
                    <div class="text-[10px] font-bold text-[#c96f2b] uppercase tracking-widest">Тестовый бот рассылок</div>
                    <div id="editUserBroadcastTestTelegramStatus" class="mt-2 text-sm font-semibold text-gray-600">Статус не определён</div>
                    <div id="editUserBroadcastTestTelegramLinkBox" class="mt-3 hidden">
                        <label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Ссылка для подключения к тестовым рассылкам</label>
                        <div class="flex gap-2">
                            <input type="text" id="editUserBroadcastTestTelegramLink" readonly class="w-full px-4 py-2.5 rounded-xl border border-gray-200 outline-none text-xs font-semibold text-gray-600 bg-white">
                            <button type="button" id="editUserBroadcastTestTelegramCopyBtn" class="px-4 py-2.5 rounded-xl bg-[#FFA048] text-white text-xs font-bold uppercase">Копировать</button>
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400 font-semibold">Этот бот нужен только для проверки рассылок перед отправкой реальным подписчикам.</p>
                    </div>
                    <p id="editUserBroadcastTestTelegramNoLink" class="mt-2 text-[11px] text-gray-400 font-semibold hidden">Ссылка недоступна. Проверьте username технического бота рассылок в настройках.</p>
                    <div id="editUserBroadcastTestOptions" class="mt-4 space-y-2 hidden">
                        <label class="flex items-start gap-3 rounded-xl bg-white border border-orange-100 px-3 py-2 text-xs font-semibold text-gray-600">
                            <input type="checkbox" name="telegram_broadcast_test_receive_broadcasts" id="edit_user_broadcast_test_receive_broadcasts" value="1" class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[#FFA048]">
                            <span><span class="block text-gray-800">Получать тестовую рассылку</span><span class="block mt-1 text-[11px] text-gray-400">На этот бот будут приходить проверки перед реальной отправкой.</span></span>
                        </label>
                        <label class="flex items-start gap-3 rounded-xl bg-white border border-orange-100 px-3 py-2 text-xs font-semibold text-gray-600">
                            <input type="checkbox" name="telegram_broadcast_test_notify_dialogs" id="edit_user_broadcast_test_notify_dialogs" value="1" class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[#FFA048]">
                            <span><span class="block text-gray-800">Получать уведомление о новом диалоге</span><span class="block mt-1 text-[11px] text-gray-400">При входящем сообщении бот пришлёт уведомление с кнопками «Закрыть» и «В спам».</span></span>
                        </label>
                    </div>
                </div>
                <div id="editUserRoleWrap" class="edit-user-role-wrap"><label class="text-[10px] font-bold text-gray-400 uppercase block mb-1">Роль</label>
                    <select name="role" id="edit_user_role" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm bg-white font-bold text-gray-600">
                        <option value="operator">Оператор</option>
                        <option value="manager">Менеджер</option>
                        <option value="admin">Администратор</option>
                    </select>
                </div>
                <label id="editUserRememberWrap" class="flex items-start gap-3 rounded-2xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm font-bold text-gray-700">
                    <input type="checkbox" name="remember_365_days" id="edit_user_remember_365_days" value="1" class="mt-1 w-4 h-4 rounded border-gray-300 text-[#FFA048]">
                    <span>
                        <span class="block text-gray-800">Не выходить из системы</span>
                        <span class="block mt-1 text-[11px] text-gray-400 font-semibold">Сессия этого пользователя будет сохраняться до 365 дней на устройстве, где он вошёл.</span>
                    </span>
                </label>
                <label id="editUserDialogsWrap" class="flex items-start gap-3 rounded-2xl border border-orange-100 bg-orange-50/40 px-4 py-3 text-sm font-bold text-gray-700">
                    <input type="checkbox" name="connect_to_dialogs" id="edit_user_connect_to_dialogs" value="1" class="mt-1 w-4 h-4 rounded border-gray-300 text-[#FFA048]">
                    <span>
                        <span class="block text-gray-800">Подключать к диалогам</span>
                        <span class="block mt-1 text-[11px] text-gray-400 font-semibold">Сотрудник будет доступен в списке назначения диалогов.</span>
                    </span>
                </label>
                <div id="editUserPermissionsWrap" class="hidden rounded-2xl border border-orange-100 bg-orange-50/40 p-4 space-y-3">
                    <div class="text-[10px] font-black text-[#FFA048] uppercase tracking-widest">Права доступа к модулям</div>
                    <?php if (function_exists('asr_known_module_permissions')): ?>
                        <?php foreach (asr_known_module_permissions() as $module): ?>
                            <div class="space-y-2">
                                <div class="text-xs font-black text-gray-700 uppercase"><?php echo htmlspecialchars((string)($module['title'] ?? 'Модуль')); ?></div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach (($module['permissions'] ?? []) as $permKey => $permLabel): ?>
                                        <label class="flex items-center gap-2 rounded-xl bg-white border border-orange-100 px-3 py-2 text-xs font-bold text-gray-600">
                                            <input type="checkbox" name="access_permissions[]" value="<?php echo htmlspecialchars((string)$permKey); ?>" class="edit-user-permission rounded border-gray-300 text-[#FFA048]">
                                            <span><?php echo htmlspecialchars((string)$permLabel); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <p class="text-[11px] text-gray-400 font-semibold">Блок показывается только для ролей «Администратор» и «Менеджер». Администраторы всё равно имеют полный доступ, но галочки фиксируются для порядка.</p>
                </div>
                <p id="editUserProtectedNotice" class="hidden text-xs font-bold text-gray-400">У суперадмина роль не меняется. Супергерою плащ не перешиваем.</p>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeEditUserModal()" class="px-6 py-3 text-xs font-bold uppercase text-gray-400">Отмена</button>
                    <button type="submit" class="px-8 py-3 bg-[#FFA048] text-white font-bold rounded-xl uppercase text-xs">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>


    <!-- Модальное окно: поделиться доступами -->
    <div id="shareAccessModal" class="hidden fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="modal-panel bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden font-bold">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h3 style="color: #FFA048;" class="font-black text-gray-800 uppercase tracking-widest text-sm">Поделиться доступами</h3>
                <button onclick="closeShareAccessModal()" class="text-gray-400 hover:text-red-500"><img src="/assets/admin/icons/mo2-close-gray.svg" alt="" aria-hidden="true" style="width:22px;height:22px;display:block;object-fit:contain;"></button>
            </div>
            <div class="p-8 space-y-5">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Текст сообщения</div>
                    <textarea id="shareAccessText" rows="10" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none font-mono text-sm font-semibold text-gray-700"></textarea>
                    <p class="mt-2 text-xs text-gray-400 font-semibold">Текст можно поправить перед отправкой. Если пароль не сохранён, задайте новый пароль в карточке пользователя — после этого {{password}} подставится автоматически.</p>
                    <button id="shareAccessSetPasswordBtn" type="button" onclick="openSharePasswordReset()" class="hidden mt-3 px-4 py-2 rounded-xl bg-yellow-50 text-yellow-700 text-[11px] font-black uppercase tracking-widest">Задать новый пароль</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <button type="button" onclick="shareAccessByMail()" class="px-4 py-3 rounded-xl bg-[#FFA048] text-white text-xs font-black uppercase tracking-widest">Почта</button>
                    <button type="button" onclick="shareAccessByNative()" class="px-4 py-3 rounded-xl bg-gray-100 text-gray-600 text-xs font-black uppercase tracking-widest">Мессенджер</button>
                    <button type="button" onclick="copyShareAccessText()" class="px-4 py-3 rounded-xl bg-orange-50 text-[#FFA048] text-xs font-black uppercase tracking-widest">Скопировать текст</button>
                </div>
            </div>
        </div>
    </div>
