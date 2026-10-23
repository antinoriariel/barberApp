/**
 * sw.js — Service worker de la PWA (vive en public/ para tener alcance sobre todo el sitio).
 *
 * Estrategias:
 * - Páginas (navegación): red primero; sin conexión, la última copia cacheada.
 * - Resto (CSS, JS, íconos, fuentes): caché primero, y se guarda lo que se descarga.
 *
 * Las páginas PHP NO se precachean: dependen de la sesión, y precachear una
 * redirección al login rompería la navegación. Se cachean solo cuando se visitan.
 *
 * Privacidad: las páginas cacheadas contienen datos del usuario. Al cerrar sesión la
 * página de login (login.js) le envía a este worker el mensaje 'purge-pages' y se
 * borran las páginas guardadas (ver PAGE_PATHS).
 *
 * La API (/api/*, /turnos*) nunca se cachea: las GET de /api responden JSON dependiente de la sesión
 * y se excluyen explícitamente abajo; las POST ya las ignora el worker.
 *
 * IMPORTANTE: al cambiar CSS o JS subir el número de CACHE para que los
 * usuarios reciban la versión nueva. Si se agregan archivos a config/app.php
 * ('assets.styles') o módulos JS, agregarlos también a SHELL.
 */
const CACHE = 'navaja-v117';
// Rutas de páginas HTML que se guardan al visitarlas (dependen de la sesión).
const PAGE_PATHS = ['/', '/login', '/registro'];
const SHELL = [
  '/assets/css/base.css',
  '/assets/css/layout.css',
  '/img/maquinilla-de-afeitar.png',
  '/img/login.webp',
  '/img/avatar-barbero.webp',
  '/assets/css/components/button.css',
  '/assets/css/components/ticket.css',
  '/assets/css/components/loyalty.css',
  '/assets/css/components/rail.css',
  '/assets/css/components/menu.css',
  '/assets/css/components/services.css',
  '/assets/css/components/profile.css',
  '/assets/css/components/crew.css',
  '/assets/css/components/booking.css',
  '/assets/css/components/tabbar.css',
  '/assets/css/components/toast.css',
  '/assets/css/components/skeleton.css',
  '/assets/css/components/arcade.css',
  '/assets/css/components/queue.css',
  '/assets/css/components/dead-hour.css',
  '/assets/vendor/driver/driver.css',
  '/assets/css/components/tour.css',
  '/assets/css/pages/login.css',
  '/assets/css/pages/admin.css',
  '/assets/css/pages/su.css',
  '/assets/css/pages/admin-skin.css',
  '/assets/css/pages/error.css',
  '/assets/css/a11y.css',
  '/assets/js/main.js',
  '/assets/js/navigation.js',
  '/assets/js/toast.js',
  '/assets/js/api.js',
  '/assets/js/booking.js',
  '/assets/js/arcade.js',
  '/assets/js/games/engine.js',
  '/assets/js/games/flappy.js',
  '/assets/js/games/runner.js',
  '/assets/js/games/pacman.js',
  '/assets/js/pwa.js',
  '/assets/js/theme.js',
  '/assets/js/catalog.js',
  '/assets/js/scanner.js',
  '/assets/js/queue.js',
  '/assets/js/tour.js',
  '/assets/js/countdown.js',
  '/assets/vendor/driver/driver.esm.js',
  '/assets/js/theme-init.js',
  '/assets/js/login.js',
  '/assets/js/money-input.js',
  '/assets/js/admin.js',
  '/assets/js/collapse.js',
  '/assets/js/admin-users.js',
  '/manifest.webmanifest',
  '/icon.png?s=192',
  '/icon.png?s=512',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

// Borra cachés de versiones anteriores y toma control de las pestañas abiertas.
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Mensaje 'purge-pages': elimina de la caché las páginas HTML (datos de sesión), no los estáticos.
self.addEventListener('message', (e) => {
  if (e.data !== 'purge-pages') return;
  e.waitUntil(
    caches.open(CACHE).then((c) =>
      c.keys().then((reqs) =>
        Promise.all(reqs.filter((r) => PAGE_PATHS.includes(new URL(r.url).pathname)).map((r) => c.delete(r)))
      )
    )
  );
});

// Intercepta cada GET: páginas con red primero, estáticos con caché primero.
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  if (new URL(req.url).pathname.startsWith('/api/')) return; // disponibilidad: siempre en vivo

  // Páginas: red primero. Las respuestas redirigidas (p. ej. "/" → login) no se
  // cachean porque el navegador no permite servirlas como navegación.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok && !res.redirected) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() =>
          caches.match(req).then(
            (hit) => hit || new Response('No pudimos conectar con la barbería. Revisá tu conexión y volvé a intentar en un momento.', { // sin internet o servidor caído
              status: 503,
              headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            })
          )
        )
    );
    return;
  }

  // Recursos estáticos: caché primero.
  e.respondWith(
    caches.match(req).then(
      (hit) =>
        hit ||
        fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
    )
  );
});
