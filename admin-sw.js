const ASR_ADMIN_CACHE = 'asr-admin-static-v1';
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
