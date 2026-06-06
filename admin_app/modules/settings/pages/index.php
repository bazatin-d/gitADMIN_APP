<?php
defined('ASR_ADMIN') || exit;
$settings = asr_get_all_settings();
$canManageAccessTelegramNotifications = function_exists('asr_is_protected_user')
    ? asr_is_protected_user(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => function_exists('asr_current_role') ? asr_current_role() : ''])
    : ((function_exists('asr_current_role') ? asr_current_role() : '') === 'superadmin');
$baseUrl = asr_current_base_url();
$broadcastTestBotConnectedCount = 0;
try {
    $hasBroadcastTestChat = function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'telegram_broadcast_test_chat_id')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'telegram_broadcast_test_chat_id'));
    if ($hasBroadcastTestChat) {
        $broadcastTestBotConnectedCount = (int)$pdo->query("SELECT COUNT(*) FROM oca_users WHERE COALESCE(telegram_broadcast_test_chat_id, '') <> '' AND (archived_at IS NULL OR archived_at = '')")->fetchColumn();
    }
} catch (Throwable $e) {
    try {
        $broadcastTestBotConnectedCount = (int)$pdo->query("SELECT COUNT(*) FROM oca_users WHERE COALESCE(telegram_broadcast_test_chat_id, '') <> ''")->fetchColumn();
    } catch (Throwable $e2) {
        $broadcastTestBotConnectedCount = 0;
    }
}
$testUrl = rtrim($baseUrl, '/') . '/index.html';
$testUrlEsc = htmlspecialchars($testUrl, ENT_QUOTES, 'UTF-8');
$iframeCode = <<<HTML
<div style="width: 100%; overflow: hidden; -webkit-overflow-scrolling: touch;">
    <iframe 
        src="{$testUrlEsc}" 
        style="width: 1px; min-width: 100%; border: none; min-height: 900px;" 
        id="ocaTestFrame"
        scrolling="no">
    </iframe>
</div>

<script>
    (function() {
        const currentSearch = window.location.search;
        
        if (currentSearch) {
            const frame = document.getElementById('ocaTestFrame');
            const baseUrl = frame.src.split('?')[0];
            frame.src = baseUrl + currentSearch;
        }

        window.addEventListener('message', function(e) {
            if (e.data === 'scrollToTop') {
                window.scrollTo({
                    top: document.getElementById('ocaTestFrame').offsetTop - 100,
                    behavior: 'smooth'
                });
            }
        }, false);
    })();
</script>
HTML;
?>
<div class="space-y-6 asr-settings-page">
<style>
    .st2-title-wrap{display:flex;align-items:flex-start;gap:14px}
    .st2-section-icon{width:32px;height:32px;flex:0 0 32px;margin-top:1px;opacity:.9}
    .st2-toggle-icon{width:22px;height:22px;display:block;opacity:.75}
    .st2-toggle-closed,.st2-toggle-open{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:14px;border:1px solid #e8edf3;background:#f8fafc}
    .st2-toggle-open{background:#fff7ed;border-color:#fed7aa}
    details[open] .st2-toggle-closed{display:none}
    details:not([open]) .st2-toggle-open{display:none}
    .st2-btn-icon{width:20px;height:20px;display:inline-block;vertical-align:middle;object-fit:contain;opacity:.9}
    .st2-editor-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:7px!important;min-height:38px}
    .st2-icon-only{width:38px;min-width:38px;padding-left:0!important;padding-right:0!important}
    @media(max-width:768px){
        .st2-title-wrap{gap:10px}
        .st2-section-icon{width:28px;height:28px;flex-basis:28px}
        .st2-toggle-closed,.st2-toggle-open{width:38px;height:38px}
    }
</style>
<style>
/* st2 button icon alignment correction */
.st2-editor-btn{
  display:inline-flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:center!important;
  gap:7px!important;
  line-height:1!important;
  white-space:nowrap!important;
  vertical-align:middle!important;
}
.st2-editor-btn .st2-btn-icon{
  width:14px!important;
  height:14px!important;
  display:inline-block!important;
  flex:0 0 14px!important;
}
.st2-icon-only .st2-btn-icon{
  width:18px!important;
  height:18px!important;
  flex-basis:18px!important;
}
</style>
    <?php if (!empty($_GET['saved'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Настройки сохранены.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['mail_saved'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Настройки писем сохранены.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['settings_error'])): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['settings_error']); ?></div>
    <?php endif; ?>
    <?php if (($_GET['tg_btest_webhook'] ?? '') === 'ok'): ?>
        <div class="bg-orange-50 border border-orange-100 text-[#b85f1f] px-5 py-4 rounded-2xl text-sm font-bold">Webhook технического бота рассылок установлен. Теперь откройте персональную ссылку в карточке сотрудника и нажмите Start.</div>
    <?php endif; ?>
    <?php if (!asr_settings_table_exists($pdo)): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold">
            Таблица настроек пока не создана. Выполните SQL из файла <code>migration_admin_settings.sql</code>, иначе форма будет показывать значения по умолчанию, но не сможет их сохранить.
        </div>
    <?php endif; ?>

    <?php
    // Блок редактирования меню вынесен в отдельный модуль.
    
    $asrMenuSettingsBlock = __DIR__ . '/../../menu/pages/settings_block.php';
    if (is_file($asrMenuSettingsBlock)) {
        require $asrMenuSettingsBlock;
    }

    ?>



    <form method="POST" id="settingsForm" class="space-y-6">
        <input type="hidden" name="action" value="save_settings">


        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-settings-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Название системы и приложения</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Title страницы, имя устанавливаемого приложения и заголовок в шапке админки.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Title страницы</span><input name="app_html_title" value="<?php echo htmlspecialchars($settings['app_html_title'] ?? 'Система тестирования АВМ'); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Название приложения</span><input name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? 'АСР АВМ'); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Заголовок в шапке</span><input name="app_header_title" value="<?php echo htmlspecialchars($settings['app_header_title'] ?? 'СИСТЕМА ТЕСТИРОВАНИЯ АВМ'); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
            </div>
        </details>




        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-telegram-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Telegram-бот</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Токены технических ботов: оповещения «Доступов» и тестовые рассылки перед отправкой подписчикам.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block md:col-span-2"><span class="text-[10px] uppercase text-gray-400 font-black">Токен Telegram-бота</span><input type="password" name="telegram_bot_token" value="<?php echo htmlspecialchars($settings['telegram_bot_token'] ?? ''); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold" autocomplete="new-password"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Username бота без @</span><input name="telegram_bot_username" value="<?php echo htmlspecialchars($settings['telegram_bot_username'] ?? ''); ?>" placeholder="my_company_bot" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Секрет вебхука</span><input name="telegram_webhook_secret" value="<?php echo htmlspecialchars($settings['telegram_webhook_secret'] ?? ''); ?>" placeholder="длинная случайная строка" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <div class="md:col-span-2 rounded-2xl bg-gray-50 border border-gray-100 p-4 text-sm text-gray-500 font-semibold leading-relaxed">
                    <div class="text-[10px] uppercase tracking-widest text-gray-400 font-black mb-2">Бот оповещений «Доступы»</div>
                    После сохранения настроек вебхук можно установить на адрес:<br>
                    <code><?php echo htmlspecialchars(rtrim($baseUrl, '/') . '/telegram/access_vault_bot.php?secret=' . ($settings['telegram_webhook_secret'] ?? '')); ?></code>
                    <br>Для первого запуска сотрудник должен открыть персональную ссылку привязки в своём профиле пользователя.
                </div>

                <div class="md:col-span-2 mt-2 pt-5 border-t border-gray-100">
                    <div class="text-[10px] uppercase tracking-widest text-[#c96f2b] font-black">Технический бот для проверки рассылок</div>
                    <p class="mt-1 text-xs font-semibold text-gray-400">В этот отдельный бот будут уходить тестовые сообщения с последнего шага подготовки рассылки. Реальным подписчикам тест не отправляется.</p>
                </div>
                <label class="block md:col-span-2"><span class="text-[10px] uppercase text-gray-400 font-black">Токен технического бота рассылок</span><input type="password" name="telegram_broadcast_test_bot_token" value="<?php echo htmlspecialchars($settings['telegram_broadcast_test_bot_token'] ?? ''); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold" autocomplete="new-password"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Username технического бота без @</span><input name="telegram_broadcast_test_bot_username" value="<?php echo htmlspecialchars($settings['telegram_broadcast_test_bot_username'] ?? ''); ?>" placeholder="my_broadcast_test_bot" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Секрет вебхука технического бота</span><input name="telegram_broadcast_test_webhook_secret" value="<?php echo htmlspecialchars($settings['telegram_broadcast_test_webhook_secret'] ?? ''); ?>" placeholder="длинная случайная строка" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <div class="md:col-span-2 rounded-2xl bg-orange-50/40 border border-orange-100 p-4 text-sm text-gray-600 font-semibold leading-relaxed">
                    <div class="text-[10px] uppercase tracking-widest text-[#c96f2b] font-black mb-2">Webhook технического бота рассылок</div>
                    <code><?php echo htmlspecialchars(rtrim($baseUrl, '/') . '/telegram/broadcast_test_bot.php?secret=' . ($settings['telegram_broadcast_test_webhook_secret'] ?? '')); ?></code>
                    <div class="mt-3 flex flex-col sm:flex-row sm:items-center gap-3">
                        <button type="submit" name="action" value="telegram_broadcast_test_set_webhook" class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-[#FFA048] text-white text-sm font-semibold hover:opacity-90">Установить webhook тестового бота</button>
                        <span class="text-xs text-gray-500 font-semibold">Подключено сотрудников к тестовому боту: <b><?php echo (int)$broadcastTestBotConnectedCount; ?></b></span>
                    </div>
                    <div class="mt-3 text-xs text-gray-500 font-semibold leading-relaxed">
                        После установки webhook откройте персональную ссылку сотрудника: «Наша команда» → «Редактировать» → «Тестовый бот рассылок». Без webhook Telegram откроет бот, но система не узнает, кто подключился.
                    </div>
                </div>
                <div class="md:col-span-2 rounded-2xl border border-orange-100 bg-orange-50/40 p-4 space-y-3">
                    <div>
                        <div class="text-[10px] uppercase text-[#c96f2b] font-black tracking-widest">Оповещения из «Доступов»</div>
                        <p class="mt-1 text-xs text-gray-500 font-semibold">Эти настройки управляют только событиями добавления, изменения и удаления ресурсов/доступов. Напоминания об оплате не отключаются здесь.</p>
                        <?php if (!$canManageAccessTelegramNotifications): ?><p class="mt-2 text-xs text-red-500 font-semibold">Менять эти галочки может только суперадминистратор.</p><?php endif; ?>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <label class="flex items-center gap-3 rounded-xl bg-white border border-orange-100 px-4 py-3 text-sm font-bold text-gray-600"><input type="checkbox" name="access_vault_tg_notifications_disabled" value="1" <?php echo (($settings['access_vault_tg_notifications_disabled'] ?? '0') === '1') ? 'checked' : ''; ?> <?php echo $canManageAccessTelegramNotifications ? '' : 'disabled'; ?> class="w-4 h-4 rounded border-gray-300 text-[#FFA048]"><span>Отключить все оповещения</span></label>
                        <label class="flex items-center gap-3 rounded-xl bg-white border border-orange-100 px-4 py-3 text-sm font-bold text-gray-600"><input type="checkbox" name="access_vault_tg_notify_create" value="1" <?php echo (($settings['access_vault_tg_notify_create'] ?? '1') === '1') ? 'checked' : ''; ?> <?php echo $canManageAccessTelegramNotifications ? '' : 'disabled'; ?> class="w-4 h-4 rounded border-gray-300 text-[#FFA048]"><span>Оповещать о добавлении</span></label>
                        <label class="flex items-center gap-3 rounded-xl bg-white border border-orange-100 px-4 py-3 text-sm font-bold text-gray-600"><input type="checkbox" name="access_vault_tg_notify_delete" value="1" <?php echo (($settings['access_vault_tg_notify_delete'] ?? '1') === '1') ? 'checked' : ''; ?> <?php echo $canManageAccessTelegramNotifications ? '' : 'disabled'; ?> class="w-4 h-4 rounded border-gray-300 text-[#FFA048]"><span>Оповещать об удалении</span></label>
                        <label class="flex items-center gap-3 rounded-xl bg-white border border-orange-100 px-4 py-3 text-sm font-bold text-gray-600"><input type="checkbox" name="access_vault_tg_notify_update" value="1" <?php echo (($settings['access_vault_tg_notify_update'] ?? '1') === '1') ? 'checked' : ''; ?> <?php echo $canManageAccessTelegramNotifications ? '' : 'disabled'; ?> class="w-4 h-4 rounded border-gray-300 text-[#FFA048]"><span>Оповещать об изменении</span></label>
                    </div>
                    <div class="rounded-2xl bg-white border border-orange-100 p-4">
                        <label class="block">
                            <span class="text-[10px] uppercase text-gray-400 font-black">Сообщение уведомления об оплате</span>
                            <textarea name="access_vault_payment_notification_message" rows="5" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-semibold text-sm"><?php echo htmlspecialchars($settings['access_vault_payment_notification_message'] ?? ''); ?></textarea>
                        </label>
                        <p class="mt-2 text-[11px] leading-relaxed text-gray-400 font-semibold">
                            Переменные: <code>{{name_user}}</code> — имя сотрудника, <code>{{pay}}</code> — размер оплаты и валюта, <code>{{date_pay}}</code> — дата оплаты, <code>{{period}}</code> — повтор оплаты.
                        </p>
                    </div>
                </div>
            </div>
        </details>

        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-bitrix-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Bitrix24</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Вебхук, воронка, стадия и ID пользовательских полей. Поля должны совпадать с вашей CRM.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block md:col-span-2"><span class="text-[10px] uppercase text-gray-400 font-black">REST вебхук</span><input name="b24_webhook_url" value="<?php echo htmlspecialchars($settings['b24_webhook_url']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block md:col-span-2"><span class="text-[10px] uppercase text-gray-400 font-black">Адрес портала для ссылок</span><input name="b24_portal_url" value="<?php echo htmlspecialchars($settings['b24_portal_url']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">CATEGORY_ID</span><input name="b24_deal_category_id" value="<?php echo htmlspecialchars($settings['b24_deal_category_id']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">STAGE_ID</span><input name="b24_deal_stage_id" value="<?php echo htmlspecialchars($settings['b24_deal_stage_id']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <?php
                $b24Fields = [
                    'b24_uf_test_manager' => 'Поле сделки: ссылка менеджера',
                    'b24_uf_test_client' => 'Поле сделки: ссылка клиента',
                    'b24_uf_test_date' => 'Поле сделки: дата теста',
                    'b24_uf_deal_city' => 'Поле сделки: город',
                    'b24_uf_deal_role' => 'Поле сделки: роль',
                    'b24_uf_contact_city' => 'Поле контакта: город',
                ];
                foreach ($b24Fields as $key => $label): ?>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black"><?php echo htmlspecialchars($label); ?></span><input name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($settings[$key]); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <?php endforeach; ?>
                <label class="flex items-center gap-3 md:col-span-2 bg-gray-50 rounded-2xl px-4 py-3"><input type="checkbox" name="b24_debug" value="1" <?php echo $settings['b24_debug']==='1'?'checked':''; ?> class="w-5 h-5"><span class="text-sm font-bold text-gray-600">Включить debug-лог Bitrix24</span></label>
            </div>
        </details>

        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-visible group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-help-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Справочная информация</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Редактирование страницы справки. Блок свёрнут, чтобы не приходилось долго листать настройки.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 space-y-3">
                <p class="text-sm text-gray-400 font-bold">Этот текст открывается по кнопке справки в админке. Можно вставлять картинки по URL и iframe.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 rounded-2xl border border-gray-100 bg-gray-50/60 p-4">
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Видео для администратора</span><textarea name="help_video_admin" rows="5" placeholder="iframe YouTube/VK/Vimeo" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-mono text-xs font-bold"><?php echo htmlspecialchars($settings['help_video_admin'] ?? ''); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Видео для менеджера</span><textarea name="help_video_manager" rows="5" placeholder="iframe YouTube/VK/Vimeo" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-mono text-xs font-bold"><?php echo htmlspecialchars($settings['help_video_manager'] ?? ''); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Видео для оператора</span><textarea name="help_video_operator" rows="5" placeholder="iframe YouTube/VK/Vimeo" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-mono text-xs font-bold"><?php echo htmlspecialchars($settings['help_video_operator'] ?? ''); ?></textarea></label>
                </div>
                <div class="sticky top-3 z-40 flex flex-wrap gap-2 bg-gray-50/95 backdrop-blur rounded-2xl p-2 shadow-sm border border-gray-100">
                    <button type="button" onclick="editorCmd('bold')" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn st2-icon-only" title="Жирный"><img class="st2-btn-icon" src="/assets/admin/icons/st2-bold-gray.svg" alt="B"></button>
                    <button type="button" onclick="editorCmd('italic')" class="px-3 py-2 bg-white rounded-xl text-xs font-black italic st2-editor-btn st2-icon-only" title="Курсив"><img class="st2-btn-icon" src="/assets/admin/icons/st2-italic-gray.svg" alt="I"></button>
                    <button type="button" onclick="editorBlock('h2')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Заголовок</button>
                    <button type="button" onclick="editorBlock('p')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Текст</button>
                    <button type="button" onclick="editorLink()" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-link-gray.svg" alt="" aria-hidden="true">Ссылка</button>
                    <button type="button" onclick="editorImage()" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-image-gray.svg" alt="" aria-hidden="true">Картинка</button>
                    <button type="button" onclick="editorIframe()" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-code-gray.svg" alt="" aria-hidden="true">iframe</button>
                    <button type="button" onclick="editorCmd('insertUnorderedList')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Список</button>
                </div>
                <div id="helpEditor" contenteditable="true" class="min-h-[320px] p-5 rounded-2xl border border-gray-200 bg-white text-gray-700 leading-relaxed outline-none overflow-auto help-content"><?php echo $settings['help_content']; ?></div>
                <textarea name="help_content" id="helpContentInput" class="hidden"><?php echo htmlspecialchars($settings['help_content']); ?></textarea>
            </div>
        </details>

        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-visible group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-link-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Инструкция для URL Shortener</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Редактирование текста под таблицей коротких ссылок. Блок свёрнут, чтобы настройки не превращались в рулон обоев.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 space-y-3">
                <p class="text-sm text-gray-400 font-bold">Можно использовать базовое HTML-форматирование, ссылки, картинки и iframe.</p>
                <div class="sticky top-3 z-40 flex flex-wrap gap-2 bg-gray-50/95 backdrop-blur rounded-2xl p-2 shadow-sm border border-gray-100">
                    <button type="button" onclick="editorCmd('bold', null, 'shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn st2-icon-only" title="Жирный"><img class="st2-btn-icon" src="/assets/admin/icons/st2-bold-gray.svg" alt="B"></button>
                    <button type="button" onclick="editorCmd('italic', null, 'shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black italic st2-editor-btn st2-icon-only" title="Курсив"><img class="st2-btn-icon" src="/assets/admin/icons/st2-italic-gray.svg" alt="I"></button>
                    <button type="button" onclick="editorBlock('h2', 'shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Заголовок</button>
                    <button type="button" onclick="editorBlock('p', 'shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Текст</button>
                    <button type="button" onclick="editorLink('shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-link-gray.svg" alt="" aria-hidden="true">Ссылка</button>
                    <button type="button" onclick="editorImage('shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-image-gray.svg" alt="" aria-hidden="true">Картинка</button>
                    <button type="button" onclick="editorIframe('shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-code-gray.svg" alt="" aria-hidden="true">iframe</button>
                    <button type="button" onclick="editorCmd('insertUnorderedList', null, 'shortener')" class="px-3 py-2 bg-white rounded-xl text-xs font-black">Список</button>
                </div>
                <div id="shortenerEditor" contenteditable="true" class="min-h-[260px] p-5 rounded-2xl border border-gray-200 bg-white text-gray-700 leading-relaxed outline-none overflow-auto help-content"><?php echo $settings['shortener_instruction'] ?? ''; ?></div>
                <textarea name="shortener_instruction" id="shortenerInstructionInput" class="hidden"><?php echo htmlspecialchars($settings['shortener_instruction'] ?? ''); ?></textarea>
            </div>
        </details>


        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-code-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-black text-gray-800 uppercase tracking-tight">Код вставки теста на стороннюю страницу</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Код вставляет стартовую страницу теста через iframe и передаёт UTM-метки из адресной строки страницы-донора внутрь теста.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3">
                    <input id="directTestLink" readonly value="<?php echo htmlspecialchars($testUrl); ?>" class="px-4 py-3 rounded-xl border border-gray-200 font-bold bg-gray-50">
                    <button type="button" onclick="copyFromInput('directTestLink')" class="px-5 py-3 bg-gray-100 rounded-xl text-xs font-black uppercase st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-copy-gray.svg" alt="" aria-hidden="true">Копировать ссылку</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 items-start">
                    <textarea id="embedCode" readonly rows="18" class="px-4 py-3 rounded-xl border border-gray-200 font-mono text-sm bg-gray-50"><?php echo htmlspecialchars($iframeCode); ?></textarea>
                    <button type="button" onclick="copyFromInput('embedCode')" class="px-5 py-3 bg-[#FFA048] text-white rounded-xl text-xs font-black uppercase st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-copy-gray.svg" alt="" aria-hidden="true">Копировать код</button>
                </div>
            </div>
        </details>



        <details class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden group">
            <summary class="list-none cursor-pointer select-none p-6 border-b border-gray-100 flex items-center justify-between gap-4 hover:bg-orange-50/40 transition">
                <div class="st2-title-wrap">
                    <img class="st2-section-icon" src="/assets/admin/icons/st2-mail-gray.svg" alt="" aria-hidden="true">
                    <div>
                        <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Письма и SMTP</h3>
                        <p class="text-sm text-gray-400 font-bold mt-1">Все шаблоны писем и сообщение с доступами. Блок внизу, чтобы настройки Bitrix не дрались с почтой за первое место.</p>
                    </div>
                </div>
                <span class="st2-toggle-closed" title="Развернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-down-gray.svg" alt="" aria-hidden="true"></span>
                <span class="st2-toggle-open" title="Свернуть"><img class="st2-toggle-icon" src="/assets/admin/icons/st2-chevron-up-gray.svg" alt="" aria-hidden="true"></span>
            </summary>
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP host</span><input name="mail_host" value="<?php echo htmlspecialchars($settings['mail_host']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP port</span><input name="mail_port" value="<?php echo htmlspecialchars($settings['mail_port']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Защита</span><select name="mail_secure" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><option value="ssl" <?php echo $settings['mail_secure']==='ssl'?'selected':''; ?>>ssl</option><option value="tls" <?php echo $settings['mail_secure']==='tls'?'selected':''; ?>>tls</option><option value="" <?php echo $settings['mail_secure']===''?'selected':''; ?>>без шифрования</option></select></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Имя отправителя</span><input name="mail_from_name" value="<?php echo htmlspecialchars($settings['mail_from_name']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP логин / email отправителя</span><input name="mail_username" value="<?php echo htmlspecialchars($settings['mail_username']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP пароль</span><input type="password" name="mail_password" value="<?php echo htmlspecialchars($settings['mail_password']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold" autocomplete="new-password"></label>
                </div>
                <div class="space-y-4">
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Получатели уведомлений</span><textarea name="notification_emails" rows="3" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"><?php echo htmlspecialchars($settings['notification_emails']); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма сотруднику о новом тесте</span><input name="notification_subject" value="<?php echo htmlspecialchars($settings['notification_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма сотруднику о новом тесте</span><textarea name="notification_body" rows="8" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['notification_body']); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма: продолжить тест</span><input name="resume_email_subject" value="<?php echo htmlspecialchars($settings['resume_email_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма: продолжить тест</span><textarea name="resume_email_body" rows="7" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['resume_email_body']); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма: график результата</span><input name="client_graph_email_subject" value="<?php echo htmlspecialchars($settings['client_graph_email_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма: график результата</span><textarea name="client_graph_email_body" rows="7" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['client_graph_email_body']); ?></textarea></label>
                    <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Сообщение с доступами сотруднику</span><textarea name="access_share_message" rows="8" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['access_share_message']); ?></textarea></label>
                    <p class="text-xs text-gray-400 font-semibold">Переменные для доступов: <code>{{full_name}}</code>, <code>{{username}}</code>, <code>{{login}}</code>, <code>{{password}}</code>, <code>{{role}}</code>, <code>{{admin_url}}</code>.</p>
                </div>
            </div>
        </details>

        <div class="sticky bottom-4 z-10 flex justify-end">
            <button type="submit" class="px-8 py-4 bg-[#ffa048] text-white rounded-2xl text-xs font-black uppercase tracking-widest shadow-xl shadow-orange-500/20 st2-editor-btn"><img class="st2-btn-icon" src="/assets/admin/icons/st2-save-white.svg" alt="" aria-hidden="true">Сохранить настройки</button>
        </div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.asr-settings-page details, #settingsForm details').forEach(function(details){
        details.removeAttribute('open');
    });
});
</script>
