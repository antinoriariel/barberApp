/**
 * admin-tabs.js — Barra de secciones del panel (.admin-tabs, components/admin-header.php) en pantallas angostas.
 *
 * En el celular la barra no entra entera y se desliza de costado: al cargar, la pestaña de la página actual se lleva
 * al centro (si no, en Barberos o Usuarios quedaba tapada a la derecha) y, mientras se desliza, las clases
 * more-left / more-right marcan de qué lado hay pestañas ocultas para que el CSS funda ese borde (admin-skin.css).
 * En escritorio la barra entra completa: no hay scroll y no se agrega ninguna clase.
 */

/** Actualiza more-left / more-right según cuánto se puede deslizar todavía hacia cada lado (1px de tolerancia). */
function mark(nav) {
  const max = nav.scrollWidth - nav.clientWidth;
  nav.classList.toggle('more-left', nav.scrollLeft > 1);
  nav.classList.toggle('more-right', nav.scrollLeft < max - 1);
}

/** Centra la pestaña activa sin animación y conecta el marcado de bordes al scroll y al cambio de tamaño. */
export function initAdminTabs() {
  const nav = document.querySelector('.admin-tabs');
  if (!nav) return;
  const current = nav.querySelector('[aria-current="page"]');
  if (current && nav.scrollWidth > nav.clientWidth) {
    // Posición de la pestaña dentro de la barra (con rects, así no depende de qué elemento sea su offsetParent).
    const left = current.getBoundingClientRect().left - nav.getBoundingClientRect().left + nav.scrollLeft;
    nav.scrollLeft = left - (nav.clientWidth - current.offsetWidth) / 2;
  }
  mark(nav);
  nav.addEventListener('scroll', () => mark(nav), { passive: true });
  window.addEventListener('resize', () => mark(nav));
}
