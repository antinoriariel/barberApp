/**
 * track.js — Mide cómo se usa la app para el mapa de calor del superusuario (/su/uso; ver Models\UiUsage).
 *
 * Qué registra (nunca textos, valores de formularios ni teclas):
 * - Cada click/tap: pantalla, capa ('page' = contenido con scroll, 'fixed' = barras y diálogos), dispositivo
 *   (touch/mouse/pen), componente (el data-track más cercano; catálogo en config/tracking.php), ítem
 *   (data-track-item), destino (data-track-to, el href o la acción del formulario), posición y la caja del componente.
 *   Sin data-track: "auto:<id|tab|clase>" si era algo interactivo, "fondo" si no hacía nada (click muerto).
 * - En PC, dónde está el mouse: una muestra cada 250 ms mientras se mueve (se corta a los 3 s quieto), sumada en
 *   celdas de 25 milésimos x 20 px antes de enviarse. Fuera de la columna de la app no cuenta.
 * - Cuántas veces se abre cada pantalla.
 *
 * Rendimiento: un solo listener delegado (pasivo) por evento, nada de trabajo en pointermove salvo guardar la posición,
 * el muestreo del mouse solo corre mientras se mueve y todo viaja en lotes con navigator.sendBeacon (cada 30 s, a los
 * 50 clicks o al salir de la página), que el navegador manda en segundo plano sin frenar la navegación.
 *
 * Unidades (iguales a las del servidor): x en milésimos del ancho de .app; y en px desde el borde superior de la
 * pantalla escalados a REF px de ancho (capa 'page') o en milésimos del alto de la ventana (capa 'fixed').
 * Respeta Global Privacy Control: si el navegador lo pide, no se mide nada.
 */
const REF = 400;                 // ancho de referencia de la capa 'page' (UiUsage::REF_WIDTH)
const CELL_X = 25;               // celda del mouse en x (milésimos) (UiUsage::MOVE_CELL_X)
const CELL_Y = 20;               // celda del mouse en y (UiUsage::MOVE_CELL_Y)
const SAMPLE_MS = 250;           // cada cuánto se toma una muestra del mouse
const IDLE_MS = 3000;            // mouse quieto: se deja de muestrear
const FLUSH_MS = 30000;          // envío periódico
const MAX_CLICKS = 50;           // envío anticipado
const FIXED = '.topbar, .tabbar, dialog, #summary'; // lo que no se mueve con el scroll
const INTERACTIVE = 'a, button, summary, label, input, select, textarea, [role="button"], [role="tab"]';

let app, known;
let clicks = [];
const moves = new Map(); // "pantalla|capa|gx|gy" → muestras
const views = new Map(); // pantalla → visitas
let lastType = '';       // pointerType del último pointerdown (Safari no lo trae en el click)
let lastEl = null, lastAt = 0;
let mouse = null, movedAt = 0, sampler = 0;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const round = (n) => Math.max(0, Math.round(n));

/** Pantalla actual, con la misma regla que navigation.js (hash desconocido = Inicio). */
function screen() {
  const name = location.hash.slice(1);
  return known.includes(name) ? name : 'inicio';
}

/**
 * Pasa un punto de la ventana (clientX/Y) a las unidades del mapa según la capa. `frame` son los rectángulos de
 * .app y de la pantalla actual, medidos una vez por evento.
 */
function toMap(cx, cy, layer, frame) {
  const x = ((cx - frame.app.left) / frame.app.width) * 1000;
  const y = layer === 'fixed'
    ? (cy / window.innerHeight) * 1000
    : ((cy - frame.view.top) * REF) / frame.app.width;
  return [round(x), round(y)];
}

/** Rectángulos de .app y de la pantalla visible (o null si la app no está en la página). */
function frameNow() {
  const view = document.getElementById('view-' + screen());
  if (!app || !view) return null;
  return { app: app.getBoundingClientRect(), view: view.getBoundingClientRect() };
}

/** Código "auto:…" para un elemento interactivo sin data-track (para detectar qué falta codificar). */
function autoCode(el) {
  const base = el.id || el.dataset.tab || el.getAttribute('name') || el.classList[0] || el.tagName;
  return 'auto:' + String(base).toLowerCase().replace(/[^a-z0-9._-]/g, '').slice(0, 50);
}

/** A dónde lleva el click: data-track-to, el href de un enlace o la acción del formulario que se envía. */
function destination(el, target) {
  if (el?.dataset.trackTo) return el.dataset.trackTo;
  const link = (el ?? target).closest('a[href]');
  if (link) return link.getAttribute('href');
  const button = (el ?? target).closest('button');
  if (button?.form && button.type === 'submit') return button.form.getAttribute('action') ?? '';
  return '';
}

/** Registra un click/tap. Los de teclado (sin coordenadas) y los del lienzo de los juegos no cuentan. */
function onClick(event) {
  const target = event.target instanceof Element ? event.target : event.target?.parentElement;
  if (!target || (event.detail === 0 && event.clientX === 0 && event.clientY === 0)) return;
  if (target.closest('[data-track-ignore]')) return;
  const frame = frameNow();
  if (!frame) return;

  let el = target.closest('[data-track]');
  let comp;
  if (el) {
    comp = el.dataset.track;
  } else {
    el = target.closest(INTERACTIVE);
    comp = el ? autoCode(el) : 'fondo';
  }
  // Un click en un <label> dispara otro sobre su <input>: es el mismo gesto, se cuenta una vez.
  if (el && el === lastEl && event.timeStamp - lastAt < 100) return;
  lastEl = el;
  lastAt = event.timeStamp;

  const layer = target.closest(FIXED) ? 'fixed' : 'page';
  const [x, y] = toMap(event.clientX, event.clientY, layer, frame);
  let box = null;
  if (el) {
    const r = el.getBoundingClientRect();
    const [bx, by] = toMap(r.left, r.top, layer, frame);
    const [ex, ey] = toMap(r.right, r.bottom, layer, frame);
    box = [bx, by, ex - bx, ey - by];
  }
  clicks.push({
    s: screen(), l: layer, d: event.pointerType || lastType || 'mouse', k: comp,
    i: el?.dataset.trackItem ?? '', t: destination(el, target), x, y, b: box, w: window.innerWidth,
  });
  if (clicks.length >= MAX_CLICKS) flush();
}

/** Toma una muestra de dónde está el mouse; se apaga sola con el mouse quieto o la pestaña oculta. */
function sample() {
  if (document.hidden || performance.now() - movedAt > IDLE_MS || !mouse) {
    clearInterval(sampler);
    sampler = 0;
    return;
  }
  if (mouse.target.closest?.('[data-track-ignore]')) return;
  const frame = frameNow();
  if (!frame) return;
  const layer = mouse.target.closest?.(FIXED) ? 'fixed' : 'page';
  const [x, y] = toMap(mouse.x, mouse.y, layer, frame);
  if (x > 1000 || mouse.x < frame.app.left) return; // fuera de la columna de la app
  const key = `${screen()}|${layer}|${Math.floor(x / CELL_X)}|${Math.floor(y / CELL_Y)}`;
  moves.set(key, (moves.get(key) ?? 0) + 1);
}

/** Guarda la última posición del mouse (nada más: el trabajo lo hace sample()). */
function onMove(event) {
  if (event.pointerType !== 'mouse') return;
  mouse = { x: event.clientX, y: event.clientY, target: event.target };
  movedAt = performance.now();
  if (!sampler) sampler = setInterval(sample, SAMPLE_MS);
}

/** Cuenta una visita a la pantalla actual. */
function countView() {
  const name = screen();
  views.set(name, (views.get(name) ?? 0) + 1);
}

/** Manda lo acumulado (si hay algo) y vacía los buffers. sendBeacon no espera respuesta ni frena la página. */
function flush() {
  if (!clicks.length && !moves.size && !views.size) return;
  const data = {
    c: clicks,
    m: [...moves].map(([key, n]) => {
      const [s, l, gx, gy] = key.split('|');
      return [s, l, Number(gx), Number(gy), n];
    }),
    v: [...views],
  };
  clicks = [];
  moves.clear();
  views.clear();
  const body = new URLSearchParams({ _csrf: csrf(), d: JSON.stringify(data) });
  try {
    if (navigator.sendBeacon?.('/api/uso', body)) return;
  } catch { /* sin beacon: se intenta con fetch */ }
  fetch('/api/uso', { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
}

/** Arranca la medición en la app (pages/shell.php). No hace nada fuera de la app o con Global Privacy Control. */
export function initTracking() {
  app = document.querySelector('.app');
  if (!app || navigator.globalPrivacyControl === true) return;
  known = [...document.querySelectorAll('.view')].map((v) => v.id.replace('view-', ''));

  document.addEventListener('pointerdown', (e) => { lastType = e.pointerType; }, { capture: true, passive: true });
  document.addEventListener('click', onClick, { capture: true, passive: true });
  if (matchMedia('(pointer: fine)').matches) {
    document.addEventListener('pointermove', onMove, { passive: true });
  }
  window.addEventListener('hashchange', countView);
  countView();

  setInterval(flush, FLUSH_MS);
  document.addEventListener('visibilitychange', () => { if (document.hidden) flush(); });
  window.addEventListener('pagehide', flush);
}
