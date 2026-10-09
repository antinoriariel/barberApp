/** pwa.js — Registra el service worker (instalación y uso sin conexión). */

/**
 * Registra /sw.js cuando la página terminó de cargar (para no competir con el render inicial).
 * Si el navegador no soporta service workers o el registro falla, la app sigue funcionando en línea.
 */
export function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
