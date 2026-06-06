<?php
defined('ASR_ADMIN') || exit;
?>
    <script>
        (function() {
            const key = 'asr-admin-theme';
            const root = document.documentElement;

            function setTheme(theme) {
                const isDark = theme === 'dark';
                root.classList.toggle('asr-dark', isDark);
                try { localStorage.setItem(key, isDark ? 'dark' : 'light'); } catch (e) {}

                const toggle = document.getElementById('adminThemeToggle');
                if (toggle) {
                    toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                    toggle.setAttribute('title', isDark ? 'Включить светлую тему' : 'Включить тёмную тему');
                }

                window.dispatchEvent(new CustomEvent('asr-admin-theme-changed', { detail: { theme: isDark ? 'dark' : 'light' } }));
            }

            window.toggleAdminTheme = function() {
                setTheme(root.classList.contains('asr-dark') ? 'light' : 'dark');
            };

            setTheme(root.classList.contains('asr-dark') ? 'dark' : 'light');
        })();
    </script>
    <script>
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_phone').value = data.phone;
            document.getElementById('edit_email').value = data.email;
            document.getElementById('edit_city').value = data.city;
            document.getElementById('edit_role').value = data.role;
            document.getElementById('edit_crm_deal').value = data.crm_deal;
            document.getElementById('edit_crm_contact').value = data.crm_contact;
            document.getElementById('editModal').classList.remove('hidden');
        }
        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }
        
        
function openEditUserModal(data) {
    data = data || {};
    const isProtectedUser = !!data.protected || String(data.id || '') === '1' || String(data.role || '') === 'superadmin';

    const idInput = document.getElementById('edit_user_id');
    const nameInput = document.getElementById('edit_user_fullname');
    const usernameInput = document.getElementById('edit_user_username');
    const passwordInput = document.getElementById('edit_user_new_password');
    const rememberInput = document.getElementById('edit_user_remember_365_days');
    const dialogsInput = document.getElementById('edit_user_connect_to_dialogs');
    const dialogsWrap = document.getElementById('editUserDialogsWrap');
    const rememberWrap = document.getElementById('editUserRememberWrap');
    const roleSelect = document.getElementById('edit_user_role');
    const roleWrap = document.getElementById('editUserRoleWrap');
    const protectedNotice = document.getElementById('editUserProtectedNotice');
    const permissionsWrap = document.getElementById('editUserPermissionsWrap');

    if (idInput) idInput.value = data.id || '';
    if (nameInput) nameInput.value = data.full_name || '';
    if (usernameInput) usernameInput.value = data.username || '';
    if (passwordInput) passwordInput.value = '';
    if (rememberInput) rememberInput.checked = !!data.remember_365_days;
    if (dialogsInput) dialogsInput.checked = !!data.connect_to_dialogs;
    if (roleSelect) roleSelect.value = data.role || 'operator';

    document.querySelectorAll('.edit-user-permission').forEach(function(cb) {
        cb.checked = !!(data.access_permissions && data.access_permissions[cb.value]);
        cb.disabled = isProtectedUser;
    });

    const updatePermissionsVisibility = function() {
        const role = roleSelect ? roleSelect.value : (data.role || 'operator');
        const shouldShow = !isProtectedUser && (role === 'admin' || role === 'manager');
        if (permissionsWrap) permissionsWrap.classList.toggle('hidden', !shouldShow);
    };

    if (roleSelect && !roleSelect.dataset.permissionsListener) {
        roleSelect.addEventListener('change', updatePermissionsVisibility);
        roleSelect.dataset.permissionsListener = '1';
    }

    if (roleWrap) roleWrap.classList.toggle('hidden', isProtectedUser);
    if (rememberWrap) rememberWrap.classList.toggle('hidden', isProtectedUser);
    if (dialogsWrap) dialogsWrap.classList.toggle('hidden', false);
    if (protectedNotice) protectedNotice.classList.toggle('hidden', !isProtectedUser);
    updatePermissionsVisibility();

    const tgStatus = document.getElementById('editUserTelegramStatus');
    const tgLinkBox = document.getElementById('editUserTelegramLinkBox');
    const tgNoLink = document.getElementById('editUserTelegramNoLink');
    const tgLinkInput = document.getElementById('editUserTelegramLink');
    const tgCopyBtn = document.getElementById('editUserTelegramCopyBtn');
    const tgConnected = !!data.telegram_connected;
    const tgLink = String(data.telegram_link || '');

    if (tgStatus) tgStatus.textContent = tgConnected ? 'Telegram подключён.' : 'Telegram пока не подключён.';
    if (tgLinkInput) tgLinkInput.value = tgLink;
    if (tgLinkBox) tgLinkBox.classList.toggle('hidden', tgLink === '');
    if (tgNoLink) tgNoLink.classList.toggle('hidden', tgLink !== '');

    if (tgCopyBtn && !tgCopyBtn.dataset.copyListener) {
        tgCopyBtn.addEventListener('click', function() {
            const input = document.getElementById('editUserTelegramLink');
            const value = input ? input.value : '';
            if (!value) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function(){ alert('Ссылка подключения Telegram скопирована'); }).catch(function(){ input.select(); document.execCommand('copy'); alert('Ссылка подключения Telegram скопирована'); });
            } else {
                input.select(); document.execCommand('copy'); alert('Ссылка подключения Telegram скопирована');
            }
        });
        tgCopyBtn.dataset.copyListener = '1';
    }

    const btestStatus = document.getElementById('editUserBroadcastTestTelegramStatus');
    const btestLinkBox = document.getElementById('editUserBroadcastTestTelegramLinkBox');
    const btestNoLink = document.getElementById('editUserBroadcastTestTelegramNoLink');
    const btestLinkInput = document.getElementById('editUserBroadcastTestTelegramLink');
    const btestCopyBtn = document.getElementById('editUserBroadcastTestTelegramCopyBtn');
    const btestOptions = document.getElementById('editUserBroadcastTestOptions');
    const btestReceiveInput = document.getElementById('edit_user_broadcast_test_receive_broadcasts');
    const btestNotifyInput = document.getElementById('edit_user_broadcast_test_notify_dialogs');
    const btestConnected = !!data.broadcast_test_telegram_connected;
    const btestLink = String(data.broadcast_test_telegram_link || '');

    if (btestStatus) btestStatus.textContent = btestConnected ? 'Тестовый бот рассылок подключён.' : 'Тестовый бот рассылок пока не подключён.';
    if (btestLinkInput) btestLinkInput.value = btestLink;
    if (btestLinkBox) btestLinkBox.classList.toggle('hidden', btestLink === '');
    if (btestNoLink) btestNoLink.classList.toggle('hidden', btestLink !== '');
    if (btestOptions) btestOptions.classList.toggle('hidden', !btestConnected);
    if (btestReceiveInput) btestReceiveInput.checked = data.broadcast_test_receive_broadcasts !== false;
    if (btestNotifyInput) btestNotifyInput.checked = !!data.broadcast_test_notify_dialogs;

    if (btestCopyBtn && !btestCopyBtn.dataset.copyListener) {
        btestCopyBtn.addEventListener('click', function() {
            const input = document.getElementById('editUserBroadcastTestTelegramLink');
            const value = input ? input.value : '';
            if (!value) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function(){ alert('Ссылка тестового бота скопирована'); }).catch(function(){ input.select(); document.execCommand('copy'); alert('Ссылка тестового бота скопирована'); });
            } else {
                input.select(); document.execCommand('copy'); alert('Ссылка тестового бота скопирована');
            }
        });
        btestCopyBtn.dataset.copyListener = '1';
    }

    const modal = document.getElementById('editUserModal');
    if (modal) modal.classList.remove('hidden');
}
function closeEditUserModal() { const modal = document.getElementById('editUserModal'); if (modal) modal.classList.add('hidden'); }

        let shareAccessTargetEmail = '';
        let shareAccessEditData = null;
        function openShareAccessModal(data) {
            shareAccessTargetEmail = data.username || '';
            shareAccessEditData = data.editData || null;
            const area = document.getElementById('shareAccessText');
            if (area) area.value = data.text || '';
            const setPasswordBtn = document.getElementById('shareAccessSetPasswordBtn');
            if (setPasswordBtn) setPasswordBtn.classList.toggle('hidden', data.passwordSaved !== false);
            const modal = document.getElementById('shareAccessModal');
            if (modal) modal.classList.remove('hidden');
        }
        function closeShareAccessModal() {
            const modal = document.getElementById('shareAccessModal');
            if (modal) modal.classList.add('hidden');
        }
        function getShareAccessText() {
            const area = document.getElementById('shareAccessText');
            return area ? area.value : '';
        }
        function shareAccessByMail() {
            const text = getShareAccessText();
            const subject = 'Доступ в админку системы тестирования АВМ';
            window.location.href = 'mailto:' + encodeURIComponent(shareAccessTargetEmail) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(text);
        }
        async function shareAccessByNative() {
            const text = getShareAccessText();
            if (navigator.share) {
                try {
                    await navigator.share({ title: 'Доступ в админку', text: text });
                    return;
                } catch (e) {
                    if (e && e.name === 'AbortError') return;
                }
            }
            window.open('https://t.me/share/url?url=&text=' + encodeURIComponent(text), '_blank');
        }
        function openSharePasswordReset() {
            if (!shareAccessEditData) return;
            closeShareAccessModal();
            openEditUserModal(shareAccessEditData);
            setTimeout(function(){
                const passwordInput = document.getElementById('edit_user_new_password');
                if (passwordInput) passwordInput.focus();
            }, 120);
        }
        function copyShareAccessText() {
            const text = getShareAccessText();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function(){ alert('Текст доступов скопирован'); });
            } else {
                const area = document.getElementById('shareAccessText');
                if (area) { area.select(); document.execCommand('copy'); alert('Текст доступов скопирован'); }
            }
        }
        
        const cbs = document.querySelectorAll('.compare-cb');
        const btn = document.getElementById('compareBtn');
        if(cbs && btn) {
            cbs.forEach(c => c.addEventListener('change', () => {
                const count = document.querySelectorAll('.compare-cb:checked').length;
                if(count === 2) {
                    btn.disabled = false; btn.className = "px-6 py-2.5 bg-[#FFA048] text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-lg cursor-pointer";
                } else {
                    btn.disabled = true; btn.className = "px-6 py-2.5 bg-gray-100 text-gray-400 font-bold text-xs uppercase tracking-widest rounded-xl cursor-not-allowed";
                }
            }));
            btn.onclick = () => {
                const checked = Array.from(document.querySelectorAll('.compare-cb:checked')).map(el => el.value);
                window.location.href = '?compare=' + checked.join(',');
            };
        }
    </script>
    <script>
        function openDrawer() {
            const drawer = document.getElementById('adminDrawer');
            const backdrop = document.getElementById('drawerBackdrop');
            if (drawer) drawer.style.transform = '';
            if (backdrop) backdrop.style.opacity = '';
            document.body.classList.add('drawer-open');
        }
        function closeDrawer() {
            const drawer = document.getElementById('adminDrawer');
            const backdrop = document.getElementById('drawerBackdrop');
            if (drawer) drawer.style.transform = '';
            if (backdrop) backdrop.style.opacity = '';
            document.body.classList.remove('drawer-open');
        }
        const drawerBackdrop = document.getElementById('drawerBackdrop');
        const adminDrawer = document.getElementById('adminDrawer');
        if (drawerBackdrop) drawerBackdrop.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDrawer(); });

const drawerGroups = Array.from(document.querySelectorAll('.drawer-group'));
drawerGroups.forEach(function(group) {
    group.addEventListener('toggle', function() {
        if (!group.open) return;
        drawerGroups.forEach(function(other) {
            if (other !== group) other.open = false;
        });
        const nav = group.closest('.drawer-nav');
        if (nav) {
            setTimeout(function() {
                const top = group.offsetTop - 6;
                nav.scrollTo({ top: top < 0 ? 0 : top, behavior: 'smooth' });
            }, 40);
        }
    });
});


        // Плавное мобильное меню: тянется пальцем от левого края и закрывается свайпом влево.
        let drawerTouch = {
            active: false,
            tracking: false,
            startX: 0,
            startY: 0,
            lastX: 0,
            width: 0,
            wasOpen: false
        };

        function setDrawerDrag(offsetPx, opacity) {
            if (!adminDrawer || !drawerBackdrop) return;
            adminDrawer.style.transition = 'none';
            drawerBackdrop.style.transition = 'none';
            adminDrawer.style.transform = 'translateX(' + offsetPx + 'px)';
            drawerBackdrop.style.opacity = String(opacity);
            drawerBackdrop.style.pointerEvents = opacity > 0.05 ? 'auto' : 'none';
        }

        function resetDrawerTransition() {
            if (!adminDrawer || !drawerBackdrop) return;
            adminDrawer.style.transition = '';
            drawerBackdrop.style.transition = '';
            adminDrawer.style.transform = '';
            drawerBackdrop.style.opacity = '';
            drawerBackdrop.style.pointerEvents = '';
        }

        document.addEventListener('touchstart', function(e) {
            if (!adminDrawer || !e.touches || e.touches.length !== 1) return;
            const touch = e.touches[0];
            const drawerOpen = document.body.classList.contains('drawer-open');
            const fromEdge = touch.clientX <= 28;
            const insideDrawer = drawerOpen && touch.clientX <= adminDrawer.getBoundingClientRect().width;
            if (!fromEdge && !insideDrawer) return;

            drawerTouch = {
                active: true,
                tracking: false,
                startX: touch.clientX,
                startY: touch.clientY,
                lastX: touch.clientX,
                width: adminDrawer.getBoundingClientRect().width || 360,
                wasOpen: drawerOpen
            };
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!drawerTouch.active || !e.touches || e.touches.length !== 1) return;
            const touch = e.touches[0];
            const dx = touch.clientX - drawerTouch.startX;
            const dy = Math.abs(touch.clientY - drawerTouch.startY);
            if (!drawerTouch.tracking) {
                if (dy > 18 && Math.abs(dx) < 24) {
                    drawerTouch.active = false;
                    return;
                }
                if (Math.abs(dx) < 8) return;
                drawerTouch.tracking = true;
                if (drawerBackdrop) drawerBackdrop.style.pointerEvents = 'auto';
            }
            e.preventDefault();
            drawerTouch.lastX = touch.clientX;
            let offset;
            if (drawerTouch.wasOpen) {
                offset = Math.min(0, Math.max(-drawerTouch.width, dx));
            } else {
                offset = Math.min(0, Math.max(-drawerTouch.width, -drawerTouch.width + Math.max(0, dx)));
            }
            const progress = 1 + (offset / drawerTouch.width);
            setDrawerDrag(offset, Math.max(0, Math.min(1, progress)) * 0.5);
        }, { passive: false });

        document.addEventListener('touchend', function() {
            if (!drawerTouch.active) return;
            const dx = drawerTouch.lastX - drawerTouch.startX;
            const shouldOpen = drawerTouch.wasOpen
                ? dx > -drawerTouch.width * 0.32
                : dx > drawerTouch.width * 0.28;
            resetDrawerTransition();
            if (shouldOpen) openDrawer(); else closeDrawer();
            drawerTouch.active = false;
            drawerTouch.tracking = false;
        }, { passive: true });


        let deferredAdminInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredAdminInstallPrompt = e;
        });

        async function installAdminApp() {
            if (deferredAdminInstallPrompt) {
                deferredAdminInstallPrompt.prompt();
                try {
                    await deferredAdminInstallPrompt.userChoice;
                } finally {
                    deferredAdminInstallPrompt = null;
                }
                return;
            }
            const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent || '');
            if (isIos) {
                alert('Чтобы установить приложение: нажмите «Поделиться» в Safari, затем «На экран Домой».');
            } else {
                alert('Если браузер не открыл установку автоматически, откройте меню браузера и выберите «Установить приложение» или «Добавить на главный экран».');
            }
        }

        function getAdminHtmlEditor(target = 'help') {
            const map = {
                help: ['helpEditor', 'helpContentInput'],
                shortener: ['shortenerEditor', 'shortenerInstructionInput']
            };
            const ids = map[target] || map.help;
            return {
                editor: document.getElementById(ids[0]),
                input: document.getElementById(ids[1])
            };
        }
        function syncAdminHtmlEditor(target = 'help') {
            const pair = getAdminHtmlEditor(target);
            if (pair.editor && pair.input) pair.input.value = pair.editor.innerHTML;
        }
        function syncAllAdminHtmlEditors() {
            syncAdminHtmlEditor('help');
            syncAdminHtmlEditor('shortener');
        }
        function syncHelpEditor() {
            syncAdminHtmlEditor('help');
        }
        const settingsForm = document.getElementById('settingsForm');
        if (settingsForm) settingsForm.addEventListener('submit', syncAllAdminHtmlEditors);
        function editorCmd(cmd, value = null, target = 'help') {
            const pair = getAdminHtmlEditor(target);
            if (!pair.editor) return;
            pair.editor.focus();
            document.execCommand(cmd, false, value);
            syncAdminHtmlEditor(target);
        }
        function editorBlock(tag, target = 'help') {
            editorCmd('formatBlock', tag, target);
        }
        function editorLink(target = 'help') {
            const url = prompt('Введите URL ссылки');
            if (url) editorCmd('createLink', url, target);
        }
        function editorImage(target = 'help') {
            const url = prompt('Введите URL картинки');
            if (!url) return;
            editorCmd('insertHTML', '<img src="' + url.replace(/"/g, '&quot;') + '" alt="" style="max-width:100%;height:auto;border-radius:16px;">', target);
        }
        function editorIframe(target = 'help') {
            const code = prompt('Вставьте iframe-код целиком');
            if (!code) return;
            editorCmd('insertHTML', code, target);
        }
        function copyFromInput(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.focus();
            el.select();
            navigator.clipboard?.writeText(el.value).catch(() => document.execCommand('copy'));
        }
    </script>

<script>


(function(){
    if (window.__asrCompactHeaderBound) return;
    window.__asrCompactHeaderBound = true;

    const getHeaders = function(){
        return Array.from(document.querySelectorAll('.admin-header'));
    };
    let compactState = false;
    let ticking = false;

    const apply = function(){
        ticking = false;
        const y = window.scrollY || document.documentElement.scrollTop || 0;
        // Один обработчик с гистерезисом: шапка уменьшается после небольшой прокрутки
        // и раскрывается только у самого верха, поэтому не дёргается на границе.
        const next = compactState ? y > 6 : y > 48;
        if (next === compactState) return;
        compactState = next;
        getHeaders().forEach(function(header){
            header.classList.toggle('is-compact', compactState);
        });
    };

    const requestApply = function(){
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(apply);
    };

    window.addEventListener('scroll', requestApply, { passive: true });
    window.addEventListener('resize', requestApply, { passive: true });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', requestApply);
    } else {
        requestApply();
    }
})();
</script>
