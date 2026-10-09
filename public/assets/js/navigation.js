/**
 * navigation.js — Navegación entre pantallas por hash (#inicio, #servicios, …).
 *
 * Cada pantalla es una <section class="view" id="view-NOMBRE" data-skel="TIPO">.
 * Los datos ya vienen renderizados por PHP, así que la pantalla se muestra al instante
 * (esperas en 0: antes había una carga simulada que hacía sentir lenta la app). El
 * skeleton (#skel-TIPO) queda listo para cuando haya una API real: subir estas esperas
 * o reemplazar el setTimeout por el fetch, y se verá mientras carga.
 */
const FIRST_LOAD_MS = 0; // primera carga de la app
const NAV_LOAD_MS = 0;   // cambios de pestaña

const views = [...document.querySelectorAll('.view')];
const skeletons = [...document.querySelectorAll('.skeleton')];
const tabs = [...document.querySelectorAll('[data-tab]')];
const known = views.map((v) => v.id.replace('view-', ''));

let loadTimer;
let firstLoad = true;

/** Marca la pestaña activa con aria-current y se la quita a las demás. */
function markTab(name) {
  tabs.forEach((t) => {
    if (t.dataset.tab === name) t.setAttribute('aria-current', 'page');
    else t.removeAttribute('aria-current');
  });
}

/**
 * Muestra la pantalla `name` (o Inicio si no existe). Si llega otra navegación
 * mientras carga, cancela la anterior.
 */
export function show(name) {
  if (!known.includes(name)) name = 'inicio';
  const view = document.getElementById('view-' + name);

  clearTimeout(loadTimer);
  views.forEach((v) => (v.hidden = true));
  skeletons.forEach((s) => (s.hidden = s.id !== 'skel-' + view.dataset.skel));

  markTab(name);
  document.title = view.dataset.title + ' · Barbería';
  window.scrollTo(0, 0);

  const reveal = () => {
    skeletons.forEach((s) => (s.hidden = true));
    view.hidden = false;
  };
  const wait = firstLoad ? FIRST_LOAD_MS : NAV_LOAD_MS;
  firstLoad = false;
  if (wait === 0) reveal(); // sin espera: se muestra en el mismo cuadro, sin parpadeo del skeleton
  else loadTimer = setTimeout(reveal, wait);
}

/** Navega a una pantalla cambiando el hash. */
export function go(name) {
  location.hash = '#' + name;
}

/** Muestra la pantalla del hash actual y escucha cambios posteriores. */
export function initNavigation() {
  window.addEventListener('hashchange', () => show(location.hash.slice(1)));
  show(location.hash.slice(1));
}
