// Credit-Flow Service Worker v2 — PWABuilder optimized
const CACHE = 'creditflow-v2';
const STATIC = [
  '/credit/',
  '/credit/index.php',
  '/credit/login.php',
  '/credit/css/styles.css',
  '/credit/print.css',
  '/credit/manifest.json',
  '/credit/icons/icon-192.png',
  '/credit/icons/icon-512.png',
  '/credit/icons/icon-512-maskable.png',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c =>
      Promise.allSettled(STATIC.map(url => c.add(url).catch(() => {})))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Never cache API calls
  if (url.pathname.includes('/api/')) {
    e.respondWith(
      fetch(e.request).catch(() => new Response(
        JSON.stringify({ error: 'Sin conexión. Intenta más tarde.' }),
        { status: 503, headers: { 'Content-Type': 'application/json' } }
      ))
    );
    return;
  }

  // Network first for PHP pages
  if (url.pathname.endsWith('.php') || url.pathname === '/credit/') {
    e.respondWith(
      fetch(e.request)
        .catch(() => caches.match(e.request)
          .then(r => r || caches.match('/credit/login.php')))
    );
    return;
  }

  // Cache first for static assets (CSS, icons, fonts)
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      }).catch(() => cached || new Response('', { status: 408 }));
    })
  );
});

// Handle push notifications (future)
self.addEventListener('push', e => {
  if (!e.data) return;
  const data = e.data.json();
  e.waitUntil(
    self.registration.showNotification(data.title || 'Credit-Flow', {
      body: data.body || '',
      icon: '/credit/icons/icon-192.png',
      badge: '/credit/icons/icon-96.png',
      vibrate: [200, 100, 200],
    })
  );
});
