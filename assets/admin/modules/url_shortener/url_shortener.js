if (!window.__asrUrlShortenerLoaded) {
window.__asrUrlShortenerLoaded = true;
function normalizeUrlForShortener(value) {
    value = (value || '').trim();
    if (value && !/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) {
        value = 'https://' + value;
    }
    return value;
}
function copyTextValue(value, btn, successText) {
    if (!value) return;
    const old = btn ? btn.textContent : '';
    const done = function(){ if (btn) { btn.textContent = successText || 'Скопировано'; setTimeout(function(){ btn.textContent = old; }, 1200); } };
    if (navigator.clipboard) {
        navigator.clipboard.writeText(value).then(done).catch(function(){ fallbackCopy(value); done(); });
    } else {
        fallbackCopy(value); done();
    }
}
function fallbackCopy(value) {
    const tmp = document.createElement('textarea');
    tmp.value = value;
    tmp.setAttribute('readonly', 'readonly');
    tmp.style.position = 'fixed';
    tmp.style.left = '-9999px';
    document.body.appendChild(tmp);
    tmp.select();
    document.execCommand('copy');
    document.body.removeChild(tmp);
}
function copyShortUrl(btn) {
    const input = btn.closest('div').querySelector('.short-url-input');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    copyTextValue(input.value, btn, 'Скопировано');
}
function openShortEditModal(data) {
    document.getElementById('shortEditId').value = data.id || '';
    document.getElementById('shortEditSlug').value = data.slug || '';
    document.getElementById('shortEditDomain').textContent = 'https://' + (data.domain || window.location.host) + '/';
    const permanent = document.getElementById('shortEditPermanent');
    if (permanent) permanent.checked = String(data.is_permanent || '0') === '1';
    document.getElementById('shortEditModal').classList.remove('hidden');
}
function closeShortEditModal() { document.getElementById('shortEditModal').classList.add('hidden'); }
function openShortenerHelpModal() { document.getElementById('shortenerHelpModal').classList.remove('hidden'); }
function closeShortenerHelpModal() { document.getElementById('shortenerHelpModal').classList.add('hidden'); }
function openUtmEditModal(data) {
    document.getElementById('utmEditId').value = data.id || '';
    document.getElementById('utmEditType').value = data.type || '';
    document.getElementById('utmEditValue').value = data.value || '';
    document.getElementById('utmEditDescription').value = data.description || '';
    document.getElementById('utmEditTypeLabel').textContent = data.type ? 'Тип: utm_' + data.type : '';
    document.getElementById('utmEditModal').classList.remove('hidden');
}
function closeUtmEditModal() { document.getElementById('utmEditModal').classList.add('hidden'); }
(function(){
    const baseInput = document.getElementById('utmBaseUrl');
    const anchorInput = document.getElementById('utmAnchor');
    const resultInput = document.getElementById('utmResultUrl');
    const notice = document.getElementById('utmBuilderNotice');
    const selects = Array.from(document.querySelectorAll('.utm-select'));
    const copyBtn = document.getElementById('copyUtmUrlBtn');
    const sendBtn = document.getElementById('sendUtmToShortenerBtn');
    const clearBtn = document.getElementById('clearUtmBuilderBtn');
    const shortInput = document.getElementById('shortSourceUrl');

    if (!baseInput || !resultInput) return;

    function showNotice(text, type) {
        if (!notice) return;
        if (!text) {
            notice.classList.add('hidden');
            notice.textContent = '';
            return;
        }
        notice.className = 'text-xs font-bold rounded-2xl px-4 py-3 ' + (type === 'error' ? 'bg-red-50 border border-red-100 text-red-700' : 'bg-green-50 border border-green-100 text-green-700');
        notice.textContent = text;
    }

    function normalizeAnchor(value) {
        value = (value || '').trim();
        if (!value) return '';
        return value.replace(/^#+/, '');
    }

    function buildUtmUrl() {
        const rawBase = normalizeUrlForShortener(baseInput.value || '');
        resultInput.value = '';
        if (!rawBase) {
            showNotice('', '');
            return '';
        }

        let url;
        try {
            url = new URL(rawBase);
            if (!/^https?:$/.test(url.protocol)) throw new Error('bad protocol');
        } catch (e) {
            showNotice('Введите корректную ссылку. Можно без https:// — система добавит сама.', 'error');
            return '';
        }

        const requiredMissing = [];
        selects.forEach(function(select){
            const value = (select.value || '').trim();
            const type = select.getAttribute('data-utm-type');
            const required = select.getAttribute('data-required') === '1';
            if (required && !value) requiredMissing.push(type);
            if (value) {
                url.searchParams.set('utm_' + type, value);
            } else {
                url.searchParams.delete('utm_' + type);
            }
        });

        const anchor = normalizeAnchor(anchorInput ? anchorInput.value : '');
        if (anchor) url.hash = anchor;

        const finalUrl = url.toString();
        resultInput.value = finalUrl;
        if (requiredMissing.length) {
            showNotice('Заполните обязательные поля: utm_source, utm_medium и utm_campaign.', 'error');
        } else {
            showNotice('Ссылка собрана. Можно копировать или отправлять в укорачиватель.', 'success');
        }
        return finalUrl;
    }

    baseInput.addEventListener('input', buildUtmUrl);
    if (anchorInput) anchorInput.addEventListener('input', buildUtmUrl);
    selects.forEach(function(select){ select.addEventListener('change', buildUtmUrl); });

    if (copyBtn) copyBtn.addEventListener('click', function(){
        const value = buildUtmUrl();
        if (!value) return;
        copyTextValue(value, copyBtn, 'Скопировано');
    });
    if (sendBtn) sendBtn.addEventListener('click', function(){
        const value = buildUtmUrl();
        if (!value || !shortInput) return;
        shortInput.value = value;
        shortInput.focus();
        showNotice('Ссылка передана в блок URL Shortener ниже. Остался один клик — «Сгенерировать».', 'success');
        document.getElementById('shortenerCreateForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    if (clearBtn) clearBtn.addEventListener('click', function(){
        baseInput.value = '';
        if (anchorInput) anchorInput.value = '';
        selects.forEach(function(select){ select.value = ''; });
        resultInput.value = '';
        showNotice('', '');
        baseInput.focus();
    });
})();
(function(){
    const form = document.getElementById('shortenerCreateForm');
    const input = document.getElementById('shortSourceUrl');
    if (form && input && !form.dataset.urlNormalizeBound) {
        form.dataset.urlNormalizeBound = '1';
        form.addEventListener('submit', function(){
            input.value = normalizeUrlForShortener(input.value || '');
        });
    }
})();
(function(){
    const rows = Array.from(document.querySelectorAll('[data-short-row]'));
    const counter = document.getElementById('shortLazyCounter');
    const sentinel = document.getElementById('shortLazySentinel');
    if (!rows.length || rows.length <= 20 || !sentinel) return;
    let shown = 20;
    const step = 20;
    function updateCounter() {
        if (counter) counter.textContent = 'Показано ' + Math.min(shown, rows.length) + ' из ' + rows.length;
        if (shown >= rows.length && sentinel) sentinel.textContent = 'Показаны все ссылки';
    }
    function showMore() {
        const next = Math.min(shown + step, rows.length);
        for (let i = shown; i < next; i++) rows[i].classList.remove('hidden');
        shown = next;
        updateCounter();
    }
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && shown < rows.length) showMore();
            });
        }, { rootMargin: '240px' });
        observer.observe(sentinel);
    } else {
        window.addEventListener('scroll', function(){
            if (shown >= rows.length) return;
            const rect = sentinel.getBoundingClientRect();
            if (rect.top < window.innerHeight + 240) showMore();
        });
    }
    updateCounter();
})();

window.copyShortUrl = copyShortUrl;
window.openShortEditModal = openShortEditModal;
window.closeShortEditModal = closeShortEditModal;
window.openShortenerHelpModal = openShortenerHelpModal;
window.closeShortenerHelpModal = closeShortenerHelpModal;
window.openUtmEditModal = openUtmEditModal;
window.closeUtmEditModal = closeUtmEditModal;

}
