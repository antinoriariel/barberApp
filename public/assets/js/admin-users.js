/**
 * admin-users.js — Comportamiento de /admin/usuarios (todo es mejora progresiva: sin JS la página funciona igual).
 *
 * - En cada formulario con [data-role-form], los campos [data-barber-only] (ficha del equipo y secciones del
 *   panel) solo se muestran si el rol elegido es "barbero"; con otro rol el servidor los ignora de todas formas.
 * - El buscador [data-user-search] filtra las tarjetas por nombre, DNI (con o sin puntos) o email.
 * - Los botones con [data-confirm] (dar de baja) piden confirmación antes de enviar.
 */

/** Muestra u oculta los campos de barbero de un formulario según su selector de rol. */
function syncRole(form) {
  const select = form.querySelector('[data-role-select]');
  if (!select) return;
  const isBarber = select.value === 'barbero';
  form.querySelectorAll('[data-barber-only]').forEach((el) => {
    el.hidden = !isBarber;
  });
}

document.querySelectorAll('[data-role-form]').forEach((form) => {
  syncRole(form);
  form.querySelector('[data-role-select]')?.addEventListener('change', () => syncRole(form));
});

/** Filtra las tarjetas de cuentas: coincide si el texto (o el texto sin puntos, para el DNI) aparece en la tarjeta. */
const search = document.querySelector('[data-user-search]');
const cards = [...document.querySelectorAll('[data-user-text]')];
const none = document.querySelector('[data-user-none]');
search?.addEventListener('input', () => {
  const q = search.value.trim().toLowerCase();
  const digits = q.replace(/\./g, '');
  let shown = 0;
  cards.forEach((card) => {
    const text = card.dataset.userText;
    const match = q === '' || text.includes(q) || (digits !== '' && text.includes(digits));
    card.hidden = !match;
    if (match) shown += 1;
  });
  if (none) none.hidden = shown > 0;
});

/** Confirmación antes de una acción que deja a alguien sin acceso. */
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-confirm]');
  if (button && !window.confirm(button.dataset.confirm)) {
    event.preventDefault();
  }
});
