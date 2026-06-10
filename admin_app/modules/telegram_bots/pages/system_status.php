<?php
defined('ASR_ADMIN') || exit;

$h = isset($h) && is_callable($h) ? $h : static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$systemCurrentRole = function_exists('asr_current_role') ? (string)asr_current_role() : (string)($_SESSION['user_role'] ?? '');
$isSuper = $systemCurrentRole === 'superadmin';
if (!$isSuper && function_exists('asr_is_protected_user')) {
    $isSuper = (bool)asr_is_protected_user([
        'id' => (int)($_SESSION['user_id'] ?? 0),
        'role' => $systemCurrentRole,
    ]);
}
if (!$isSuper): ?>
<section class="bg-white rounded-3xl border border-red-100 p-8 text-red-700 font-semibold">Раздел доступен только суперадминистратору.</section>
<?php return; endif;

require_once dirname(__DIR__) . '/system_status_service.php';
$initialPlatform = asr_tg_system_platform((string)($_GET['platform'] ?? 'telegram'));
$sys = asr_tg_system_collect($pdo, $initialPlatform);
$shortDate = static function($value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : $value;
};
?>
<style>
.tg-system-page{display:grid;gap:18px}.tg-system-head{background:#fff;border:1px solid #edf0f2;border-radius:24px;padding:22px;box-shadow:0 10px 28px rgba(15,23,42,.04);display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.tg-system-title{font-size:22px;font-weight:600;color:#1f2937;margin:0}.tg-system-sub{margin-top:6px;font-size:13px;font-weight:500;color:#8a94a6;line-height:1.45}.tg-system-refresh{height:42px;border:1px solid #fed7aa;background:#fff7ed;color:#c2410c;border-radius:14px;padding:0 15px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.tg-system-tabs{display:inline-flex;gap:8px;background:#f9fafb;border:1px solid #edf0f2;border-radius:18px;padding:6px;align-items:center}.tg-system-tab{height:38px;border:0;background:transparent;color:#6b7280;border-radius:13px;padding:0 15px;font-size:12px;font-weight:600;cursor:pointer}.tg-system-tab.is-active{background:#fff7ed;color:#c2410c;box-shadow:0 7px 18px rgba(194,65,12,.10)}.tg-system-panel{display:grid;gap:16px}.tg-system-platform-head{background:#fff;border:1px solid #edf0f2;border-radius:22px;padding:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;box-shadow:0 10px 28px rgba(15,23,42,.025)}.tg-system-platform-head h4{margin:0;font-size:17px;font-weight:600;color:#1f2937}.tg-system-platform-head p{margin:5px 0 0;font-size:12px;font-weight:500;color:#8a94a6;line-height:1.45}.tg-system-platform-pill{border-radius:999px;border:1px solid #fed7aa;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:700;padding:8px 11px;white-space:nowrap}.tg-system-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.tg-system-card{background:#fff;border:1px solid #edf0f2;border-radius:24px;padding:18px;box-shadow:0 10px 28px rgba(15,23,42,.035);min-width:0}.tg-system-card--wide{grid-column:1/-1}.tg-system-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.tg-system-card h4{margin:0;font-size:16px;font-weight:600;color:#1f2937}.tg-system-card p{margin:5px 0 0;font-size:12px;font-weight:500;color:#9ca3af;line-height:1.45}.tg-system-badge{border-radius:999px;padding:7px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}.tg-system-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.tg-system-badge--warning{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}.tg-system-badge--danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.tg-system-badge--neutral{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}.tg-system-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.tg-system-metric{background:#f9fafb;border:1px solid #f1f3f5;border-radius:16px;padding:12px}.tg-system-metric-value{font-size:20px;font-weight:600;color:#111827}.tg-system-metric-label{margin-top:4px;font-size:11px;font-weight:600;color:#9ca3af}.tg-system-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.tg-system-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #edf0f2;background:#f9fafb;color:#6b7280;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:600}.tg-system-chip b{color:#374151;font-weight:600}.tg-system-list{display:grid;gap:8px;margin-top:13px}.tg-system-row{border:1px solid #f1f3f5;background:#fbfbfc;border-radius:16px;padding:11px 12px}.tg-system-row-top{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:11px;font-weight:700;color:#9ca3af}.tg-system-row-text{margin-top:6px;font-size:12px;font-weight:500;color:#4b5563;line-height:1.45;word-break:break-word}.tg-system-muted{font-size:12px;font-weight:600;color:#9ca3af}.tg-system-note{border:1px solid #fed7aa;background:#fff7ed;color:#7c2d12;border-radius:18px;padding:13px 15px;font-size:12px;font-weight:600;line-height:1.45}.tg-system-loading{border:1px dashed #fed7aa;background:#fff7ed;color:#9a3412;border-radius:18px;padding:18px;font-size:13px;font-weight:600}@media(max-width:980px){.tg-system-grid{grid-template-columns:1fr}.tg-system-head{flex-direction:column}.tg-system-metrics{grid-template-columns:1fr 1fr}}@media(max-width:560px){.tg-system-metrics{grid-template-columns:1fr}.tg-system-tabs{width:100%;display:grid;grid-template-columns:1fr 1fr}.tg-system-tab{width:100%}}
</style>
<section class="tg-system-page" data-system-status-root data-platform="<?php echo $h($initialPlatform); ?>">
    <div class="tg-system-head">
        <div>
            <h3 class="tg-system-title">Состояние системы</h3>
            <div class="tg-system-sub" data-system-updated>Обновлено: <?php echo $h($shortDate($sys['generated_at'] ?? '')); ?></div>
        </div>
        <a class="tg-system-refresh" data-system-refresh href="admin.php?tab=telegram_bots&page=system_status&platform=<?php echo $h($initialPlatform); ?>">Обновить</a>
    </div>


    <div class="tg-system-tabs" role="tablist" aria-label="Платформа диагностики">
        <button type="button" class="tg-system-tab <?php echo $initialPlatform === 'telegram' ? 'is-active' : ''; ?>" data-system-platform="telegram">Telegram</button>
        <button type="button" class="tg-system-tab <?php echo $initialPlatform === 'vk' ? 'is-active' : ''; ?>" data-system-platform="vk">VK</button>
    </div>

    <div class="tg-system-panel" data-system-panel><?php echo asr_tg_system_render_panel($sys, $h); ?></div>
</section>
<script>
(function(){
  var root = document.querySelector('[data-system-status-root]');
  if(!root) return;
  var panel = root.querySelector('[data-system-panel]');
  var updated = root.querySelector('[data-system-updated]');
  var refresh = root.querySelector('[data-system-refresh]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-system-platform]'));
  function setActive(platform){
    root.setAttribute('data-platform', platform);
    tabs.forEach(function(btn){ btn.classList.toggle('is-active', btn.getAttribute('data-system-platform') === platform); });
    if(refresh) refresh.setAttribute('href', 'admin.php?tab=telegram_bots&page=system_status&platform=' + encodeURIComponent(platform));
  }
  function load(platform){
    setActive(platform);
    if(panel) panel.innerHTML = '<div class="tg-system-loading">Загружаю диагностику ' + (platform === 'vk' ? 'VK' : 'Telegram') + '...</div>';
    var url = 'admin.php?tab=telegram_bots&page=system_status&tg_ajax=system_status_panel&platform=' + encodeURIComponent(platform);
    fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Не удалось загрузить диагностику.');
        if(panel) panel.innerHTML = data.html || '';
        if(updated) updated.textContent = 'Обновлено: ' + (data.generated_at_label || '—');
      })
      .catch(function(err){
        if(panel) panel.innerHTML = '<div class="tg-system-loading">Ошибка загрузки: ' + String((err && err.message) ? err.message : err) + '</div>';
      });
  }
  tabs.forEach(function(btn){
    btn.addEventListener('click', function(){
      var platform = btn.getAttribute('data-system-platform') || 'telegram';
      if(root.getAttribute('data-platform') === platform) return;
      load(platform);
    });
  });
})();
</script>
