/**
 * login.js — Comportamiento del formulario de login (pages/login.php).
 * - Formatea el DNI con puntos mientras se escribe (40123456 → 40.123.456). Si se escriben letras (el usuario
 *   "superuser") se deja el texto tal cual.
 * - Pide al service worker que borre las páginas cacheadas con datos de sesión.
 * (Sin CSP inline: el formulario se envía de forma nativa; esto solo mejora la entrada.)
 */
import { registerServiceWorker } from './pwa.js';

const input = document.getElementById('dni');

/** Deja solo dígitos (máx. 8) y los agrupa de a tres desde la derecha; con letras no toca nada. */
function format(raw) {
  if (/[a-z]/i.test(raw)) return raw;
  const digits = raw.replace(/\D/g, '').slice(0, 8);
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

if (input) {
  input.addEventListener('input', () => {
    input.value = format(input.value);
    input.removeAttribute('aria-invalid');
  });

}

registerServiceWorker();

// Estar en el login significa que no hay sesión: se descartan las páginas con datos de usuario
// que el service worker pudo haber guardado (p. ej. tras cerrar sesión).
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.ready.then((reg) => reg.active && reg.active.postMessage('purge-pages')).catch(() => {});
}
