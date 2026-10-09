/**
 * queue.js — Mantiene al día la tarjeta del número de la fila en Inicio (components/queue-ticket.php).
 *
 * Mientras la app está a la vista consulta GET /api/fila cada POLL_MS y actualiza el lugar en la fila. Cuando llaman
 * al cliente, la tarjeta cambia a "¡Es tu turno!" y el celular vibra. Si el número ya no está activo (lo atendieron,
 * lo marcaron ausente o dejó la fila), recarga para que la tarjeta desaparezca.
 */
import { get } from './api.js';

const POLL_MS = 15000;

let card, timer = null, wasCalled = false;

/** Texto del estado según el lugar en la fila. */
function stateHtml(t) {
  if (t.status === 'called') return '¡Es tu turno! Acercate al sillón.';
  if (t.ahead === 0) return 'Sos el próximo.';
  return `Estás después del <b>${Number(t.before)}</b>.`;
}

/** Pinta los datos recibidos en la tarjeta. */
function render(t) {
  card.querySelector('[data-q-number]').textContent = String(t.number);
  card.querySelector('[data-q-ahead]').textContent = String(t.ahead);
  card.querySelector('[data-q-serving]').textContent = t.serving === null ? '—' : String(t.serving);
  card.querySelector('[data-q-state]').innerHTML = stateHtml(t); // solo números del servidor (Number/plantilla fija)
  const called = t.status === 'called';
  card.classList.toggle('is-called', called);
  if (called && !wasCalled && navigator.vibrate) navigator.vibrate([200, 100, 200]);
  wasCalled = called;
}

/** Consulta el estado y programa la próxima consulta (solo con la pestaña visible, para no gastar batería). */
async function poll() {
  clearTimeout(timer);
  if (document.visibilityState === 'visible') {
    const { ok, body } = await get('/api/fila');
    if (ok && body.ticket === null) return location.reload(); // el número ya no está activo
    if (ok) render(body.ticket);
  }
  timer = setTimeout(poll, POLL_MS);
}

/** Arranca el seguimiento si el cliente tiene número hoy. */
export function initQueue() {
  card = document.getElementById('queue-card');
  if (!card) return;
  wasCalled = card.classList.contains('is-called');
  timer = setTimeout(poll, POLL_MS);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
}
