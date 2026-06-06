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
    min-height: 46px !important;
    border-radius: 22px !important;
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
        <div class="px-0 pb-4">
            <button id="pwaInstallButton" type="button" onclick="installAdminApp()" class="drawer-footer-btn ly2-btn w-full flex items-center justify-center gap-2 rounded-2xl bg-[#7A8A3A] text-white hover:bg-[#687731] uppercase transition-colors shadow-lg shadow-lime-900/10">
                <img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-download-white.svg" alt="" aria-hidden="true">
                <span>Установить приложение</span>
            </button>
        </div>
        <div class="drawer-footer border-t border-gray-100 space-y-3">
            <div class="theme-row">
                <button id="adminThemeToggle" type="button" onclick="toggleAdminTheme()" class="theme-toggle" aria-label="Переключить тему" title="Переключить тему">
                    <img class="ly2-theme-icon ly2-theme-sun" src="/assets/admin/icons/ly2-sun-gray.svg" alt="" aria-hidden="true">
                    <img class="ly2-theme-icon ly2-theme-moon" src="/assets/admin/icons/ly2-moon-gray.svg" alt="" aria-hidden="true">
                </button>
                <button onclick="document.getElementById('helpModal').classList.remove('hidden'); closeDrawer();" class="drawer-footer-btn ly2-btn w-full flex items-center justify-center gap-2 rounded-2xl bg-gray-100 text-gray-500 hover:bg-[#ffa048] hover:text-white uppercase transition-colors"><img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-help-gray.svg" alt="" aria-hidden="true">Справочная информация</button>
            </div>
            <a href="?logout" class="drawer-footer-btn ly2-btn w-full border-2 border-gray-100 text-gray-400 rounded-2xl hover:text-red-500 uppercase"><img class="ly2-icon ly2-icon-sm" src="/assets/admin/icons/ly2-logout-gray.svg" alt="" aria-hidden="true">Выйти</a>
            <div class="drawer-copyright">© 2026 Дмитрий Базатин. Все права защищены.</div>
        </div>
    </aside>
    <?php endif; ?>

    <div class="<?php echo htmlspecialchars($asrAdminShellClass, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (!$is_shared_view && !$asrIsDialogsFullscreen && !$asrIsFlowFullscreen): ?>
        <div class="admin-header flex items-center justify-between mb-8 bg-white p-6 rounded-2xl shadow-sm border border-gray-100 gap-4">
            <div class="admin-brand flex items-center gap-4 min-w-0">
                <button type="button" onclick="openDrawer()" class="w-11 h-11 shrink-0 rounded-2xl bg-[#FFA048] text-white flex items-center justify-center hover:bg-[#ff9226] shadow-lg shadow-orange-500/20" aria-label="Открыть меню">
                    <img class="ly2-menu-icon" src="/assets/admin/icons/ly2-menu-white.svg" alt="" aria-hidden="true">
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
                if (!('serviceWorker' in navigator)) return;
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin.php' })
                        .catch(function(error) {
                            console.warn('PWA service worker registration failed:', error);
                        });
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
