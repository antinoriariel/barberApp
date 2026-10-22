/**
 * countdown.js — Cuentas regresivas de la "Hora muerta" (panel y app de clientes).
 *
 * Cada elemento con data-countdown (fin en ms epoch) y data-now (hora del servidor en ms al renderizar) muestra el
 * tiempo que falta como "m:ss" o "h:mm:ss". Se corrige con la diferencia entre el reloj del servidor y el del
 * teléfono, así un reloj mal puesto no cambia el tiempo. Al llegar a cero: data-reload recarga la página (panel) y
 * data-hide="<selector>" oculta el ancestro que coincide (aviso del cliente).
 */

/** Formatea milisegundos restantes como "m:ss" o, desde una hora, "h:mm:ss" (mismo formato que countdown_text en PHP). */
function format(ms) {
  const total = Math.max(0, Math.ceil(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = String(total % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

/** Arranca todas las cuentas regresivas de la página (un solo intervalo de 1 s para todas). */
export function initCountdowns() {
  const clocks = [...document.querySelectorAll('[data-countdown]')].map((el) => ({
    el,
    end: Number(el.dataset.countdown),
    skew: Number(el.dataset.now || Date.now()) - Date.now(),
    done: false,
  }));
  if (!clocks.length) return;

  const tick = () => {
    for (const c of clocks) {
      if (c.done) continue;
      const left = c.end - (Date.now() + c.skew);
      c.el.textContent = format(left);
      if (left > 0) continue;
      c.done = true;
      if ('reload' in c.el.dataset) setTimeout(() => location.reload(), 1500);
      if (c.el.dataset.hide) c.el.closest(c.el.dataset.hide)?.setAttribute('hidden', '');
    }
  };
  tick();
  setInterval(tick, 1000);
}
