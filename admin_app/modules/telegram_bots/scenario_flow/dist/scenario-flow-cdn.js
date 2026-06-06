// v3.5.65 React Flow stable AJAX actions + broadcast-like block editor
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'https://esm.sh/react@18.3.1';
import {createRoot} from 'https://esm.sh/react-dom@18.3.1/client';
import {
  ReactFlow,
  Background,
  Controls,
  Handle,
  MarkerType,
  Position,
  BaseEdge,
  getBezierPath,
  addEdge,
  useEdgesState,
  useNodesState,
  useReactFlow,
  ReactFlowProvider
} from 'https://esm.sh/@xyflow/react@12.3.5?deps=react@18.3.1,react-dom@18.3.1';

(function(){
  const boot = window.__tgScenarioFlowBoot || (window.__tgScenarioFlowBoot = {});
  boot.evaluated = true;
  boot.version = '3.5.105';
  const status = document.getElementById('tg-flow-boot-status');
  if (status) status.textContent = 'PHP: ' + (boot.nodes ?? '?') + ' блоков / ' + (boot.edges ?? '?') + ' связей · React: модуль загружен';
})();

function readScenarioFlowConfig() {
  const dataEl = document.getElementById('scenario-flow-data');
  if (!dataEl) return {};
  try {
    return JSON.parse(dataEl.textContent || '{}') || {};
  } catch (error) {
    console.error('Scenario Flow config parse error', error);
    return {};
  }
}

const cfg = readScenarioFlowConfig();
const rootEl = document.getElementById('tg-scenario-flow-root');
const errorEl = document.getElementById('tg-scenario-flow-error');
const drawerEl = document.getElementById('tg-flow-block-drawer');
console.info('[ScenarioFlow] init', {nodes: Array.isArray(cfg.nodes) ? cfg.nodes.length : 0, edges: Array.isArray(cfg.edges) ? cfg.edges.length : 0, scenarioId: cfg.scenarioId});

let drawerRequestId = 0;
let flowToastTimer = null;
let tgFlowLastSourceHandle = null;
function tgFlowRememberSourceHandle(nodeId, handleId) {
  tgFlowLastSourceHandle = {nodeId: String(nodeId || ''), handleId: String(handleId || 'out'), at: Date.now()};
}
function tgFlowResolveSourceHandle(nodeId, reportedHandle) {
  const value = String(reportedHandle || '');
  const captured = tgFlowLastSourceHandle;
  if (captured && captured.nodeId === String(nodeId || '') && (Date.now() - captured.at) < 2500) {
    if (!value || value === 'out') return captured.handleId || 'out';
  }
  return value || 'out';
}
function showFlowToast(message, type = 'info') {
  const text = String(message || '').trim();
  if (!text) return;
  let el = document.getElementById('tg-flow-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'tg-flow-toast';
    el.className = 'tg-flow-toast';
    document.body.appendChild(el);
  }
  el.className = 'tg-flow-toast is-open is-' + (type === 'error' ? 'error' : 'info');
  el.textContent = text;
  window.clearTimeout(flowToastTimer);
  flowToastTimer = window.setTimeout(() => el.classList.remove('is-open'), type === 'error' ? 5200 : 2800);
}
function showFlowConfirm(options = {}) {
  const title = String(options.title || 'Подтвердите действие');
  const text = String(options.text || 'Продолжить?');
  const dangerText = String(options.dangerText || 'Подтвердить');
  const cancelText = String(options.cancelText || 'Отмена');
  return new Promise((resolve) => {
    const old = document.getElementById('tg-flow-confirm');
    if (old) old.remove();
    const wrap = document.createElement('div');
    wrap.id = 'tg-flow-confirm';
    wrap.className = 'tg-flow-confirm-backdrop';
    wrap.innerHTML = '<div class="tg-flow-confirm-modal" role="dialog" aria-modal="true">'
      + '<div class="tg-flow-confirm-title"></div>'
      + '<div class="tg-flow-confirm-text"></div>'
      + '<div class="tg-flow-confirm-actions">'
      + '<button type="button" class="tg-flow-confirm-secondary" data-confirm="cancel"></button>'
      + '<button type="button" class="tg-flow-confirm-danger" data-confirm="ok"></button>'
      + '</div></div>';
    wrap.querySelector('.tg-flow-confirm-title').textContent = title;
    wrap.querySelector('.tg-flow-confirm-text').textContent = text;
    wrap.querySelector('[data-confirm="cancel"]').textContent = cancelText;
    wrap.querySelector('[data-confirm="ok"]').textContent = dangerText;
    const close = (value) => {
      wrap.classList.remove('is-open');
      window.setTimeout(() => wrap.remove(), 160);
      resolve(value);
    };
    wrap.addEventListener('click', (event) => {
      if (event.target === wrap || event.target.closest('[data-confirm="cancel"]')) close(false);
      if (event.target.closest('[data-confirm="ok"]')) close(true);
    });
    document.addEventListener('keydown', function esc(event) {
      if (event.key === 'Escape') { document.removeEventListener('keydown', esc); close(false); }
    });
    document.body.appendChild(wrap);
    window.requestAnimationFrame(() => wrap.classList.add('is-open'));
  });
}
function ensureScenarioPanelStyles(doc) {
  if (!doc || document.getElementById('tg-flow-classic-panel-styles')) return;
  const collected = Array.from(doc.querySelectorAll('style'))
    .map((style) => style.textContent || '')
    .filter((code) => code.includes('.tg-scenario-'))
    .join('\n');
  if (!collected) return;
  const style = document.createElement('style');
  style.id = 'tg-flow-classic-panel-styles';
  style.textContent = collected;
  document.head.appendChild(style);
}
function executeDrawerScripts(host, doc, modalId) {
  if (!host || !doc) return;
  const scripts = modalId === 'block-modal'
    ? Array.from(doc.querySelectorAll('script')).filter((script) => {
      const code = script.textContent || '';
      return code.includes('const initialCards =') && code.includes('scenario-cards-box') && !code.includes('tg-scenario-canvas-shell');
    })
    : [];
  scripts.forEach((oldScript) => {
    const script = document.createElement('script');
    Array.from(oldScript.attributes).forEach((attr) => script.setAttribute(attr.name, attr.value));
    script.textContent = oldScript.textContent || '';
    host.appendChild(script);
  });
}
function closeDrawer() {
  if (!drawerEl) return;
  drawerEl.classList.remove('is-open', 'is-loading', 'is-saving');
  window.setTimeout(() => {
    if (!drawerEl.classList.contains('is-open') && !drawerEl.classList.contains('is-loading')) drawerEl.innerHTML = '';
  }, 260);
}
function bindDrawerChrome() {
  if (!drawerEl) return;
  drawerEl.querySelectorAll('.tg-scenario-modal-close,.tg-flow-drawer-close,.tg-scenario-form-actions a').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeDrawer();
    });
  });
  drawerEl.addEventListener('mousedown', (event) => {
    if (event.target === drawerEl) closeDrawer();
  }, {once: true});
}
async function refreshFlowFromServer() {
  if (!cfg.flowUrl || typeof window.tgScenarioFlowApplyData !== 'function') return;
  const response = await fetch(cfg.flowUrl + '&_=' + Date.now(), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
  const html = await response.text();
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const dataEl = doc.getElementById('scenario-flow-data');
  if (!dataEl) return;
  const next = JSON.parse(dataEl.textContent || '{}') || {};
  window.tgScenarioFlowApplyData(next);
}
function bindDrawerForm() {
  if (!drawerEl) return;
  const form = drawerEl.querySelector('form#scenario-message-form');
  if (!form) return;
  const drawerDiag = (stage, data = {}) => {
    window.__tgScenarioDrawerDiag = window.__tgScenarioDrawerDiag || [];
    const row = {time: new Date().toISOString(), stage, data};
    window.__tgScenarioDrawerDiag.push(row);
    if (window.__tgScenarioDrawerDiag.length > 40) window.__tgScenarioDrawerDiag.shift();
    try { console.info('[ScenarioDrawerDiag]', row); } catch(e) {}
    return row;
  };
  const showDrawerDiag = (message, data = {}) => {
    const alertBox = form.querySelector('#scenario-message-alert') || form.querySelector('.tg-scenario-alert');
    const text = String(message || '') + '\n\nДиагностика сохранения:\n' + JSON.stringify(data, null, 2).slice(0, 2200);
    if (alertBox) {
      alertBox.textContent = text;
      alertBox.classList.add('is-open');
      alertBox.style.whiteSpace = 'pre-wrap';
      try { alertBox.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch(e) {}
    } else {
      showFlowToast(String(message || 'Ошибка сохранения'), 'error');
    }
  };
  const showSaveProbe = (stage, data = {}) => {
    drawerDiag(stage, data);
  };
  const returnPage = form.querySelector('input[name="return_page"]');
  if (returnPage) returnPage.value = 'scenario_flow';
  form.dataset.flowDrawerAjax = '1';
  const saveBtn = form.querySelector('.tg-flow-panel-primary');
  if (saveBtn) {
    saveBtn.addEventListener('click', () => {
      const cardsInput = form.querySelector('input[name="scenario_cards_json"]');
      let parsed = null;
      try { parsed = JSON.parse(String(cardsInput && cardsInput.value ? cardsInput.value : '[]')); } catch(e) { parsed = {error: e.message}; }
      showSaveProbe('save-button-click-captured', {
        cardsJsonLength: String(cardsInput && cardsInput.value ? cardsInput.value : '').length,
        cardTypes: Array.isArray(parsed) ? parsed.map(c => c && c.type) : parsed,
        questionAnswers: Array.isArray(parsed) ? parsed.filter(c => c && c.type === 'question').map(c => (c.answers || []).map(a => a.text || a.title || '')) : []
      });
      window.setTimeout(() => {
        if (drawerEl && drawerEl.classList.contains('is-open') && !drawerEl.classList.contains('is-saving')) {
          drawerDiag('save-click-timeout-no-submit-or-fetch', {
            hint: 'Клик по кнопке пойман, но submit/fetch не стартовал. Значит, событие заблокировано в JS или HTML-валидацией формы.',
            activeElement: document.activeElement ? (document.activeElement.tagName + (document.activeElement.className ? '.' + String(document.activeElement.className).replace(/\s+/g,'.') : '')) : ''
          });
        }
      }, 900);
    }, true);
  }
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showSaveProbe('submit:start', {isSaving: drawerEl.classList.contains('is-saving')});
    if (drawerEl.classList.contains('is-saving')) return;
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
    if (typeof form.tgFlowPrepareSave === 'function') {
      const prepared = form.tgFlowPrepareSave();
      if (prepared === false || (prepared && prepared.ok === false)) {
        const message = prepared && prepared.message ? prepared.message : 'Не удалось подготовить карточки к сохранению.';
        showDrawerDiag(message, {stage:'prepare:false', prepared});
        return;
      }
    }
    showSaveProbe('submit:prepared', {cardsJsonLength: String(form.querySelector('input[name="scenario_cards_json"]')?.value || '').length});
    drawerEl.classList.add('is-saving');
    try {
      const cardsInput = form.querySelector('input[name="scenario_cards_json"]');
      if (cardsInput && !String(cardsInput.value || '').trim()) {
        showDrawerDiag('Карточки не подготовились к сохранению. Обновите окно блока и попробуйте ещё раз.', {stage:'empty_scenario_cards_json'});
        return;
      }
      const fd = new FormData(form);
      fd.set('return_page', 'scenario_flow');
      fd.set('tg_ajax', '1');
      if (!fd.get('scenario_id')) fd.set('scenario_id', String(cfg.scenarioId || ''));
      showSaveProbe('fetch:start', {url: cfg.flowUrl || window.location.href, cardsJsonLength: String(fd.get('scenario_cards_json') || '').length, cardTypes: (()=>{try{return JSON.parse(String(fd.get('scenario_cards_json')||'[]')).map(c=>c.type)}catch(e){return ['json-parse-error:'+e.message]}})()});
      const response = await fetch(cfg.flowUrl || window.location.href, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
      });
      let payload = null;
      const responseText = await response.text();
      try { payload = responseText ? JSON.parse(responseText) : null; } catch (e) { payload = null; }
      showSaveProbe('fetch:response', {ok: response.ok, status: response.status, json: !!payload, payloadOk: payload ? payload.ok : null, textHead: String(responseText || '').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0, 700)});
      if (!response.ok || (payload && payload.ok === false)) {
        const message = (payload && payload.error) ? payload.error : 'Не удалось сохранить блок. Проверьте размер и тип файла.';
        showDrawerDiag(message, {stage:'server_error', status: response.status, response: String(responseText || '').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,1200)});
        return;
      }
      await refreshFlowFromServer();
      closeDrawer();
    } catch (error) {
      console.error('Scenario drawer save error', error);
      const message = error && error.message ? error.message : 'Не удалось сохранить блок.';
      showDrawerDiag(message, {stage:'catch', stack: error && error.stack ? String(error.stack).slice(0,1200) : ''});
    } finally {
      if (drawerEl) drawerEl.classList.remove('is-saving');
    }
  });
}

function buildCleanDrawerShell(modal, modalId) {
  const panel = document.createElement('section');
  panel.className = 'tg-flow-clean-drawer';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  const titleText = (modal.querySelector('.tg-scenario-modal-title')?.textContent || (modalId === 'start-modal' ? 'Старт сценария' : 'Редактировать блок')).trim();
  const subtitleText = (modal.querySelector('.tg-scenario-modal-subtitle')?.textContent || '').trim();
  const form = modal.querySelector('form#scenario-message-form');
  if (form) {
    form.classList.add('tg-flow-clean-form');
    const oldActions = form.querySelector('.tg-scenario-form-actions');
    const bodyNodes = Array.from(form.childNodes).filter((node) => node !== oldActions);
    const head = document.createElement('div');
    head.className = 'tg-flow-clean-head';
    head.innerHTML = '<div><div class="tg-flow-clean-title"></div>' + (subtitleText ? '<div class="tg-flow-clean-subtitle"></div>' : '') + '</div><button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>';
    head.querySelector('.tg-flow-clean-title').textContent = titleText;
    const sub = head.querySelector('.tg-flow-clean-subtitle');
    if (sub) sub.textContent = subtitleText;
    const body = document.createElement('div');
    body.className = 'tg-flow-clean-body';
    bodyNodes.forEach((node) => body.appendChild(node));
    const footer = document.createElement('div');
    footer.className = 'tg-flow-clean-footer';
    if (oldActions) footer.appendChild(oldActions);
    form.innerHTML = '';
    form.appendChild(head);
    form.appendChild(body);
    form.appendChild(footer);
    panel.appendChild(form);
    return panel;
  }
  const head = document.createElement('div');
  head.className = 'tg-flow-clean-head';
  head.innerHTML = '<div><div class="tg-flow-clean-title"></div>' + (subtitleText ? '<div class="tg-flow-clean-subtitle"></div>' : '') + '</div><button type="button" class="tg-flow-drawer-close" aria-label="Закрыть">×</button>';
  head.querySelector('.tg-flow-clean-title').textContent = titleText;
  const sub = head.querySelector('.tg-flow-clean-subtitle');
  if (sub) sub.textContent = subtitleText;
  const body = document.createElement('div');
  body.className = 'tg-flow-clean-body';
  const content = modal.querySelector('.tg-scenario-start-panel') || modal;
  Array.from(content.childNodes).forEach((node) => body.appendChild(node));
  panel.appendChild(head);
  panel.appendChild(body);
  return panel;
}
window.tgScenarioFlowOpenDrawer = async function(url) {
  if (!drawerEl || !url) {
    window.location.href = url;
    return;
  }
  const requestId = ++drawerRequestId;
  drawerEl.className = 'tg-flow-block-drawer is-loading';
  drawerEl.innerHTML = '<div class="tg-flow-drawer-loader">Загружаю настройки блока…</div>';
  try {
    const response = await fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
    if (requestId !== drawerRequestId) return;
    const html = await response.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const panel = doc.querySelector('#tg-flow-panel');
    if (panel) {
      drawerEl.className = 'tg-flow-block-drawer';
      drawerEl.innerHTML = '';
      drawerEl.appendChild(panel);
      doc.querySelectorAll('style').forEach((oldStyle) => {
        const style = document.createElement('style');
        style.textContent = oldStyle.textContent || '';
        const styleId = oldStyle.getAttribute('data-flow-panel-style') || '';
        if (styleId) style.setAttribute('data-flow-panel-style', styleId);
        drawerEl.appendChild(style);
      });
      doc.querySelectorAll('script[data-flow-panel-script]').forEach((oldScript) => {
        const script = document.createElement('script');
        script.textContent = oldScript.textContent || '';
        drawerEl.appendChild(script);
      });
      bindDrawerChrome();
      bindDrawerForm();
      window.requestAnimationFrame(() => drawerEl.classList.add('is-open'));
      return;
    }
    // Fallback for old classic page, kept only as emergency reserve.
    ensureScenarioPanelStyles(doc);
    const modal = doc.querySelector('#block-modal,#start-modal');
    if (!modal) {
      window.location.href = url;
      return;
    }
    const cleanPanel = buildCleanDrawerShell(modal, modal.id);
    drawerEl.className = 'tg-flow-block-drawer';
    drawerEl.innerHTML = '';
    drawerEl.appendChild(cleanPanel);
    bindDrawerChrome();
    executeDrawerScripts(drawerEl, doc, modal.id);
    bindDrawerForm();
    window.requestAnimationFrame(() => drawerEl.classList.add('is-open'));
  } catch (error) {
    console.error('Scenario drawer load error', error);
    window.location.href = url;
  }
};

async function postAction(action, payload = {}) {
  const actionMap = {
    tg_scenario_quick_message_create: 'tg_scenario_quick_message_create',
    tg_scenario_quick_delay_create: 'tg_scenario_quick_delay_create',
    tg_scenario_link_save: 'tg_scenario_link_save',
    tg_scenario_blocks_positions_save: 'tg_scenario_blocks_positions_save',
    tg_scenario_duplicate_block: 'tg_scenario_block_duplicate',
    tg_scenario_block_delete: 'tg_scenario_block_delete'
  };
  const realAction = actionMap[action] || action;
  const fd = new FormData();
  fd.set('action', realAction);
  fd.set('return_page', 'scenario_flow');
  fd.set('scenario_id', String(cfg.scenarioId || ''));
  fd.set('tg_ajax', '1');
  if (cfg.csrfToken) fd.set('csrf_token', cfg.csrfToken);
  Object.entries(payload).forEach(([key, value]) => fd.set(key, String(value ?? '')));
  const response = await fetch('admin.php?tab=telegram_bots', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
  });
  const responseText = await response.text();
  let payloadJson = null;
  try { payloadJson = responseText ? JSON.parse(responseText) : null; } catch (e) { payloadJson = null; }
  if (!response.ok || (payloadJson && payloadJson.ok === false)) {
    const serverText = responseText ? responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 320) : '';
    const message = payloadJson && payloadJson.error ? payloadJson.error : (serverText || ('Не удалось выполнить действие «' + realAction + '».'));
    throw new Error(message);
  }
  if (!payloadJson) {
    throw new Error('Сервер не вернул JSON для действия «' + realAction + '».');
  }
  return payloadJson;
}

function collectNodePositions(nodes) {
  const positions = {};
  (Array.isArray(nodes) ? nodes : []).forEach((node) => {
    if (!node || !node.id || !node.position) return;
    const x = Number(node.position.x);
    const y = Number(node.position.y);
    positions[String(node.id)] = {
      x: Math.round(Number.isFinite(x) ? x : 0),
      y: Math.round(Number.isFinite(y) ? y : 0)
    };
  });
  return positions;
}

function savePositions(nodes) {
  return postAction('tg_scenario_blocks_positions_save', {
    positions_json: JSON.stringify(collectNodePositions(nodes))
  });
}

function sendPositionsBeacon(nodes) {
  if (!navigator.sendBeacon) return false;
  try {
    const fd = new FormData();
    fd.set('action', 'tg_scenario_blocks_positions_save');
    fd.set('return_page', 'scenario_flow');
    fd.set('scenario_id', String(cfg.scenarioId || ''));
    fd.set('tg_ajax', '1');
    if (cfg.csrfToken) fd.set('csrf_token', cfg.csrfToken);
    fd.set('positions_json', JSON.stringify(collectNodePositions(nodes)));
    return navigator.sendBeacon('admin.php?tab=telegram_bots', fd);
  } catch (error) {
    return false;
  }
}


function flowStripHtml(value) {
  const div = document.createElement('div');
  div.innerHTML = String(value || '');
  return (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
}
function flowMediaSrc(card) {
  const raw = String((card && (card.media_file_path || card.media_url || card.media)) || '').trim();
  if (!raw) return '';
  if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('/')) return raw;
  return '/' + raw.replace(/^\/+/, '');
}
function flowCardTitle(type) {
  return {text: 'Сообщение', image: 'Картинка', file: 'Файл', audio: 'Аудио', video: 'Видео', video_note: 'Видео-заметка', gallery: 'Галерея', question: 'Вопрос'}[type] || 'Сообщение';
}
function NodeShell({id, data, isStart}) {
  const edit = (event) => {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    if (data.editUrl && typeof window.tgScenarioFlowOpenDrawer === 'function') {
      window.tgScenarioFlowOpenDrawer(data.editUrl, {blockId: data.blockId, blockType: data.blockType});
      return;
    }
    if (data.editUrl) window.location.href = data.editUrl;
  };
  const stop = (event) => {
    if (!event) return;
    event.preventDefault();
    event.stopPropagation();
  };
  const testFromStep = (event) => {
    stop(event);
    showFlowToast('Тестирование с этого шага подключим следующим runner-патчем.');
  };
  const duplicateBlock = async (event) => {
    stop(event);
    if (isStart) return;
    try {
      await postAction('tg_scenario_duplicate_block', {block_id: data.blockId || id});
      await refreshFlowFromServer();
    } catch (e) {
      console.error('Scenario duplicate block error', e);
      showFlowToast(e && e.message ? e.message : 'Не удалось дублировать блок.', 'error');
    }
  };
  const deleteBlock = async (event) => {
    stop(event);
    if (isStart || !data.deleteAllowed) return;
    const confirmed = await showFlowConfirm({title: 'Удалить блок?', text: 'Вы уверены, что хотите удалить этот блок? Это действие нельзя отменить.', dangerText: 'Удалить', cancelText: 'Отмена'});
    if (!confirmed) return;
    try {
      await postAction('tg_scenario_block_delete', {block_id: data.blockId || id});
      await refreshFlowFromServer();
    } catch (e) {
      console.error('Scenario delete block error', e);
      showFlowToast(e && e.message ? e.message : 'Не удалось удалить блок.', 'error');
    }
  };
  const cards = Array.isArray(data.cards) ? data.cards : [];
  const isDelay = String(data.blockType || '') === 'delay';
  const renderButton = (button) => {
    const handleId = button.handleId || ('btn-' + Math.random().toString(16).slice(2));
    const remember = () => tgFlowRememberSourceHandle(id, handleId);
    return React.createElement('div', {className: 'tg-flow-preview-button', key: handleId},
      React.createElement('span', {className: 'tg-flow-preview-button-text'}, button.text || 'Кнопка'),
      React.createElement(Handle, {
        id: handleId,
        type: 'source',
        position: Position.Right,
        className: 'tg-flow-button-handle',
        isConnectable: true,
        onMouseDownCapture: remember,
        onPointerDownCapture: remember,
        onTouchStartCapture: remember
      })
    );
  };
  const renderCard = (card, cardIndex) => {
    const type = card && card.type ? card.type : 'text';
    const text = flowStripHtml(card && card.text ? card.text : card && card.textPreview ? card.textPreview : '');
    const mediaSrc = flowMediaSrc(card);
    const buttons = [];
    (Array.isArray(card && card.buttons) ? card.buttons : []).forEach((row) => {
      (Array.isArray(row) ? row : []).forEach((button) => buttons.push(button));
    });
    const answers = Array.isArray(card && card.answers) ? card.answers : [];
    const galleryItems = Array.isArray(card && card.gallery_items) ? card.gallery_items : [];
    const renderAnswer = (answer, index) => {
      const handleId = answer.handleId || ('q-a' + cardIndex + '-' + index);
      const remember = () => tgFlowRememberSourceHandle(id, handleId);
      return React.createElement('div', {className: 'tg-flow-preview-button', key: handleId},
        React.createElement('span', {className: 'tg-flow-preview-button-text'}, answer.text || 'Ответ'),
        React.createElement(Handle, {
          id: handleId,
          type: 'source',
          position: Position.Right,
          className: 'tg-flow-question-handle',
          isConnectable: true,
          onMouseDownCapture: remember,
          onPointerDownCapture: remember,
          onTouchStartCapture: remember
        })
      );
    };
    const renderGalleryThumb = (item, index) => {
      const raw = String((item && (item.media_file_path || item.media_url || item.url)) || '').trim();
      const src = raw ? (/^(https?:)?\/\//i.test(raw) || raw.startsWith('/') ? raw : '/' + raw.replace(/^\/+/, '')) : '';
      return src ? React.createElement('img', {key: 'g' + index, src, alt: ''}) : React.createElement('span', {key: 'g' + index}, '—');
    };
    return React.createElement('div', {className: 'tg-flow-preview-card is-' + type, key: cardIndex},
      React.createElement('div', {className: 'tg-flow-preview-card-label'}, flowCardTitle(type)),
      type === 'image' && mediaSrc
        ? React.createElement('img', {className: 'tg-flow-preview-image', src: mediaSrc, alt: 'Картинка'})
        : null,
      type === 'gallery' && galleryItems.length
        ? React.createElement('div', {className: 'tg-flow-preview-gallery'}, galleryItems.slice(0, 5).map(renderGalleryThumb).concat(galleryItems.length > 5 ? [React.createElement('span', {className: 'tg-flow-preview-gallery-more', key: 'more'}, '+' + (galleryItems.length - 5))] : []))
        : null,
      type !== 'text' && type !== 'image' && type !== 'gallery' && type !== 'question'
        ? React.createElement('div', {className: 'tg-flow-preview-media'}, card.media_file_name || mediaSrc || flowCardTitle(type))
        : null,
      text ? React.createElement('div', {className: 'tg-flow-preview-text'}, text) : null,
      type === 'question' && answers.length ? React.createElement('div', {className: 'tg-flow-preview-buttons'}, answers.map(renderAnswer)) : null,
      type === 'question' ? React.createElement('div', {className: 'tg-flow-preview-button is-muted'},
        React.createElement('span', {className: 'tg-flow-preview-button-text'}, 'Подписчик не ответил'),
        React.createElement(Handle, {
          id: card.no_answer_handle_id || ('q-noanswer-c' + cardIndex),
          type: 'source',
          position: Position.Right,
          className: 'tg-flow-question-handle',
          isConnectable: true,
          onMouseDownCapture: () => tgFlowRememberSourceHandle(id, card.no_answer_handle_id || ('q-noanswer-c' + cardIndex)),
          onPointerDownCapture: () => tgFlowRememberSourceHandle(id, card.no_answer_handle_id || ('q-noanswer-c' + cardIndex)),
          onTouchStartCapture: () => tgFlowRememberSourceHandle(id, card.no_answer_handle_id || ('q-noanswer-c' + cardIndex))
        })
      ) : null,
      buttons.length ? React.createElement('div', {className: 'tg-flow-preview-buttons'}, buttons.map(renderButton)) : null
    );
  };
  const nodeClassName = 'tg-flow-node' + (isStart ? ' is-start' : '') + (isDelay ? ' is-delay' : '') + (isDelay && data.missingNext ? ' is-missing-next' : '');
  const delayPreview = React.createElement('div', {className: 'tg-flow-node-card tg-flow-delay-preview'},
    React.createElement('div', {className: 'tg-flow-delay-preview-col'},
      React.createElement('span', {className: 'tg-flow-delay-preview-label'}, 'Отправить'),
      React.createElement('span', {className: 'tg-flow-delay-preview-main'}, data.delaySendLabel || data.preview || 'Через 1 день')
    ),
    React.createElement('div', {className: 'tg-flow-delay-preview-col is-right'},
      React.createElement('span', {className: 'tg-flow-delay-preview-label'}, 'Время отправки'),
      React.createElement('span', {className: 'tg-flow-delay-preview-main'}, data.delayTimeLabel || 'Любое')
    ),
    React.createElement('div', {className: 'tg-flow-delay-preview-days'}, data.delayWeekdaysTitle || 'Пн, Вт, Ср, Чт, Пт, Сб, Вс')
  );
  return React.createElement('div', {className: nodeClassName},
    !isStart && React.createElement(Handle, {id: 'in', type: 'target', position: Position.Left, className: 'tg-flow-in-handle', isConnectable: true}),
    React.createElement('div', {className: 'tg-flow-node-head'},
      React.createElement('div', {className: 'tg-flow-node-title'}, data.title || (isStart ? 'Старт' : 'Сообщение')),
      React.createElement('div', {className: 'tg-flow-node-actions', onMouseDown: (event) => event.stopPropagation(), onClick: (event) => event.stopPropagation()},
        React.createElement('button', {type: 'button', className: 'tg-flow-node-action', title: 'Тестировать с этого шага', onClick: testFromStep}, '▶'),
        React.createElement('button', {type: 'button', className: 'tg-flow-node-action', title: 'Редактировать', onClick: edit}, isStart ? '⚙' : '✎'),
        !isStart && React.createElement('button', {type: 'button', className: 'tg-flow-node-action', title: 'Дублировать', onClick: duplicateBlock}, '⧉'),
        !isStart && React.createElement('button', {type: 'button', className: 'tg-flow-node-action is-danger', title: 'Удалить', onClick: deleteBlock}, '×')
      )
    ),
    React.createElement('div', {className: 'tg-flow-node-body'},
      isStart
        ? React.createElement('div', {className: 'tg-flow-node-card'}, data.preview || 'По кнопке «Начать»')
        : (isDelay
          ? delayPreview
          : (cards.length
            ? React.createElement('div', {className: 'tg-flow-message-cards'}, cards.map(renderCard))
            : React.createElement('div', {className: 'tg-flow-node-card is-empty'}, 'Добавить сообщение'))),
      React.createElement('div', {className: 'tg-flow-next-row'},
        React.createElement('span', {className: 'tg-flow-node-muted'}, isStart ? 'Начало сценария' : 'Следующий шаг'),
        React.createElement(Handle, {id: 'out', type: 'source', position: Position.Right, className: 'tg-flow-main-out-handle', isConnectable: true, onMouseDownCapture: () => tgFlowRememberSourceHandle(id, 'out'), onPointerDownCapture: () => tgFlowRememberSourceHandle(id, 'out'), onTouchStartCapture: () => tgFlowRememberSourceHandle(id, 'out')})
      )
    )
  );
}
function StartNode(props) { return React.createElement(NodeShell, {...props, isStart: true}); }
function MessageNode(props) { return React.createElement(NodeShell, {...props, isStart: false}); }
function DelayNode(props) { return React.createElement(NodeShell, {...props, isStart: false}); }

function AddMenu({menu, onClose, onCreateMessage, onCreateDelay}) {
  if (!menu) return null;
  const item = (type, glyph, name, disabled, onClick) => React.createElement('button', {
    type: 'button',
    className: 'tg-flow-add-item tg-flow-add-item--' + type,
    disabled: !!disabled,
    onClick: disabled ? undefined : onClick
  },
    React.createElement('span', {className: 'tg-flow-add-icon tg-flow-add-icon--' + type},
      React.createElement('span', {className: 'tg-flow-add-glyph'}, glyph)
    ),
    React.createElement('span', {className: 'tg-flow-add-text'},
      React.createElement('span', {className: 'tg-flow-add-name'}, name)
    )
  );
  return React.createElement('div', {
    className: 'tg-flow-add-menu',
    style: {left: menu.x, top: menu.y},
    onMouseDown: (event) => event.stopPropagation(),
    onClick: (event) => event.stopPropagation()
  },
    item('message', '≣', 'Сообщение', false, onCreateMessage),
    item('actions', '⚡', 'Действия', true),
    item('delay', '◴', 'Задержка', false, onCreateDelay),
    item('condition', '⇄', 'Условие', true),
    item('schedule', '□', 'Расписание', true),
    item('random', '✦', 'Случайный выбор', true),
    item('formula', 'ƒ', 'Формула', true)
  );
}


function ScenarioSmoothEdge(props) {
  const radius = 13;
  const startX = props.sourcePosition === Position.Right ? props.sourceX + radius : props.sourceX;
  const endX = props.targetPosition === Position.Left ? props.targetX - radius : props.targetX;
  const [edgePath] = getBezierPath({
    sourceX: startX,
    sourceY: props.sourceY,
    sourcePosition: props.sourcePosition,
    targetX: endX,
    targetY: props.targetY,
    targetPosition: props.targetPosition,
    curvature: 0.42
  });
  return React.createElement(BaseEdge, {
    id: props.id,
    path: edgePath,
    markerEnd: props.markerEnd,
    style: Object.assign({stroke: '#6f7378', strokeWidth: 1.9}, props.style || {})
  });
}

function ScenarioFlow() {
  const nodeTypes = useMemo(() => ({startNode: StartNode, messageNode: MessageNode, delayNode: DelayNode}), []);
  const edgeTypes = useMemo(() => ({scenarioSmooth: ScenarioSmoothEdge}), []);
  const blockLimit = Number(cfg.blockLimit || 550);
  const initialNodes = Array.isArray(cfg.nodes) ? cfg.nodes : [];
  const initialEdges = Array.isArray(cfg.edges) ? cfg.edges : [];
  const [nodes, setNodes, onNodesChange] = useNodesState(initialNodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(initialEdges.map((edge) => ({
    ...edge,
    type: 'scenarioSmooth',
    markerEnd: edge.markerEnd || {type: MarkerType.ArrowClosed, width: 10, height: 10},
    style: edge.style || {strokeWidth: 1.8, stroke: '#6f7378'}
  })));
  const [menu, setMenu] = useState(null);
  const [isRightPanning, setIsRightPanning] = useState(false);
  const connectingRef = useRef(null);
  const connectedRef = useRef(false);
  const menuOpenedAtRef = useRef(0);
  const flow = useReactFlow();
  const saveTimer = useRef(null);
  const latestNodesRef = useRef(initialNodes);
  const positionsDirtyRef = useRef(false);

  React.useEffect(() => {
    const addBtn = document.getElementById('tg-flow-add-block-btn');
    const openAddMenu = () => {
      if (nodes.length >= blockLimit) {
        showFlowToast('В сценарии уже ' + nodes.length + ' из ' + blockLimit + ' блоков. Новые блоки пока нельзя добавить.', 'error');
        return;
      }
      const bounds = rootEl ? rootEl.getBoundingClientRect() : {width: window.innerWidth, height: window.innerHeight, left: 0, top: 0};
      const menuWidth = Math.min(360, Math.max(300, bounds.width - 32));
      const menuHeight = Math.min(520, Math.max(420, bounds.height - 32));
      const x = Math.max(16, Math.min(Math.round((bounds.width - menuWidth) / 2), Math.max(16, bounds.width - menuWidth - 16)));
      const y = Math.max(16, Math.min(Math.round((bounds.height - menuHeight) / 2), Math.max(16, bounds.height - menuHeight - 16)));
      const screenX = bounds.left + x + Math.round(menuWidth / 2);
      const screenY = bounds.top + y + Math.round(menuHeight / 2);
      setMenu({
        source: '',
        x,
        y,
        screenX,
        screenY
      });
    };
    if (addBtn) addBtn.addEventListener('click', openAddMenu);
    return () => { if (addBtn) addBtn.removeEventListener('click', openAddMenu); };
  }, [nodes.length, blockLimit]);

  React.useEffect(() => {
    latestNodesRef.current = nodes;
  }, [nodes]);

  React.useEffect(() => {
    const outgoing = new Set((Array.isArray(edges) ? edges : []).filter((edge) => String(edge.sourceHandle || 'out') === 'out').map((edge) => String(edge.source || '')));
    setNodes((currentNodes) => currentNodes.map((node) => {
      if (!node || !node.data || String(node.data.blockType || '') !== 'delay') return node;
      const missingNext = !outgoing.has(String(node.id));
      if (!!node.data.missingNext === missingNext) return node;
      return {...node, data: {...node.data, missingNext}};
    }));
  }, [edges, setNodes]);

  const flushPositions = useCallback((nextNodes, silent = true) => {
    const snapshot = Array.isArray(nextNodes) ? nextNodes : latestNodesRef.current;
    latestNodesRef.current = snapshot;
    window.clearTimeout(saveTimer.current);
    positionsDirtyRef.current = false;
    return savePositions(snapshot).catch((error) => {
      positionsDirtyRef.current = true;
      if (!silent) showFlowToast(error && error.message ? error.message : 'Не удалось сохранить расположение блоков.', 'error');
    });
  }, []);

  const scheduleSave = useCallback((nextNodes) => {
    const snapshot = Array.isArray(nextNodes) ? nextNodes : latestNodesRef.current;
    latestNodesRef.current = snapshot;
    positionsDirtyRef.current = true;
    window.clearTimeout(saveTimer.current);
    saveTimer.current = window.setTimeout(() => flushPositions(snapshot, true), 220);
  }, [flushPositions]);

  React.useEffect(() => {
    window.tgScenarioFlowSaveNow = () => flushPositions(latestNodesRef.current, false);
    const saveBtn = document.getElementById('tg-flow-save-btn');
    const handler = () => flushPositions(latestNodesRef.current, false);
    if (saveBtn) saveBtn.addEventListener('click', handler);
    return () => {
      if (saveBtn) saveBtn.removeEventListener('click', handler);
      if (window.tgScenarioFlowSaveNow) delete window.tgScenarioFlowSaveNow;
    };
  }, [nodes, flushPositions]);

  React.useEffect(() => {
    const flushBeforeLeave = () => {
      if (!positionsDirtyRef.current) return;
      window.clearTimeout(saveTimer.current);
      sendPositionsBeacon(latestNodesRef.current);
    };
    window.addEventListener('beforeunload', flushBeforeLeave);
    window.addEventListener('pagehide', flushBeforeLeave);
    return () => {
      window.removeEventListener('beforeunload', flushBeforeLeave);
      window.removeEventListener('pagehide', flushBeforeLeave);
    };
  }, []);

  React.useEffect(() => {
    window.tgScenarioFlowApplyData = (nextCfg) => {
      if (!nextCfg || !Array.isArray(nextCfg.nodes)) return;
      const nextNodes = nextCfg.nodes;
      const nextEdges = Array.isArray(nextCfg.edges) ? nextCfg.edges.map((edge) => ({
        ...edge,
        type: 'scenarioSmooth',
        markerEnd: edge.markerEnd || {type: MarkerType.ArrowClosed, width: 10, height: 10},
        style: edge.style || {strokeWidth: 1.8, stroke: '#6f7378'}
      })) : [];
      setNodes(nextNodes);
      setEdges(nextEdges);
      const status = document.getElementById('tg-flow-boot-status');
      if (status) status.textContent = 'PHP: ' + nextNodes.length + ' блоков / ' + nextEdges.length + ' связей · React: обновлено';
    };
    return () => {
      if (window.tgScenarioFlowApplyData) delete window.tgScenarioFlowApplyData;
    };
  }, [setNodes, setEdges]);

  useEffect(() => {
    const expectedNodes = Array.isArray(cfg.nodes) ? cfg.nodes.length : 0;
    const status = document.getElementById('tg-flow-boot-status');
    const check = () => {
      const drawnNodes = rootEl ? rootEl.querySelectorAll('.react-flow__node').length : 0;
      const drawnEdges = rootEl ? rootEl.querySelectorAll('.react-flow__edge').length : 0;
      if (status) status.textContent = 'PHP: ' + expectedNodes + ' блоков / ' + (Array.isArray(cfg.edges) ? cfg.edges.length : 0) + ' связей · React: DOM ' + drawnNodes + ' узлов / ' + drawnEdges + ' стрелок';
      if (expectedNodes > 0 && drawnNodes === 0 && errorEl) {
        errorEl.style.display = 'block';
        const p = errorEl.querySelector('p');
        if (p) p.textContent = 'React Flow запустился, но в DOM не появилось ни одного узла. Версия: 3.5.105. Вероятная причина — конфликт ESM-зависимостей React/React Flow или CSS холста.';
      }
    };
    const t1 = window.setTimeout(check, 350);
    const t2 = window.setTimeout(check, 1200);
    return () => { window.clearTimeout(t1); window.clearTimeout(t2); };
  }, []);

  React.useEffect(() => {
    const preventContextMenu = (event) => {
      if (rootEl && rootEl.contains(event.target)) event.preventDefault();
    };
    const startRightPanNative = (event) => {
      if (!rootEl || !rootEl.contains(event.target)) return;
      if (event.button !== 2) return;
      if (event.target && event.target.closest && event.target.closest('.react-flow__node, .react-flow__handle, .tg-flow-add-menu')) return;
      rootEl.classList.add('is-right-panning');
      setIsRightPanning(true);
    };
    const stopRightPan = () => {
      if (rootEl) rootEl.classList.remove('is-right-panning');
      setIsRightPanning(false);
    };
    document.addEventListener('contextmenu', preventContextMenu);
    document.addEventListener('mousedown', startRightPanNative, true);
    window.addEventListener('mouseup', stopRightPan);
    window.addEventListener('blur', stopRightPan);
    return () => {
      document.removeEventListener('contextmenu', preventContextMenu);
      document.removeEventListener('mousedown', startRightPanNative, true);
      window.removeEventListener('mouseup', stopRightPan);
      window.removeEventListener('blur', stopRightPan);
    };
  }, []);

  const onNodeDragStop = useCallback((event, node) => {
    setNodes((current) => {
      const next = current.map((item) => item.id === node.id ? {...item, position: {...node.position}} : item);
      latestNodesRef.current = next;
      scheduleSave(next);
      return next;
    });
  }, [setNodes, scheduleSave]);

  const isSourceHandleAllowed = useCallback((handleId) => {
    const value = String(handleId || '');
    return value === 'out' || value.indexOf('btn-') === 0 || /^q-a\d+-\d+$/.test(value) || /^q-noanswer-c\d+$/.test(value);
  }, []);

  const onConnectStart = useCallback((event, params) => {
    setMenu(null);
    connectedRef.current = false;
    const sourceNodeId = String((params && params.nodeId) || '');
    const handleId = tgFlowResolveSourceHandle(sourceNodeId, (params && params.handleId) || '');
    connectingRef.current = params && params.handleType === 'source' && isSourceHandleAllowed(handleId)
      ? {source: sourceNodeId, sourceHandle: handleId}
      : null;
  }, [isSourceHandleAllowed]);

  const onConnect = useCallback(async (params) => {
    connectedRef.current = true;
    setMenu(null);
    if (!params.source || !params.target || params.source === params.target) return;
    const sourceHandle = tgFlowResolveSourceHandle(params.source, params.sourceHandle || '');
    if (!isSourceHandleAllowed(sourceHandle) || params.targetHandle !== 'in') return;
    const targetNode = nodes.find((node) => node.id === String(params.target));
    if (targetNode && targetNode.type === 'startNode') return;
    setEdges((current) => addEdge({
      ...params,
      sourceHandle,
      id: 'tmp-' + Date.now(),
      type: 'scenarioSmooth',
      markerEnd: {type: MarkerType.ArrowClosed, width: 10, height: 10},
      style: {strokeWidth: 1.8, stroke: '#737373'}
    }, current));
    try {
      await postAction('tg_scenario_link_save', {from_block_id: params.source, to_block_id: params.target, source_handle: sourceHandle});
      await refreshFlowFromServer();
    } catch (e) {
      console.error('Scenario link save error', e);
      showFlowToast(e && e.message ? e.message : 'Не удалось сохранить связь.', 'error');
      await refreshFlowFromServer();
    }
  }, [nodes, setEdges]);

  const onConnectEnd = useCallback((event, connectionState) => {
    const fallbackSource = connectingRef.current && connectingRef.current.source;
    const fallbackSourceHandle = connectingRef.current && connectingRef.current.sourceHandle;
    const stateSource = connectionState && connectionState.fromNode && connectionState.fromNode.id ? String(connectionState.fromNode.id) : '';
    const stateHandle = connectionState && connectionState.fromHandle && connectionState.fromHandle.id ? String(connectionState.fromHandle.id) : '';
    const source = stateSource || fallbackSource;
    const sourceHandle = tgFlowResolveSourceHandle(source, stateHandle || fallbackSourceHandle || 'out');
    const wasConnected = connectedRef.current || (connectionState && connectionState.isValid);
    connectingRef.current = null;
    connectedRef.current = false;
    if (!source || wasConnected) return;

    const clientX = event && event.changedTouches && event.changedTouches[0] ? event.changedTouches[0].clientX : event.clientX;
    const clientY = event && event.changedTouches && event.changedTouches[0] ? event.changedTouches[0].clientY : event.clientY;
    if (typeof clientX !== 'number' || typeof clientY !== 'number') return;

    const dropTarget = document.elementFromPoint(clientX, clientY);
    if (dropTarget && dropTarget.closest && dropTarget.closest('.react-flow__handle.target, .react-flow__handle-left')) return;

    const bounds = rootEl.getBoundingClientRect();
    menuOpenedAtRef.current = Date.now();
    setMenu({
      source,
      sourceHandle,
      x: Math.max(16, Math.min(clientX - bounds.left, bounds.width - 300)),
      y: Math.max(76, Math.min(clientY - bounds.top, bounds.height - 360)),
      screenX: clientX,
      screenY: clientY
    });
  }, []);

  const createBlockFromMenu = useCallback(async (kind) => {
    if (!menu) return;
    if (nodes.length >= blockLimit) {
      showFlowToast('В сценарии уже ' + nodes.length + ' из ' + blockLimit + ' блоков. Новые блоки пока нельзя добавить.', 'error');
      setMenu(null);
      return;
    }
    const flowPos = flow.screenToFlowPosition({x: menu.screenX, y: menu.screenY});
    const placeX = Math.round(flowPos.x);
    const placeY = Math.round(menu.source ? (flowPos.y - 86) : flowPos.y);
    const action = kind === 'delay' ? 'tg_scenario_quick_delay_create' : 'tg_scenario_quick_message_create';
    setMenu(null);
    try {
      await postAction(action, {
        from_block_id: menu.source || '',
        source_handle: menu.sourceHandle || 'out',
        position_x: placeX,
        position_y: placeY
      });
      await refreshFlowFromServer();
    } catch (e) {
      console.error('Scenario quick block create error', e);
      showFlowToast(e && e.message ? e.message : 'Не удалось создать блок.', 'error');
    }
  }, [menu, flow, nodes.length, blockLimit]);

  const createMessageFromMenu = useCallback(() => createBlockFromMenu('message'), [createBlockFromMenu]);
  const createDelayFromMenu = useCallback(() => createBlockFromMenu('delay'), [createBlockFromMenu]);

  const isValidConnection = useCallback((connection) => {
    if (!connection || !connection.source || !connection.target) return false;
    if (String(connection.source) === String(connection.target)) return false;
    if (!isSourceHandleAllowed(connection.sourceHandle) || connection.targetHandle !== 'in') return false;
    const targetNode = nodes.find((node) => node.id === String(connection.target));
    if (targetNode && targetNode.type === 'startNode') return false;
    return true;
  }, [nodes, isSourceHandleAllowed]);

  const onPaneMouseDown = useCallback((event) => {
    if (event.button !== 0 && event.button !== 2) return;
    if (event.button === 2) event.preventDefault();
    if (rootEl) rootEl.classList.add('is-right-panning');
    setIsRightPanning(true);
    setMenu(null);
  }, []);

  const onPaneMouseUp = useCallback((event) => {
    if (event.button !== 0 && event.button !== 2) return;
    if (event.button === 2) event.preventDefault();
    if (rootEl) rootEl.classList.remove('is-right-panning');
    setIsRightPanning(false);
  }, []);

  const onPaneContextMenu = useCallback((event) => {
    event.preventDefault();
  }, []);

  return React.createElement(React.Fragment, null,
    React.createElement(ReactFlow, {
      className: isRightPanning ? 'is-right-panning' : '',
      nodes,
      edges,
      nodeTypes,
      edgeTypes,
      onNodesChange,
      onEdgesChange,
      onNodeDragStop,
      onConnect,
      onConnectStart,
      onConnectEnd,
      isValidConnection,
      onPaneMouseDown,
      onPaneMouseUp,
      onPaneContextMenu,
      panOnDrag: [0, 1, 2],
      panOnScroll: false,
      zoomOnScroll: true,
      zoomOnPinch: true,
      zoomOnDoubleClick: false,
      preventScrolling: true,
      fitView: false,
      defaultViewport: {x: 0, y: 0, zoom: 1},
      defaultEdgeOptions: {
        type: 'scenarioSmooth',
        markerEnd: {type: MarkerType.ArrowClosed, width: 10, height: 10},
        style: {strokeWidth: 1.9, stroke: '#6f7378'}
      },
      connectionLineStyle: {strokeWidth: 1.9, stroke: '#6f7378'},
      proOptions: {hideAttribution: true},
      onPaneClick: () => { if (Date.now() - menuOpenedAtRef.current > 250) setMenu(null); }
    },
      React.createElement(Background, {gap: 22, size: 1}),
      React.createElement(Controls, {showInteractive: false})
    ),
    React.createElement('div', {className: 'tg-flow-block-counter'}, nodes.length + '/' + blockLimit + ' блоков использовано'),
    React.createElement(AddMenu, {menu, onClose: () => setMenu(null), onCreateMessage: createMessageFromMenu, onCreateDelay: createDelayFromMenu})
  );
}

try {
  if (!rootEl) throw new Error('Root not found');
  createRoot(rootEl).render(
    React.createElement(ReactFlowProvider, null, React.createElement(ScenarioFlow))
  );
  window.__tgScenarioFlowBoot = window.__tgScenarioFlowBoot || {};
  window.__tgScenarioFlowBoot.started = true;
  window.__tgScenarioFlowBoot.rendered = true;
  const status = document.getElementById('tg-flow-boot-status');
  if (status) status.textContent = 'PHP: ' + (Array.isArray(cfg.nodes) ? cfg.nodes.length : 0) + ' блоков / ' + (Array.isArray(cfg.edges) ? cfg.edges.length : 0) + ' связей · React: монтируется…';
  if ((!Array.isArray(cfg.nodes) || cfg.nodes.length === 0) && errorEl) {
    errorEl.style.display = 'block';
    const p = errorEl.querySelector('p');
    if (p) p.textContent = 'React Flow запустился, но PHP передал 0 блоков. Значит надо смотреть запрос блоков сценария. Версия: 3.5.105.';
  }
} catch (error) {
  console.error(error);
  window.__tgScenarioFlowBoot = window.__tgScenarioFlowBoot || {};
  window.__tgScenarioFlowBoot.started = false;
  window.__tgScenarioFlowBoot.error = error && (error.message || String(error));
  const status = document.getElementById('tg-flow-boot-status');
  if (status) status.textContent = 'React: ошибка запуска';
  if (errorEl) {
    errorEl.style.display = 'block';
    const p = errorEl.querySelector('p');
    if (p) p.textContent = 'Ошибка запуска React Flow: ' + (error && error.message ? error.message : 'неизвестная ошибка') + '. Версия: 3.5.105.';
  }
}
