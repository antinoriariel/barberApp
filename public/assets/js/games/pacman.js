/**
 * pacman.js — "Pac-Barber": Pac-Man arcade original (nivel 1), en pixel art con la paleta de la página.
 *
 * Reglas del original que se respetan:
 * - Laberinto de 28x31 casillas de 8 px, 240 puntos (10 pts) y 4 pelotas (50 pts) = 244 en total, túnel lateral con wrap.
 * - 3 vidas (vida extra a los 10.000), "READY!" antes de cada intento, cereza de 100 pts a los 70 y 170 puntos comidos.
 * - Cuatro fantasmas con su IA propia: Blinky persigue; Pinky apunta 4 casillas adelante (con el fallo de "arriba");
 *   Inky usa el vector Blinky→2 casillas delante de Pac-Man, duplicado; Clyde persigue a más de 8 casillas y huye al estar cerca.
 * - Ciclo dispersión/persecución del nivel 1 (7-20-7-20-5-20-5-∞ s) con giro de 180° en cada cambio.
 * - Los fantasmas eligen en cada intersección la salida que más los acerca a su objetivo (sin volver atrás, sin
 *   girar hacia arriba en las 4 casillas prohibidas). Asustados: giran al azar, van lento y valen 200/400/800/1600.
 * - Velocidades del nivel 1 (% de 75,75 px/s): Pac-Man 80% (90% con pelota), fantasmas 75% (50% asustados,
 *   40% en el túnel), Blinky "Cruise Elroy" 80%/85% cuando quedan 20/10 puntos. Pac-Man se frena al comer.
 * - Casa de fantasmas: salida por contadores de puntos (Pinky 0, Inky 30, Clyde 60; tras morir, contador global 7/17/32)
 *   y por tiempo (4 s sin comer). Un fantasma comido vuelve a la casa como ojos y revive.
 * Termina al perder las 3 vidas (onEnd(puntaje, false)) o al comer todo (onEnd(puntaje, true) → cupón).
 */
import { loop, binder, PAL, setup, makeSprite, blit, drawText } from './engine.js';

const TILE = 8, COLS = 28, ROWS = 31, OY = 24, W = COLS * TILE, H = 288;

// # pared · . punto · o pelota · espacio camino vacío · - puerta de la casa · v fuera del laberinto
const MAP = [
  '############################',
  '#............##............#',
  '#.####.#####.##.#####.####.#',
  '#o####.#####.##.#####.####o#',
  '#.####.#####.##.#####.####.#',
  '#..........................#',
  '#.####.##.########.##.####.#',
  '#.####.##.########.##.####.#',
  '#......##....##....##......#',
  '######.##### ## #####.######',
  'vvvvv#.##### ## #####.#vvvvv',
  'vvvvv#.##          ##.#vvvvv',
  'vvvvv#.## ###--### ##.#vvvvv',
  '######.## #      # ##.######',
  '      .   #      #   .      ',
  '######.## #      # ##.######',
  'vvvvv#.## ######## ##.#vvvvv',
  'vvvvv#.##          ##.#vvvvv',
  'vvvvv#.## ######## ##.#vvvvv',
  '######.## ######## ##.######',
  '#............##............#',
  '#.####.#####.##.#####.####.#',
  '#.####.#####.##.#####.####.#',
  '#o..##.......  .......##..o#',
  '###.##.##.########.##.##.###',
  '###.##.##.########.##.##.###',
  '#......##....##....##......#',
  '#.##########.##.##########.#',
  '#.##########.##.##########.#',
  '#..........................#',
  '############################',
];

const DIRS = { up: [0, -1], left: [-1, 0], down: [0, 1], right: [1, 0] };
const ORDER = ['up', 'left', 'down', 'right']; // prioridad ante empates, como en el original
const OPP = { up: 'down', down: 'up', left: 'right', right: 'left' };
const KEYS = { ArrowUp: 'up', w: 'up', ArrowDown: 'down', s: 'down', ArrowLeft: 'left', a: 'left', ArrowRight: 'right', d: 'right' };
const GAME_KEYS = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
const NO_UP = [[12, 11], [15, 11], [12, 23], [15, 23]]; // casillas donde los fantasmas no pueden girar hacia arriba

const BASE = 75.75; // px/s al 100%
const PAC_SPEED = BASE * 0.8, PAC_FRIGHT = BASE * 0.9;
const GHOST_SPEED = BASE * 0.75, GHOST_FRIGHT = BASE * 0.5, GHOST_TUNNEL = BASE * 0.4, EYES_SPEED = 170;
const SCHEDULE = [7, 20, 7, 20, 5, 20, 5, Infinity]; // dispersión, persecución, …
const FRIGHT_SECONDS = 6;
const HOME = { x: 112, y: 116 }, DOOR_Y = 92;           // centro de la casa y punto de salida (fila 11)
const FRUIT_POS = { x: 112, y: 140 };

const GHOSTS = [
  { name: 'blinky', color: '#D7263D', x: 112, y: 92, state: 'out', corner: { c: 25, r: -3 }, limit: 0 },
  { name: 'pinky', color: '#FFB8DE', x: 112, y: 116, state: 'house', corner: { c: 2, r: -3 }, limit: 0 },
  { name: 'inky', color: '#4ADCEE', x: 96, y: 116, state: 'house', corner: { c: 27, r: 31 }, limit: 30 },
  { name: 'clyde', color: '#FFB851', x: 128, y: 116, state: 'house', corner: { c: 0, r: 31 }, limit: 60 },
];

/** Carácter de la casilla (c, r) con wrap horizontal; fuera de las filas del mapa cuenta como "fuera". */
const tileAt = (c, r) => MAP[r]?.[((c % COLS) + COLS) % COLS] ?? 'v';
/** ¿Se puede caminar por la casilla? (la puerta solo la cruzan los fantasmas con su lógica propia) */
const open = (c, r) => ' .o'.includes(tileAt(c, r));
const TOTAL = MAP.join('').split('').filter((ch) => ch === '.' || ch === 'o').length;

const FRUIT_SPRITE_ROWS = [
  '.......gg...', '......g..g..', '.....g...g..', '..rr.g...rr.',
  '.rrrr...rrrr', '.rwrr...rwrr', '.rrrr...rrrr', '..rr.....rr.',
];

/** Dibuja el laberinto (solo paredes) como contornos de 2 px sobre cada borde que da a un camino. */
function renderMaze(color) {
  const c = document.createElement('canvas');
  c.width = W; c.height = ROWS * TILE;
  const g = c.getContext('2d');
  const walkable = (ch) => ch === ' ' || ch === '.' || ch === 'o' || ch === '-';
  for (let r = 0; r < ROWS; r++) {
    for (let col = 0; col < COLS; col++) {
      const ch = MAP[r][col], x = col * TILE, y = r * TILE;
      if (ch === '-') {
        g.fillStyle = '#FFB8DE';
        g.fillRect(x, y + 3, TILE, 2);
      } else if (ch === '#') {
        g.fillStyle = color;
        if (walkable(tileAt(col, r - 1))) g.fillRect(x, y, TILE, 2);
        if (walkable(tileAt(col, r + 1))) g.fillRect(x, y + 6, TILE, 2);
        if (walkable(tileAt(col - 1, r))) g.fillRect(x, y, 2, TILE);
        if (walkable(tileAt(col + 1, r))) g.fillRect(x + 6, y, 2, TILE);
      }
    }
  }
  return c;
}

/** Sprite de Pac-Man de 14x14 mirando a `dir` con la boca abierta `half` grados a cada lado (rasterizado, se cachea). */
const pacCache = new Map();
function pacSprite(dir, half) {
  const key = `${dir}${half}`;
  if (pacCache.has(key)) return pacCache.get(key);
  const c = document.createElement('canvas');
  c.width = c.height = 14;
  const g = c.getContext('2d');
  const face = { right: 0, down: Math.PI / 2, left: Math.PI, up: -Math.PI / 2 }[dir];
  g.fillStyle = PAL.gold;
  for (let y = 0; y < 14; y++) {
    for (let x = 0; x < 14; x++) {
      const dx = x + 0.5 - 7, dy = y + 0.5 - 7;
      if (Math.hypot(dx, dy) > 6.6) continue;
      let diff = Math.abs(Math.atan2(dy, dx) - face);
      if (diff > Math.PI) diff = 2 * Math.PI - diff;
      if (half > 0 && diff < (half * Math.PI) / 180) continue;
      g.fillRect(x, y, 1, 1);
    }
  }
  pacCache.set(key, c);
  return c;
}

/** Cuerpo de fantasma (14x14, 2 cuadros de "falda") en el color dado. */
function ghostBody(color) {
  const top = ['....xxxxxx....', '..xxxxxxxxxx..', '.xxxxxxxxxxxx.', '.xxxxxxxxxxxx.', '.xxxxxxxxxxxx.'];
  const mid = Array(7).fill('xxxxxxxxxxxxxx');
  const skirts = [
    ['xxx.xxxxxx.xxx', 'xx..xxxxxx..xx'],
    ['xxxxx.xx.xxxxx', 'xxxx..xx..xxxx'],
  ];
  return skirts.map((s) => makeSprite([...top, ...mid, ...s], { x: color }));
}

/** Inicia la partida. Ver el contrato en arcade.js (aquí onEnd recibe también si se ganó). */
export function start(canvas, { onScore, onEnd }) {
  const ctx = setup(canvas, W, H);
  const input = binder();

  const mazeBlue = renderMaze(PAL.blue), mazeFlash = renderMaze(PAL.lather);
  const bodies = Object.fromEntries(GHOSTS.map((g) => [g.name, ghostBody(g.color)]));
  const frightBlue = ghostBody(PAL.blue), frightWhite = ghostBody(PAL.lather);
  const cherry = makeSprite(FRUIT_SPRITE_ROWS, { g: '#3FA34D', r: PAL.red, w: PAL.lather });
  const pellet = makeSprite(['..oooo..', '.oooooo.', 'oooooooo', 'oooooooo', 'oooooooo', 'oooooooo', '.oooooo.', '..oooo..'], { o: PAL.lather });

  const food = MAP.map((row) => [...row].map((ch) => (ch === '.' || ch === 'o' ? ch : '')));
  let score = 0, lives = 3, extraLife = false, eaten = 0, t = 0, over = false;
  let st = 'ready', timer = 2.6;               // ready | play | eatpause | dying | win | gameover
  let pac, ghosts, fright = 0, combo = 0, modeIdx = 0, modeT = 0, fruit = null, foodTimer = 0;
  let global = { on: false, count: 0 };
  const dots = { pinky: 0, inky: 0, clyde: 0 }; // contadores personales de salida
  let floaters = [], showGhosts = true, hiddenGhost = null;

  /** Suma puntos, avisa y otorga la vida extra a los 10.000. */
  function addScore(n) {
    score += n;
    onScore(score);
    if (!extraLife && score >= 10000) { extraLife = true; lives++; }
  }

  /** Coloca a Pac-Man y los fantasmas en la salida (inicio y tras perder una vida). */
  function resetActors(afterDeath) {
    pac = { x: 112, y: 188, dir: 'left', want: null, face: 'left', pause: 0, anim: 0 };
    ghosts = GHOSTS.map((g, i) => ({
      ...g, dir: g.state === 'out' ? 'left' : null, fright: false, vy: i % 2 ? 22 : -22, flash: 0,
    }));
    fright = 0; combo = 0; modeIdx = 0; modeT = SCHEDULE[0]; fruit = null; foodTimer = 0;
    global = { on: afterDeath, count: 0 };
    showGhosts = true; hiddenGhost = null;
  }
  resetActors(false);

  // ─── Entrada ────────────────────────────────────────────────────────────
  input.bind(window, 'keydown', (e) => {
    const dir = KEYS[e.key];
    if (!dir) return;
    if (GAME_KEYS.includes(e.key)) e.preventDefault();
    pac.want = dir;
  });
  let sx = 0, sy = 0, swiping = false; // deslizar: el eje dominante del arrastre decide la dirección
  input.bind(canvas, 'pointerdown', (e) => { sx = e.clientX; sy = e.clientY; swiping = true; });
  input.bind(canvas, 'pointermove', (e) => {
    if (!swiping) return;
    const dx = e.clientX - sx, dy = e.clientY - sy;
    if (Math.hypot(dx, dy) < 16) return;
    pac.want = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
    sx = e.clientX; sy = e.clientY;
  });
  input.bind(canvas, 'pointerup', () => (swiping = false));
  input.bind(canvas, 'pointercancel', () => (swiping = false));

  // ─── Movimiento ─────────────────────────────────────────────────────────
  /**
   * Avanza `e` (x, y, dir) `dist` px. Al llegar al centro de una casilla llama a `onCenter(e, c, r)`,
   * que puede cambiar e.dir (o ponerla en null para frenar). Los giros solo ocurren en centros: nunca se atraviesan paredes.
   */
  function advance(e, dist, onCenter) {
    while (dist > 0 && e.dir) {
      const [dx, dy] = DIRS[e.dir];
      const cx = Math.floor(e.x / TILE) * TILE + 4, cy = Math.floor(e.y / TILE) * TILE + 4;
      let target;
      if (dx) target = dx > 0 ? (e.x < cx ? cx : cx + TILE) : (e.x > cx ? cx : cx - TILE);
      else target = dy > 0 ? (e.y < cy ? cy : cy + TILE) : (e.y > cy ? cy : cy - TILE);
      const d = Math.abs(dx ? target - e.x : target - e.y);
      if (d > dist + 1e-9) { // tolerancia: evita pasar de largo un centro por error de coma flotante
        e.x += dx * dist; e.y += dy * dist;
        e.x = ((e.x % W) + W) % W;
        return;
      }
      if (dx) e.x = target; else e.y = target; // llegó exacto al centro de la casilla
      e.x = ((e.x % W) + W) % W;
      dist -= d;
      onCenter(e, Math.floor(e.x / TILE), Math.floor(e.y / TILE));
    }
  }

  /** Pac-Man en el centro de una casilla: come, gira hacia donde pidió si puede y, si no, sigue recto o se frena. */
  function pacCenter(p, c, r) {
    eat(c, r);
    if (p.want && open(c + DIRS[p.want][0], r + DIRS[p.want][1])) p.dir = p.want;
    else if (!(p.dir && open(c + DIRS[p.dir][0], r + DIRS[p.dir][1]))) p.dir = null;
    if (p.dir) p.face = p.dir;
  }

  /** Come lo que haya en (c, r): punto, pelota (asusta a los fantasmas), y dispara la cereza y la victoria. */
  function eat(c, r) {
    const f = food[r]?.[c];
    if (!f) return;
    food[r][c] = '';
    eaten++;
    foodTimer = 0;
    if (f === '.') { addScore(10); pac.pause = 1 / 60; }
    else { addScore(50); pac.pause = 3 / 60; startFright(); }
    countDot();
    if (eaten === 70 || eaten === 170) fruit = { t: 9.5 };
    if (eaten === TOTAL) { st = 'win'; timer = 2.4; showGhosts = false; }
  }

  /** Pelota: los fantasmas que no están como ojos se asustan y (si ya andan por el laberinto) dan la vuelta. */
  function startFright() {
    fright = FRIGHT_SECONDS;
    combo = 0;
    for (const g of ghosts) {
      if (g.state === 'eaten' || g.state === 'entering') continue;
      g.fright = true;
      if (g.state === 'out') g.dir = OPP[g.dir];
    }
  }

  /** Cuenta un punto comido para las reglas de salida de la casa (contador global tras morir, personal si no). */
  function countDot() {
    if (global.on) { global.count++; return; }
    const next = ghosts.find((g) => g.state === 'house');
    if (next) dots[next.name]++;
  }

  /** Decide quién sale de la casa: por contador de puntos o, si Pac-Man no come hace 4 s, el siguiente en la fila. */
  function checkRelease() {
    const g = ghosts.find((x) => x.state === 'house');
    if (!g) return;
    let go = false;
    if (global.on) {
      go = global.count >= { pinky: 7, inky: 17, clyde: 32 }[g.name];
      if (go && g.name === 'clyde') global.on = false;
    } else {
      go = dots[g.name] >= g.limit;
    }
    if (!go && foodTimer >= 4) { go = true; foodTimer = 0; }
    if (go) g.state = 'leaving';
  }

  const tileOf = (e) => ({ c: Math.floor(e.x / TILE), r: Math.floor(e.y / TILE) });

  /** Casilla objetivo del fantasma según su personalidad y el modo (dispersión / persecución). */
  function targetOf(g) {
    if (g.state === 'eaten') return { c: 13, r: 11 };
    const dotsLeft = TOTAL - eaten;
    const elroy = g.name === 'blinky' && dotsLeft <= 20;
    const modeName = modeIdx % 2 === 0 ? 'scatter' : 'chase';
    if (modeName === 'scatter' && !elroy) return g.corner;
    const p = tileOf(pac), [fx, fy] = DIRS[pac.face];
    const ahead = (n) => ({ c: p.c + fx * n - (pac.face === 'up' ? n : 0), r: p.r + fy * n }); // el fallo original de "arriba"
    switch (g.name) {
      case 'pinky': return ahead(4);
      case 'inky': {
        const pivot = ahead(2), b = tileOf(ghosts[0]);
        return { c: 2 * pivot.c - b.c, r: 2 * pivot.r - b.r };
      }
      case 'clyde': {
        const me = tileOf(g);
        return (me.c - p.c) ** 2 + (me.r - p.r) ** 2 > 64 ? p : g.corner;
      }
      default: return p;
    }
  }

  /** Fantasma en un centro: ojos que llegan a la puerta entran; el resto elige la salida que más lo acerca al objetivo. */
  function ghostCenter(g, c, r) {
    if (g.state === 'eaten' && r === 11 && (c === 13 || c === 14)) { g.state = 'entering'; g.dir = null; return; }
    const noUp = g.state === 'out' && !g.fright && NO_UP.some(([a, b]) => a === c && b === r);
    const opts = ORDER.filter((d) => d !== OPP[g.dir] && open(c + DIRS[d][0], r + DIRS[d][1]) && !(d === 'up' && noUp));
    if (!opts.length) { g.dir = OPP[g.dir]; return; }
    if (g.fright && g.state === 'out') { g.dir = opts[Math.floor(Math.random() * opts.length)]; return; }
    const tg = targetOf(g);
    let best = opts[0], bestD = Infinity;
    for (const d of opts) {
      const dd = (c + DIRS[d][0] - tg.c) ** 2 + (r + DIRS[d][1] - tg.r) ** 2;
      if (dd < bestD) { bestD = dd; best = d; }
    }
    g.dir = best;
  }

  /** Velocidad del fantasma en px/s según su estado, el túnel, el susto y el "Cruise Elroy" de Blinky. */
  function ghostSpeed(g) {
    if (g.state === 'eaten') return EYES_SPEED;
    const { c, r } = tileOf(g);
    const tunnel = r === 14 && (c <= 5 || c >= 22);
    if (g.fright) return tunnel ? GHOST_TUNNEL : GHOST_FRIGHT;
    if (tunnel) return GHOST_TUNNEL;
    if (g.name === 'blinky') {
      const left = TOTAL - eaten;
      if (left <= 10) return BASE * 0.85;
      if (left <= 20) return BASE * 0.8;
    }
    return GHOST_SPEED;
  }

  /** Mueve a un fantasma según su estado: rebota en la casa, sale, recorre el laberinto, vuelve como ojos o reingresa. */
  function moveGhost(g, dt) {
    if (g.state === 'house') {
      g.y += g.vy * dt;
      if (g.y <= 111) { g.y = 111; g.vy = Math.abs(g.vy); }
      if (g.y >= 121) { g.y = 121; g.vy = -Math.abs(g.vy); }
    } else if (g.state === 'leaving' || g.state === 'entering') {
      const sp = (g.state === 'leaving' ? 45 : 90) * dt;
      g.dir = g.state === 'leaving' ? 'up' : 'down';
      if (Math.abs(g.x - HOME.x) > 0.01) {
        g.x += Math.sign(HOME.x - g.x) * Math.min(sp, Math.abs(HOME.x - g.x));
      } else if (g.state === 'leaving') {
        g.y -= sp;
        if (g.y <= DOOR_Y) { g.y = DOOR_Y; g.state = 'out'; g.dir = 'left'; }
      } else {
        g.y += sp;
        if (g.y >= HOME.y) { g.y = HOME.y; g.state = 'leaving'; g.fright = false; }
      }
    } else {
      advance(g, ghostSpeed(g) * dt, ghostCenter);
    }
  }

  /** Pac-Man pierde una vida. */
  function die() {
    st = 'dying';
    timer = 1.9;
    showGhosts = false;
  }

  /** Resuelve los choques de Pac-Man con los fantasmas que andan por el laberinto, y la cereza. */
  function collisions() {
    for (const g of ghosts) {
      if (g.state !== 'out' || Math.hypot(g.x - pac.x, g.y - pac.y) >= 6) continue;
      if (g.fright) {
        const pts = 200 * 2 ** Math.min(combo, 3);
        combo++;
        addScore(pts);
        g.fright = false;
        g.state = 'eaten';
        hiddenGhost = g;
        floaters.push({ x: g.x, y: g.y, text: String(pts), t: 0.8 });
        st = 'eatpause';
        timer = 0.8;
        return;
      }
      return die();
    }
    if (fruit && Math.hypot(pac.x - FRUIT_POS.x, pac.y - FRUIT_POS.y) < 6) {
      fruit = null;
      addScore(100);
      floaters.push({ x: FRUIT_POS.x, y: FRUIT_POS.y, text: '100', t: 1.5 });
    }
  }

  /** Un paso de simulación (dt ≤ 1/60 s): máquina de estados del juego. */
  function step(dt) {
    t += dt;
    floaters.forEach((f) => (f.t -= dt));
    floaters = floaters.filter((f) => f.t > 0);

    if (st === 'ready') {
      timer -= dt;
      if (timer <= 0) st = 'play';
      return;
    }
    if (st === 'eatpause') {
      timer -= dt;
      if (timer <= 0) { st = 'play'; hiddenGhost = null; }
      return;
    }
    if (st === 'dying') {
      timer -= dt;
      if (timer <= 0) {
        lives--;
        if (lives <= 0) { st = 'gameover'; timer = 2.4; }
        else { resetActors(true); st = 'ready'; timer = 1.8; }
      }
      return;
    }
    if (st === 'win' || st === 'gameover') {
      timer -= dt;
      if (timer <= 0 && !over) {
        over = true;
        onEnd(score, st === 'win');
      }
      return;
    }

    // — Modo de los fantasmas: el reloj de dispersión/persecución se congela mientras dura el susto.
    if (fright > 0) {
      fright -= dt;
      if (fright <= 0) { fright = 0; ghosts.forEach((g) => (g.fright = false)); }
    } else {
      modeT -= dt;
      if (modeT <= 0) {
        modeIdx++;
        modeT = SCHEDULE[Math.min(modeIdx, SCHEDULE.length - 1)];
        ghosts.forEach((g) => { if (g.state === 'out') g.dir = OPP[g.dir]; }); // giro de 180° al cambiar de modo
      }
    }
    foodTimer += dt;
    if (fruit && (fruit.t -= dt) <= 0) fruit = null;
    checkRelease();

    // — Pac-Man: dar la vuelta es inmediato; el resto de los giros, en el próximo centro.
    if (pac.dir && pac.want === OPP[pac.dir]) { pac.dir = pac.want; pac.face = pac.dir; }
    if (!pac.dir && pac.want) {
      const { c, r } = tileOf(pac);
      if (open(c + DIRS[pac.want][0], r + DIRS[pac.want][1])) { pac.dir = pac.want; pac.face = pac.dir; }
    }
    if (pac.pause > 0) pac.pause -= dt;
    else if (pac.dir) {
      pac.anim += dt * 14;
      advance(pac, (fright > 0 ? PAC_FRIGHT : PAC_SPEED) * dt, pacCenter);
    }
    if (st !== 'play') return;

    ghosts.forEach((g) => moveGhost(g, dt));
    collisions();
  }

  /** Dibuja un sprite con wrap horizontal (los fantasmas y Pac-Man cruzan el túnel). */
  function wrapBlit(spr, x, y) {
    blit(ctx, spr, x, y);
    if (x < 14) blit(ctx, spr, x + W, y);
    else if (x > W - 14) blit(ctx, spr, x - W, y);
  }

  /** Dibuja un fantasma: cuerpo (o azul asustado, que parpadea al final), ojos que miran hacia donde va. */
  function drawGhost(g) {
    const frame = Math.floor(t * 8) % 2;
    const x = g.x - 7, y = g.y - 7 + OY;
    const flashing = g.fright && fright < 2 && Math.floor(fright * 5) % 2 === 0;
    if (g.state !== 'eaten' && g.state !== 'entering') {
      const spr = g.fright ? (flashing ? frightWhite : frightBlue)[frame] : bodies[g.name][frame];
      wrapBlit(spr, x, y);
    }
    const rx = Math.round(x), ry = Math.round(y);
    const paint = (fx, fy, w, h, color) => { ctx.fillStyle = color; ctx.fillRect(rx + fx, ry + fy, w, h); };
    if (g.fright && g.state !== 'eaten' && g.state !== 'entering') {
      const face = flashing ? PAL.red : PAL.lather;
      paint(4, 5, 2, 2, face); paint(8, 5, 2, 2, face);
      for (let i = 0; i < 6; i++) paint(2 + i * 2, 10 + (i % 2), 2, 1, face);
      return;
    }
    const [dx, dy] = DIRS[g.dir ?? 'left'];
    for (const ex of [3, 8]) { // ojo blanco de 3x4 con pupila de 2x2 desplazada hacia donde mira
      paint(ex, 4, 3, 4, PAL.lather);
      paint(ex + (dx > 0 ? 1 : 0), 4 + (dy < 0 ? 0 : dy > 0 ? 2 : 1), 2, 2, PAL.blue);
    }
  }

  /** Dibuja todo el cuadro: laberinto, comida, cereza, fantasmas, Pac-Man (o su animación de muerte) y el marcador. */
  function draw() {
    ctx.fillStyle = PAL.deep;
    ctx.fillRect(0, 0, W, H);
    const flash = st === 'win' && Math.floor(timer * 4) % 2 === 0;
    ctx.drawImage(flash ? mazeFlash : mazeBlue, 0, OY);

    const blink = Math.floor(t * 4) % 2 === 0;
    ctx.fillStyle = PAL.line;
    for (let r = 0; r < ROWS; r++) {
      for (let c = 0; c < COLS; c++) {
        const f = food[r][c];
        if (f === '.') ctx.fillRect(c * TILE + 3, OY + r * TILE + 3, 2, 2);
        else if (f === 'o' && blink) ctx.drawImage(pellet, c * TILE, OY + r * TILE);
      }
    }
    if (fruit) blit(ctx, cherry, FRUIT_POS.x - 6, FRUIT_POS.y - 4 + OY);

    if (showGhosts && st !== 'win') {
      [...ghosts].reverse().forEach((g) => { if (g !== hiddenGhost) drawGhost(g); });
    }

    if (st === 'dying') {
      const k = Math.floor(Math.max(0, 1.9 - timer - 0.4) / 1.3 * 12); // 0,4 s quieto y luego se "abre" hasta desaparecer
      if (k < 12) wrapBlit(pacSprite('up', k * 15), pac.x - 7, pac.y - 7 + OY);
    } else if (st !== 'win' && st !== 'eatpause' && st !== 'gameover') {
      const half = [0, 18, 40, 18][Math.floor(pac.anim) % 4];
      wrapBlit(pacSprite(pac.face, st === 'ready' ? 0 : half), pac.x - 7, pac.y - 7 + OY);
    }

    for (const f of floaters) drawText(ctx, f.text, f.x, f.y - 2 + OY, '#4ADCEE', 1, 'center');

    if (Math.floor(t * 2) % 2 === 0 || st !== 'ready') drawText(ctx, '1UP', 24, 2, PAL.lather, 2, 'center');
    drawText(ctx, score, 46, 13, PAL.lather, 2, 'right');
    drawText(ctx, 'PAC-BARBER', 130, 2, PAL.gold, 2, 'center');
    if (st === 'ready') drawText(ctx, 'READY!', 112, OY + 17 * TILE - 5, PAL.gold, 2, 'center');
    if (st === 'gameover') drawText(ctx, 'GAME OVER', 112, OY + 17 * TILE - 5, PAL.red, 2, 'center');

    for (let i = 0; i < Math.max(0, lives - 1); i++) blit(ctx, pacSprite('left', 18), 12 + i * 17, H - 17);
    blit(ctx, cherry, W - 22, H - 15);
  }

  const stopLoop = loop((dt) => {
    let left = dt;
    while (left > 0) { const s = Math.min(left, 1 / 60); step(s); left -= s; }
    draw();
  });
  return () => {
    over = true;
    stopLoop();
    input.unbind();
  };
}
