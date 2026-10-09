/**
 * toast.js — Aviso breve que se oculta solo.
 *
 * Uso:
 *   showToast('Mensaje');
 *   o en el HTML: <button data-toast="Mensaje"> (lo conecta initToast()).
 */
const DURATION_MS = 2600;

let timer;

/** Muestra `msg` en #toast durante unos segundos. */
export function showToast(msg) {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(timer);
  timer = setTimeout(() => el.classList.remove('show'), DURATION_MS);
}

/** Conecta cualquier elemento con data-toast="..." para que dispare un aviso al tocarlo. */
export function initToast() {
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-toast]');
    if (trigger) showToast(trigger.dataset.toast);
  });
}
