/**
 * runner.js — "Barbero corredor" (pixel art, estilo dinosaurio de Chrome).
 *
 * Controles: tocar la pantalla / espacio / flecha arriba = saltar; mantener flecha abajo (o apoyar el
 * dedo en el tercio inferior de la pantalla) = agacharse; en el aire, abajo cae más rápido.
 * Obstáculos de suelo (frascos de tónico y postes de barbería) y, pasados ~130 puntos, tijeras
 * voladoras a tres alturas: alta (se pasa por debajo), media (hay que agacharse) y baja (hay que saltar).
 * Sin meta: la velocidad sube y termina al chocar. El puntaje es la distancia recorrida.
 */
import { loop, binder, GAME_KEYS, PAL, setup, makeSprite, blit, drawText, scissorsSprite, skyline } from './engine.js';

const W = 180, H = 120, GROUND = 100;
const GRAVITY = 900, JUMP = 250;                      // px/s², px/s (hacia arriba)
const START_SPEED = 110, MAX_SPEED = 270, ACCEL = 2.7; // px/s y px/s²
const PX = 22;                                        // x fija del barbero
const STAND_H = 22, DUCK_H = 13;

const BARBER_PAL = { h: PAL.hair, s: PAL.skin, S: PAL.skinD, w: PAL.lather, g: PAL.line, r: PAL.red, b: PAL.blue, n: PAL.navy, y: PAL.gold };
const HEAD = [
  '....hhhhhh....',
  '...hhhhhhhh...',
  '..hhhhhhhhhh..',
  '..hhssssssss..',
  '..hsssnsssns..',
  '...ssssssssS..',
  '...sshhhhhss..',
  '...sssssssS...',
  '....rrnnrr....',
];
const BODY = [
  '..wwwwwwwwww..',
  '.wwwwwwwwwwww.',
  '.wwwgwwwwgwww.',
  '.sswgwwwwgwss.',
  '.sswwwwwwwwss.',
  '..wwwwwwwwww..',
  '..wwwwwwwwww..',
  '..bbbbbbbbbb..',
  '..bbbbbbbbbb..',
];
const LEGS = {
  stand: ['..bbbb..bbbb..', '..bbbb..bbbb..', '..hhhh..hhhh..', '..hhhh..hhhh..'],
  run1: ['..bbbb...bbb..', '.bbbb.....bbb.', '.hhhh.....hhh.', '.hhhh.....hhh.'],
  run2: ['..bbb...bbbb..', '..bbb....bbbb.', '..hhh....hhhh.', '..hhh....hhhh.'],
  jump: ['..bbbb..bbbb..', '.bbbb....bbbb.', '.hhhh....hhhh.', '..............'],
};
const DUCK = [
  '.wwwwwwwwwwww.',
  '.sswwwwwwwwss.',
  '..bbbbbbbbbb..',
  '..hhhhhhhhhh..',
];

/** Arma los sprites del barbero: correr (2 cuadros), saltar, agachado y "fuera de combate" (ojos rojos). */
function barberSprites() {
  const make = (rows) => makeSprite(rows, BARBER_PAL);
  const dead = [...HEAD];
  dead[4] = dead[4].replaceAll('n', 'r');
  return {
    stand: make([...HEAD, ...BODY, ...LEGS.stand]),
    run: [make([...HEAD, ...BODY, ...LEGS.run1]), make([...HEAD, ...BODY, ...LEGS.run2])],
    jump: make([...HEAD, ...BODY, ...LEGS.jump]),
    duck: make([...HEAD, ...DUCK]),
    dead: make([...dead, ...BODY, ...LEGS.jump]),
  };
}

/** Frasco de tónico (6x12): tapa dorada, vidrio celeste con etiqueta. */
function bottleSprite(n) {
  const one = ['..yy..', '..bb..', '.cccb.', 'ccccbb', 'cwwwbb', 'cwrwbb', 'cwwwbb', 'ccccbb', 'ccccbb', 'ccccbb', 'ccccbb', 'bbbbbb'];
  const rows = one.map((r) => Array(n).fill(r).join('.'));
  return makeSprite(rows, { y: PAL.gold, b: PAL.blue, c: PAL.sky, w: PAL.lather, r: PAL.red });
}

/** Poste de barbería (8x22): casquete dorado, vidrio con espiral rojo/blanco/azul y base. */
function poleSprite() {
  const rows = ['.yyyyyy.', '.gggggg.'];
  for (let y = 0; y < 16; y++) {
    let row = 'g';
    for (let x = 1; x < 7; x++) row += ['r', 'r', 'w', 'w', 'b', 'b'][(x + y) % 6];
    rows.push(row + 'g');
  }
  rows.push('.gggggg.', '.gggggg.', 'gggggggg', 'gggggggg');
  return makeSprite(rows, { y: PAL.gold, g: PAL.soft, r: PAL.red, w: PAL.lather, b: PAL.sky });
}

/** Inicia la partida. Ver el contrato en arcade.js. */
export function start(canvas, { onScore, onEnd }) {
  const ctx = setup(canvas, W, H);
  const input = binder();
  const barber = barberSprites();
  const bottles = [bottleSprite(1), bottleSprite(2), bottleSprite(3)];
  const pole = poleSprite();
  const fly = [scissorsSprite(5), scissorsSprite(1)];
  let lift = 0, vl = 0, speed = START_SPEED, dist = 0, score = 0, t = 0;
  let started = false, over = false, duckHeld = false;
  let obstacles = [], sinceSpawn = 0, gap = 110;

  /** Salta si está apoyado; el primer toque arranca la carrera. */
  const jump = () => {
    if (over) return;
    started = true;
    if (lift === 0) vl = JUMP;
  };
  /** ¿El toque cae en el tercio inferior del canvas? (zona de agacharse en pantallas táctiles) */
  const lowZone = (e) => {
    const r = canvas.getBoundingClientRect();
    return (e.clientY - r.top) / r.height > 0.62;
  };
  input.bind(canvas, 'pointerdown', (e) => {
    e.preventDefault();
    if (started && lowZone(e)) duckHeld = true;
    else jump();
  });
  input.bind(window, 'pointerup', () => (duckHeld = false));
  input.bind(window, 'pointercancel', () => (duckHeld = false));
  input.bind(window, 'keydown', (e) => {
    if (!GAME_KEYS.includes(e.key)) return;
    e.preventDefault();
    if (e.key === ' ' || e.key === 'ArrowUp') jump();
    if (e.key === 'ArrowDown') duckHeld = true;
  });
  input.bind(window, 'keyup', (e) => {
    if (e.key === 'ArrowDown') duckHeld = false;
  });

  /** Termina una sola vez y avisa el puntaje. */
  const end = () => {
    if (over) return;
    over = true;
    setTimeout(() => onEnd(score), 500);
  };

  /** Crea un obstáculo de suelo (frascos o poste) o, con la partida avanzada, una tijera voladora. */
  function spawn() {
    sinceSpawn = 0;
    if (dist > 900 && Math.random() < 0.3) {
      const cy = [62, 77, 90][Math.floor(Math.random() * 3)]; // alta / media / baja (centro de la caja)
      obstacles.push({ kind: 'fly', x: W + 12, w: 20, h: 17, hx: 3, hy: 4, y: cy - 8, vx: 22 });
      gap = speed * 1.0 + 60 + Math.random() * 50;
      return;
    }
    if (Math.random() < 0.35) {
      obstacles.push({ kind: 'pole', x: W + 12, w: 8, h: 22, hx: 0, hy: 0, y: GROUND - 22, spr: pole });
      if (dist > 700 && Math.random() < 0.3) obstacles.push({ kind: 'pole', x: W + 22, w: 8, h: 22, hx: 0, hy: 0, y: GROUND - 22, spr: pole });
    } else {
      const n = 1 + Math.floor(Math.random() * Math.min(3, 1 + dist / 500));
      obstacles.push({ kind: 'bottle', x: W + 12, w: n * 7 - 1, h: 12, hx: 0, hy: 0, y: GROUND - 12, spr: bottles[n - 1] });
    }
    gap = speed * 0.9 + 32 + Math.random() * 60; // el hueco crece con la velocidad: siempre hay tiempo de reaccionar
  }

  /** Caja de choque del barbero (un poco más chica que el dibujo, para ser justo). */
  function barberBox() {
    const ducking = duckHeld && lift === 0;
    const h = ducking ? DUCK_H : STAND_H;
    return { x: PX + (ducking ? 1 : 3), w: ducking ? 12 : 8, y: GROUND - lift - h + 2, h: h - 3 };
  }

  /** Avanza la simulación: salto, velocidad, obstáculos, puntaje y choques. */
  function update(dt) {
    t += dt;
    if (!started || over) return;
    speed = Math.min(speed + ACCEL * dt, MAX_SPEED);
    dist += speed * dt;

    if (lift > 0 || vl > 0) {
      vl -= GRAVITY * (duckHeld && vl < 0 ? 2.6 : 1) * dt; // abajo en el aire = caída rápida
      lift += vl * dt;
      if (lift <= 0) { lift = 0; vl = 0; }
    }

    const s = Math.floor(dist / 7);
    if (s !== score) { score = s; onScore(score); }

    sinceSpawn += speed * dt;
    if (sinceSpawn >= gap) spawn();
    obstacles.forEach((o) => (o.x -= (speed + (o.vx ?? 0)) * dt));
    obstacles = obstacles.filter((o) => o.x + o.w > -4);

    const b = barberBox();
    for (const o of obstacles) {
      const ox = o.x + o.hx, oy = o.y + o.hy, ow = o.w - o.hx * 2, oh = o.h - o.hy * 2;
      if (b.x < ox + ow && b.x + b.w > ox && b.y < oy + oh && b.y + b.h > oy) return end();
    }
  }

  /** Dibuja el cielo con parallax, el suelo en movimiento, los obstáculos y al barbero. */
  function draw() {
    ctx.fillStyle = PAL.navy;
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = PAL.lather; // luna y estrellas fijas
    ctx.fillRect(150, 14, 10, 10);
    ctx.fillStyle = PAL.navy;
    ctx.fillRect(153, 14, 7, 7);
    ctx.fillStyle = PAL.soft;
    for (const [sx, sy] of [[20, 12], [52, 28], [88, 10], [120, 30], [40, 46], [100, 50], [170, 44]]) ctx.fillRect(sx, sy, 1, 1);
    skyline(ctx, dist * 0.2, GROUND);

    ctx.fillStyle = PAL.lather;
    ctx.fillRect(0, GROUND, W, 1);
    ctx.fillStyle = PAL.blue;
    ctx.fillRect(0, GROUND + 1, W, H - GROUND - 1);
    ctx.fillStyle = PAL.sky; // marcas del piso que se desplazan
    for (let i = 0; i < 9; i++) {
      const x = (((i * 23 - dist) % 207) + 207) % 207 - 20;
      ctx.fillRect(Math.round(x), GROUND + 5 + (i % 3) * 5, 5 + (i % 2) * 4, 1);
    }

    for (const o of obstacles) {
      if (o.kind === 'fly') blit(ctx, fly[Math.floor(t * 7) % 2], o.x, o.y, true);
      else blit(ctx, o.spr, o.x, o.y);
    }

    const ducking = duckHeld && lift === 0 && !over;
    const spr = over ? barber.dead : lift > 0 ? barber.jump : ducking ? barber.duck : started ? barber.run[Math.floor(t * 10) % 2] : barber.stand;
    blit(ctx, spr, PX, GROUND - lift - spr.height);

    drawText(ctx, String(score).padStart(5, '0'), W - 5, 5, PAL.lather, 2, 'right');
    if (!started) {
      drawText(ctx, 'TOCA PARA CORRER', W / 2, 40, PAL.lather, 1, 'center');
      drawText(ctx, 'ABAJO = AGACHAR', W / 2, 50, PAL.line, 1, 'center');
    }
  }

  const stopLoop = loop((dt) => { update(dt); draw(); });
  return () => {
    over = true;
    stopLoop();
    input.unbind();
  };
}
