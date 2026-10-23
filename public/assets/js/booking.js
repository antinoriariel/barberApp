/**
 * booking.js — Flujo de reserva: resumen en vivo, disponibilidad real, confirmación y turnos existentes.
 *
 * Lee los radios de components/booking-steps.php (name = barber | day | time) y las casillas de servicios
 * (name = service: se pueden combinar varios en un turno),
 * actualiza components/booking-summary.php y habla con la API de turnos (api.js):
 *   - cambia servicio, barbero o día → GET /api/disponibilidad y se redibujan los horarios;
 *   - Confirmar → POST /turnos (o /turnos/reprogramar si se está reprogramando);
 *   - botones del ticket (components/ticket.php) → reprogramar / cancelar.
 */
import { go } from './navigation.js';
import { showToast } from './toast.js';
import { get, post } from './api.js';

const line = document.getElementById('summary-line');
const sub = document.getElementById('summary-sub');
const confirmBtn = document.getElementById('confirm');
const stopBtn = document.getElementById('stop-reschedule');
const slotsBox = document.querySelector('.slots');

/** Id del turno que se está reprogramando (null = reserva nueva). */
let rescheduleId = null;
/** Número de la última consulta de disponibilidad: descarta respuestas viejas que llegan tarde. */
let slotsRequest = 0;

/** Opción seleccionada de un grupo de radios, o null. */
const checked = (name) => document.querySelector(`input[name="${name}"]:checked`);

/** 12000 → "$12.000" */
const money = (n) => '$' + Number(n).toLocaleString('es-AR');

/** % de la "Hora muerta" en curso (data-discount de booking-steps; 0 si no hay o si ya terminó: clase sale-over). */
const discount = () => document.documentElement.classList.contains('sale-over')
  ? 0
  : Number(document.querySelector('.reserve-steps')?.dataset.discount || 0);

/**
 * Nodos del precio para el resumen: el total tal cual o, con hora muerta, el de lista tachado y el descontado
 * (redondeado al peso sobre el total, la misma cuenta que hace el servidor al guardar el turno).
 */
function priceNodes(total) {
  const pct = discount();
  if (!pct) return [money(total)];
  const old = document.createElement('s');
  old.className = 'price-old';
  old.textContent = money(total);
  const now = document.createElement('b');
  now.className = 'price-new';
  now.textContent = money(Math.round(total * (100 - pct) / 100));
  return [old, ' ', now];
}

/**
 * Servicios elegidos (casillas: puede haber varios) resumidos como si fueran una sola opción:
 * value "corte,cejas" (lo que esperan la API y /turnos), label "Corte clásico + Perfilado de cejas" y precio sumado.
 * null si no hay ninguno.
 */
function chosenServices() {
  const list = [...document.querySelectorAll('input[name="service"]:checked')];
  if (!list.length) return null;
  return {
    value: list.map((i) => i.value).join(','),
    dataset: {
      label: list.map((i) => i.dataset.label).join(' + '),
      price: list.reduce((sum, i) => sum + Number(i.dataset.price), 0),
    },
  };
}

/**
 * Mantiene la elección de servicios entre 1 y el máximo (data-max del contenedor): si se destilda el último
 * se vuelve a tildar, y si se pasa del máximo se destilda el recién marcado y se avisa.
 */
function limitServices(input) {
  const all = [...document.querySelectorAll('input[name="service"]:checked')];
  const max = Number(input.closest('[data-max]')?.dataset.max || 4);
  if (!all.length) input.checked = true;
  else if (all.length > max) {
    input.checked = false;
    showToast(`Podés combinar hasta ${max} servicios por turno.`);
  }
}

/** Actualiza el resumen y habilita "Confirmar" solo si hay horario elegido. */
function renderSummary() {
  const s = chosenServices(), b = checked('barber'), d = checked('day'), t = checked('time');
  stopBtn.hidden = rescheduleId === null;
  confirmBtn.textContent = rescheduleId === null ? 'Confirmar turno' : 'Confirmar cambio';
  if (!t) {
    line.textContent = rescheduleId === null ? 'Elegí un horario' : 'Elegí el nuevo horario';
    sub.replaceChildren(...(s ? [`${s.dataset.label} · `, ...priceNodes(s.dataset.price)] : []));
    confirmBtn.disabled = true;
    return;
  }
  line.textContent = `${d.dataset.label} a las ${t.dataset.label}`;
  sub.replaceChildren(`${s.dataset.label} · ${b.dataset.label} · `, ...priceNodes(s.dataset.price));
  confirmBtn.disabled = false;
}

/** Dibuja los horarios recibidos conservando el elegido si sigue libre. */
function renderSlots(slots) {
  const keep = checked('time')?.value;
  slotsBox.replaceChildren(...slots.map(({ time, free }) => {
    const label = document.createElement('label');
    label.className = 'slot';
    const input = document.createElement('input');
    input.type = 'radio';
    input.name = 'time';
    input.value = time;
    input.dataset.label = time;
    input.disabled = !free;
    input.checked = free && time === keep;
    const span = document.createElement('span');
    span.textContent = time;
    label.append(input, span);
    return label;
  }));
  renderSummary();
}

/** Pide a la API los horarios de los servicios (su duración sumada), barbero y día elegidos. */
async function loadSlots() {
  const s = chosenServices(), b = checked('barber'), d = checked('day');
  if (!s || !b || !d) return;
  const request = ++slotsRequest;
  slotsBox.setAttribute('aria-busy', 'true');
  const { ok, body } = await get('/api/disponibilidad', { fecha: d.value, barbero: b.value, servicio: s.value });
  if (request !== slotsRequest) return;
  slotsBox.removeAttribute('aria-busy');
  if (ok) renderSlots(body.slots);
  else showToast(body.error);
}

/**
 * Preselecciona la opción `value` del grupo `name`; devuelve si existía. Para servicios `value` puede traer
 * varios separados por coma ("corte,cejas") y reemplaza la elección anterior (si ninguno existe, no la toca).
 */
function select(name, value) {
  if (name === 'service') {
    const wanted = String(value).split(',')
      .map((v) => document.querySelector(`input[name="service"][value="${v}"]`))
      .filter(Boolean);
    if (!wanted.length) return false;
    document.querySelectorAll('input[name="service"]').forEach((i) => (i.checked = wanted.includes(i)));
    return true;
  }
  const input = document.querySelector(`input[name="${name}"][value="${value}"]`);
  if (input) input.checked = true;
  return Boolean(input);
}

/** Atajo desde otras pantallas: preselecciona una opción y abre Reservar. */
function preselect(name, value) {
  select(name, value);
  renderSummary();
  loadSlots();
  go('reservar');
}

/** Sale del modo reprogramar (los radios quedan como están). */
function stopReschedule() {
  rescheduleId = null;
  renderSummary();
}

/** Tras un cambio real los datos del servidor (ticket, perfil) cambiaron: se recarga la app en Inicio. */
function reloadHome() {
  location.hash = '#inicio';
  setTimeout(() => location.reload(), 900);
}

/** Confirmar: guarda el turno (o el cambio) en el servidor y recién entonces avisa. */
async function submit() {
  const s = chosenServices(), b = checked('barber'), d = checked('day'), t = checked('time');
  const data = { service: s.value, barber: b.value, day: d.value, time: t.value };
  confirmBtn.disabled = true;

  const rescheduling = rescheduleId !== null;
  if (rescheduling) data.id = rescheduleId;
  const { ok, status, body } = await post(rescheduling ? '/turnos/reprogramar' : '/turnos', data);

  if (ok) {
    const a = body.appointment;
    showToast(`${rescheduling ? 'Turno reprogramado' : 'Turno confirmado'}: ${a.day} a las ${a.time} con ${a.barber}`);
    reloadHome();
    return;
  }
  showToast(body.error);
  if (status === 409 || status === 422) await loadSlots(); // el horario pudo ocuparse: se actualiza la grilla
  renderSummary();
}

/** Cancelar un turno desde el ticket (con confirmación previa). */
async function cancelAppointment(id) {
  if (!window.confirm('¿Cancelar este turno?')) return;
  const { ok, body } = await post('/turnos/cancelar', { id });
  if (!ok) return showToast(body.error);
  showToast('Turno cancelado');
  reloadHome();
}

/** Reprogramar desde el ticket: carga servicio y barbero del turno y pide elegir un nuevo horario. */
function startReschedule(btn) {
  rescheduleId = Number(btn.dataset.reschedule);
  select('service', btn.dataset.service);
  select('barber', btn.dataset.barber);
  document.querySelectorAll('input[name="time"]').forEach((i) => (i.checked = false));
  renderSummary();
  loadSlots();
  go('reservar');
}

/**
 * Conecta el flujo de reserva: recalcula el resumen ante cualquier cambio de radio (y recarga los horarios
 * si cambió servicio, barbero o día), confirma al tocar el botón y atiende los atajos y los botones del ticket
 * (delegación de eventos en document: funciona aunque estén en otras pantallas).
 */
export function initBooking() {
  document.getElementById('view-reservar').addEventListener('change', (e) => {
    if (e.target.name === 'service') limitServices(e.target);
    if (e.target.name !== 'time') loadSlots();
    renderSummary();
  });
  confirmBtn.addEventListener('click', submit);
  stopBtn.addEventListener('click', stopReschedule);
  renderSummary();

  document.addEventListener('click', (e) => {
    const s = e.target.closest('[data-book-service]');
    if (s) return preselect('service', s.dataset.bookService);
    const b = e.target.closest('[data-book-barber]');
    if (b) return preselect('barber', b.dataset.bookBarber);
    const r = e.target.closest('[data-reschedule]');
    if (r) return startReschedule(r);
    const c = e.target.closest('[data-cancel]');
    if (c) cancelAppointment(c.dataset.cancel);
  });
}
