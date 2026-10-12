/**
 * arcade.js — Área de minijuegos de Inicio (components/arcade.php).
 *
 * Al tocar una tarjeta abre el diálogo y carga el juego (js/games/<slug>.js). Cada juego exporta
 *   start(canvas, { target, onScore(n), onEnd(score, won) }) → stop()
 * y avisa con onEnd cuando la partida termina (choque, o meta alcanzada). Los juegos sin meta
 * (target 0) solo suman puntos.
 *
 * Registro en el servidor (ver Controllers\GameController): /juegos/iniciar abre la partida y devuelve un token;
 * al terminar se manda el puntaje a /juegos/fin, que mide la duración con su reloj, valida el puntaje y responde
 * el top del juego, que se muestra en el cartel de fin. Si se sale a mitad de partida (Salir, Esc o cerrar la
 * pestaña) se avisa igual con sendBeacon, así el tiempo jugado queda registrado. Si el puntaje llega a la meta se
 * pide el premio (/juegos/premio) para esa misma partida: el cliente nunca decide solo si hay promo.
 * Los nombres del ranking vienen del servidor y se insertan siempre como texto (textContent), nunca como HTML.
 */
import { post } from './api.js';
import { showToast } from './toast.js';

const $ = (id) => document.getElementById(id);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

let dialog, canvas, overBox, current = null; // current = { slug, target, stop, token, score, ended }

/**
 * Avisa al servidor que la partida `run` terminó sin pasar por onEnd (Salir, Esc, cerrar la pestaña), con el puntaje
 * que llevaba. Usa sendBeacon porque sobrevive al cierre de la página; el CSRF va en el cuerpo (_csrf).
 */
function reportQuit(run) {
  if (!run?.token || run.ended) return;
  run.ended = true;
  const data = new FormData();
  data.append('game', run.slug);
  data.append('run', run.token);
  data.append('score', String(run.score));
  data.append('_csrf', csrf());
  try { navigator.sendBeacon?.('/juegos/fin', data); } catch { /* sin beacon: el servidor la cierra como abandonada */ }
}

/** Cierra la partida en curso (corta el bucle y los listeners del juego) y, si quedó a medias, la informa. */
function stopCurrent() {
  reportQuit(current);
  current?.stop?.();
  current = null;
}

/**
 * Dibuja el top del juego en el cartel de fin (lista vacía = se oculta). Cada fila: puesto, nombre y puntaje;
 * la propia se resalta. Todo con textContent: un nombre como "<img onerror=…>" se ve tal cual, no se ejecuta.
 */
function renderTop(top) {
  const list = $('arcade-top-list');
  list.replaceChildren();
  (Array.isArray(top) ? top : []).forEach((row) => {
    const li = document.createElement('li');
    if (row.me === true) li.className = 'is-me';
    const pos = document.createElement('span');
    pos.className = 'arcade-top-pos';
    pos.textContent = `${Number(row.rank) || 0}.`;
    const name = document.createElement('span');
    name.className = 'arcade-top-name';
    name.textContent = String(row.name ?? '');
    const pts = document.createElement('b');
    pts.textContent = String(Number(row.score) || 0);
    li.append(pos, name, pts);
    list.append(li);
  });
  $('arcade-top').hidden = list.children.length === 0;
}

/** Muestra el cartel de fin de partida con título, mensaje (texto plano o con el código) y botón de reintento. */
function showOver(title, msg, code = '') {
  $('arcade-over-title').textContent = title;
  const p = $('arcade-over-msg');
  p.textContent = msg;
  if (code) {
    const b = document.createElement('b');
    b.textContent = code;
    p.append(b);
  }
  overBox.hidden = false;
  $('arcade-again').focus();
}

/** Agrega el premio recién ganado a la lista "Tus premios". */
function addReward({ code, label, expires }) {
  const li = document.createElement('li');
  const b = document.createElement('b');
  b.textContent = code;
  const small = document.createElement('small');
  small.textContent = ` vence el ${expires}`;
  li.append(b, ` ${label}`, small);
  $('arcade-rewards-list').prepend(li);
  $('arcade-rewards').hidden = false;
}

/**
 * Muestra en la tarjeta de un juego (span [data-last]) el último puntaje guardado en este navegador; si no hay
 * ninguno (o el storage está bloqueado) deja el texto original (data-default: "Solo puntos" o la promo).
 */
function renderLast(slug) {
  const span = document.querySelector(`.arcade-card[data-game="${slug}"] [data-last]`);
  if (!span) return;
  let last = null;
  try { last = localStorage.getItem(`arcade-last-${slug}`); } catch { /* storage bloqueado */ }
  span.textContent = last === null ? span.dataset.default : `Último puntaje: ${last}`;
}

/** Récord personal guardado en este navegador (respaldo cuando el servidor no registra la partida). */
function localBest(slug, score) {
  try {
    const best = Math.max(Number(localStorage.getItem(`arcade-best-${slug}`)) || 0, score);
    localStorage.setItem(`arcade-best-${slug}`, String(best));
    return best;
  } catch { return score; }
}

/**
 * Manda el resultado de `run` a /juegos/fin y devuelve la respuesta ({ counted, best, record, top }), o null si no
 * hay partida registrada (sin base de datos o sin token) o el servidor la rechazó.
 */
async function report(run, score) {
  if (!run.token) return null;
  run.ended = true;
  const { ok, body } = await post('/juegos/fin', { game: run.slug, run: run.token, score });
  return ok ? body : null;
}

/**
 * Fin de partida: registra el puntaje en el servidor y muestra el top; si llegó a la meta pide el premio para esa
 * misma partida; si no, invita a reintentar.
 */
async function finish(run, score, won) {
  const { slug, target } = run;
  try { localStorage.setItem(`arcade-last-${slug}`, String(score)); } catch { /* storage bloqueado */ }
  renderLast(slug);
  renderTop([]);
  showOver('Fin del juego', `Hiciste ${score} puntos. Guardando…`);
  const res = await report(run, score);
  if (current !== run) return; // mientras guardaba, el usuario cerró el diálogo o empezó otra partida
  renderTop(res?.top);
  if (res && !res.counted) {
    showOver('Partida no registrada', `Hiciste ${score} puntos, pero no pudimos validar la partida para el ranking.`);
    return;
  }
  const best = res ? res.best : localBest(slug, score);
  if (!target) { // juego de puntos: sin premio, solo récord y ranking
    const record = res ? res.record : score >= best && score > 0;
    showOver(record ? '¡Nuevo récord!' : '¡Buen intento!', `Hiciste ${score} puntos. Tu mejor marca: ${best}.`);
    return;
  }
  if (!won || score < target) {
    showOver('¡Casi!', `Hiciste ${score} puntos. Para el cupón hay que limpiar todo el laberinto.`);
    return;
  }
  showOver('¡Ganaste!', 'Estamos guardando tu premio…');
  const { ok, body } = await post('/juegos/premio', { game: slug, run: run.token ?? '' });
  if (current !== run) return;
  if (ok) {
    addReward(body.reward);
    showOver('¡Ganaste!', `${body.reward.label}. Mostrá este código en el mostrador:`, body.reward.code);
  } else {
    showOver('¡Muy bien!', body.error ?? 'No pudimos guardar el premio.');
  }
}

/** Arranca (o reinicia) una partida de `slug`: la abre en el servidor (token) y carga el juego. */
async function play(slug, target) {
  stopCurrent();
  overBox.hidden = true;
  $('arcade-score').textContent = '0';
  const run = { slug, target, stop: null, token: null, score: 0, ended: false };
  current = run;
  const { ok, body } = await post('/juegos/iniciar', { game: slug }); // la hora de inicio la toma el servidor
  if (ok && typeof body.run === 'string') run.token = body.run;
  if (!ok && body.error) showToast(body.error);
  const mod = await import(`./games/${slug}.js`);
  if (current !== run) { reportQuit(run); return; } // se cerró o reinició mientras cargaba
  run.stop = mod.start(canvas, {
    target,
    onScore: (n) => { run.score = n; $('arcade-score').textContent = String(n); },
    onEnd: (score, won) => {
      run.ended = true; // terminó por el juego: la informa finish(), no stopCurrent()
      run.stop?.();
      finish(run, score, won);
    },
  });
}

/** Conecta las tarjetas, el cierre del diálogo y el botón de reintento. */
export function initArcade() {
  dialog = $('arcade-dialog');
  if (!dialog) return;
  canvas = $('arcade-canvas');
  overBox = $('arcade-over');
  let open = null; // tarjeta abierta, para reintentar

  document.querySelectorAll('.arcade-card').forEach((card) => {
    renderLast(card.dataset.game); // último puntaje de las partidas anteriores
    card.addEventListener('click', () => {
      if (typeof dialog.showModal !== 'function') {
        showToast('Tu navegador no soporta los juegos.');
        return;
      }
      open = card;
      $('arcade-title').textContent = card.dataset.name;
      $('arcade-hint').textContent = card.dataset.goal;
      dialog.showModal();
      play(card.dataset.game, Number(card.dataset.target));
    });
  });

  $('arcade-again').addEventListener('click', () => play(open.dataset.game, Number(open.dataset.target)));
  $('arcade-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', stopCurrent); // también con Esc
  window.addEventListener('pagehide', () => reportQuit(current)); // cerrar la pestaña a mitad de partida
}
