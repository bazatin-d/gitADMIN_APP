<?php
/**
 * Static help texts for Telegram scenario block editor.
 * Intentionally stored in code for now: later it can be replaced with editable admin pages.
 */
defined('ASR_ADMIN') || exit;

if (!function_exists('asr_tg_scenario_help_normalize_type')) {
    function asr_tg_scenario_help_normalize_type(string $type): string
    {
        $type = trim($type);
        $map = [
            'action' => 'actions',
            'message_card' => 'message',
            'question' => 'message',
        ];
        return $map[$type] ?? ($type !== '' ? $type : 'message');
    }
}

if (!function_exists('asr_tg_scenario_help_definition')) {
    function asr_tg_scenario_help_definition(string $type): array
    {
        $type = asr_tg_scenario_help_normalize_type($type);
        $definitions = [
            'start' => [
                'title' => 'Справка: Старт сценария',
                'subtitle' => 'Точка входа, с которой подписчик попадает в сценарий.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Стартовый блок — это техническая точка входа. Он показывает, откуда начинается сценарий при запуске через <code>/start</code>, диплинк, команду меню Telegram или ручной запуск.</div>
<section><h4>Что делает</h4><p>Сам по себе стартовый блок не отправляет сообщение. Он передаёт подписчика в первый подключённый рабочий блок: сообщение, условие, задержку, действие и так далее.</p></section>
<section><h4>Как использовать</h4><ol><li>Подключите от стартового блока стрелку к первому реальному шагу.</li><li>Убедитесь, что сценарий активен и привязан к нужному каналу.</li><li>Для запуска с конкретного места используйте диплинк нужного блока или команду меню.</li></ol></section>
<section><h4>Частые ошибки</h4><ul><li>От старта нет стрелки — сценарий запускается, но дальше идти некуда.</li><li>Сценарий не активен или не привязан к каналу.</li><li>Команда Telegram настроена на другой шаг.</li></ul></section>
HTML
            ],
            'message' => [
                'title' => 'Справка: Сообщение',
                'subtitle' => 'Отправляет подписчику текст, медиа, кнопки или вопрос.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Сообщение» — основной блок общения с подписчиком. Через него бот отправляет текст, картинки, файлы, видео, аудио, галереи, видео-заметки и вопросы.</div>
<section><h4>Что можно отправить</h4><ul><li><b>Текст</b> — обычное сообщение с форматированием, переменными и кнопками.</li><li><b>Картинка</b> — изображение с подписью или без неё.</li><li><b>Файл, видео, аудио</b> — вложение с подписью, если она нужна.</li><li><b>Галерея</b> — несколько изображений одним блоком.</li><li><b>Видео-заметка</b> — короткое круглое видео Telegram.</li><li><b>Вопрос</b> — сообщение, после которого бот ждёт ответ пользователя.</li></ul></section>
<section><h4>Кнопки</h4><p>К сообщению можно добавить кнопки двух типов:</p><ul><li><b>URL-кнопка</b> — ведёт на сайт, лендинг, оплату, документ.</li><li><b>Переход на шаг</b> — переводит подписчика в выбранный блок сценария.</li></ul><p>Если кнопка ведёт на другой шаг, после нажатия сценарий продолжится с этого блока.</p></section>
<section><h4>Переменные</h4><p>В текст можно вставлять системные и пользовательские переменные: <code>{{first_name}}</code>, <code>{{phone}}</code>, <code>{{email}}</code>, <code>{{custom.code}}</code>. Перед отправкой бот подставит данные конкретного подписчика.</p></section>
<section><h4>Вопрос внутри сообщения</h4><p>Карточка «Вопрос» нужна, когда надо получить ответ и сохранить его в поле подписчика. Ответ может быть свободным текстом или выбором кнопки. После ответа сценарий идёт дальше по общей стрелке или по отдельному переходу кнопки.</p></section>
<section><h4>Полезные настройки</h4><ul><li><b>Защитить контент</b> — запретить пересылку и сохранение средствами Telegram, где это поддерживается.</li><li><b>Отключить превью ссылки</b> — полезно, если ссылка есть, но большая карточка сайта мешает сообщению.</li><li><b>Диплинк блока</b> — позволяет запускать сценарий сразу с этого сообщения.</li></ul></section>
<section><h4>Как проверять</h4><ol><li>Сохраните блок.</li><li>Запустите сценарий тестом с этого шага или через диплинк.</li><li>Проверьте текст, переносы, форматирование, кнопки и подстановку переменных.</li><li>Если есть вопрос — проверьте, что ответ записался в поле подписчика.</li></ol></section>
HTML
            ],
            'delay' => [
                'title' => 'Справка: Задержка',
                'subtitle' => 'Ставит сценарий на паузу и продолжает его позже.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Задержка» нужен, чтобы не отправлять всё сразу. Он делает паузу между шагами: минуты, часы или дни.</div>
<section><h4>Как работает</h4><ol><li>Подписчик доходит до блока задержки.</li><li>Система записывает время продолжения.</li><li>Общий cron подхватывает ожидание.</li><li>Когда время наступило, сценарий идёт по стрелке «Следующий шаг».</li></ol></section>
<section><h4>Когда использовать</h4><ul><li>Серия прогрева: сообщение сегодня, второе завтра, третье через 3 дня.</li><li>Напоминание после регистрации.</li><li>Пауза перед вопросом или предложением.</li><li>Разделение длинного сценария, чтобы бот не выглядел как пулемёт в отпуске.</li></ul></section>
<section><h4>Важные нюансы</h4><ul><li>Задержка не отправляет сообщение сама.</li><li>После задержки обязательно подключите следующий блок.</li><li>Продолжение зависит от общего cron отправки очереди.</li><li>Если подписчик остановлен или отписан, продолжение может не выполниться.</li></ul></section>
<section><h4>Как проверять</h4><p>Для теста ставьте короткую задержку — например, 1–2 минуты. Подключите после неё обычное сообщение и проверьте, что оно пришло после паузы.</p></section>
HTML
            ],
            'condition' => [
                'title' => 'Справка: Условие',
                'subtitle' => 'Проверяет данные подписчика и ведёт его по ветке «Да» или «Нет».',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Условие» — это развилка. Он проверяет поля, теги, канал, дату, время или день недели и решает, куда вести подписчика дальше.</div>
<section><h4>Что можно проверять</h4><ul><li>Системные поля: имя, фамилия, username, телефон, email.</li><li>Пользовательские поля: текст, число, дата, дата и время.</li><li>Наличие или отсутствие тега.</li><li>Канал, из которого пришёл подписчик.</li><li>Текущую дату, время и день недели.</li></ul></section>
<section><h4>Логика проверки</h4><p>Можно выбрать, как объединять правила:</p><ul><li><b>Все условия</b> — ветка «Да» сработает только если совпали все правила.</li><li><b>Любое условие</b> — ветка «Да» сработает, если совпало хотя бы одно правило.</li></ul></section>
<section><h4>Выходы блока</h4><ul><li><b>Да</b> — подписчик подходит под условия.</li><li><b>Нет</b> — подписчик не подходит под условия.</li></ul><p>Обе ветки лучше подключать явно, чтобы сценарий не останавливался в неожиданном месте.</p></section>
<section><h4>Примеры</h4><ul><li>Есть тег «Купил» → отправить инструкцию для клиента.</li><li>Поле «Город» равно «Алматы» → пригласить на офлайн-встречу.</li><li>Email заполнен → не спрашивать его повторно.</li><li>Сегодня понедельник → показать одно сообщение, в остальные дни другое.</li></ul></section>
<section><h4>Частые ошибки</h4><ul><li>Выбрано поле, но не заполнено значение для сравнения.</li><li>Подключена только ветка «Да», а подписчик попал в «Нет».</li><li>Телефон проверяют как число. Телефон — это текст, потому что в нём могут быть плюс, пробелы и ведущие нули.</li></ul></section>
HTML
            ],
            'actions' => [
                'title' => 'Справка: Действия',
                'subtitle' => 'Меняет данные подписчика, теги, сценарий или отправляет служебные события.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Действия» выполняет внутренние операции без обычного сообщения подписчику. Это рабочий блок для тегов, полей, уведомлений, webhook, Яндекс.Метрики и управления Telegram-группами.</div>
<section><h4>Как выполняются действия</h4><p>Действия идут сверху вниз. Если одно из действий останавливает сценарий или отписывает подписчика, действия ниже уже не выполняются.</p></section>
<section><h4>Теги</h4><ul><li><b>Добавить тег</b> — назначает подписчику выбранный тег.</li><li><b>Удалить тег</b> — снимает выбранный тег.</li></ul><p>Теги применяются к человеку целиком через общий <code>telegram_user_id</code>, даже если он подписан на несколько каналов.</p></section>
<section><h4>Поля и переменные</h4><p>Можно изменить безопасные системные поля: имя, фамилию, телефон, email. Также можно менять пользовательские поля: текст, число, дату и дату-время.</p><ul><li>Для текста: установить или очистить.</li><li>Для числа: установить, очистить, прибавить, вычесть.</li><li>Для даты: установить или очистить.</li></ul></section>
<section><h4>Сценарий</h4><ul><li><b>Остановить сценарий</b> — прекращает текущий сценарий для подписчика.</li><li><b>Отписать от бота</b> — ставит подписчику статус «отписан» в текущем канале и останавливает сценарий.</li><li><b>Удалить шаг-сообщение</b> — удаляет из Telegram сообщения, которые выбранный блок «Сообщение» ранее отправил подписчику.</li></ul></section>
<section><h4>Уведомления и интеграции</h4><ul><li><b>Отправить уведомление</b> — пишет сотруднику через технический Telegram-бот.</li><li><b>Webhook</b> — отправляет данные подписчика во внешний сервис.</li><li><b>Внешний запрос</b> — выполняет HTTPS-запрос и может записать данные из ответа в поля подписчика.</li><li><b>Яндекс.Метрика</b> — ставит событие офлайн-конверсии в очередь отправки.</li></ul></section>
<section><h4>Управление группами</h4><p>Можно разблокировать пользователя, исключить из группы/канала, подтвердить или отклонить заявку на вступление. Для этого бот должен быть администратором нужной группы или канала.</p></section>
<section><h4>Как проверять</h4><ol><li>Соберите короткую цепочку: Сообщение → Действия → Сообщение.</li><li>Проверьте каждое действие отдельно.</li><li>После прохождения сценария откройте карточку подписчика и журнал событий.</li></ol></section>
HTML
            ],
            'schedule' => [
                'title' => 'Справка: Расписание',
                'subtitle' => 'Ждёт конкретную дату и время или уводит в ветку «Дата прошла».',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Расписание» нужен, когда продолжение должно произойти не через «5 минут», а в конкретный день и время: вебинар, старт курса, дедлайн, событие.</div>
<section><h4>Два выхода</h4><ul><li><b>По расписанию</b> — дата ещё впереди, подписчик ждёт до нужного времени.</li><li><b>Дата прошла</b> — выбранная дата уже в прошлом или дата не найдена.</li></ul></section>
<section><h4>Варианты даты</h4><ul><li><b>Фиксированная дата</b> — одна дата для всех подписчиков.</li><li><b>Пользовательское поле даты</b> — у каждого подписчика может быть своя дата.</li></ul><p>Если поле у подписчика пустое, блок может использовать дату, указанную прямо в настройках блока.</p></section>
<section><h4>Часовой пояс</h4><p>По умолчанию используется часовой пояс сценария. Внутри блока его можно переопределить. Это важно для вебинаров, запусков и рассылок по локальному времени.</p></section>
<section><h4>Примеры</h4><ul><li>До вебинара отправить напоминание в 18:30.</li><li>Если дата вебинара прошла — отправить запись.</li><li>Перед персональной датой консультации отправить подготовительное сообщение.</li></ul></section>
<section><h4>Частые ошибки</h4><ul><li>Подключена только ветка «По расписанию», а подписчик попал в «Дата прошла».</li><li>Выбрано поле даты, но у подписчика оно пустое и запасная дата не задана.</li><li>Неверно выбран часовой пояс.</li></ul></section>
HTML
            ],
            'random' => [
                'title' => 'Справка: Случайный выбор',
                'subtitle' => 'Распределяет подписчиков по нескольким веткам с заданной вероятностью.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Случайный выбор» нужен для A/B-тестов, разных вариантов прогрева и случайного распределения подписчиков.</div>
<section><h4>Как работает</h4><p>Вы создаёте несколько выходов и задаёте процент для каждого. Когда подписчик доходит до блока, система выбирает одну ветку с учётом вероятности.</p></section>
<section><h4>Правила настройки</h4><ul><li>Минимум 2 выхода.</li><li>Максимум 10 выходов.</li><li>Сумма вероятностей должна быть ровно 100%.</li><li>Каждый выход лучше подключить к своему следующему блоку.</li></ul></section>
<section><h4>Примеры</h4><ul><li>50% подписчиков получают вариант А, 50% — вариант Б.</li><li>80% идут в основной сценарий, 20% — в тестовую ветку.</li><li>33/33/34 — три разных формулировки оффера.</li></ul></section>
<section><h4>Как проверять</h4><p>Для быстрой проверки можно поставить 100% на один выход и убедиться, что подписчик всегда идёт в нужную ветку. Потом вернуть нормальные проценты.</p></section>
<section><h4>Важный нюанс</h4><p>Случайность работает на момент прохождения блока. Если один и тот же подписчик снова попадёт в этот блок, ветка может быть выбрана заново.</p></section>
HTML
            ],
            'formula' => [
                'title' => 'Справка: Формула',
                'subtitle' => 'Выполняет вычисления и записывает результат в поля подписчика.',
                'body' => <<<'HTML'
<div class="tg-scenario-help-lead">Блок «Формула» нужен, когда надо посчитать значение, собрать текст, изменить поле по правилу или подготовить данные для следующих шагов сценария.</div>
<section><h4>Как работает</h4><ol><li>Формула выполняется сверху вниз, строка за строкой.</li><li>Каждая рабочая строка — это присваивание через один знак <code>=</code>.</li><li>Результат можно записать в поле подписчика или во временную локальную переменную.</li><li>После успешного выполнения сценарий идёт к следующему блоку.</li></ol></section>
<section><h4>Примеры</h4><pre><code>client.first_name = "Дмитрий"
client.score = int(client.score) + 1
client.full_name = concat(client.first_name, " ", client.last_name)
bonus = 10
client.total = float(client.price) + bonus
client.today = today()</code></pre></section>
<section><h4>Что можно использовать</h4><ul><li>Числа, строки в кавычках, скобки.</li><li>Операции <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, <code>%</code>.</li><li>Поля подписчика через <code>client.field</code>.</li><li>Локальные переменные: <code>bonus = 10</code>, потом <code>client.total = bonus + 5</code>.</li><li>Комментарии через <code>#</code>.</li></ul></section>
<section><h4>Функции</h4><ul><li><code>int()</code>, <code>float()</code>, <code>round()</code> — работа с числами.</li><li><code>str()</code>, <code>trim()</code>, <code>lower()</code>, <code>upper()</code> — работа с текстом.</li><li><code>concat()</code> — склеить несколько значений.</li><li><code>len()</code> — длина текста.</li><li><code>replace()</code> — заменить фрагмент текста.</li><li><code>today()</code>, <code>now()</code> — текущая дата и дата-время.</li></ul></section>
<section><h4>Ограничения</h4><ul><li>Нельзя перезаписывать технические поля: <code>username</code>, <code>telegram_user_id</code>, <code>chat_id</code>, <code>subscriber_id</code>, <code>bot_id</code>.</li><li>Нельзя запускать внешние API-запросы. Для этого есть действие «Внешний запрос».</li><li>Нельзя использовать сравнения вместо присваивания: <code>==</code>, <code>!=</code>, <code>&gt;=</code>, <code>&lt;=</code>.</li></ul></section>
<section><h4>Если возникла ошибка</h4><p>Сценарий остановит выполнение формулы на проблемной строке. Строки выше, которые уже успели выполниться, останутся записанными. Поэтому важные операции лучше располагать осознанно: сначала подготовка, потом финальная запись.</p></section>
HTML
            ],
        ];

        return $definitions[$type] ?? $definitions['message'];
    }
}


if (!function_exists('asr_tg_scenario_block_help_button')) {
    function asr_tg_scenario_block_help_button(string $type): string
    {
        $help = asr_tg_scenario_help_definition($type);
        $title = htmlspecialchars((string)($help['title'] ?? 'Справка по блоку'), ENT_QUOTES, 'UTF-8');
        return '<button type="button" class="tg-scenario-help-open" data-scenario-help-open-direct aria-label="Открыть справку по блоку" title="' . $title . '" onclick="var p=this.closest(\'.tg-flow-panel\')||document;var m=p.querySelector(\'[data-scenario-help-modal]\')||document.querySelector(\'[data-scenario-help-modal]\');if(m){m.hidden=false;document.documentElement.classList.add(\'tg-scenario-help-lock\');}return false;">?</button>';
    }
}

if (!function_exists('asr_tg_scenario_block_help_render')) {
    function asr_tg_scenario_block_help_render(string $type): string
    {
        $help = asr_tg_scenario_help_definition($type);
        $title = htmlspecialchars((string)($help['title'] ?? 'Справка'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string)($help['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $body = (string)($help['body'] ?? '');
        $id = 'tg-scenario-help-' . substr(sha1($type . '|' . $title), 0, 10);
        return <<<HTML
<div class="tg-scenario-help-root" data-scenario-help-root data-scenario-help-id="{$id}">
    <div class="tg-scenario-help-modal" data-scenario-help-modal role="dialog" aria-modal="true" aria-labelledby="{$id}-title" hidden>
        <div class="tg-scenario-help-backdrop" data-scenario-help-close onclick="var m=this.closest('[data-scenario-help-modal]');if(m){m.hidden=true;document.documentElement.classList.remove('tg-scenario-help-lock');}"></div>
        <div class="tg-scenario-help-dialog">
            <div class="tg-scenario-help-head">
                <div>
                    <div class="tg-scenario-help-kicker">Справка по блоку</div>
                    <h3 id="{$id}-title">{$title}</h3>
                    <p>{$subtitle}</p>
                </div>
                <button type="button" class="tg-scenario-help-close" data-scenario-help-close aria-label="Закрыть" onclick="var m=this.closest('[data-scenario-help-modal]');if(m){m.hidden=true;document.documentElement.classList.remove('tg-scenario-help-lock');}">×</button>
            </div>
            <div class="tg-scenario-help-body">{$body}</div>
        </div>
    </div>
</div>
<style data-scenario-help-style>
.tg-scenario-help-open{width:34px;height:34px;border-radius:50%;border:1px solid #e5e0d8;background:#fff8ef;color:#9a5a1c;font-size:16px;font-weight:700;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.08);transition:background .15s ease,transform .15s ease,border-color .15s ease}.tg-scenario-help-open:hover{background:#fff1dc;border-color:#f0c384;transform:translateY(-1px)}.tg-scenario-help-modal[hidden]{display:none}.tg-scenario-help-modal{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:28px}.tg-scenario-help-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.42);backdrop-filter:blur(2px)}.tg-scenario-help-dialog{position:relative;z-index:1;width:min(860px,calc(100vw - 28px));max-height:min(86vh,820px);display:flex;flex-direction:column;background:#fff;border:1px solid #eee5da;border-radius:24px;box-shadow:0 28px 80px rgba(15,23,42,.22);overflow:hidden}.tg-scenario-help-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:22px 24px 18px;border-bottom:1px solid #f0ece7;background:linear-gradient(180deg,#fffaf3 0%,#fff 100%)}.tg-scenario-help-kicker{font-size:12px;font-weight:650;letter-spacing:.02em;color:#b46a22;margin-bottom:5px}.tg-scenario-help-head h3{margin:0;color:#2f343b;font-size:22px;font-weight:680;line-height:1.2}.tg-scenario-help-head p{margin:7px 0 0;color:#6b7280;font-size:13px;line-height:1.45}.tg-scenario-help-close{width:36px;height:36px;border:1px solid #eee7df;border-radius:50%;background:#fff;color:#6b7280;font-size:25px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}.tg-scenario-help-close:hover{background:#f9fafb;color:#374151}.tg-scenario-help-body{padding:22px 24px 26px;overflow:auto;color:#3f4650;font-size:14px;line-height:1.65}.tg-scenario-help-lead{font-size:15px;line-height:1.65;color:#374151;background:#fff8ef;border:1px solid #f4dec3;border-radius:18px;padding:14px 16px;margin-bottom:18px}.tg-scenario-help-body section{border-top:1px solid #f0f1f3;padding-top:16px;margin-top:16px}.tg-scenario-help-body section:first-of-type{border-top:0;padding-top:0}.tg-scenario-help-body h4{margin:0 0 8px;color:#2f343b;font-size:15px;font-weight:680}.tg-scenario-help-body p{margin:7px 0}.tg-scenario-help-body ul,.tg-scenario-help-body ol{margin:8px 0 0;padding-left:21px}.tg-scenario-help-body li{margin:4px 0}.tg-scenario-help-body code{background:#f6f7f8;border:1px solid #eceff2;border-radius:7px;padding:1px 5px;color:#374151;font-size:12px}.tg-scenario-help-body pre{margin:10px 0 0;background:#1f2937;color:#f9fafb;border-radius:16px;padding:14px 16px;overflow:auto}.tg-scenario-help-body pre code{background:transparent;border:0;color:inherit;padding:0;font-size:13px}.tg-flow-panel-actions .tg-scenario-help-open{box-shadow:none}.tg-flow-panel-head>.tg-scenario-help-open{margin-left:auto;margin-right:8px}@media(max-width:760px){.tg-scenario-help-modal{padding:12px;align-items:flex-end}.tg-scenario-help-dialog{max-height:88vh;border-radius:22px 22px 0 0;width:100%}.tg-scenario-help-head{padding:18px 18px 14px}.tg-scenario-help-body{padding:18px}.tg-scenario-help-head h3{font-size:19px}}
</style>
<script>
(function(){
  var root = document.querySelector('[data-scenario-help-root][data-scenario-help-id="{$id}"]');
  if (!root || root.dataset.ready === '1') return;
  root.dataset.ready = '1';
  var modal = root.querySelector('[data-scenario-help-modal]');
  function closeHelp(){ if (!modal) return; modal.hidden = true; document.documentElement.classList.remove('tg-scenario-help-lock'); }
  root.querySelectorAll('[data-scenario-help-close]').forEach(function(el){ el.addEventListener('click', function(e){ e.preventDefault(); closeHelp(); }); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal && !modal.hidden) closeHelp(); });
})();
</script>
HTML;
    }
}
