/**
 * login.js — Comportamiento del formulario de login (pages/login.php).
 * - Formatea el DNI con puntos mientras se escribe (40123456 → 40.123.456).
 * - En el alta (register.php, input con data-min/data-max) avisa si el DNI está fuera del rango aceptado
 *   y no deja enviar el formulario; el servidor lo valida igual.
 * - Pide al service worker que borre las páginas cacheadas con datos de sesión.
 * (Sin CSP inline: el formulario se envía de forma nativa; esto solo mejora la entrada.)
 */
import { registerServiceWorker } from './pwa.js';

const input = document.getElementById('dni');

/** Deja solo dígitos (máx. 8) y los agrupa de a tres desde la derecha. */
function format(raw) {
  const digits = raw.replace(/\D/g, '').slice(0, 8);
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/**
 * true si el DNI escrito está entre data-min y data-max (incluidos). Sin esos atributos (login) no hay
 * rango que controlar y siempre da true.
 */
function inRange(el) {
  if (!el.dataset.min || !el.dataset.max) return true;
  const value = Number(el.value.replace(/\D/g, ''));
  return value >= Number(el.dataset.min) && value <= Number(el.dataset.max);
}

/** Muestra u oculta el error del rango debajo del input y marca el campo como inválido. Devuelve si es válido. */
function checkRange(el) {
  const ok = inRange(el);
  const msg = document.getElementById('dni-error');
  if (msg) {
    msg.textContent = ok ? '' : el.dataset.error;
    msg.hidden = ok;
  }
  if (ok) el.removeAttribute('aria-invalid');
  else el.setAttribute('aria-invalid', 'true');
  return ok;
}

if (input) {
  input.addEventListener('input', () => {
    input.value = format(input.value);
    input.removeAttribute('aria-invalid');
    const msg = document.getElementById('dni-error');
    if (msg) msg.hidden = true;
  });

  // Se controla al salir del campo (si ya escribió algo) y siempre al enviar.
  input.addEventListener('blur', () => {
    if (input.value !== '') checkRange(input);
  });
  input.form?.addEventListener('submit', (ev) => {
    if (!checkRange(input)) {
      ev.preventDefault();
      input.focus();
    }
  });
}

registerServiceWorker();

// Estar en el login significa que no hay sesión: se descartan las páginas con datos de usuario
// que el service worker pudo haber guardado (p. ej. tras cerrar sesión).
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.ready.then((reg) => reg.active && reg.active.postMessage('purge-pages')).catch(() => {});
}
