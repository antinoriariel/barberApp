/**
 * collapse.js — Tarjetas minimizables del panel (.collapsible, p. ej. components/admin-chart.php).
 *
 * El botón .card-toggle de cada tarjeta alterna la clase is-collapsed (el CSS oculta .admin-card-body) y
 * actualiza aria-expanded / etiqueta. El estado se recuerda por tarjeta en localStorage ('collapse:<data-collapse-key>'),
 * envuelto en try/catch: si el almacenamiento no está disponible, la tarjeta arranca abierta y todo sigue andando.
 */

const PREFIX = 'collapse:';

/** Lee el estado guardado; null si no hay o si localStorage no está disponible. */
function load(key) {
  try { return localStorage.getItem(PREFIX + key); } catch { return null; }
}

/** Guarda el estado ('1' minimizada); si falla el almacenamiento, sólo se pierde el recuerdo. */
function save(key, collapsed) {
  try {
    if (collapsed) localStorage.setItem(PREFIX + key, '1');
    else localStorage.removeItem(PREFIX + key);
  } catch { /* sin almacenamiento */ }
}

/** Aplica el estado a la tarjeta: clase, aria-expanded y texto del botón. */
function apply(card, button, collapsed) {
  card.classList.toggle('is-collapsed', collapsed);
  button.setAttribute('aria-expanded', String(!collapsed));
  const label = collapsed ? 'Mostrar gráfico' : 'Minimizar gráfico';
  button.setAttribute('aria-label', label);
  button.title = collapsed ? 'Mostrar' : 'Minimizar';
}

/** Conecta cada tarjeta .collapsible de la página con su botón y restaura el estado guardado. */
export function initCollapse() {
  document.querySelectorAll('.collapsible').forEach((card) => {
    const button = card.querySelector('.card-toggle');
    if (!button) return;
    const key = card.dataset.collapseKey;
    if (key && load(key) === '1') apply(card, button, true);
    button.addEventListener('click', () => {
      const collapsed = !card.classList.contains('is-collapsed');
      apply(card, button, collapsed);
      if (key) save(key, collapsed);
    });
  });
}
