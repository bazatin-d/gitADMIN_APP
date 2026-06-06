<?php
defined('ASR_ADMIN') || exit;

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

if (!function_exists('asrResultsColumnExists')) {
    function asrResultsColumnExists(PDO $pdo, string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // 1) Основная проверка через INFORMATION_SCHEMA. Надёжнее, чем SHOW COLUMNS LIKE ?
        // на некоторых связках MySQL/phpMyAdmin/PDO.
        try {
            $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($dbName !== '') {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $stmt->execute([$dbName, $table, $column]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return $cache[$key] = true;
                }
            }
        } catch (Throwable $e) {
            // Ниже есть резервные проверки.
        }

        // 2) Резервная проверка через SHOW COLUMNS без параметра LIKE.
        try {
            $safeTable = '`' . str_replace('`', '``', $table) . '`';
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . $safeTable);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['Field'] ?? '') === $column) {
                    return $cache[$key] = true;
                }
            }
        } catch (Throwable $e) {
            // Ниже есть последняя проверка.
        }

        // 3) Последняя проверка: пробуем выбрать сам столбец.
        try {
            $safeTable = '`' . str_replace('`', '``', $table) . '`';
            $safeColumn = '`' . str_replace('`', '``', $column) . '`';
            $pdo->query('SELECT ' . $safeColumn . ' FROM ' . $safeTable . ' LIMIT 1');
            return $cache[$key] = true;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

$hasSpamStatus = asrResultsColumnExists($pdo, 'oca_results', 'spam_status');
$hasSpamReason = asrResultsColumnExists($pdo, 'oca_results', 'spam_reason');
$requestedSpamView = isAdmin() && (($_GET['spam'] ?? '') === 'blocked');
$isSpamView = $requestedSpamView;

$whereParts = [];
$params = [];
if ($hasSpamStatus) {
    if ($isSpamView) {
        $whereParts[] = "spam_status = 'blocked'";
    } else {
        $whereParts[] = "(spam_status IS NULL OR spam_status = '' OR spam_status <> 'blocked')";
    }
} elseif ($isSpamView) {
    // Если миграция ещё не добавила spam_status, раздел «Спам» открывается пустым,
    // а не показывает обычные результаты и не ломает страницу.
    $whereParts[] = "1 = 0";
}
if ($search !== '') {
    $whereParts[] = "(name LIKE ? OR phone LIKE ? OR email LIKE ? OR city LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM oca_results $where");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));

$recordsStmt = $pdo->prepare("SELECT * FROM oca_results $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$recordsStmt->execute($params);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

function asrStatusBadge(array $r): string {
    $status = $r['status'] ?? 'completed';
    if ($status === 'in_progress') {
        $page = max(1, min(10, (int)($r['current_page'] ?? 1)));
        return '<span class="inline-flex items-center px-4 py-1 rounded-full bg-yellow-50 text-yellow-700 text-[10px] font-bold uppercase tracking-widest whitespace-nowrap">Не завершён · стр. ' . $page . '/10</span>';
    }
    return '<span class="inline-flex items-center px-4 py-1 rounded-full bg-green-50 text-green-700 text-[10px] font-bold uppercase tracking-widest whitespace-nowrap">Завершён</span>';
}

function asrSpamReasonText(string $reason): string {
    $map = [
        'honeypot' => 'скрытое поле заполнено ботом',
        'fast_submit' => 'слишком быстрая отправка формы',
        'bad_email_domain' => 'временный или мусорный email-домен',
        'bad_phone' => 'некорректный телефон',
        'bad_email' => 'некорректный email',
        'bad_name' => 'ФИО похоже на мусор',
        'bad_city' => 'город похож на мусор',
        'link_or_html' => 'ссылка или HTML в полях',
    ];
    return $map[$reason] ?? ($reason !== '' ? $reason : 'причина не записана');
}

function asrResumeUrl(array $r): string {
    $token = trim((string)($r['resume_token'] ?? ''));
    if ($token === '') return '';
    return (function_exists('asr_config_app_url') ? asr_config_app_url('?resume=' . rawurlencode($token)) : ('/?resume=' . rawurlencode($token)));
}

function asrSharedUrl(array $r): string {
    return function_exists('asr_config_app_url') ? asr_config_app_url('admin.php?shared=' . encodeId((int)$r['id'])) : ('admin.php?shared=' . encodeId((int)$r['id']));
}

function asrCleanUtm(array $r): string {
    $utm = trim((string)($r['utm'] ?? ''));
    if ($utm === '') return '';
    $utmLower = function_exists('mb_strtolower') ? mb_strtolower($utm, 'UTF-8') : strtolower($utm);
    if (in_array($utmLower, ['нет', 'no', 'none', 'null', '-'], true)) return '';
    return $utm;
}

function asrResultsPageUrl(int $page, string $search): string {
    // Важно: tab=results должен сохраняться во всех ссылках пагинатора.
    // Иначе admin.php?page=2 или admin.php?search=... открывает дефолтную общую панель.
    $query = ['tab' => 'results'];
    if ($page > 1) $query['page'] = $page;
    if ($search !== '') $query['search'] = $search;
    if (isset($_GET['spam']) && $_GET['spam'] === 'blocked') $query['spam'] = 'blocked';
    return 'admin.php?' . http_build_query($query);
}
?>
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

<?php if (!empty($_GET['mail_sent'])): ?>
    <div class="mb-4 bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Письмо клиенту отправлено.</div>
<?php endif; ?>
<?php if (!empty($_GET['mail_error'])): ?>
    <div class="mb-4 bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars($_GET['mail_error']); ?></div>
<?php endif; ?>
<?php if (!empty($_GET['crm_refreshed'])): ?>
    <div class="mb-4 bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">CRM обновлена: контакт и сделка синхронизированы.</div>
<?php endif; ?>
<?php if (!empty($_GET['crm_error'])): ?>
    <div class="mb-4 bg-red-50 border border-red-100 text-red-700 px-5 py-4 rounded-2xl text-sm font-bold"><?php echo htmlspecialchars($_GET['crm_error']); ?></div>
<?php endif; ?>
<?php if (!empty($_GET['restored'])): ?>
    <div class="mb-4 bg-green-50 border border-green-100 text-green-700 px-5 py-4 rounded-2xl text-sm font-bold">Запись возвращена в обычные результаты.</div>
<?php endif; ?>

<div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-6 border-b border-gray-50 flex flex-col xl:flex-row justify-between items-center gap-4 mobile-center-left">
        <form method="GET" action="admin.php" class="mobile-stack flex items-center gap-3 w-full sm:w-auto">
            <input type="hidden" name="tab" value="results">
            <?php if ($isSpamView): ?><input type="hidden" name="spam" value="blocked"><?php endif; ?>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Поиск..." class="mobile-full px-4 py-2.5 text-sm rounded-xl border border-gray-200 focus:border-[#ffa048] outline-none w-64 transition-all font-bold">
            <div class="mobile-full flex gap-2">
                <button type="submit" class="rs2-btn rs2-toolbar-btn px-6 py-2.5 bg-[#ffa048] text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-sm hover:bg-[#ff8f28]"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-search-white.svg" alt="" aria-hidden="true">Найти</button>
                <?php if($search): ?>
                    <a href="<?php echo $isSpamView ? 'admin.php?tab=results&spam=blocked' : 'admin.php?tab=results'; ?>" class="rs2-btn rs2-toolbar-btn px-4 py-2.5 bg-gray-100 text-gray-500 font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-gray-200 flex items-center"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-clear-gray.svg" alt="" aria-hidden="true">Сбросить</a>
                <?php endif; ?>
                <?php if(isAdmin()): ?>
                <a href="?action=export_results" title="Экспорт в CSV" class="flex items-center justify-center w-10 h-10 bg-[#ffa048] text-white rounded-xl hover:bg-[#ff8f28] transition-all">
                    <img class="rs2-icon" src="/assets/admin/icons/rs2-download-white.svg" alt="" aria-hidden="true">
                </a>
                <?php endif; ?>
            </div>
        </form>
        <div class="mobile-stack mobile-full flex items-center gap-2">
            <?php if (!$isSpamView): ?>
                <button id="compareBtn" disabled class="rs2-btn rs2-toolbar-btn px-6 py-2.5 bg-gray-100 text-gray-400 font-bold text-xs uppercase tracking-widest rounded-xl cursor-not-allowed transition-all"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-compare-gray.svg" alt="" aria-hidden="true">Сравнить графики</button>
                <?php if(asr_can_work_results()): ?><a href="admin.php?tab=manual_input" class="rs2-btn rs2-toolbar-btn px-6 py-2.5 bg-[#ffa048] text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-sm hover:bg-[#ff8f28] transition-all whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-edit-white.svg" alt="" aria-hidden="true">Ввод вручную</a><?php endif; ?>
                <?php if(isAdmin()): ?>
                    <a href="admin.php?tab=results&spam=blocked" class="rs2-btn rs2-toolbar-btn px-6 py-2.5 bg-pink-500 text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-sm hover:bg-pink-600 transition-all whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-block-white.svg" alt="" aria-hidden="true">Спам</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="admin.php?tab=results" class="rs2-btn rs2-toolbar-btn px-6 py-2.5 bg-gray-100 text-gray-500 font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-gray-200 transition-all whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-list-gray.svg" alt="" aria-hidden="true">Обычные результаты</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if($isSpamView): ?>
        <div class="px-6 py-4 bg-pink-50 border-b border-pink-100 text-pink-700 text-sm font-bold">
            <?php if(!$hasSpamStatus): ?>
                Раздел «Спам» включён в интерфейсе, но в таблице результатов пока не найдено поле spam_status. Выполните миграцию, чтобы система начала складывать сюда заблокированные старты теста.
            <?php else: ?>
                Раздел «Спам»: здесь лежат заблокированные старты теста. Можно вернуть запись в обычную таблицу, если фильтр переусердствовал и решил поработать начальником службы безопасности.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="desktop-results-table desktop-table">
        <table class="w-full text-left border-collapse table-fixed">
            <colgroup>
                <col class="w-[44px]"><col class="w-[110px]"><col class="w-[245px]"><col><col class="w-[135px]"><col class="w-[270px]">
            </colgroup>
            <thead class="bg-gray-50/70">
                <tr>
                    <th class="py-3 pl-4 pr-2"></th>
                    <th class="py-3 px-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider text-center">Дата</th>
                    <th class="py-3 px-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">Статус</th>
                    <th class="py-3 px-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">Респондент</th>
                    <th class="py-3 px-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">CRM</th>
                    <th class="py-3 pl-2 pr-4 text-[10px] font-bold text-gray-400 uppercase tracking-wider text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="text-sm font-semibold">
                <?php foreach($records as $rowIndex => $r): ?>
                <?php
                    $isCompleted = (($r['status'] ?? 'completed') === 'completed');
                    $resumeUrl = asrResumeUrl($r);
                    $sharedUrl = $isCompleted ? asrSharedUrl($r) : '';
                    $utmValue = asrCleanUtm($r);
                    $rowBg = ($rowIndex % 2 === 0) ? 'bg-white' : 'bg-[#fff9ed]';
                    $rowTone = ($rowIndex % 2 === 0) ? 'asr-result-row-even' : 'asr-result-row-odd';
                ?>
                <tr class="asr-result-row <?php echo $rowTone; ?> <?php echo $rowBg; ?> hover:bg-orange-50/40 transition-colors">
                    <td class="pl-4 pr-2 py-3 align-top"><input type="checkbox" value="<?php echo (int)$r['id']; ?>" class="compare-cb w-4 h-4 text-[#ffa048] rounded border-gray-300"></td>
                    <td class="px-2 py-3 align-top text-gray-400 text-[11px] text-center font-bold leading-tight"><span class="block"><?php echo date("d.m.Y", strtotime($r['created_at'])); ?></span><span class="block text-gray-300 mt-0.5"><?php echo date("H:i", strtotime($r['created_at'])); ?></span></td>
                    <td class="px-2 py-3 align-top status-cell whitespace-nowrap">
                        <?php echo asrStatusBadge($r); ?>
                        <?php if($isSpamView): ?><div class="mt-2 text-[10px] leading-snug text-pink-600 font-black uppercase tracking-wide">Спам: <?php echo htmlspecialchars(asrSpamReasonText((string)($r['spam_reason'] ?? ''))); ?></div><?php endif; ?>
                    </td>
                    <td class="px-2 py-3 align-top min-w-0">
                        <div class="min-w-0">
                            <div class="font-bold text-gray-900 truncate <?php echo $isCompleted ? 'underline decoration-gray-200 underline-offset-4 decoration-dashed' : ''; ?>" title="<?php echo htmlspecialchars($r['name']); ?>">
                                <?php if($isCompleted && !$isSpamView): ?><a href="?view=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a><?php else: ?><?php echo htmlspecialchars($r['name']); ?><?php endif; ?>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] leading-snug text-gray-500">
                                <?php if(!empty($r['phone'])): ?><span class="truncate max-w-[150px]" title="<?php echo htmlspecialchars($r['phone']); ?>"><?php echo htmlspecialchars($r['phone']); ?></span><?php endif; ?>
                                <?php if(!empty($r['city'])): ?><span class="truncate max-w-[130px]" title="<?php echo htmlspecialchars($r['city']); ?>"><?php echo htmlspecialchars($r['city']); ?></span><?php endif; ?>
                                <?php if(!empty($r['role'])): ?><span class="truncate max-w-[220px]" title="<?php echo htmlspecialchars($r['role']); ?>">Роль: <?php echo htmlspecialchars($r['role']); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-2 py-3 align-top">
                        <div class="flex flex-col items-start gap-0.5 text-[11px] leading-tight whitespace-nowrap">
                            <?php if(!empty($r['crm_deal'])): ?><a href="<?php echo htmlspecialchars($r['crm_deal']); ?>" target="_blank" class="text-[#ffa048] hover:text-[#ff8f28] underline">Сделка</a><?php endif; ?>
                            <?php if(!empty($r['crm_contact'])): ?><a href="<?php echo htmlspecialchars($r['crm_contact']); ?>" target="_blank" class="text-[#ffa048] hover:text-[#ff8f28] underline">Контакт</a><?php endif; ?>
                            <?php if(asr_can_work_results() && !$isSpamView): ?><a href="?refresh_crm=<?php echo (int)$r['id']; ?>" onclick="return confirm('Обновить CRM по этому результату? Если сделку или контакт удалили в Bitrix24, система попробует создать их заново.');" class="text-orange-600 hover:text-orange-700 underline">Обновить CRM</a><?php endif; ?>
                            <?php if(empty($r['crm_deal']) && empty($r['crm_contact']) && $isSpamView): ?><span class="text-gray-300">—</span><?php endif; ?>
                        </div>
                    </td>
                    <td class="pl-2 pr-4 py-3 align-top text-right">
                        <div class="flex justify-end gap-2 items-start flex-nowrap">
                            <div class="flex flex-col items-stretch gap-1.5 min-w-[116px]">
                                <?php if($isSpamView): ?>
                                    <a href="?restore_spam=<?php echo (int)$r['id']; ?>" onclick="return confirm('Вернуть запись в обычную таблицу результатов?')" class="px-2 py-1.5 rounded-lg bg-green-50 text-green-700 hover:bg-green-100 text-[10px] font-bold uppercase text-center whitespace-nowrap">Вернуть</a>
                                <?php elseif(!$isCompleted && $resumeUrl): ?>
                                    <button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($resumeUrl, ENT_QUOTES); ?>'); alert('Ссылка продолжения скопирована');" class="px-2 py-1.5 rounded-lg bg-yellow-50 text-yellow-700 hover:bg-yellow-100 text-[10px] font-bold uppercase whitespace-nowrap">Продолжить</button>
                                    <a href="?send_client_email=<?php echo (int)$r['id']; ?>&email_type=resume" onclick="return confirm('Отправить клиенту письмо со ссылкой на продолжение теста?')" class="px-2 py-1.5 rounded-lg bg-gray-50 text-gray-500 hover:bg-gray-100 text-[10px] font-bold uppercase text-center whitespace-nowrap">Email клиенту</a>
                                <?php elseif($isCompleted && $sharedUrl): ?>
                                    <button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($sharedUrl, ENT_QUOTES); ?>'); alert('Ссылка на график для клиента скопирована');" class="px-2 py-1.5 rounded-lg bg-orange-50 text-[#ffa048] hover:bg-orange-100 text-[10px] font-bold uppercase whitespace-nowrap">График клиенту</button>
                                    <a href="?send_client_email=<?php echo (int)$r['id']; ?>&email_type=client_graph" onclick="return confirm('Отправить клиенту письмо со ссылкой на график?')" class="px-2 py-1.5 rounded-lg bg-gray-50 text-gray-500 hover:bg-gray-100 text-[10px] font-bold uppercase text-center whitespace-nowrap">Email клиенту</a>
                                <?php endif; ?>
                            </div>
                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>)" class="w-8 h-8 rounded-lg bg-gray-50 text-gray-300 hover:text-[#ffa048] hover:bg-orange-50 inline-flex items-center justify-center shrink-0" title="Редактировать"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-edit-gray.svg" alt="" aria-hidden="true"></button>
                            <?php if(isAdmin()): ?><a href="?delete_result=<?php echo (int)$r['id']; ?>" onclick="return confirm('Удалить?')" class="w-8 h-8 rounded-lg bg-gray-50 text-gray-200 hover:text-red-500 hover:bg-red-50 inline-flex items-center justify-center shrink-0" title="Удалить"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-delete-gray.svg" alt="" aria-hidden="true"></a><?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if($utmValue !== ''): ?>
                    <tr class="asr-result-row <?php echo $rowTone; ?> border-b border-gray-50 <?php echo $rowBg; ?> hover:bg-orange-50/30 transition-colors"><td colspan="6" class="px-4 pb-3 pt-0 align-top"><div class="asr-result-utm-box rounded-xl bg-white/65 px-3 py-2 text-[10px] leading-relaxed text-gray-500 font-medium break-words border border-orange-50"><span class="text-gray-400 font-bold uppercase tracking-wider mr-2">UTM</span><?php echo htmlspecialchars($utmValue); ?></div></td></tr>
                <?php else: ?>
                    <tr class="asr-result-row <?php echo $rowTone; ?> border-b border-gray-50 <?php echo $rowBg; ?>"><td colspan="6" class="p-0"></td></tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$records): ?><tr><td colspan="6" class="p-10 text-center text-gray-400 font-bold">Записей нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mobile-card-list p-4 space-y-4">
        <?php foreach($records as $r): ?>
        <?php $isCompleted = (($r['status'] ?? 'completed') === 'completed'); $resumeUrl = asrResumeUrl($r); $sharedUrl = $isCompleted ? asrSharedUrl($r) : ''; ?>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <label class="flex items-center gap-3 min-w-0"><input type="checkbox" value="<?php echo (int)$r['id']; ?>" class="compare-cb w-4 h-4 text-[#ffa048] rounded border-gray-300 shrink-0"><?php if($isCompleted && !$isSpamView): ?><a href="?view=<?php echo (int)$r['id']; ?>" class="font-black text-gray-900 text-sm truncate hover:text-[#ffa048] underline-offset-4"><?php echo htmlspecialchars($r['name']); ?></a><?php else: ?><span class="font-black text-gray-900 text-sm truncate"><?php echo htmlspecialchars($r['name']); ?></span><?php endif; ?></label>
                <div class="flex gap-3 items-center shrink-0"><button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE)); ?>)" class="text-gray-300 hover:text-[#ffa048]" title="Редактировать"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-edit-gray.svg" alt="" aria-hidden="true"></button></div>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-2 text-xs font-semibold text-gray-500">
                <div><span class="font-black text-gray-400 uppercase">Дата:</span> <?php echo date("d.m.Y H:i", strtotime($r['created_at'])); ?></div>
                <div><span class="font-black text-gray-400 uppercase">Статус:</span> <?php echo asrStatusBadge($r); ?></div>
                <?php if($isSpamView): ?><div><span class="font-black text-pink-600 uppercase">Спам:</span> <?php echo htmlspecialchars(asrSpamReasonText((string)($r['spam_reason'] ?? ''))); ?></div><a href="?restore_spam=<?php echo (int)$r['id']; ?>" class="text-green-700 underline font-bold">Вернуть в обычные результаты</a><?php endif; ?>
                <?php if(!$isSpamView && !$isCompleted && $resumeUrl): ?><div class="flex flex-wrap gap-3"><button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($resumeUrl, ENT_QUOTES); ?>'); alert('Ссылка продолжения скопирована');" class="text-yellow-700 underline font-bold">Скопировать ссылку продолжения</button><a href="?send_client_email=<?php echo (int)$r['id']; ?>&email_type=resume" class="text-gray-500 underline font-bold">Email клиенту</a></div><?php endif; ?>
                <?php if(!$isSpamView && $isCompleted && $sharedUrl): ?><div class="flex flex-wrap gap-3"><button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($sharedUrl, ENT_QUOTES); ?>'); alert('Ссылка на график для клиента скопирована');" class="text-[#ffa048] underline font-bold">График клиенту</button><a href="?send_client_email=<?php echo (int)$r['id']; ?>&email_type=client_graph" class="text-gray-500 underline font-bold">Email клиенту</a></div><?php endif; ?>
                <div><span class="font-black text-gray-400 uppercase">Телефон:</span> <?php echo htmlspecialchars($r['phone']); ?></div>
                <div><span class="font-black text-gray-400 uppercase">Город:</span> <?php echo htmlspecialchars($r['city']); ?></div>
                <div><span class="font-black text-gray-400 uppercase">Роль:</span> <?php echo htmlspecialchars($r['role']); ?></div>
                <?php if(!$isSpamView && asr_can_work_results()): ?><a href="?refresh_crm=<?php echo (int)$r['id']; ?>" class="text-orange-600 underline font-bold">Обновить CRM</a><?php endif; ?>
                <?php if($utmValue = asrCleanUtm($r)): ?><div class="break-words"><span class="font-black text-gray-400 uppercase">UTM:</span> <?php echo htmlspecialchars($utmValue); ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination-mobile p-6 bg-gray-50/50 border-t border-gray-50 flex items-center justify-center gap-2">
        <a href="<?php echo asrResultsPageUrl(1, $search); ?>" class="px-3 py-2 rounded-xl text-[10px] font-black uppercase bg-white border border-gray-200 text-gray-400">Начало</a>
        <?php $startP = max(1, $page - 2); $endP = min($totalPages, $page + 2); for($i=$startP; $i<=$endP; $i++): ?>
            <a href="<?php echo asrResultsPageUrl($i, $search); ?>" class="min-w-10 px-3 py-2 rounded-xl text-xs font-black text-center <?php echo $i === $page ? 'bg-[#ffa048] text-white' : 'bg-white border text-gray-500 hover:text-[#ffa048]'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <a href="<?php echo asrResultsPageUrl($totalPages, $search); ?>" class="px-3 py-2 rounded-xl text-[10px] font-black uppercase bg-white border border-gray-200 text-gray-400">Конец</a>
    </div>
    <?php endif; ?>
</div>
