const ASR_ADMIN_CACHE = 'asr-admin-static-v3';
const ASR_STATIC_ASSETS = [
  '/manifest.webmanifest',
  '/pwa/offline.html',
  '/pwa/icons/icon-180.png',
  '/pwa/icons/icon-192.png',
  '/pwa/icons/icon-512.png',
  '/pwa/icons/icon-maskable-192.png',
  '/pwa/icons/icon-maskable-512.png',
  '/chart_background_analiz_1100.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(ASR_ADMIN_CACHE)
      .then((cache) => cache.addAll(ASR_STATIC_ASSETS))
      .catch(() => null)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== ASR_ADMIN_CACHE).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

function isStaticRequest(request) {
  const url = new URL(request.url);
  if (request.method !== 'GET') return false;
  if (url.origin !== self.location.origin) return false;

  return (
    url.pathname.startsWith('/pwa/') ||
    url.pathname === '/manifest.webmanifest' ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.jpg') ||
    url.pathname.endsWith('.jpeg') ||
    url.pathname.endsWith('.webp') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js')
  );
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Админские действия, сохранение тестов, письма и Bitrix24 всегда идут в сеть.
  if (request.method !== 'GET') return;

  // Саму админку не кешируем как данные: сначала сеть, офлайн-экран только как запасной вариант.
  if (url.origin === self.location.origin && url.pathname.endsWith('/admin.php')) {
    event.respondWith(
      fetch(request).catch(() => caches.match('/pwa/offline.html'))
    );
    return;
  }

  // Стартовую страницу теста, save.php и прогресс теста не превращаем в офлайн-приложение.
  if (url.origin === self.location.origin && (
      url.pathname.endsWith('/index.html') ||
      url.pathname.endsWith('/save.php') ||
      url.pathname.endsWith('/save_progress.php') ||
      url.pathname.endsWith('/resume_test.php')
  )) {
    return;
  }

  if (isStaticRequest(request)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const networkFetch = fetch(request)
          .then((response) => {
            if (response && response.status === 200) {
              const copy = response.clone();
              caches.open(ASR_ADMIN_CACHE).then((cache) => cache.put(request, copy));
            }
            return response;
          })
          .catch(() => cached);
        return cached || networkFetch;
      })
    );
  }
});


self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification && event.notification.data ? event.notification.data : {};
  const targetUrl = data.url || '/admin.php?tab=telegram_bots&page=messages&dialog_view=new';
  const absoluteUrl = new URL(targetUrl, self.location.origin).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        try {
          const clientUrl = new URL(client.url);
          if (clientUrl.origin === self.location.origin && clientUrl.pathname.endsWith('/admin.php')) {
            if ('navigate' in client) client.navigate(absoluteUrl);
            if ('focus' in client) return client.focus();
            return client;
          }
        } catch (e) {}
      }
      if (clients.openWindow) return clients.openWindow(absoluteUrl);
      return null;
    })
  );
});

self.addEventListener('push', (event) => {
  const dialogsUrl = '/admin.php?tab=telegram_bots&page=messages&dialog_view=new';
  const fallbackTitle = 'Новый диалог';
  const fallbackOptions = {
    body: 'В админке есть новое сообщение. Откройте диалоги, чтобы ответить.',
    tag: 'asr-dialogs-server-push',
    renotify: true,
    icon: '/pwa/icons/icon-192.png',
    badge: '/pwa/icons/icon-192.png',
    data: { url: dialogsUrl }
  };

  event.waitUntil((async () => {
    try {
      const response = await fetch('/admin.php?tab=telegram_bots&tg_ajax=dialog_badges&_=' + Date.now(), {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (response && response.ok) {
        const payload = await response.json();
        const count = parseInt((payload && payload.attention_total) || 0, 10) || 0;
        const title = count > 1 ? 'Новые диалоги' : fallbackTitle;
        const body = count > 0
          ? (count === 1 ? 'Есть 1 диалог без ответа.' : 'Диалогов без ответа: ' + (count > 99 ? '99+' : count) + '.')
          : fallbackOptions.body;
        await self.registration.showNotification(title, Object.assign({}, fallbackOptions, { body }));
        return;
      }
    } catch (e) {}
    await self.registration.showNotification(fallbackTitle, fallbackOptions);
  })());
});
