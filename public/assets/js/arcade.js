/**
 * arcade.js — Área de minijuegos de Inicio (components/arcade.php).
 *
 * Al tocar una tarjeta abre el diálogo y carga el juego (js/games/<slug>.js). Cada juego exporta
 *   start(canvas, { target, onScore(n), onEnd(score, won) }) → stop()
 * y avisa con onEnd cuando la partida termina (choque, o meta alcanzada). Los juegos sin meta
 * (target 0) solo suman puntos. Si el puntaje llega a la
 * meta se pide el premio al servidor (/juegos/premio), que vuelve a validar la partida
 * (ver Controllers\GameController): el cliente nunca decide solo si hay promo.
 */
import { post } from './api.js';
import { showToast } from './toast.js';

const $ = (id) => document.getElementById(id);

let dialog, canvas, overBox, current = null; // current = { slug, target, stop }

/** Cierra la partida en curso (corta el bucle y los listeners del juego). */
function stopCurrent() {
  current?.stop?.();
  current = null;
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

/** Fin de partida: guarda el último puntaje; si llegó a la meta pide el premio; si no, invita a reintentar. */
async function finish(slug, target, score, won) {
  stopCurrent();
  try { localStorage.setItem(`arcade-last-${slug}`, String(score)); } catch { /* storage bloqueado */ }
  renderLast(slug);
  if (!target) { // juego de puntos: sin premio, solo récord personal
    let best = 0;
    try {
      best = Math.max(Number(localStorage.getItem(`arcade-best-${slug}`)) || 0, score);
      localStorage.setItem(`arcade-best-${slug}`, String(best));
    } catch { best = score; }
    showOver(score >= best && score > 0 ? '¡Nuevo récord!' : '¡Buen intento!', `Hiciste ${score} puntos. Tu mejor marca: ${best}.`);
    return;
  }
  if (!won || score < target) {
    showOver('¡Casi!', `Hiciste ${score} puntos. Para el cupón hay que limpiar todo el laberinto.`);
    return;
  }
  showOver('¡Ganaste!', 'Estamos guardando tu premio…');
  const { ok, body } = await post('/juegos/premio', { game: slug, score });
  if (ok) {
    addReward(body.reward);
    showOver('¡Ganaste!', `${body.reward.label}. Mostrá este código en el mostrador:`, body.reward.code);
  } else {
    showOver('¡Muy bien!', body.error ?? 'No pudimos guardar el premio.');
  }
}

/** Arranca (o reinicia) una partida de `slug`. */
async function play(slug, target) {
  stopCurrent();
  overBox.hidden = true;
  $('arcade-score').textContent = '0';
  const run = { slug, target, stop: null };
  current = run;
  await post('/juegos/iniciar', { game: slug }); // abre la partida en el servidor (hora de inicio)
  const mod = await import(`./games/${slug}.js`);
  if (current !== run) return; // se cerró o reinició mientras cargaba
  run.stop = mod.start(canvas, {
    target,
    onScore: (n) => ($('arcade-score').textContent = String(n)),
    onEnd: (score, won) => finish(slug, target, score, won),
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
}
