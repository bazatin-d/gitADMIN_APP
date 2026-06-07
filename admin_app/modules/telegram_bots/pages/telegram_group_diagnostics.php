<?php
defined('ASR_ADMIN') || exit;

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$rows = [];
$error = '';
try {
    if (function_exists('asr_tg_repository_ensure_schema')) {
        asr_tg_repository_ensure_schema($pdo);
    }
    if (function_exists('asr_tg_table_exists') && asr_tg_table_exists($pdo, 'oca_telegram_bot_scenario_events')) {
        $stmt = $pdo->query("SELECT id, scenario_id, subscriber_id, telegram_user_id, bot_id, block_id, event_type, event_text, payload_json, created_at
            FROM oca_telegram_bot_scenario_events
            WHERE event_type IN ('runtime_action_telegram_group', 'runtime_action_telegram_group_failed')
            ORDER BY id DESC
            LIMIT 40");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$decodePayload = static function($json): array {
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
};

$shortJson = static function($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return '';
    if (mb_strlen($json, 'UTF-8') > 3500) $json = mb_substr($json, 0, 3500, 'UTF-8') . "\n... обрезано ...";
    return $json;
};
?>

<div class="tg-module max-w-7xl mx-auto space-y-5">
    <section class="bg-white rounded-3xl border border-gray-100 p-6 sm:p-8">
        <div class="flex flex-col gap-2">
            <div class="text-xs font-semibold text-gray-400">Чат-боты / Диагностика</div>
            <h1 class="text-2xl font-semibold text-gray-800">Управление группами/каналами</h1>
            <p class="text-sm font-semibold text-gray-500 leading-6 max-w-3xl">
                Здесь показываются последние попытки действий «Разблокировать пользователя», «Исключить из группы/канала»,
                «Подтвердить заявку» и «Отклонить заявку». Страница нужна, чтобы увидеть реальный ответ Telegram:
                найден ли канал, найден ли подписчик в канале, хватает ли прав боту.
            </p>
        </div>
        <div class="mt-4 rounded-2xl border border-orange-100 bg-orange-50 p-4 text-sm font-semibold text-orange-900 leading-6">
            Для исключения из канала Telegram обычно нужен именно <b>banChatMember</b>. Бот должен быть администратором канала/группы
            с правом блокировки пользователей. Для публичного канала можно использовать <code>@username</code>, для приватного чаще нужен ID вида <code>-100...</code>.
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="bg-red-50 border border-red-100 rounded-3xl p-5 text-sm font-semibold text-red-700"><?php echo $h($error); ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-3xl border border-gray-100 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="text-lg font-semibold text-gray-800">Последние события</h2>
            <a class="inline-flex items-center justify-center rounded-2xl border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50" href="/admin.php?tab=telegram_bots&page=telegram_group_diagnostics">Обновить</a>
        </div>

        <?php if (!$rows): ?>
            <div class="rounded-2xl border border-dashed border-gray-200 p-6 text-sm font-semibold text-gray-500">
                Пока нет событий по управлению группами. Проведите подписчика через сценарий с действием «Исключить из группы/канала» и обновите страницу.
            </div>
        <?php endif; ?>

        <div class="space-y-4">
            <?php foreach ($rows as $row):
                $payload = $decodePayload($row['payload_json'] ?? '');
                $diagnostics = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
                $isOk = (string)($row['event_type'] ?? '') === 'runtime_action_telegram_group';
                $mainCall = is_array($diagnostics['main_call'] ?? null) ? $diagnostics['main_call'] : [];
                $getChat = is_array($diagnostics['get_chat'] ?? null) ? $diagnostics['get_chat'] : [];
                $beforeStatus = (string)($payload['member_before_status'] ?? $diagnostics['member_before_status'] ?? '');
                $afterStatus = (string)($payload['member_after_main_status'] ?? $diagnostics['member_after_main_status'] ?? '');
            ?>
                <article class="rounded-3xl border <?php echo $isOk ? 'border-green-100 bg-green-50/30' : 'border-red-100 bg-red-50/30'; ?> p-5">
                    <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3">
                        <div>
                            <div class="text-xs font-semibold text-gray-400">#<?php echo (int)$row['id']; ?> · <?php echo $h($row['created_at'] ?? ''); ?></div>
                            <div class="mt-1 text-base font-semibold <?php echo $isOk ? 'text-green-800' : 'text-red-800'; ?>"><?php echo $h($row['event_text'] ?? ''); ?></div>
                        </div>
                        <div class="text-xs font-semibold text-gray-500 lg:text-right">
                            сценарий #<?php echo (int)$row['scenario_id']; ?> · блок #<?php echo (int)$row['block_id']; ?><br>
                            подписчик #<?php echo (int)$row['subscriber_id']; ?> · user_id <?php echo $h($row['telegram_user_id'] ?? ($payload['telegram_user_id'] ?? '')); ?>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
                        <div class="rounded-2xl bg-white border border-gray-100 p-3">
                            <div class="text-[11px] font-semibold text-gray-400">Метод</div>
                            <div class="font-semibold text-gray-800"><?php echo $h($payload['method'] ?? ''); ?></div>
                        </div>
                        <div class="rounded-2xl bg-white border border-gray-100 p-3">
                            <div class="text-[11px] font-semibold text-gray-400">Канал/группа</div>
                            <div class="font-semibold text-gray-800"><?php echo $h($payload['chat_id'] ?? ($diagnostics['chat_id_normalized'] ?? '')); ?></div>
                        </div>
                        <div class="rounded-2xl bg-white border border-gray-100 p-3">
                            <div class="text-[11px] font-semibold text-gray-400">Статус до</div>
                            <div class="font-semibold text-gray-800"><?php echo $h($beforeStatus !== '' ? $beforeStatus : 'нет данных'); ?></div>
                        </div>
                        <div class="rounded-2xl bg-white border border-gray-100 p-3">
                            <div class="text-[11px] font-semibold text-gray-400">Статус после</div>
                            <div class="font-semibold text-gray-800"><?php echo $h($afterStatus !== '' ? $afterStatus : 'нет данных'); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($payload['error']) || !empty($mainCall['error']) || empty($getChat['ok'])): ?>
                        <div class="mt-3 rounded-2xl border border-red-100 bg-white p-3 text-sm font-semibold text-red-700 leading-6">
                            <?php if (!empty($payload['error'])): ?>Ошибка действия: <?php echo $h($payload['error']); ?><br><?php endif; ?>
                            <?php if (!empty($mainCall['error'])): ?>Ошибка Telegram API: <?php echo $h($mainCall['error']); ?><br><?php endif; ?>
                            <?php if (empty($getChat['ok']) && !empty($getChat['error'])): ?>Канал/группа не проверены: <?php echo $h($getChat['error']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-semibold text-gray-500 hover:text-gray-800">Показать полный payload</summary>
                        <pre class="mt-3 overflow-auto rounded-2xl border border-gray-100 bg-white p-4 text-xs text-gray-700 leading-5"><?php echo $h($shortJson($payload)); ?></pre>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
