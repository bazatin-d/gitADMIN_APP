<?php

defined('ASR_ADMIN') || exit;
$settings = asr_get_all_settings();
require_once __DIR__ . '/../service.php';
$varsHint = asr_emails_template_vars_hint();
$accessVarsHint = asr_emails_access_vars_hint();
?>
<div class="space-y-6">
    <?php if (!empty($_GET['saved'])): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Настройки писем сохранены.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['email_error'])): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars((string)$_GET['email_error']); ?></div>
    <?php endif; ?>
    <?php if (!asr_settings_table_exists($pdo)): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold">
            Таблица настроек пока не создана. Выполните SQL из файла <code>migration_admin_settings.sql</code>, иначе форма будет показывать значения по умолчанию, но не сможет их сохранить.
        </div>
    <?php endif; ?>

    <form method="POST" id="emailSettingsForm" class="space-y-6">
        <input type="hidden" name="action" value="save_email_settings">

        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">SMTP и отправитель</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Эти настройки используются для всех писем: сотрудникам и клиентам.</p>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP host</span><input name="mail_host" value="<?php echo htmlspecialchars($settings['mail_host']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP port</span><input name="mail_port" value="<?php echo htmlspecialchars($settings['mail_port']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Защита</span><select name="mail_secure" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold bg-white"><option value="ssl" <?php echo $settings['mail_secure']==='ssl'?'selected':''; ?>>ssl</option><option value="tls" <?php echo $settings['mail_secure']==='tls'?'selected':''; ?>>tls</option><option value="" <?php echo $settings['mail_secure']===''?'selected':''; ?>>без шифрования</option></select></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Имя отправителя</span><input name="mail_from_name" value="<?php echo htmlspecialchars($settings['mail_from_name']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP логин / email отправителя</span><input name="mail_username" value="<?php echo htmlspecialchars($settings['mail_username']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">SMTP пароль</span><input type="password" name="mail_password" value="<?php echo htmlspecialchars($settings['mail_password']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold" autocomplete="new-password"></label>
            </div>
        </section>

        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Письмо сотруднику о новом тесте</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Уходит после полного завершения теста. Переменные: <?php echo htmlspecialchars($varsHint); ?>.</p>
            </div>
            <div class="p-6 space-y-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Получатели уведомлений</span><textarea name="notification_emails" rows="3" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold" placeholder="email через запятую или с новой строки"><?php echo htmlspecialchars($settings['notification_emails']); ?></textarea></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма</span><input name="notification_subject" value="<?php echo htmlspecialchars($settings['notification_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма</span><textarea name="notification_body" rows="10" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['notification_body']); ?></textarea></label>
            </div>
        </section>

        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Письмо клиенту: продолжить тест</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Уходит по кнопке «Email клиенту» у незавершённого теста. Главная переменная: <code>{{resume_link}}</code>.</p>
            </div>
            <div class="p-6 space-y-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма</span><input name="resume_email_subject" value="<?php echo htmlspecialchars($settings['resume_email_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма</span><textarea name="resume_email_body" rows="9" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['resume_email_body']); ?></textarea></label>
            </div>
        </section>

        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Письмо клиенту: график результата</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Уходит по кнопке «Email клиенту» у завершённого теста. Главная переменная: <code>{{client_link}}</code>.</p>
            </div>
            <div class="p-6 space-y-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Тема письма</span><input name="client_graph_email_subject" value="<?php echo htmlspecialchars($settings['client_graph_email_subject']); ?>" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold"></label>
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст письма</span><textarea name="client_graph_email_body" rows="9" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['client_graph_email_body']); ?></textarea></label>
            </div>
        </section>

        <section class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-[#FFA048] uppercase tracking-tight">Сообщение с доступами сотруднику</h3>
                <p class="text-sm text-gray-400 font-bold mt-1">Используется кнопкой «Поделиться доступами» на странице пользователей. Переменные: <?php echo htmlspecialchars($accessVarsHint); ?>.</p>
            </div>
            <div class="p-6 space-y-4">
                <label class="block"><span class="text-[10px] uppercase text-gray-400 font-black">Текст сообщения</span><textarea name="access_share_message" rows="9" class="mt-1 w-full px-4 py-3 rounded-xl border border-gray-200 font-bold font-mono text-sm"><?php echo htmlspecialchars($settings['access_share_message']); ?></textarea></label>
            </div>
        </section>

        <div class="sticky bottom-4 z-10 flex justify-end">
            <button type="submit" class="px-8 py-4 bg-[#ffa048] text-white rounded-2xl text-xs font-black uppercase tracking-widest shadow-xl shadow-orange-500/20">Сохранить письма</button>
        </div>
    </form>
</div>
