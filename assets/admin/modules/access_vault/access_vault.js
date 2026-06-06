(function () {
  'use strict';

  window.avShareState = window.avShareState || { credentialId: 0, text: '' };

  function byId(id) { return document.getElementById(id); }

  function closeAvModal(id) {
    const el = byId(id);
    if (el) {
      el.classList.add('hidden');
      el.classList.remove('flex');
      if (id === 'avCredentialModal') {
        const dd = byId('avIndividualDropdown');
        if (dd) dd.classList.add('hidden');
        const add = byId('avAssignedDropdown');
        if (add) add.classList.add('hidden');
      }
    }
  }

  function showAvModal(id) {
    const el = byId(id);
    if (el) {
      el.classList.remove('hidden');
      el.classList.add('flex');
      el.scrollTop = 0;
    }
  }

  function toggleAvAddMenu(force) {
    const el = byId('avAddMenu');
    if (!el) return;
    if (force === false) el.classList.add('hidden');
    else if (force === true) el.classList.remove('hidden');
    else el.classList.toggle('hidden');
  }

  function openAvGroupsModal() { showAvModal('avGroupsModal'); }

  function toggleAvGroup(key) {
    let selectorKey = String(key || '');
    const esc = window.CSS && typeof window.CSS.escape === 'function' ? window.CSS.escape(selectorKey) : selectorKey.replace(/"/g, '\\"');
    const el = document.querySelector('[data-av-group="' + esc + '"]');
    if (el) el.classList.toggle('is-collapsed');
  }

  function setValue(id, value) {
    const el = byId(id);
    if (el) el.value = value == null ? '' : String(value);
  }

  function filterAvResourceGroups(category) {
    const group = byId('av_resource_group');
    if (!group) return;
    const activeCategory = String(category || window.asrAvCategory || 'sites');
    let selectedVisible = false;
    Array.prototype.forEach.call(group.options, function (option) {
      const optionCategory = option.getAttribute('data-category') || activeCategory;
      const visible = option.value === '0' || optionCategory === 'all' || optionCategory === activeCategory;
      option.hidden = !visible;
      option.disabled = !visible;
      if (option.selected && visible) selectedVisible = true;
    });
    if (!selectedVisible) group.value = '0';
  }

  function openAvResourceModal(data) {
    data = data || {};
    const actionEl = byId('av_resource_action');
    if (!actionEl) return;
    actionEl.value = data.id ? 'av_update_resource' : 'av_create_resource';
    setValue('av_resource_id', data.id || '');
    setValue('av_resource_category', data.category || window.asrAvCategory || 'sites');
    filterAvResourceGroups(data.category || window.asrAvCategory || 'sites');
    setValue('av_resource_group', data.group_id || '0');
    setValue('av_resource_title', data.title || '');
    setValue('av_resource_url', data.url || '');
    setValue('av_resource_comment', data.comment || '');
    showAvModal('avResourceModal');
  }

  function openAvCredentialModal(data) {
    data = data || {};
    const actionEl = byId('av_credential_action');
    if (!actionEl) return;
    actionEl.value = data.id ? 'av_update_credential' : 'av_create_credential';
    setValue('av_credential_id', data.id || '');
    setValue('av_credential_resource_id', data.resource_id || '');
    setValue('av_credential_login', data.login || '');
    setValue('av_credential_password', '');
    setValue('av_credential_comment', data.comment || '');
    if (typeof setAvIndividualUsers === 'function') {
      setAvIndividualUsers(data.allowed_user_ids || []);
    }
    if (typeof setAvAssignedUsers === 'function') {
      setAvAssignedUsers(data.assigned_user_ids || []);
    }
    showAvModal('avCredentialModal');
  }

  function openAvShareModal(credentialId, text) {
    window.avShareState = { credentialId: credentialId || 0, text: text || '' };
    const input = byId('av_email_credential_id');
    if (input) input.value = window.avShareState.credentialId;
    showAvModal('avShareModal');
  }

  function openAvEmailModal(credentialId) { openAvShareModal(credentialId, ''); }

  function generateAvPassword() {
    const length = 20;
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*()-_=+[]{};:,.?'];
    const chars = sets.join('');
    const rand = function (max) {
      const a = new Uint32Array(1);
      window.crypto.getRandomValues(a);
      return a[0] % max;
    };
    let pass = sets.map(function (s) { return s[rand(s.length)]; }).join('');
    while (pass.length < length) pass += chars[rand(chars.length)];
    pass = pass.split('').sort(function () { return rand(2) ? 1 : -1; }).join('');
    setValue('av_credential_password', pass);
  }

  function toggleAvPassword(btn) {
    const input = btn && btn.parentElement ? btn.parentElement.querySelector('input') : null;
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    if (input.type === 'text') setTimeout(function () { input.type = 'password'; }, 30000);
  }

  async function avPostAudit(action, credentialId) {
    try {
      const fd = new FormData();
      fd.append('action', action);
      fd.append('credential_id', credentialId);
      fd.append('category', window.asrAvCategory || 'sites');
      fd.append('csrf_token', window.asrCsrfToken || '');
      await fetch('admin.php?tab=access_vault&category=' + encodeURIComponent(window.asrAvCategory || 'sites'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
    } catch (e) {}
  }

  async function copyAvText(credentialId, text, kind) {
    try {
      if (navigator.clipboard) await navigator.clipboard.writeText(text || '');
      else {
        const ta = document.createElement('textarea');
        ta.value = text || '';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      await avPostAudit('av_copy_credential', credentialId);
      alert(kind === 'password' ? 'Пароль скопирован' : 'Скопировано');
    } catch (e) {
      alert('Не удалось скопировать');
    }
  }

  async function shareAvCredential(credentialId, text) {
    await avPostAudit('av_share_messenger', credentialId);
    if (navigator.share) {
      try {
        await navigator.share({ title: 'Доступ', text: text || '' });
        return;
      } catch (e) {
        if (e && e.name === 'AbortError') return;
      }
    }
    await copyAvText(credentialId, text, 'full');
  }

  async function sendAvShareMessenger() {
    await shareAvCredential(window.avShareState.credentialId, window.avShareState.text);
    closeAvModal('avShareModal');
  }

  function setAvAutoPaymentVisible() {
    const auto = byId('av_payment_auto');
    const options = byId('av_auto_payment_options');
    if (!options) return;
    const isOn = !!(auto && auto.checked);
    options.classList.toggle('hidden', !isOn);
  }

  function openAvPaymentModal(data) {
    data = data || {};
    const modal = byId('avPaymentModal');
    if (!modal) return;
    const title = byId('av_payment_resource_title');
    if (title) title.textContent = data.title || '';
    setValue('av_payment_resource_id', data.resource_id || '');
    setValue('av_payment_date', data.payment_date || '');
    setValue('av_payment_days', data.remind_days_before || '14,3');
    setValue('av_payment_repeat', data.repeat_type || 'yearly');
    setValue('av_payment_amount', data.payment_amount || '');
    setValue('av_payment_currency', data.payment_currency || '₸');
    setValue('av_payment_message', data.message || '');
    const enabled = byId('av_payment_enabled');
    if (enabled) enabled.checked = String(data.is_enabled == null ? '1' : data.is_enabled) !== '0';
    const auto = byId('av_payment_auto');
    if (auto) auto.checked = String(data.auto_payment || '0') === '1';
    const autoPeriod = String(data.auto_payment_period || data.repeat_type || 'monthly');
    modal.querySelectorAll('.av-auto-payment-period').forEach(function (radio) {
      radio.checked = String(radio.value) === (autoPeriod === 'yearly' ? 'yearly' : 'monthly');
    });
    if (!modal.querySelector('.av-auto-payment-period:checked')) {
      const monthly = modal.querySelector('.av-auto-payment-period[value="monthly"]');
      if (monthly) monthly.checked = true;
    }
    setAvAutoPaymentVisible();
    const recipients = Array.isArray(data.recipients) ? data.recipients.map(String) : [];
    modal.querySelectorAll('.av-payment-recipient').forEach(function (cb) {
      cb.checked = recipients.includes(String(cb.value));
    });
    showAvModal('avPaymentModal');
  }



  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (ch) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];
    });
  }

  function toggleAvIndividualDropdown() {
    const el = byId('avIndividualDropdown');
    if (el) el.classList.toggle('hidden');
  }

  function filterAvIndividualUsers(query) {
    query = String(query || '').toLowerCase().trim();
    document.querySelectorAll('.av-individual-user').forEach(function (row) {
      const hay = String(row.getAttribute('data-search') || '').toLowerCase();
      row.style.display = !query || hay.includes(query) ? '' : 'none';
    });
  }

  function updateAvIndividualSelected() {
    const box = byId('avIndividualSelected');
    if (!box) return;
    const chips = [];
    document.querySelectorAll('.av-individual-checkbox').forEach(function (cb) {
      const row = cb.closest('.av-individual-user');
      if (!row) return;
      row.classList.toggle('is-selected', cb.checked);
      if (cb.checked) {
        const text = row.innerText.trim();
        chips.push('<span class="inline-flex px-3 py-1 rounded-xl bg-orange-50 text-[#FFA048] border border-orange-100">' + escapeHtml(text) + '</span>');
      }
    });
    box.innerHTML = chips.length ? chips.join('') : '<span class="text-gray-400">Индивидуальные сотрудники не выбраны</span>';
  }

  function setAvIndividualUsers(ids) {
    const selected = (ids || []).map(String);
    document.querySelectorAll('.av-individual-checkbox').forEach(function (cb) {
      cb.checked = selected.includes(String(cb.value));
    });
    const search = byId('avIndividualSearch');
    if (search) search.value = '';
    filterAvIndividualUsers('');
    updateAvIndividualSelected();
    const dd = byId('avIndividualDropdown');
    if (dd) dd.classList.add('hidden');
  }


  function toggleAvAssignedDropdown() {
    const el = byId('avAssignedDropdown');
    if (el) el.classList.toggle('hidden');
  }

  function filterAvAssignedUsers(query) {
    query = String(query || '').toLowerCase().trim();
    document.querySelectorAll('.av-assigned-user').forEach(function (row) {
      const hay = String(row.getAttribute('data-search') || '').toLowerCase();
      row.style.display = !query || hay.includes(query) ? '' : 'none';
    });
  }

  function updateAvAssignedSelected() {
    const box = byId('avAssignedSelected');
    if (!box) return;
    const chips = [];
    document.querySelectorAll('.av-assigned-checkbox').forEach(function (cb) {
      const row = cb.closest('.av-assigned-user');
      if (!row) return;
      row.classList.toggle('is-selected', cb.checked);
      if (cb.checked) {
        const text = row.innerText.trim();
        chips.push('<span class="inline-flex px-3 py-1 rounded-xl bg-gray-100 text-gray-600 border border-gray-200">' + escapeHtml(text) + '</span>');
      }
    });
    box.innerHTML = chips.length ? chips.join('') : '<span class="text-gray-400">Сотрудники не выбраны</span>';
  }

  function setAvAssignedUsers(ids) {
    const selected = (ids || []).map(String);
    document.querySelectorAll('.av-assigned-checkbox').forEach(function (cb) {
      cb.checked = selected.includes(String(cb.value));
    });
    const search = byId('avAssignedSearch');
    if (search) search.value = '';
    filterAvAssignedUsers('');
    updateAvAssignedSelected();
    const dd = byId('avAssignedDropdown');
    if (dd) dd.classList.add('hidden');
  }


  function closeAvMoreMenus() {
    document.querySelectorAll('.av-more-menu.is-open').forEach(function (m) {
      m.classList.remove('is-open');
      m.style.left = '';
      m.style.top = '';
      m.style.right = '';
      m.style.bottom = '';
    });
  }

  function toggleAvMoreMenu(btn) {
    if (!btn) return;
    const wrap = btn.closest('.av-more-wrap');
    const menu = wrap ? wrap.querySelector('.av-more-menu') : null;
    if (!menu) return;
    const isOpen = menu.classList.contains('is-open');
    closeAvMoreMenus();
    if (isOpen) return;
    menu.classList.add('is-open');
    const rect = btn.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const width = menuRect.width || 220;
    const height = menuRect.height || 180;
    let left = Math.min(window.innerWidth - width - 12, Math.max(12, rect.right - width));
    let top = rect.bottom + 8;
    if (top + height > window.innerHeight - 12) top = Math.max(12, rect.top - height - 8);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }

  function startAvCredentialReorder(resourceId) {
    const article = document.querySelector('[data-av-order-save-resource="' + String(resourceId) + '"]')?.closest('.av-resource');
    if (!article) return;
    article.classList.add('av-reorder-mode');
    const save = article.querySelector('[data-av-order-save-resource="' + String(resourceId) + '"]');
    if (save) save.classList.remove('hidden');
    refreshAvCredentialOrder(article);
  }

  function refreshAvCredentialOrder(article) {
    const form = article.querySelector('.av-order-save');
    if (!form) return;
    const input = form.querySelector('input[name="credential_order"]');
    if (!input) return;
    input.value = Array.prototype.map.call(article.querySelectorAll('.av-credential-row[data-credential-id]'), function (row) { return row.getAttribute('data-credential-id'); }).join(',');
  }

  function initAvDragSorting() {
    let dragged = null;
    document.addEventListener('dragstart', function (e) {
      const handle = e.target.closest ? e.target.closest('.av-drag-handle') : null;
      if (!handle) return;
      const row = handle.closest('.av-credential-row');
      if (!row || !row.closest('.av-reorder-mode')) { e.preventDefault(); return; }
      dragged = row;
      row.classList.add('is-dragging');
      if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
    });
    document.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('is-dragging');
      dragged = null;
      document.querySelectorAll('.av-resource.av-reorder-mode').forEach(refreshAvCredentialOrder);
    });
    document.addEventListener('dragover', function (e) {
      if (!dragged) return;
      const over = e.target.closest ? e.target.closest('.av-credential-row[data-credential-id]') : null;
      if (!over || over === dragged) return;
      const list = over.parentElement;
      if (!list || list !== dragged.parentElement) return;
      e.preventDefault();
      const rect = over.getBoundingClientRect();
      const before = e.clientY < rect.top + rect.height / 2;
      list.insertBefore(dragged, before ? over : over.nextSibling);
      refreshAvCredentialOrder(list.closest('.av-resource'));
    });
  }

  function submitAllAvGroupForms() {
    const modal = byId('avGroupsModal');
    if (!modal) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin.php?tab=access_vault&category=' + encodeURIComponent(window.asrAvCategory || 'sites');
    function add(name, value) { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value == null ? '' : String(value); form.appendChild(i); }
    add('action', 'av_bulk_update_groups');
    add('category', window.asrAvCategory || 'sites');
    add('csrf_token', window.asrCsrfToken || '');
    let index = 0;
    modal.querySelectorAll('.av-group-edit-form').forEach(function (rowForm) {
      const id = rowForm.querySelector('input[name="group_id"]')?.value || '';
      if (!id) return;
      add('groups[' + index + '][id]', id);
      add('groups[' + index + '][title]', rowForm.querySelector('input[name="group_title"]')?.value || '');
      add('groups[' + index + '][sort_order]', rowForm.querySelector('input[name="sort_order"]')?.value || '100');
      add('groups[' + index + '][color]', rowForm.querySelector('input[name="group_color"]')?.value || '#F4E4A6');
      add('groups[' + index + '][text_color]', rowForm.querySelector('input[name="group_text_color"]')?.value || '#4B5563');
      add('groups[' + index + '][icon]', rowForm.querySelector('select[name="group_icon"]')?.value || 'flask');
      index++;
    });
    document.body.appendChild(form);
    form.submit();
  }

  initAvDragSorting();

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.av-more-wrap')) closeAvMoreMenus();
    const menu = byId('avAddMenu');
    if (!menu) return;
    const btn = e.target.closest ? e.target.closest('.av-add-main') : null;
    if (!btn && !menu.contains(e.target)) menu.classList.add('hidden');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      ['avResourceModal', 'avCredentialModal', 'avEmailModal', 'avShareModal', 'avGroupsModal', 'avPaymentModal'].forEach(closeAvModal);
      toggleAvAddMenu(false);
    }
  });

  document.addEventListener('change', function (event) {
    if (event.target && event.target.id === 'av_resource_category') {
      filterAvResourceGroups(event.target.value);
    }
    if (event.target && event.target.id === 'av_payment_auto') {
      setAvAutoPaymentVisible();
    }
  });

  window.closeAvModal = closeAvModal;
  window.showAvModal = showAvModal;
  window.toggleAvAddMenu = toggleAvAddMenu;
  window.openAvGroupsModal = openAvGroupsModal;
  window.toggleAvGroup = toggleAvGroup;
  window.openAvResourceModal = openAvResourceModal;
  window.openAvCredentialModal = openAvCredentialModal;
  window.openAvShareModal = openAvShareModal;
  window.openAvEmailModal = openAvEmailModal;
  window.generateAvPassword = generateAvPassword;
  window.toggleAvPassword = toggleAvPassword;
  window.copyAvText = copyAvText;
  window.shareAvCredential = shareAvCredential;
  window.sendAvShareMessenger = sendAvShareMessenger;
  window.openAvPaymentModal = openAvPaymentModal;
  window.setAvAutoPaymentVisible = setAvAutoPaymentVisible;
  window.toggleAvIndividualDropdown = toggleAvIndividualDropdown;
  window.filterAvIndividualUsers = filterAvIndividualUsers;
  window.updateAvIndividualSelected = updateAvIndividualSelected;
  window.setAvIndividualUsers = setAvIndividualUsers;
  window.closeAvMoreMenus = closeAvMoreMenus;
  window.toggleAvMoreMenu = toggleAvMoreMenu;
  window.startAvCredentialReorder = startAvCredentialReorder;
  window.submitAllAvGroupForms = submitAllAvGroupForms;
  window.toggleAvAssignedDropdown = toggleAvAssignedDropdown;
  window.filterAvAssignedUsers = filterAvAssignedUsers;
  window.updateAvAssignedSelected = updateAvAssignedSelected;
  window.setAvAssignedUsers = setAvAssignedUsers;
})();
