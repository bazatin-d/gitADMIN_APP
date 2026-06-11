<?php
defined('ASR_ADMIN') || exit;
$asrLayoutSettings = asr_get_all_settings();
$asrHtmlTitle = trim((string)($asrLayoutSettings['app_html_title'] ?? 'Система тестирования АВМ')) ?: 'Система тестирования АВМ';
$asrAppName = trim((string)($asrLayoutSettings['app_name'] ?? 'АСР АВМ')) ?: 'АСР АВМ';
$asrHeaderTitle = trim((string)($asrLayoutSettings['app_header_title'] ?? 'СИСТЕМА ТЕСТИРОВАНИЯ АВМ')) ?: 'СИСТЕМА ТЕСТИРОВАНИЯ АВМ';
$asrIsDialogsFullscreen = !$is_shared_view
    && (string)($current_tab ?? '') === 'telegram_bots'
    && (string)($_GET['page'] ?? 'channels') === 'messages';
$asrIsFlowFullscreen = !$is_shared_view
    && (string)($current_tab ?? '') === 'telegram_bots'
    && (string)($_GET['page'] ?? 'channels') === 'scenario_flow';
$asrBodyClass = $asrIsDialogsFullscreen
    ? 'asr-dialogs-fullscreen relative custom-scrollbar text-left'
    : ($asrIsFlowFullscreen
        ? 'asr-flow-fullscreen relative text-left'
        : 'p-4 lg:p-8 relative custom-scrollbar text-left');
$asrAdminShellClass = $asrIsDialogsFullscreen
    ? 'admin-shell admin-main asr-dialogs-main'
    : ($asrIsFlowFullscreen
        ? 'admin-shell admin-main asr-flow-main'
        : 'admin-shell admin-main max-w-[1440px] mx-auto');
$asrCurrentUserPwaDialogNotifyEnabled = false;
try {
    if (!$is_shared_view && isset($pdo) && (int)($_SESSION['user_id'] ?? 0) > 0) {
        $asrUsersRepositoryPath = __DIR__ . '/../modules/users/repository.php';
        if (is_file($asrUsersRepositoryPath)) {
            require_once $asrUsersRepositoryPath;
            if (function_exists('asr_users_repository_get_pwa_dialog_notify_enabled')) {
                $asrCurrentUserPwaDialogNotifyEnabled = asr_users_repository_get_pwa_dialog_notify_enabled($pdo, (int)$_SESSION['user_id']) === 1;
            }
        }
    }
} catch (Throwable $e) {
    $asrCurrentUserPwaDialogNotifyEnabled = false;
}
$asrCsrfTokenForJs = function_exists('asr_csrf_token') ? asr_csrf_token() : (string)($_SESSION['csrf_token'] ?? '');

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($asrHtmlTitle); ?></title>
    <?php if (!$is_shared_view): ?>
    <script>
        (function() {
            try {
                if (localStorage.getItem('asr-admin-theme') === 'dark') {
                    document.documentElement.classList.add('asr-dark');
                }
            } catch (e) {}
        })();
    </script>
    <?php endif; ?>
    <?php if (!$is_shared_view): ?>
    <meta name="theme-color" content="#FFA048">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($asrAppName); ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/admin.php?admin_manifest=1">
    <link rel="icon" type="image/png" sizes="192x192" href="/pwa/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/pwa/icons/icon-180.png">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { font-family: 'Montserrat', sans-serif; background-color: #f8fafc; background-image: url('<?php echo htmlspecialchars(function_exists('asr_config_grid_background_url') ? asr_config_grid_background_url() : '/img4bitrix/grid_amo.png', ENT_QUOTES, 'UTF-8'); ?>'); background-repeat: repeat; overflow-x: hidden; }
        input, button, select, textarea { font-size: 16px; }
        .screenshot-area { background: white; padding: 50px 40px; border-radius: 40px; box-shadow: 0 20px 60px rgba(0,0,0,0.1); width: 1200px; margin: 0 auto; }
        .chart-scroll { width: 100%; overflow-x: visible; }
        .chart-wrapper { position: relative; width: 1100px; height: 596px; margin: 0 auto; background-image: url('/chart_background_analiz_1100.png'); background-size: 1100px 596px; background-repeat: no-repeat; border: 1px solid #eee; }
        canvas#ocaCanvas { position: absolute; top: 0; left: 0; z-index: 10; }
        .chart-mobile-preview { display: none; }
        .chart-mobile-preview-button { display: block; width: 100%; border: 0; padding: 0; background: transparent; text-align: left; }
        .chart-mobile-preview-button:disabled { opacity: .75; }
        .chart-mobile-preview img { display: block; width: 100%; height: auto; border: 1px solid #e5e7eb; border-radius: 18px; box-shadow: 0 10px 28px rgba(15,23,42,.10); background: #fff; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #ffa048; border-radius: 10px; }
        .mobile-card-list { display: none; }
        .drawer-backdrop { opacity: 0; pointer-events: none; transition: opacity .22s ease; }
        .drawer-panel { transform: translateX(-105%); transition: transform .28s cubic-bezier(.2,.8,.2,1); will-change: transform; touch-action: pan-y; }
        body.drawer-open .drawer-backdrop { opacity: 1; pointer-events: auto; }
        body.drawer-open .drawer-panel { transform: translateX(0); }
        .admin-main h1, .admin-main h2, .admin-main h3, .admin-main h4, .modal-panel h3 { color: #1f2937 !important; font-weight: 650 !important; }
        .admin-title { color: #FFA048 !important; font-weight: 700 !important; }
        .drawer-edge-zone { position: fixed; left: 0; top: 0; width: 18px; height: 100vh; z-index: 35; }
        .drawer-panel { padding: 14px !important; }
        .drawer-head { padding-bottom: 16px; }
        .drawer-logo { width: 112px; height: auto; object-fit: contain; }
        .logo-dark { display: none !important; }
        html.asr-dark .logo-light { display: none !important; }
        html.asr-dark .logo-dark { display: block !important; }
        .drawer-close { width: 38px; height: 38px; border-radius: 14px; }
        .drawer-nav { padding-top: 12px; padding-bottom: 12px; }
        .drawer-link { min-height: 46px; padding: 11px 14px; font-size: 12px; line-height: 1.12; font-weight: 700; letter-spacing: .09em; }
        .drawer-link svg { width: 20px; height: 20px; flex: 0 0 auto; }
        .drawer-group { border-radius: 24px; }
        .drawer-group > summary { list-style: none; }
        .drawer-group > summary::-webkit-details-marker { display: none; }
        .drawer-group-summary { min-height: 46px; padding: 11px 14px; font-size: 12px; line-height: 1.12; font-weight: 800; letter-spacing: .09em; color: #6b7280; cursor: pointer; user-select: none; }
        .drawer-group-summary:hover { background: #fff7ed; color: #FFA048; }
        .drawer-group-summary svg:first-child { width: 20px; height: 20px; flex: 0 0 auto; }
        .drawer-chevron { transition: transform .18s ease; }
        .drawer-group[open] .drawer-chevron { transform: rotate(180deg); }
        .drawer-subnav { display: grid; gap: 6px; margin-top: 6px; padding-left: 8px; padding-bottom: 2px; }
        .drawer-subnav .drawer-link { min-height: 38px; padding: 9px 12px; border-radius: 16px; font-size: 10px; letter-spacing: .08em; }
        .drawer-subnav .drawer-link svg { width: 17px; height: 17px; }
        .drawer-footer { padding-top: 12px; gap: 8px; }

        .drawer-copyright { padding: 10px 4px 0; color: #9ca3af; font-size: 10px; line-height: 1.35; font-weight: 600; text-align: center; }
        html.asr-dark .drawer-copyright { color: #6F7A88 !important; }
        .drawer-footer-btn { min-height: 44px; padding: 11px 14px; font-size: 10px; line-height: 1.2; font-weight: 700; letter-spacing: .08em; }
        
.admin-main { transition: transform .2s ease; }
        .admin-header { position: sticky; top: 10px; z-index: 30; transition: padding .18s ease, border-radius .18s ease, box-shadow .18s ease, transform .18s ease; }
        .admin-header.is-compact { padding-top: 12px !important; padding-bottom: 12px !important; border-radius: 20px !important; box-shadow: 0 14px 32px rgba(15,23,42,.10) !important; }
        .admin-header.is-compact .admin-logo { width: 96px !important; }
        .admin-header.is-compact .admin-title { font-size: 11px !important; }
        .admin-header.is-compact .admin-user { display: none !important; }
        .modal-panel { max-height: calc(100vh - 24px); }
        .modal-form { overflow-y: auto; }

        .help-content iframe { max-width: 100%; }
        .help-content img { max-width: 100%; height: auto; border-radius: 16px; }

        .help-video-block { margin-bottom: 22px; border-radius: 24px; overflow: hidden; background: #111827; }
        .help-video-block iframe { display: block; width: 100%; min-height: 360px; border: 0; }
        .users-mobile-list { display: none; }
        .edit-user-role-wrap.is-protected { display: none; }
        .modal-panel { max-height: calc(100vh - 32px); display: flex; flex-direction: column; }
        .modal-form { overflow-y: auto; }
        @media (max-width: 767px) {
            .users-desktop-table { display: none !important; }
            .users-mobile-list { display: grid !important; gap: 12px; }
            .help-video-block iframe { min-height: 220px; }
        }
        .theme-row { display: grid; grid-template-columns: 54px minmax(0, 1fr); gap: 12px; align-items: stretch; }
        .theme-toggle { width: 54px; min-width: 54px; height: 48px; border-radius: 18px; border: 1px solid #e5e7eb; background: #f3f4f6; color: #6b7280; display: inline-flex; align-items: center; justify-content: center; position: relative; transition: all .2s ease; }
        .theme-toggle:hover { color: #FFA048; border-color: #FFD0A3; background: #fff7ed; }
        .theme-toggle svg { width: 20px; height: 20px; transition: opacity .18s ease, transform .18s ease; position: absolute; }
        .theme-toggle .theme-moon { opacity: 0; transform: rotate(-45deg) scale(.75); }
        html.asr-dark .theme-toggle .theme-sun { opacity: 0; transform: rotate(45deg) scale(.75); }
        html.asr-dark .theme-toggle .theme-moon { opacity: 1; transform: rotate(0) scale(1); }

        html.asr-dark body { background-color: #111317 !important; background-image: none !important; color: #E5E7EB; }
        html.asr-dark .admin-header,
        html.asr-dark .drawer-panel,
        html.asr-dark .screenshot-area,
        html.asr-dark .modal-panel,
        html.asr-dark .bg-white { background-color: #191D23 !important; border-color: #2B313A !important; color: #E5E7EB !important; }
        html.asr-dark .bg-gray-50,
        html.asr-dark .bg-gray-50\/50,
        html.asr-dark .bg-gray-100,
        html.asr-dark .hover\:bg-gray-50:hover,
        html.asr-dark .hover\:bg-gray-100:hover { background-color: #222832 !important; }
        html.asr-dark .border-gray-50,
        html.asr-dark .border-gray-100,
        html.asr-dark .border-gray-200,
        html.asr-dark .border-gray-300 { border-color: #2B313A !important; }
        html.asr-dark .text-gray-300,
        html.asr-dark .text-gray-400 { color: #8F9AA8 !important; }
        html.asr-dark .text-gray-500,
        html.asr-dark .text-gray-600,
        html.asr-dark .text-gray-700 { color: #C8D0DA !important; }
        html.asr-dark .text-gray-800,
        html.asr-dark .text-gray-900 { color: #F3F4F6 !important; }
        html.asr-dark input,
        html.asr-dark select,
        html.asr-dark textarea,
        html.asr-dark [contenteditable="true"] { background-color: #14181E !important; border-color: #303844 !important; color: #EEF2F7 !important; caret-color: #FFA048; }
        html.asr-dark input::placeholder,
        html.asr-dark textarea::placeholder { color: #6F7A88 !important; }
        html.asr-dark table { color: #E5E7EB !important; }
        html.asr-dark thead,
        html.asr-dark tbody tr:hover { background-color: #222832 !important; }
        html.asr-dark tbody tr { border-color: #2B313A !important; }
        html.asr-dark .asr-result-row-even { background-color: #15191F !important; }
        html.asr-dark .asr-result-row-odd { background-color: #1D232B !important; }
        html.asr-dark .asr-result-row:hover { background-color: #252C36 !important; }
        html.asr-dark .asr-result-utm-box { background-color: rgba(20,24,30,.72) !important; border-color: #303844 !important; }
        html.asr-dark .asr-role-help { background-color: #222832 !important; border-color: #303844 !important; color: #E5E7EB !important; }
        html.asr-dark .asr-role-help .text-gray-800 { color: #F3F4F6 !important; }
        html.asr-dark .asr-role-help .text-gray-500 { color: #C8D0DA !important; }
        html.asr-dark .drawer-group-summary { color: #C8D0DA !important; }
        html.asr-dark .drawer-group-summary:hover { background-color: #222832 !important; color: #FFA048 !important; }
        html.asr-dark .drawer-subnav .drawer-link:not(.bg-\[\#FFA048\]) { color: #C8D0DA !important; }
        html.asr-dark .drawer-link:not(.bg-\[\#FFA048\]) { color: #C8D0DA !important; }
        html.asr-dark .drawer-link:not(.bg-\[\#FFA048\]):hover { background-color: rgba(255,160,72,.12) !important; color: #FFA048 !important; }
        html.asr-dark .drawer-close,
        html.asr-dark .theme-toggle { background-color: #222832 !important; border-color: #303844 !important; color: #C8D0DA !important; }
        html.asr-dark .theme-toggle:hover { background-color: rgba(255,160,72,.12) !important; border-color: rgba(255,160,72,.45) !important; color: #FFA048 !important; }
        html.asr-dark .shadow-sm,
        html.asr-dark .shadow-md,
        html.asr-dark .shadow-lg,
        html.asr-dark .shadow-2xl { box-shadow: 0 18px 50px rgba(0,0,0,.32) !important; }
        html.asr-dark .chart-mobile-preview img { background: #14181E !important; border-color: #303844 !important; box-shadow: 0 10px 28px rgba(0,0,0,.32) !important; }
        html.asr-dark .chart-wrapper { background-image: none !important; background-color: #14181E !important; border-color: #303844 !important; overflow: hidden; }
        html.asr-dark .chart-wrapper::before { content: ""; position: absolute; inset: 0; background-image: url('/chart_background_analiz_1100.png'); background-size: 1100px 596px; background-repeat: no-repeat; filter: invert(1) hue-rotate(180deg) brightness(.78) contrast(.92); opacity: .64; z-index: 1; }
        html.asr-dark canvas#ocaCanvas { z-index: 10; }
        html.asr-dark .help-content { color: #E5E7EB !important; }
        html.asr-dark .help-content p,
        html.asr-dark .help-content div,
        html.asr-dark .help-content span,
        html.asr-dark .help-content li,
        html.asr-dark .help-content strong,
        html.asr-dark .help-content em { color: #E5E7EB !important; }
        html.asr-dark .help-content h1,
        html.asr-dark .help-content h2,
        html.asr-dark .help-content h3,
        html.asr-dark .help-content h4 { color: #FFA048 !important; }
        html.asr-dark .help-content a { color: #FFA048 !important; }
        html.asr-dark .oca-interpretation-block,
        html.asr-dark .oca-interpretation-card { background-color: #191D23 !important; border-color: #2B313A !important; color: #E5E7EB !important; }
        html.asr-dark .oca-interpretation-head,
        html.asr-dark .oca-interpretation-note { background-color: #222832 !important; border-color: #2B313A !important; }
        html.asr-dark .oca-interpretation-title { color: #FFA048 !important; }
        html.asr-dark .oca-interpretation-subtitle,
        html.asr-dark .oca-interpretation-text,
        html.asr-dark .oca-interpretation-text p,
        html.asr-dark .oca-interpretation-text span,
        html.asr-dark .oca-interpretation-text li { color: #D7DEE8 !important; }
        html.asr-dark .oca-interpretation-section-title { color: #F3F4F6 !important; }

        /* Dark theme hardening 2026-05-30: единая защита от светлых Tailwind/inline-элементов */
        html.asr-dark .admin-title,
        html.asr-dark .admin-header .admin-title { color: #F3F4F6 !important; }
        html.asr-dark .admin-main h1,
        html.asr-dark .admin-main h2,
        html.asr-dark .admin-main h3,
        html.asr-dark .admin-main h4,
        html.asr-dark .modal-panel h3 { color: #F3F4F6 !important; }
        html.asr-dark .admin-user { color: #A7B0BE !important; }
        html.asr-dark .bg-white\/80,
        html.asr-dark .bg-white\/90,
        html.asr-dark .bg-white\/95 { background-color: rgba(25,29,35,.92) !important; }
        html.asr-dark .bg-gray-200,
        html.asr-dark .bg-gray-300,
        html.asr-dark .bg-slate-50,
        html.asr-dark .bg-slate-100,
        html.asr-dark .bg-zinc-50,
        html.asr-dark .bg-zinc-100,
        html.asr-dark .bg-neutral-50,
        html.asr-dark .bg-neutral-100,
        html.asr-dark .bg-stone-50,
        html.asr-dark .bg-stone-100 { background-color: #222832 !important; }
        html.asr-dark .bg-orange-50,
        html.asr-dark .bg-amber-50,
        html.asr-dark .hover\:bg-orange-50:hover,
        html.asr-dark .hover\:bg-amber-50:hover { background-color: rgba(255,160,72,.12) !important; }
        html.asr-dark .bg-red-50,
        html.asr-dark .hover\:bg-red-50:hover { background-color: rgba(220,38,38,.12) !important; }
        html.asr-dark .bg-green-50,
        html.asr-dark .hover\:bg-green-50:hover { background-color: rgba(22,163,74,.12) !important; }
        html.asr-dark .border-slate-100,
        html.asr-dark .border-slate-200,
        html.asr-dark .border-zinc-100,
        html.asr-dark .border-zinc-200,
        html.asr-dark .border-neutral-100,
        html.asr-dark .border-neutral-200,
        html.asr-dark .border-orange-100,
        html.asr-dark .border-amber-100 { border-color: #303844 !important; }
        html.asr-dark .border-red-100,
        html.asr-dark .border-red-200 { border-color: rgba(248,113,113,.35) !important; }
        html.asr-dark .border-green-100,
        html.asr-dark .border-green-200 { border-color: rgba(74,222,128,.30) !important; }
        html.asr-dark .text-black,
        html.asr-dark .text-slate-700,
        html.asr-dark .text-slate-800,
        html.asr-dark .text-slate-900,
        html.asr-dark .text-zinc-700,
        html.asr-dark .text-zinc-800,
        html.asr-dark .text-zinc-900,
        html.asr-dark .text-neutral-700,
        html.asr-dark .text-neutral-800,
        html.asr-dark .text-neutral-900,
        html.asr-dark .text-stone-700,
        html.asr-dark .text-stone-800,
        html.asr-dark .text-stone-900 { color: #F3F4F6 !important; }
        html.asr-dark .text-slate-400,
        html.asr-dark .text-slate-500,
        html.asr-dark .text-slate-600,
        html.asr-dark .text-zinc-400,
        html.asr-dark .text-zinc-500,
        html.asr-dark .text-zinc-600,
        html.asr-dark .text-neutral-400,
        html.asr-dark .text-neutral-500,
        html.asr-dark .text-neutral-600,
        html.asr-dark .text-stone-400,
        html.asr-dark .text-stone-500,
        html.asr-dark .text-stone-600 { color: #C8D0DA !important; }
        html.asr-dark .text-red-700,
        html.asr-dark .text-red-600 { color: #FCA5A5 !important; }
        html.asr-dark .text-green-700,
        html.asr-dark .text-green-600 { color: #86EFAC !important; }
        html.asr-dark .text-orange-700,
        html.asr-dark .text-orange-600,
        html.asr-dark .text-amber-700,
        html.asr-dark .text-amber-600 { color: #FDBA74 !important; }
        html.asr-dark .divide-gray-50 > :not([hidden]) ~ :not([hidden]),
        html.asr-dark .divide-gray-100 > :not([hidden]) ~ :not([hidden]),
        html.asr-dark .divide-gray-200 > :not([hidden]) ~ :not([hidden]) { border-color: #2B313A !important; }
        html.asr-dark code,
        html.asr-dark pre { background-color: #111827 !important; border-color: #303844 !important; color: #E5E7EB !important; }
        html.asr-dark .hover\:text-red-500:hover { color: #F87171 !important; }
        html.asr-dark .hover\:text-\[\#FFA048\]:hover { color: #FFA048 !important; }
        @media (max-width: 767px) {
            body { padding: 12px !important; }
            .admin-shell { width: 100%; max-width: 100%; }
            .admin-header { padding: 14px !important; border-radius: 22px !important; }
            .admin-brand { min-width: 0; align-items: center !important; gap: 10px !important; }
            .admin-logo { width: 108px !important; height: auto !important; }
            .admin-title { font-size: 12px !important; line-height: 1.25 !important; }
            .admin-user { font-size: 9px !important; }
            .drawer-panel { width: min(82vw, 320px) !important; padding: 14px !important; }
            .drawer-head { padding-bottom: 14px !important; }
            .drawer-logo { width: 104px !important; }
            .drawer-close { width: 36px !important; height: 36px !important; border-radius: 13px !important; }
            .drawer-nav { padding-top: 12px !important; padding-bottom: 12px !important; gap: 6px !important; }
            .drawer-link { min-height: 44px !important; padding: 11px 14px !important; border-radius: 16px !important; font-size: 11px !important; letter-spacing: .1em !important; }
            .drawer-link svg { width: 18px !important; height: 18px !important; }
            .drawer-footer { padding-top: 12px !important; gap: 8px !important; }
            .drawer-footer-btn { min-height: 42px !important; padding: 10px 13px !important; border-radius: 16px !important; font-size: 10px !important; letter-spacing: .08em !important; }
            .mobile-stack { flex-direction: column !important; align-items: stretch !important; }
            .mobile-full { width: 100% !important; }
            .mobile-center-left { align-items: flex-start !important; text-align: left !important; }
            .mobile-grid-1 { grid-template-columns: 1fr !important; }
            .mobile-p-4 { padding: 16px !important; }
            .mobile-gap-2 { gap: 8px !important; }
            .desktop-table { display: none !important; }
            .mobile-card-list { display: grid; gap: 12px; }
            .pagination-mobile { overflow-x: auto; justify-content: flex-start !important; -webkit-overflow-scrolling: touch; }
            .screenshot-area { width: 100%; max-width: 100%; min-width: 0; padding: 18px 12px; border-radius: 24px; overflow-x: hidden; }
            .chart-mobile-preview { display: block; }
            .chart-scroll { position: absolute !important; left: -99999px !important; top: -99999px !important; width: 1100px !important; height: 596px !important; overflow: hidden !important; padding: 0 !important; }
            .chart-wrapper { min-width: 1100px; margin-left: 0; margin-right: 0; }
            .profile-head { flex-direction: column !important; align-items: flex-start !important; gap: 12px !important; }
            .profile-role { width: 100%; text-align: left !important; }
            .share-row { flex-direction: column !important; align-items: stretch !important; }
            .modal-panel { max-height: calc(100vh - 24px); overflow: hidden; border-radius: 24px !important; }
            .modal-form { padding: 16px !important; max-height: calc(100vh - 96px); overflow-y: auto; }
        }

/* Layout SVG icons pass */
.ly2-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;object-fit:contain;flex:0 0 auto;opacity:.95}
.ly2-icon-sm{width:16px;height:16px}
.ly2-icon-lg{width:24px;height:24px}
.ly2-btn{display:inline-flex!important;flex-direction:row!important;align-items:center!important;justify-content:center!important;gap:8px!important;line-height:1!important;white-space:nowrap!important}
.ly2-menu-icon{width:25px;height:25px;display:block}
.theme-toggle .ly2-theme-icon{width:21px;height:21px;transition:opacity .18s ease,transform .18s ease;position:absolute}
.theme-toggle .ly2-theme-moon{opacity:0;transform:rotate(-45deg) scale(.75)}
html.asr-dark .theme-toggle .ly2-theme-sun{opacity:0;transform:rotate(45deg) scale(.75)}
html.asr-dark .theme-toggle .ly2-theme-moon{opacity:1;transform:rotate(0) scale(1)}


/* Menu polish 2026-05-30: softer active state and inner gutters */
.drawer-panel {
    padding-left: 18px !important;
    padding-right: 18px !important;
}
.drawer-nav {
    padding-left: 8px !important;
    padding-right: 8px !important;
}
.drawer-link,
.drawer-group-summary {
    margin-left: 0 !important;
    margin-right: 0 !important;
    min-height: 46px !important;
    padding-left: 14px !important;
    padding-right: 14px !important;
    box-sizing: border-box !important;
}
.drawer-group[open] > .drawer-group-summary {
    background: #fff7ed !important;
    color: #C96F2B !important;
    border: 1px solid #fed7aa !important;
    box-shadow: 0 8px 20px rgba(255, 160, 72, .06) !important;
}
.drawer-footer,
.drawer-panel > .px-0 {
    padding-left: 8px !important;
    padding-right: 8px !important;
}
#pwaInstallButton {
    width: 100% !important;
    min-height: 42px !important;
    border-radius: 18px !important;
}
#pwaInstallButton.is-install-hidden {
    display: none !important;
}
.drawer-compact-footer {
    padding-left: 8px !important;
    padding-right: 8px !important;
    padding-top: 12px !important;
}
.asr-drawer-quick-row {
    display: grid;
    grid-template-columns: 54px minmax(0, 1fr);
    gap: 10px;
    align-items: stretch;
}
.asr-drawer-settings-summary {
    min-height: 48px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 0 16px;
    color: #6b7280;
    cursor: pointer;
    user-select: none;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    border: 1px solid #eef0f3;
    border-radius: 22px;
    background: #f7f8fa;
    min-width: 0;
}
.asr-drawer-settings-caret {
    color: #9ca3af;
    font-size: 14px;
    line-height: 1;
    transition: transform .18s ease;
}
.asr-drawer-settings-summary[aria-expanded="true"] .asr-drawer-settings-caret { transform: rotate(180deg); }
.asr-drawer-settings-body {
    display: none;
    width: 100%;
    gap: 8px;
    padding: 10px;
    border: 1px solid #eef0f3;
    border-radius: 24px;
    background: #f7f8fa;
    overflow: hidden;
}
.asr-drawer-settings-body.is-open {
    display: grid;
}
.asr-drawer-settings-row {
    width: 100%;
    min-height: 42px;
    border-radius: 18px;
    background: #fff;
    color: #6b7280;
    border: 1px solid #edf0f3;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 9px !important;
    padding: 0 13px;
    font-size: 12px;
    font-weight: 500 !important;
    letter-spacing: .015em !important;
    text-transform: none;
    white-space: nowrap;
    transition: background .16s ease, color .16s ease, border-color .16s ease;
}
.asr-drawer-settings-row span:last-child {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.asr-drawer-settings-row:hover {
    background: #fff7ed;
    color: #C96F2B;
    border-color: #fed7aa;
}
.asr-drawer-settings-row.asr-danger:hover {
    background: #fef2f2;
    color: #ef4444;
    border-color: #fecaca;
}
.asr-drawer-theme-btn {
    width: 54px;
    height: 48px;
    min-width: 54px;
    border-radius: 22px;
    border: 1px solid #eef0f3;
    background: #f7f8fa;
    color: #6b7280;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.asr-drawer-theme-btn:hover {
    background: #fff7ed;
    color: #C96F2B;
    border-color: #fed7aa;
}
.asr-drawer-theme-btn .ly2-theme-icon {
    width: 20px;
    height: 20px;
}
html.asr-dark .asr-drawer-settings-summary,
html.asr-dark .asr-drawer-settings-body {
    background: #191D23;
    border-color: #2B313A;
    color: #C8D0DA;
}
html.asr-dark .asr-drawer-theme-btn {
    background: #191D23;
    color: #C8D0DA;
    border-color: #2B313A;
}
html.asr-dark .asr-drawer-theme-btn:hover {
    background: rgba(255,160,72,.12);
    color: #FFA048;
    border-color: rgba(255,160,72,.26);
}
html.asr-dark .asr-drawer-settings-row {
    background: #222832;
    color: #C8D0DA;
    border-color: #2B313A;
}
html.asr-dark .asr-drawer-settings-row:hover {
    background: rgba(255,160,72,.12);
    color: #FFA048;
    border-color: rgba(255,160,72,.26);
}
@media (max-width: 768px) {
    .drawer-footer.drawer-compact-footer {
        padding-top: 10px !important;
        padding-bottom: 0 !important;
        border-top: 1px solid #f3f4f6 !important;
        gap: 0 !important;
    }
    .drawer-footer.drawer-compact-footer .drawer-copyright {
        display: none !important;
    }
    .asr-drawer-settings-summary { min-height: 46px; }
    .asr-drawer-settings-row { min-height: 40px; }
}
html.asr-dark .drawer-group[open] > .drawer-group-summary {
    background: rgba(255,160,72,.12) !important;
    border-color: rgba(255,160,72,.26) !important;
    color: #FFA048 !important;
}
@media (max-width: 768px) {
    .drawer-panel {
        padding-left: 14px !important;
        padding-right: 14px !important;
    }
    .drawer-nav,
    .drawer-footer,
    .drawer-panel > .px-0 {
        padding-left: 8px !important;
        padding-right: 8px !important;
    }
    .drawer-link,
    .drawer-group-summary {
        min-height: 44px !important;
        padding-left: 14px !important;
        padding-right: 14px !important;
    }
}

        body.asr-dialogs-fullscreen { padding: 0 !important; margin: 0 !important; overflow: hidden !important; background-image: none !important; }
        body.asr-dialogs-fullscreen .admin-main.asr-dialogs-main { width: 100% !important; max-width: none !important; height: 100dvh !important; min-height: 100dvh !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; }
        body.asr-dialogs-fullscreen .admin-header { display: none !important; }
        body.asr-dialogs-fullscreen .drawer-edge-zone { display: none !important; }
        body.asr-flow-fullscreen { padding: 0 !important; margin: 0 !important; overflow: hidden !important; background-image: none !important; background: #f1f3f5 !important; }
        body.asr-flow-fullscreen .admin-main.asr-flow-main { width: 100% !important; max-width: none !important; height: 100dvh !important; min-height: 100dvh !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; }
        body.asr-flow-fullscreen .admin-header { display: none !important; }

        .asr-dialog-menu-badge,
        .asr-dialog-group-badge,
        .asr-dialog-header-badge {
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #EF4444;
            color: #fff;
            font-size: 10px;
            line-height: 18px;
            font-weight: 800;
            text-align: center;
            box-shadow: 0 8px 18px rgba(239,68,68,.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            letter-spacing: 0;
        }
        .asr-dialog-menu-badge.is-hidden,
        .asr-dialog-group-badge.is-hidden,
        .asr-dialog-header-badge.is-hidden { display: none !important; }
        .drawer-link.asr-has-dialog-badge { color: #C96F2B !important; }
        .drawer-link .asr-dialog-menu-badge { margin-left: auto; }
        .drawer-group-summary .asr-dialog-group-badge { margin-left: 6px; }
        .asr-header-menu-wrap { position: relative; }
        .asr-dialog-header-badge {
            position: absolute;
            right: -5px;
            top: -5px;
            min-width: 17px;
            height: 17px;
            line-height: 17px;
            font-size: 9px;
            border: 2px solid #fff;
            padding: 0 4px;
        }
        html.asr-dark .asr-dialog-header-badge { border-color: #191D23; }

        .asr-pwa-notify-button {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 60;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255,160,72,.38);
            background: #fff7ed;
            color: #9a4f12;
            border-radius: 999px;
            padding: 10px 13px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 12px 28px rgba(15,23,42,.12);
            cursor: pointer;
        }
        .asr-pwa-notify-button:hover { background: #ffedd5; color: #7c3d0b; }
        .asr-pwa-notify-button.is-hidden { display: none !important; }
        .asr-pwa-notify-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #FFA048;
            box-shadow: 0 0 0 4px rgba(255,160,72,.18);
            flex: 0 0 auto;
        }
        html.asr-dark .asr-pwa-notify-button {
            background: #2a2119;
            border-color: rgba(255,160,72,.32);
            color: #ffd2a3;
            box-shadow: 0 12px 28px rgba(0,0,0,.22);
        }
        html.asr-dark .asr-pwa-notify-button:hover { background: #342617; color: #ffe0bd; }
        @media (max-width: 768px) {
            .asr-pwa-notify-button { left: 14px; right: 14px; bottom: 14px; justify-content: center; }
            body.asr-dialogs-fullscreen .asr-pwa-notify-button,
            body.asr-flow-fullscreen .asr-pwa-notify-button { display: none !important; }
        }

        .asr-pwa-toast {
            position: fixed;
            right: 18px;
            bottom: 74px;
            z-index: 70;
            max-width: min(420px, calc(100vw - 28px));
            padding: 12px 14px;
            border-radius: 18px;
            background: #111827;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.35;
            box-shadow: 0 18px 40px rgba(15,23,42,.24);
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease;
        }
        .asr-pwa-toast.is-visible { opacity: 1; transform: translateY(0); }
        .asr-pwa-toast.is-error { background: #7f1d1d; }
        .asr-pwa-settings-backdrop { position: fixed; inset: 0; z-index: 65; background: rgba(17,24,39,.58); backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; padding: 16px; }
        .asr-pwa-settings-backdrop.is-open { display: flex; }
        .asr-pwa-settings-panel { width: min(520px, 100%); border-radius: 28px; background: #fff; border: 1px solid #f3f4f6; box-shadow: 0 24px 70px rgba(15,23,42,.28); overflow: hidden; }
        .asr-pwa-settings-head { padding: 20px 22px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        .asr-pwa-settings-title { color: #1f2937; font-size: 15px; font-weight: 750; text-transform: uppercase; letter-spacing: .08em; }
        .asr-pwa-settings-body { padding: 22px; display: grid; gap: 14px; }
        .asr-pwa-settings-check { display: flex; align-items: flex-start; gap: 12px; border: 1px solid #fed7aa; background: #fff7ed; border-radius: 20px; padding: 14px; color: #374151; font-size: 13px; font-weight: 700; }
        .asr-pwa-settings-check input { margin-top: 2px; width: 18px; height: 18px; flex: 0 0 auto; accent-color: #FFA048; }
        .asr-pwa-settings-hint { color: #6b7280; font-size: 12px; font-weight: 600; line-height: 1.45; }
        .asr-pwa-settings-hint.is-error { color: #991b1b; }
        .asr-pwa-settings-hint.is-warning { color: #9a3412; }
        .asr-pwa-settings-hint.is-success { color: #4d7c0f; }
        .asr-pwa-settings-close { width: 38px; height: 38px; border-radius: 14px; background: #f3f4f6; color: #6b7280; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; }
        .asr-pwa-settings-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 0 22px 22px; flex-wrap: wrap; }
        .asr-pwa-settings-save { border-radius: 16px; background: #FFA048; color: #fff; padding: 11px 16px; font-size: 12px; font-weight: 800; text-transform: uppercase; white-space: nowrap; }
        .asr-pwa-settings-secondary { border-radius: 16px; background: #f3f4f6; color: #6b7280; padding: 11px 16px; font-size: 12px; font-weight: 800; text-transform: uppercase; white-space: nowrap; }
        .asr-pwa-settings-secondary.is-hidden { display: none !important; }
        html.asr-dark .asr-pwa-settings-panel { background: #191D23; border-color: #2B313A; }
        html.asr-dark .asr-pwa-settings-head { border-color: #2B313A; }
        html.asr-dark .asr-pwa-settings-title { color: #f3f4f6; }
        html.asr-dark .asr-pwa-settings-check { background: rgba(255,160,72,.10); border-color: rgba(255,160,72,.28); color: #e5e7eb; }
        html.asr-dark .asr-pwa-settings-hint { color: #C8D0DA; }
        html.asr-dark .asr-pwa-settings-hint.is-error { color: #fca5a5; }
        html.asr-dark .asr-pwa-settings-hint.is-warning { color: #fdba74; }
        html.asr-dark .asr-pwa-settings-hint.is-success { color: #bef264; }
        html.asr-dark .asr-pwa-settings-close,
        html.asr-dark .asr-pwa-settings-secondary { background: #222832; color: #C8D0DA; }
        @media (max-width: 768px) {
            .asr-pwa-toast { left: 14px; right: 14px; bottom: 70px; max-width: none; }
            .asr-pwa-settings-backdrop { padding: 12px; align-items: center; }
            .asr-pwa-settings-panel { width: min(100%, calc(100vw - 24px)); max-height: calc(100dvh - 36px); border-radius: 24px; overflow-y: auto; }
            .asr-pwa-settings-head { padding: 18px 18px; }
            .asr-pwa-settings-body { padding: 18px; }
            .asr-pwa-settings-actions { padding: 0 18px 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            .asr-pwa-settings-actions > button { width: 100%; min-width: 0; padding-left: 10px; padding-right: 10px; }
            #asrPwaNotifyRetryButton:not(.is-hidden) { grid-column: 1 / -1; }
        }


        body.asr-flow-fullscreen .drawer-edge-zone { display: none !important; }

    </style>
</head>
<body class="<?php echo htmlspecialchars($asrBodyClass, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!$is_shared_view): ?>
    <div class="drawer-edge-zone" aria-hidden="true"></div>
    <div id="drawerBackdrop" class="drawer-backdrop fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-40"></div>
    <aside id="adminDrawer" class="drawer-panel fixed left-0 top-0 z-50 h-full w-[360px] bg-white shadow-2xl border-r border-gray-100 p-5 flex flex-col">
        <div class="drawer-head flex items-center justify-between border-b border-gray-100">
            <div class="flex items-center gap-3 min-w-0">
                <img src="/logo.png" alt="АВМ" class="drawer-logo logo-light">
                <img src="/logo_dark.png" alt="АВМ" class="drawer-logo logo-dark">
            </div>
            <button type="button" onclick="closeDrawer()" class="drawer-close bg-gray-100 text-gray-400 hover:bg-red-50 hover:text-red-500 flex items-center justify-center" aria-label="Закрыть меню">
                <img class="ly2-icon" src="/assets/admin/icons/ly2-close-gray.svg" alt="" aria-hidden="true">
            </button>
        </div>
        <nav class="drawer-nav space-y-2 flex-1 overflow-y-auto custom-scrollbar">
            <?php echo asr_render_admin_drawer_menu($pdo); ?>
        </nav>
        <div class="drawer-footer drawer-compact-footer border-t border-gray-100 space-y-2">
            <?php
                $asrFooterCanAdmin = false;
                try {
                    $asrFooterRole = function_exists('asr_current_role') ? (string)asr_current_role() : (string)($_SESSION['role'] ?? ($_SESSION['user_role'] ?? ''));
                    $asrFooterCanAdmin = in_array($asrFooterRole, ['superadmin', 'admin'], true)
                        || !empty($_SESSION['is_admin'])
                        || (function_exists('asr_menu_permission_allowed') && asr_menu_permission_allowed('admin'));
                } catch (Throwable $e) {
                    $asrFooterCanAdmin = !empty($_SESSION['is_admin']);
                }
            ?>
            <div class="asr-drawer-quick-row">
                <button id="adminThemeToggle" type="button" onclick="toggleAdminTheme()" class="asr-drawer-theme-btn theme-toggle" aria-label="Переключить тему" title="Переключить тему">
                    <img class="ly2-theme-icon ly2-theme-sun" src="/assets/admin/icons/ly2-sun-gray.svg" alt="" aria-hidden="true">
                    <img class="ly2-theme-icon ly2-theme-moon" src="/assets/admin/icons/ly2-moon-gray.svg" alt="" aria-hidden="true">
                </button>
                <button type="button" class="asr-drawer-settings-summary" aria-expanded="false" aria-controls="asrDrawerSettingsBody" onclick="(function(btn){var body=document.getElementById('asrDrawerSettingsBody'); if(!body) return; var open=!body.classList.contains('is-open'); body.classList.toggle('is-open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false');})(this);">
                    <span>Настройки</span>
                    <span class="asr-drawer-settings-caret" aria-hidden="true">⌄</span>
                </button>
            </div>
            <div id="asrDrawerSettingsBody" class="asr-drawer-settings-body">
                <?php if ($asrFooterCanAdmin): ?>
                    <a href="admin.php?tab=settings" class="asr-drawer-settings-row" onclick="closeDrawer();">
                        <img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/tb2-settings-gray.svg" alt="" aria-hidden="true">
                        <span>Система</span>
                    </a>
                    <a href="admin.php?tab=users" class="asr-drawer-settings-row" onclick="closeDrawer();">
                        <span aria-hidden="true">👥</span>
                        <span>Сотрудники</span>
                    </a>
                <?php endif; ?>
                <button id="pwaInstallButton" type="button" onclick="installAdminApp()" class="asr-drawer-settings-row is-install-hidden">
                    <img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/download.svg" alt="" aria-hidden="true">
                    <span>Установить приложение</span>
                </button>
                <button type="button" onclick="document.getElementById('helpModal').classList.remove('hidden'); closeDrawer();" class="asr-drawer-settings-row">
                    <img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-help-gray.svg" alt="" aria-hidden="true">
                    <span>Справочная информация</span>
                </button>
                <button type="button" onclick="window.asrOpenPwaNotifySettings && window.asrOpenPwaNotifySettings(); closeDrawer();" class="asr-drawer-settings-row">
                    <span aria-hidden="true">🔔</span>
                    <span>Уведомления PWA</span>
                </button>
                <a href="?logout" class="asr-drawer-settings-row asr-danger">
                    <img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-logout-gray.svg" alt="" aria-hidden="true">
                    <span>Выйти</span>
                </a>
            </div>
            <div class="drawer-copyright">© 2026 Дмитрий Базатин. Все права защищены.</div>
        </div>
    </aside>
    <?php endif; ?>

    <?php if (!$is_shared_view): ?>
    <div id="asrPwaNotifySettingsModal" class="asr-pwa-settings-backdrop" role="dialog" aria-modal="true" aria-labelledby="asrPwaNotifySettingsTitle">
        <div class="asr-pwa-settings-panel">
            <div class="asr-pwa-settings-head">
                <div id="asrPwaNotifySettingsTitle" class="asr-pwa-settings-title">Уведомления PWA</div>
                <button type="button" class="asr-pwa-settings-close" onclick="window.asrClosePwaNotifySettings && window.asrClosePwaNotifySettings();" aria-label="Закрыть">×</button>
            </div>
            <div class="asr-pwa-settings-body">
                <label class="asr-pwa-settings-check">
                    <input type="checkbox" id="asrPwaDialogNotifyCheckbox" <?php echo $asrCurrentUserPwaDialogNotifyEnabled ? 'checked' : ''; ?>>
                    <span>
                        <span class="block text-gray-800">Получать уведомления о новых диалогах</span>
                        <span class="block mt-1 text-[11px] text-gray-500">Настройка сохраняется в профиле пользователя. Если браузер заблокировал уведомления, их нужно разрешить в настройках сайта/PWA.</span>
                    </span>
                </label>
                <div id="asrPwaNotifySettingsStatus" class="asr-pwa-settings-hint"></div>
            </div>
            <div class="asr-pwa-settings-actions">
                <button type="button" id="asrPwaNotifyRetryButton" class="asr-pwa-settings-secondary is-hidden" onclick="window.asrRetryPwaPush && window.asrRetryPwaPush();">Попробовать полный режим</button>
                <button type="button" class="asr-pwa-settings-secondary" onclick="window.asrClosePwaNotifySettings && window.asrClosePwaNotifySettings();">Закрыть</button>
                <button type="button" class="asr-pwa-settings-save" onclick="window.asrSavePwaNotifySettings && window.asrSavePwaNotifySettings();">Сохранить</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?php echo htmlspecialchars($asrAdminShellClass, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (!$is_shared_view && !$asrIsDialogsFullscreen && !$asrIsFlowFullscreen): ?>
        <div class="admin-header flex items-center justify-between mb-8 bg-white p-6 rounded-2xl shadow-sm border border-gray-100 gap-4">
            <div class="admin-brand flex items-center gap-4 min-w-0">
                <button id="adminMainMenuButton" type="button" onclick="openDrawer()" class="asr-header-menu-wrap w-11 h-11 shrink-0 rounded-2xl bg-[#FFA048] text-white flex items-center justify-center hover:bg-[#ff9226] shadow-lg shadow-orange-500/20" aria-label="Открыть меню">
                    <img class="ly2-menu-icon" src="/assets/admin/icons/ly2-menu-white.svg" alt="" aria-hidden="true">
                    <span class="asr-dialog-header-badge is-hidden" data-asr-dialog-badge="header" aria-hidden="true"></span>
                </button>
                <img src="/logo.png" alt="АВМ" style="width: 150px; height: 51px; object-fit: contain;" class="admin-logo logo-light block">
                <img src="/logo_dark.png" alt="АВМ" style="width: 150px; height: 51px; object-fit: contain;" class="admin-logo logo-dark">
                <div class="min-w-0">
                    <h2 style="color: #FFA048;" class="admin-title text-xl font-bold uppercase tracking-tight"><?php echo htmlspecialchars($asrHeaderTitle); ?></h2>
                    <div class="admin-user text-[10px] text-gray-400 font-bold uppercase tracking-widest truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></div>
                </div>
            </div>
            <div class="hidden md:flex items-center gap-2">
                <a href="?logout" class="ly2-btn px-6 py-2 border-2 border-gray-100 text-gray-400 font-bold rounded-xl hover:text-red-500 text-xs uppercase"><img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-logout-gray.svg" alt="" aria-hidden="true">Выйти</a>
            </div>
        </div>
        <?php else: ?>
        <div class="admin-header flex items-center justify-center mb-8 bg-white p-6 rounded-2xl shadow-sm border border-gray-100 gap-4 w-full max-w-[1200px] mx-auto">
            <img src="/logo.png" alt="АВМ" style="width: 150px; height: 51px; object-fit: contain;" class="admin-logo block">
            <div>
                <h2 style="color: #FFA048;" class="text-xl font-bold uppercase tracking-tight ml-2">Ваш профиль руководителя</h2>
            </div>
        </div>
        <?php endif; ?>

        <?php
            $asrCurrentPagePath = asr_admin_resolve_page_path($current_tab, (bool)$is_detail_view, (bool)$is_shared_view);
            if ($asrCurrentPagePath) {
                require $asrCurrentPagePath;
            }
        ?>
    </div>

    <?php if (!$is_shared_view): ?>
        <?php require __DIR__ . '/partials/modals.php'; ?>
        <?php require __DIR__ . '/partials/scripts.php'; ?>

        <script>
            (function() {
                var endpoint = '/admin.php?tab=telegram_bots&tg_ajax=dialog_badges';
                var preferenceEndpoint = '/admin.php?tab=users';
                var csrfToken = <?php echo json_encode($asrCsrfTokenForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                var pwaNotifyEnabled = <?php echo $asrCurrentUserPwaDialogNotifyEnabled ? 'true' : 'false'; ?>;
                var isDialogPage = (function(){
                    try {
                        var params = new URLSearchParams(window.location.search || '');
                        return params.get('tab') === 'telegram_bots' && params.get('page') === 'messages';
                    } catch (e) { return false; }
                })();
                var baseTitle = document.title.replace(/^\(\d+\)\s+/, '').replace(/^●\s+/, '');
                var timer = null;
                var lastAttentionTotal = null;
                var lastBrowserNotifyAt = 0;
                var currentAttentionTotal = 0;
                var notificationUrl = window.location.origin + '/admin.php?tab=telegram_bots&page=messages&dialog_view=new';
                var toastTimer = null;
                var lastServerPushReady = false;
                var lastServerPushError = '';

                function shortCount(count) {
                    count = parseInt(count || 0, 10) || 0;
                    return count > 99 ? '99+' : String(count);
                }
                function setBadgeNode(node, count) {
                    if (!node) return;
                    count = parseInt(count || 0, 10) || 0;
                    node.textContent = count > 0 ? shortCount(count) : '';
                    node.classList.toggle('is-hidden', count <= 0);
                    if (count > 0) {
                        node.setAttribute('aria-label', 'Диалогов без ответа: ' + count);
                    } else {
                        node.removeAttribute('aria-label');
                    }
                }
                function ensureBadge(parent, selector, className, attrValue) {
                    if (!parent) return null;
                    var badge = parent.querySelector(selector);
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = className + ' is-hidden';
                        badge.setAttribute('data-asr-dialog-badge', attrValue);
                        badge.setAttribute('aria-hidden', 'true');
                        parent.appendChild(badge);
                    }
                    return badge;
                }
                function updateDrawerBadges(count) {
                    var dialogLinks = Array.prototype.slice.call(document.querySelectorAll('.drawer-link[href]')).filter(function(link) {
                        var href = link.getAttribute('href') || '';
                        return href.indexOf('tab=telegram_bots') !== -1 && href.indexOf('page=messages') !== -1;
                    });
                    dialogLinks.forEach(function(link) {
                        link.classList.toggle('asr-has-dialog-badge', count > 0);
                        setBadgeNode(ensureBadge(link, '[data-asr-dialog-badge="menu"]', 'asr-dialog-menu-badge', 'menu'), count);
                    });

                    var groupSummary = null;
                    if (dialogLinks[0]) {
                        var group = dialogLinks[0].closest('.drawer-group');
                        groupSummary = group ? group.querySelector('.drawer-group-summary') : null;
                    }
                    setBadgeNode(ensureBadge(groupSummary, '[data-asr-dialog-badge="group"]', 'asr-dialog-group-badge', 'group'), count);
                    setBadgeNode(document.querySelector('[data-asr-dialog-badge="header"]'), count);
                }
                async function clearAppBadgeSafe() {
                    try {
                        if ('clearAppBadge' in navigator) await navigator.clearAppBadge();
                        else if ('setAppBadge' in navigator) await navigator.setAppBadge(0);
                    } catch (e) {}
                }
                async function setAppBadgeSafe(count) {
                    try {
                        count = parseInt(count || 0, 10) || 0;
                        if (!pwaNotifyEnabled) {
                            await clearAppBadgeSafe();
                            return;
                        }
                        if (count > 0 && 'setAppBadge' in navigator) {
                            await navigator.setAppBadge(count);
                        } else if (count <= 0) {
                            await clearAppBadgeSafe();
                        }
                    } catch (e) {}
                }
                function updateTitle(count) {
                    count = parseInt(count || 0, 10) || 0;
                    if (isDialogPage) return;
                    document.title = count > 0 ? '(' + shortCount(count) + ') ' + baseTitle : baseTitle;
                }
                function notificationsSupported() {
                    return ('Notification' in window) && ('serviceWorker' in navigator) && window.isSecureContext;
                }
                function serverPushSupported() {
                    return notificationsSupported() && ('PushManager' in window);
                }
                function urlBase64ToUint8Array(base64String) {
                    var padding = '='.repeat((4 - base64String.length % 4) % 4);
                    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                    var rawData = window.atob(base64);
                    var outputArray = new Uint8Array(rawData.length);
                    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
                    return outputArray;
                }
                async function fetchVapidPublicKey() {
                    var response = await fetch(preferenceEndpoint + '&pwa_push=public_key&_=' + Date.now(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    var payload = null;
                    try { payload = await response.json(); } catch (e) {}
                    if (!response.ok || !payload || !payload.ok || !payload.public_key) {
                        throw new Error((payload && (payload.error || payload.message)) || 'Не удалось получить серверный ключ PWA Push.');
                    }
                    return payload.public_key;
                }
                async function postPushSubscription(action, subscription) {
                    var fd = new FormData();
                    fd.set('action', action);
                    if (csrfToken) fd.set('csrf_token', csrfToken);
                    if (subscription) {
                        fd.set('subscription_json', JSON.stringify(subscription));
                        try { fd.set('endpoint', subscription.endpoint || ''); } catch (e) {}
                    }
                    var response = await fetch(preferenceEndpoint, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    var payload = null;
                    try { payload = await response.json(); } catch (e) {}
                    if (!response.ok || !payload || !payload.ok) {
                        throw new Error((payload && (payload.error || payload.message)) || 'Не удалось сохранить push-подписку.');
                    }
                    return payload;
                }
                async function getFreshServiceWorkerRegistration() {
                    if (!('serviceWorker' in navigator)) return null;
                    var registration = null;
                    try { registration = await navigator.serviceWorker.getRegistration('/admin.php'); } catch (e) {}
                    if (!registration) {
                        registration = await navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin.php' });
                    }
                    try { await registration.update(); } catch (e) {}
                    try { registration = await navigator.serviceWorker.ready; } catch (e) {}
                    return registration || null;
                }
                async function waitForPushManagerRegistration() {
                    var registration = await getFreshServiceWorkerRegistration();
                    if (registration && registration.pushManager) return registration;
                    await new Promise(function(resolve) { window.setTimeout(resolve, 700); });
                    registration = await getFreshServiceWorkerRegistration();
                    return registration && registration.pushManager ? registration : null;
                }
                async function ensureServerPushSubscription(silent, forceRefresh) {
                    lastServerPushReady = false;
                    lastServerPushError = '';
                    if (!serverPushSupported()) {
                        lastServerPushError = 'Этот браузер не поддерживает серверные Push-уведомления. Уведомления во время открытой админки могут работать, но при полностью закрытом приложении — нет.';
                        if (!silent) setNotifySettingsStatus(lastServerPushError, 'error');
                        return false;
                    }
                    if (Notification.permission !== 'granted') {
                        lastServerPushError = 'Системное разрешение на уведомления ещё не выдано.';
                        return false;
                    }
                    var lastError = null;
                    for (var attempt = 0; attempt < 2; attempt++) {
                        try {
                            var registration = await waitForPushManagerRegistration();
                            if (!registration || !registration.pushManager) {
                                throw new Error('Service Worker активен, но PushManager для него недоступен.');
                            }
                            var subscription = await registration.pushManager.getSubscription();
                            if (subscription && (forceRefresh || attempt > 0)) {
                                try { await postPushSubscription('pwa_push_unsubscribe', subscription.toJSON ? subscription.toJSON() : subscription); } catch (e) {}
                                try { await subscription.unsubscribe(); } catch (e) {}
                                subscription = null;
                            }
                            if (!subscription) {
                                var publicKey = await fetchVapidPublicKey();
                                subscription = await registration.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: urlBase64ToUint8Array(publicKey)
                                });
                            }
                            await postPushSubscription('pwa_push_subscribe', subscription.toJSON ? subscription.toJSON() : subscription);
                            lastServerPushReady = true;
                            lastServerPushError = '';
                            setRetryPushButtonVisible(false);
                            return true;
                        } catch (e) {
                            lastError = e;
                            await new Promise(function(resolve) { window.setTimeout(resolve, 500); });
                        }
                    }
                    lastServerPushError = lastError && lastError.message ? lastError.message : 'Не удалось создать серверную push-подписку.';
                    if (!silent) setNotifySettingsStatus(lastServerPushError, 'error');
                    setRetryPushButtonVisible(true);
                    return false;
                }
                async function disableServerPushSubscription() {
                    if (!('serviceWorker' in navigator)) return;
                    try {
                        var registration = await navigator.serviceWorker.ready;
                        if (!registration || !registration.pushManager) return;
                        var subscription = await registration.pushManager.getSubscription();
                        if (subscription) {
                            try { await postPushSubscription('pwa_push_unsubscribe', subscription.toJSON ? subscription.toJSON() : subscription); } catch (e) {}
                            try { await subscription.unsubscribe(); } catch (e) {}
                        } else {
                            try { await postPushSubscription('pwa_push_unsubscribe', null); } catch (e) {}
                        }
                    } catch (e) {}
                }
                function showPwaToast(message, isError) {
                    var toast = document.querySelector('[data-asr-pwa-toast]');
                    if (!toast) {
                        toast = document.createElement('div');
                        toast.className = 'asr-pwa-toast';
                        toast.setAttribute('data-asr-pwa-toast', '1');
                        document.body.appendChild(toast);
                    }
                    toast.textContent = message || '';
                    toast.classList.toggle('is-error', !!isError);
                    toast.classList.add('is-visible');
                    if (toastTimer) window.clearTimeout(toastTimer);
                    toastTimer = window.setTimeout(function(){ toast.classList.remove('is-visible'); }, 4200);
                }
                function updateNotifyButton() {
                    // Плавающую кнопку включения уведомлений больше не показываем.
                    // Управление уведомлениями находится в меню: Настройки → Уведомления PWA.
                    var buttons = document.querySelectorAll('[data-asr-pwa-notify-button]');
                    buttons.forEach(function(button) { button.remove(); });
                }
                function setNotifySettingsStatus(message, type) {
                    var status = document.getElementById('asrPwaNotifySettingsStatus');
                    if (!status) return;
                    status.textContent = message || '';
                    status.classList.toggle('is-error', type === 'error');
                    status.classList.toggle('is-warning', type === 'warning');
                    status.classList.toggle('is-success', type === 'success');
                }
                function setRetryPushButtonVisible(visible) {
                    var button = document.getElementById('asrPwaNotifyRetryButton');
                    if (!button) return;
                    button.classList.toggle('is-hidden', !visible);
                }
                async function silentlyDisableNotificationPreference() {
                    try {
                        if (pwaNotifyEnabled) {
                            await saveNotificationPreference(false);
                        } else {
                            updateNotifySettingsUi();
                        }
                    } catch (e) {
                        pwaNotifyEnabled = false;
                        updateNotifySettingsUi();
                    }
                    try { await clearAppBadgeSafe(); } catch (e) {}
                }
                async function saveNotificationPreference(enabled) {
                    var fd = new FormData();
                    fd.set('action', 'update_my_pwa_dialog_notifications');
                    fd.set('enabled', enabled ? '1' : '0');
                    if (csrfToken) fd.set('csrf_token', csrfToken);
                    var response = await fetch(preferenceEndpoint, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    var payload = null;
                    try { payload = await response.json(); } catch (e) {}
                    if (!response.ok || !payload || !payload.ok) {
                        throw new Error((payload && (payload.error || payload.message)) || 'Не удалось сохранить настройку.');
                    }
                    pwaNotifyEnabled = !!payload.enabled;
                    updateNotifySettingsUi();
                    updateNotifyButton();
                    setAppBadgeSafe(currentAttentionTotal);
                    return payload;
                }
                async function requestDialogNotifications(showTest, forcePushRefresh) {
                    if (!notificationsSupported()) {
                        await silentlyDisableNotificationPreference();
                        setNotifySettingsStatus('На этом устройстве или в этом браузере PWA-уведомления недоступны. Внутренние красные кружки в меню продолжают работать.', 'error');
                        return false;
                    }
                    try {
                        var permission = Notification.permission;
                        if (permission === 'denied') {
                            await silentlyDisableNotificationPreference();
                            setNotifySettingsStatus('Браузер уже заблокировал уведомления для этого сайта/PWA. Сначала разрешите их в настройках браузера, потом включите галочку снова.', 'error');
                            updateNotifyButton();
                            return false;
                        }
                        if (permission === 'default') {
                            permission = await Notification.requestPermission();
                        }
                        if (permission !== 'granted') {
                            await silentlyDisableNotificationPreference();
                            setNotifySettingsStatus('Системное разрешение не выдано. Настройку не включил, чтобы не показывать ложный статус. На этом смартфоне попробуйте открыть сайт в установленной PWA/Chrome или разрешить уведомления в настройках сайта.', 'error');
                            updateNotifyButton();
                            return false;
                        }
                        await saveNotificationPreference(true);
                        var serverPushReady = false;
                        try { serverPushReady = await ensureServerPushSubscription(false, !!forcePushRefresh); } catch (pushErr) {
                            setNotifySettingsStatus((pushErr && pushErr.message ? pushErr.message : 'Не удалось подключить серверный Push.') + ' Уведомления во время открытой админки останутся активными.', 'error');
                        }
                        if (serverPushReady) {
                            setNotifySettingsStatus('Включено. Серверные PWA Push-уведомления подключены: новые диалоги смогут приходить даже при закрытой админке, если браузер это поддерживает.', 'success');
                        } else if (serverPushSupported()) {
                            setNotifySettingsStatus('Уведомления включены частично. Красные кружки в меню работают. Фоновые уведомления при закрытом приложении недоступны в этом браузере. Для полного режима можно попробовать установить PWA через Chrome.', '');
                            setRetryPushButtonVisible(true);
                        } else {
                            setNotifySettingsStatus('Уведомления включены частично. Красные кружки в меню работают. Фоновые уведомления при закрытом приложении недоступны в этом браузере. Для полного режима можно попробовать установить PWA через Chrome.', '');
                            setRetryPushButtonVisible(false);
                        }
                        showPwaToast(serverPushReady ? 'Серверные PWA Push-уведомления подключены.' : 'Уведомления включены частично. Красные кружки работают.', false);
                        if (showTest) {
                            await showDialogNotification('Уведомления включены', 'Теперь новые диалоги будут отмечаться в меню. Фоновые уведомления зависят от браузера.', true);
                        }
                        return true;
                    } catch (e) {
                        await silentlyDisableNotificationPreference();
                        setNotifySettingsStatus(e && e.message ? e.message : 'Не удалось включить уведомления. Настройку не сохранял включённой без системного разрешения.', 'error');
                        updateNotifyButton();
                        return false;
                    }
                }
                async function showDialogNotification(title, body, force) {
                    if (!notificationsSupported() || Notification.permission !== 'granted' || !pwaNotifyEnabled) return;
                    var now = Date.now();
                    if (!force && now - lastBrowserNotifyAt < 15000) return;
                    lastBrowserNotifyAt = now;
                    var options = {
                        body: body,
                        tag: 'asr-dialogs',
                        renotify: true,
                        icon: '/pwa/icons/icon-192.png',
                        badge: '/pwa/icons/icon-192.png',
                        data: { url: notificationUrl }
                    };
                    try {
                        var registration = await navigator.serviceWorker.ready;
                        if (registration && registration.showNotification) {
                            await registration.showNotification(title, options);
                            return;
                        }
                    } catch (e) {}
                    try { new Notification(title, options); } catch (e) {}
                }
                function maybeNotifyDialogIncrease(count) {
                    count = parseInt(count || 0, 10) || 0;
                    if (lastAttentionTotal === null) {
                        lastAttentionTotal = count;
                        return;
                    }
                    var previous = parseInt(lastAttentionTotal || 0, 10) || 0;
                    lastAttentionTotal = count;
                    if (isDialogPage && !document.hidden) return;
                    if (count > previous && pwaNotifyEnabled && ('Notification' in window) && Notification.permission === 'granted') {
                        var diff = count - previous;
                        var title = diff === 1 ? 'Новый диалог' : 'Новые диалоги: +' + diff;
                        var body = count === 1 ? 'Есть 1 диалог без ответа.' : 'Диалогов без ответа: ' + shortCount(count) + '.';
                        showDialogNotification(title, body, false);
                    }
                }
                function updateNotifySettingsUi() {
                    var checkbox = document.getElementById('asrPwaDialogNotifyCheckbox');
                    if (checkbox) checkbox.checked = !!pwaNotifyEnabled;
                    setRetryPushButtonVisible(false);
                    if (!notificationsSupported()) {
                        setNotifySettingsStatus('На этом устройстве браузерные PWA-уведомления недоступны. Внутренние красные кружки в меню всё равно работают.', 'error');
                    } else if (Notification.permission === 'denied') {
                        setNotifySettingsStatus('Браузер заблокировал уведомления. Сначала разрешите уведомления для сайта/PWA в настройках браузера.', 'error');
                    } else if (pwaNotifyEnabled && Notification.permission === 'granted') {
                        if (serverPushSupported()) {
                            setNotifySettingsStatus(lastServerPushReady ? 'Включено полностью: разрешение есть, серверная push-подписка этого устройства создана.' : 'Разрешение браузера есть. Проверяем серверную push-подписку устройства...', lastServerPushReady ? 'success' : '');
                            ensureServerPushSubscription(true, false).then(function(ok) {
                                if (!pwaNotifyEnabled || Notification.permission !== 'granted') return;
                                if (ok) {
                                    setNotifySettingsStatus('Включено полностью: разрешение есть, серверная push-подписка этого устройства создана.', 'success');
                                    setRetryPushButtonVisible(false);
                                } else {
                                    setNotifySettingsStatus('Уведомления включены частично. Красные кружки в меню работают. Фоновые уведомления при закрытом приложении недоступны в этом браузере.', '');
                                    setRetryPushButtonVisible(true);
                                }
                            }).catch(function() {
                                setNotifySettingsStatus('Уведомления включены частично. Красные кружки в меню работают. Фоновые уведомления при закрытом приложении недоступны в этом браузере.', '');
                                setRetryPushButtonVisible(true);
                            });
                        } else {
                            setNotifySettingsStatus('Уведомления включены частично. Красные кружки в меню работают. Фоновые уведомления при закрытом приложении недоступны в этом браузере.', '');
                            setRetryPushButtonVisible(false);
                        }
                    } else if (pwaNotifyEnabled) {
                        setNotifySettingsStatus('Галочка была включена в профиле, но это устройство ещё не выдало системное разрешение. Нажмите «Сохранить», чтобы запросить разрешение. Если окно не появится — браузер не даёт PWA-уведомления в этом режиме.', 'error');
                    } else {
                        setNotifySettingsStatus('Выключено. Внутренние кружки в меню останутся, но PWA/браузерные уведомления и бейдж иконки будут отключены.', '');
                    }
                }
                window.asrOpenPwaNotifySettings = function() {
                    var modal = document.getElementById('asrPwaNotifySettingsModal');
                    updateNotifySettingsUi();
                    if (modal) modal.classList.add('is-open');
                };
                window.asrClosePwaNotifySettings = function() {
                    var modal = document.getElementById('asrPwaNotifySettingsModal');
                    if (modal) modal.classList.remove('is-open');
                };
                window.asrSavePwaNotifySettings = async function() {
                    var checkbox = document.getElementById('asrPwaDialogNotifyCheckbox');
                    var desired = checkbox ? !!checkbox.checked : false;
                    if (desired) {
                        await requestDialogNotifications(false);
                    } else {
                        try {
                            await saveNotificationPreference(false);
                            await disableServerPushSubscription();
                            await clearAppBadgeSafe();
                            setNotifySettingsStatus('PWA-уведомления о диалогах отключены. Внутренние красные кружки в меню остаются.', '');
                            showPwaToast('PWA-уведомления о диалогах отключены.', false);
                        } catch (e) {
                            showPwaToast(e && e.message ? e.message : 'Не удалось отключить уведомления.', true);
                        }
                    }
                    updateNotifySettingsUi();
                };
                window.asrRetryPwaPush = async function() {
                    if (!pwaNotifyEnabled) {
                        var checkbox = document.getElementById('asrPwaDialogNotifyCheckbox');
                        if (checkbox) checkbox.checked = true;
                    }
                    setNotifySettingsStatus('Пробую включить полный режим фоновых уведомлений...', '');
                    setRetryPushButtonVisible(false);
                    var ok = await requestDialogNotifications(false, true);
                    if (!ok) setRetryPushButtonVisible(true);
                };
                function applyPayload(payload) {
                    var count = parseInt((payload && payload.attention_total) || 0, 10) || 0;
                    currentAttentionTotal = count;
                    updateDrawerBadges(count);
                    setAppBadgeSafe(count);
                    updateTitle(count);
                    maybeNotifyDialogIncrease(count);
                    updateNotifyButton();
                    updateNotifySettingsUi();
                    window.asrDialogBadgeState = payload || { attention_total: count };
                }
                function refresh() {
                    return fetch(endpoint + '&_=' + Date.now(), {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store'
                    }).then(function(response) {
                        return response.json().then(function(payload) {
                            if (response.ok && payload && payload.ok) applyPayload(payload);
                        });
                    }).catch(function() {});
                }
                window.asrRefreshDialogBadges = refresh;
                window.addEventListener('load', function() {
                    updateNotifyButton();
                    updateNotifySettingsUi();
                    refresh();
                    if (pwaNotifyEnabled && notificationsSupported() && Notification.permission === 'granted') {
                        ensureServerPushSubscription(true, false).catch(function(){});
                    }
                    timer = window.setInterval(refresh, 20000);
                });
                document.addEventListener('visibilitychange', function() {
                    if (!document.hidden) refresh();
                });
                window.addEventListener('beforeunload', function() {
                    if (timer) window.clearInterval(timer);
                });
            })();
        </script>
        <script>
            (function() {
                if (!('serviceWorker' in navigator)) return;
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin.php' })
                        .then(function(registration) {
                            try { registration.update(); } catch (e) {}
                        })
                        .catch(function(error) {
                            console.warn('PWA service worker registration failed:', error);
                        });
                });
            })();
        </script>
    <?php endif; ?>

<?php if (!$is_shared_view && isset($_GET['dark_audit']) && (string)$_GET['dark_audit'] === '1'): ?>
<script>
(function(){
    'use strict';

    var auditScanning = false;
    var auditTimer = null;
    var lastMode = 'page';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, {once:true});
        } else {
            fn();
        }
    }

    function isVisible(el) {
        if (!el || el === document.documentElement || el === document.body) return false;
        var rect = el.getBoundingClientRect();
        if (rect.width < 18 || rect.height < 18) return false;
        var style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity || '1') < 0.05) return false;
        return true;
    }

    function parseRgb(value) {
        var match = String(value || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([0-9.]+))?\)/i);
        if (!match) return null;
        return { r: parseInt(match[1], 10), g: parseInt(match[2], 10), b: parseInt(match[3], 10), a: match[4] === undefined ? 1 : parseFloat(match[4]) };
    }

    function isLightColor(rgb) {
        if (!rgb || rgb.a < 0.25) return false;
        var luma = (0.2126 * rgb.r + 0.7152 * rgb.g + 0.0722 * rgb.b);
        return luma >= 205;
    }

    function isNearWhite(value) { return isLightColor(parseRgb(value)); }

    function shortSelector(el) {
        if (!el) return '';
        var tag = (el.tagName || '').toLowerCase();
        var id = el.id ? ('#' + el.id) : '';
        var cls = '';
        if (el.classList && el.classList.length) {
            cls = '.' + Array.prototype.slice.call(el.classList).slice(0, 9).join('.');
        }
        return (tag + id + cls).replace(/\s+/g, ' ').slice(0, 360);
    }

    function parentSelectors(el) {
        var out = [];
        var p = el ? el.parentElement : null;
        while (p && p !== document.body && p !== document.documentElement && out.length < 6) {
            out.push(shortSelector(p));
            p = p.parentElement;
        }
        return out.join(' ← ');
    }

    function buildItem(el, index) {
        var cs = window.getComputedStyle(el);
        var rect = el.getBoundingClientRect();
        return {
            number: index,
            selector: shortSelector(el),
            parents: parentSelectors(el),
            background: cs.backgroundColor,
            color: cs.color,
            borderTopColor: cs.borderTopColor,
            position: cs.position,
            zIndex: cs.zIndex,
            size: Math.round(rect.width) + 'x' + Math.round(rect.height),
            text: (el.innerText || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 220)
        };
    }

    function clearMarks() {
        document.querySelectorAll('[data-dark-audit-hit]').forEach(function(el){
            el.removeAttribute('data-dark-audit-hit');
            el.style.outline = '';
            el.style.outlineOffset = '';
            el.style.boxShadow = '';
        });
        document.querySelectorAll('.asr-dark-audit-badge').forEach(function(el){ el.remove(); });
    }

    function mark(el, index, bg, color) {
        el.setAttribute('data-dark-audit-hit', String(index));
        el.style.outline = '3px solid rgba(239, 68, 68, .95)';
        el.style.outlineOffset = '-3px';
        el.style.boxShadow = 'inset 0 0 0 9999px rgba(239, 68, 68, .035)';
        el.title = '[Dark Audit #' + index + '] bg=' + bg + ' color=' + color + ' | ' + shortSelector(el);

        var badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'asr-dark-audit-badge';
        badge.textContent = '#' + index;
        badge.setAttribute('data-dark-audit-badge-for', String(index));
        badge.addEventListener('click', function(ev){
            ev.preventDefault();
            ev.stopPropagation();
            var item = buildItem(el, index);
            try { if (navigator.clipboard) navigator.clipboard.writeText(JSON.stringify(item, null, 2)); } catch (e) {}
            console.log('[Dark Audit item #' + index + ']', item, el);
        });
        document.body.appendChild(badge);

        function placeBadge() {
            if (!document.body.contains(el) || !document.body.contains(badge)) return;
            var r = el.getBoundingClientRect();
            badge.style.left = Math.max(8, Math.min(window.innerWidth - 54, r.left + window.scrollX + 6)) + 'px';
            badge.style.top = Math.max(8, r.top + window.scrollY + 6) + 'px';
        }
        placeBadge();
        window.addEventListener('scroll', placeBadge, {passive:true});
        window.addEventListener('resize', placeBadge, {passive:true});
    }

    function elementScoreForModal(el) {
        if (!isVisible(el)) return -1;
        if (el.closest('.asr-dark-audit-panel') || el.classList.contains('asr-dark-audit-badge')) return -1;
        var rect = el.getBoundingClientRect();
        if (rect.width < 260 || rect.height < 180) return -1;
        var cls = String(el.className || '');
        var id = String(el.id || '');
        var role = String(el.getAttribute('role') || '');
        var aria = String(el.getAttribute('aria-modal') || '');
        var style = window.getComputedStyle(el);
        var score = 0;
        if (/modal|dialog|drawer|panel|popup|sheet/i.test(cls + ' ' + id)) score += 50;
        if (/dialog/i.test(role) || aria === 'true') score += 50;
        if (style.position === 'fixed') score += 35;
        if (style.position === 'absolute') score += 15;
        if (/is-open|open|active|show|visible/i.test(cls)) score += 20;
        var z = parseInt(style.zIndex, 10);
        if (!isNaN(z)) score += Math.min(40, Math.max(0, z / 100));
        var bg = parseRgb(style.backgroundColor);
        if (bg && bg.a > 0.55) score += 8;
        if (/backdrop|overlay/i.test(cls)) score -= 80;
        return score;
    }

    function findActiveModal() {
        var selectors = [
            '[role="dialog"]', '[aria-modal="true"]',
            '.modal-panel', '.modal-form', '.tg-subs-modal-card', '.tg-scenario-modal', '.tg-channel-modal',
            '.tg-dialog-settings-modal', '.tg-button-modal', '.tg-date-modal', '.tg-alert-modal',
            '[class*="modal"]', '[class*="Modal"]', '[class*="dialog"]', '[class*="Dialog"]', '[class*="drawer"]', '[class*="panel"]'
        ];
        var list = [];
        try { list = Array.prototype.slice.call(document.querySelectorAll(selectors.join(','))); } catch (e) { list = []; }
        var scored = list.map(function(el){ return {el: el, score: elementScoreForModal(el), area: el.getBoundingClientRect().width * el.getBoundingClientRect().height}; })
            .filter(function(item){ return item.score > 20; })
            .sort(function(a,b){ return (b.score - a.score) || (b.area - a.area); });
        return scored.length ? scored[0].el : null;
    }

    function scan(mode) {
        mode = mode || 'page';
        lastMode = mode;
        if (auditScanning) return;
        auditScanning = true;

        if (!document.documentElement.classList.contains('asr-dark')) {
            console.warn('[Dark Audit] Тёмная тема не включена. Включите тёмную тему и обновите страницу с dark_audit=1.');
        }

        clearMarks();

        var root = document.body;
        var modal = null;
        if (mode === 'modal') {
            modal = findActiveModal();
            if (modal) root = modal;
        }

        var candidates = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));
        var hits = [];
        candidates.forEach(function(el){
            if (!isVisible(el)) return;
            if (el.closest('.asr-dark-audit-panel') || el.classList.contains('asr-dark-audit-badge')) return;

            var cs = window.getComputedStyle(el);
            var bg = cs.backgroundColor;
            if (!isNearWhite(bg)) return;

            var rect = el.getBoundingClientRect();
            if (rect.width < 32 || rect.height < 24) return;
            if (['svg','path','img','use','br','script','style'].indexOf((el.tagName || '').toLowerCase()) !== -1) return;

            hits.push({el: el, area: rect.width * rect.height, bg: bg, color: cs.color});
        });

        hits.sort(function(a,b){ return b.area - a.area; });
        var filtered = [];
        hits.forEach(function(item){
            var duplicateChild = filtered.some(function(prev){ return prev.el.contains(item.el) && prev.bg === item.bg; });
            if (!duplicateChild) filtered.push(item);
        });

        filtered = filtered.slice(0, 180);
        var report = filtered.map(function(item, i){ return buildItem(item.el, i + 1); });
        filtered.forEach(function(item, i){ mark(item.el, i + 1, item.bg, item.color); });

        window.ASR_DARK_AUDIT_REPORT = report;
        window.ASR_DARK_AUDIT_MODE = mode;
        console.group('[Dark Audit] ' + (mode === 'modal' ? 'Модалка' : 'Страница') + ': светлые элементы в тёмной теме: ' + report.length);
        try { console.table(report); } catch (e) { console.log(report); }
        console.groupEnd();

        renderPanel(report, mode, modal);
        auditScanning = false;
    }

    function scheduleScan(mode, delay) {
        if (auditScanning) return;
        clearTimeout(auditTimer);
        auditTimer = setTimeout(function(){ scan(mode || lastMode || 'page'); }, delay || 350);
    }

    function renderPanel(report, mode, modal) {
        var old = document.querySelector('.asr-dark-audit-panel');
        if (old) old.remove();

        var panel = document.createElement('div');
        panel.className = 'asr-dark-audit-panel';
        panel.innerHTML = ''
            + '<div class="asr-dark-audit-head">'
            + '<div><b>Dark Audit v2</b><span>' + (mode === 'modal' ? 'Режим: модальное окно' : 'Режим: вся страница') + ' · Найдено: ' + report.length + '</span></div>'
            + '<button type="button" data-dark-audit-close>×</button>'
            + '</div>'
            + '<div class="asr-dark-audit-actions">'
            + '<button type="button" data-dark-audit-copy>Скопировать отчёт</button>'
            + '<button type="button" data-dark-audit-rescan>Сканировать страницу</button>'
            + '<button type="button" data-dark-audit-modal>Сканировать модалку</button>'
            + '</div>'
            + '<div class="asr-dark-audit-note">Откройте нужное окно и нажмите «Сканировать модалку». Если окно открылось после загрузки, аудит также попробует пересканировать его автоматически.</div>'
            + '<textarea readonly spellcheck="false"></textarea>';

        document.body.appendChild(panel);
        var textarea = panel.querySelector('textarea');
        textarea.value = JSON.stringify(report, null, 2);

        panel.querySelector('[data-dark-audit-close]').addEventListener('click', function(){ panel.remove(); });
        panel.querySelector('[data-dark-audit-rescan]').addEventListener('click', function(){ scan('page'); });
        panel.querySelector('[data-dark-audit-modal]').addEventListener('click', function(){ scan('modal'); });
        panel.querySelector('[data-dark-audit-copy]').addEventListener('click', function(){
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);
            try {
                navigator.clipboard.writeText(textarea.value).then(function(){
                    panel.querySelector('[data-dark-audit-copy]').textContent = 'Скопировано';
                    setTimeout(function(){ panel.querySelector('[data-dark-audit-copy]').textContent = 'Скопировать отчёт'; }, 1200);
                });
            } catch (e) { document.execCommand('copy'); }
        });
    }

    onReady(function(){
        var style = document.createElement('style');
        style.textContent = ''
            + '.asr-dark-audit-panel{position:fixed;right:18px;bottom:18px;z-index:2147483000;width:min(560px,calc(100vw - 36px));max-height:min(660px,calc(100vh - 36px));display:flex;flex-direction:column;gap:10px;background:#111827;color:#E5E7EB;border:1px solid #374151;border-radius:20px;box-shadow:0 24px 80px rgba(0,0,0,.45);padding:14px;font-family:Montserrat,Arial,sans-serif}'
            + '.asr-dark-audit-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.asr-dark-audit-head b{display:block;font-size:14px}.asr-dark-audit-head span{display:block;margin-top:3px;color:#A9B4C2;font-size:12px;font-weight:600}.asr-dark-audit-head button{width:34px;height:34px;border:0;border-radius:12px;background:#1f2937;color:#E5E7EB;font-size:22px;line-height:1;cursor:pointer}'
            + '.asr-dark-audit-actions{display:flex;gap:8px;flex-wrap:wrap}.asr-dark-audit-actions button{border:1px solid #4B5563;background:#1f2937;color:#F3F4F6;border-radius:12px;padding:10px 12px;font-size:12px;font-weight:700;cursor:pointer}.asr-dark-audit-actions button:first-child{background:#FFA048;border-color:#FFA048;color:#fff}'
            + '.asr-dark-audit-note{font-size:11px;line-height:1.45;color:#CBD5E1;font-weight:600}.asr-dark-audit-panel textarea{width:100%;min-height:210px;flex:1;background:#0B1120!important;color:#D7DEE8!important;border:1px solid #374151!important;border-radius:14px;padding:10px;font:11px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace!important;resize:vertical}'
            + '.asr-dark-audit-badge{position:absolute;z-index:2147482999;width:38px;height:26px;border:0;border-radius:999px;background:#ef4444;color:white;font:800 12px/1 Montserrat,Arial,sans-serif;box-shadow:0 6px 16px rgba(239,68,68,.42);cursor:pointer}';
        document.head.appendChild(style);

        document.addEventListener('click', function(ev){
            if (ev.target && (ev.target.closest('.asr-dark-audit-panel') || ev.target.closest('.asr-dark-audit-badge'))) return;
            setTimeout(function(){ if (findActiveModal()) scan('modal'); }, 650);
        }, true);

        try {
            var observer = new MutationObserver(function(mutations){
                if (auditScanning) return;
                var relevant = mutations.some(function(m){
                    return Array.prototype.slice.call(m.addedNodes || []).some(function(n){
                        if (!n || n.nodeType !== 1) return false;
                        if (n.closest && (n.closest('.asr-dark-audit-panel') || n.closest('.asr-dark-audit-badge'))) return false;
                        var s = shortSelector(n);
                        return /modal|dialog|drawer|panel|popup|sheet|is-open|open/i.test(s) || (n.querySelector && n.querySelector('[role="dialog"],.modal-panel,[class*="modal"],[class*="dialog"]'));
                    });
                });
                if (relevant) scheduleScan('modal', 450);
            });
            observer.observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['class','style','aria-hidden']});
        } catch (e) {}

        setTimeout(function(){ scan('page'); }, 450);
        setTimeout(function(){ scan(findActiveModal() ? 'modal' : 'page'); }, 1600);
        window.ASR_DARK_AUDIT_RESCAN = function(){ scan('page'); };
        window.ASR_DARK_AUDIT_MODAL = function(){ scan('modal'); };
    });
})();
</script>
<?php endif; ?>

</body>
</html>
