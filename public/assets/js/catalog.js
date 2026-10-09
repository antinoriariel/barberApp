/**
 * catalog.js — Pestañas "Servicios | Productos" de la pantalla Servicios (screens/servicios.php).
 *
 * Patrón de pestañas accesible: cada botón [role=tab] muestra su panel (aria-controls) y oculta
 * el resto; las flechas izquierda/derecha mueven el foco entre pestañas.
 */

/** Activa la pestaña `tab`: la marca como seleccionada, muestra su panel y oculta los demás. */
function select(tabs, tab) {
  tabs.forEach((t) => {
    const on = t === tab;
    t.setAttribute('aria-selected', String(on));
    t.tabIndex = on ? 0 : -1;
    document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
  });
}

/** Conecta las pestañas del catálogo (si la pantalla está en la página). */
export function initCatalog() {
  const tabs = [...document.querySelectorAll('.catalog-tab')];
  if (!tabs.length) return;
  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => select(tabs, tab));
    tab.addEventListener('keydown', (ev) => {
      if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
      const next = tabs[(i + (ev.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
      select(tabs, next);
      next.focus();
    });
  });
}
