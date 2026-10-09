/**
 * flappy.js — "Tijera voladora" (pixel art): tocá (o espacio / flecha arriba) para aletear y pasá entre los peines.
 * Sin meta: termina al chocar y solo suma puntos (uno por peine superado).
 */
import { loop, binder, GAME_KEYS, PAL, setup, blit, drawText, scissorsSprite, skyline, stripeTile } from './engine.js';

const W = 180, H = 270, GROUND = H - 24;
const GRAVITY = 790, FLAP = -228;       // px/s², px/s
const SPEED = 68, SPACING = 98;         // velocidad de los peines y distancia entre ellos
const GAP = 78, PIPE_W = 26, BIRD_X = 46, HIT_W = 6, HIT_H = 4; // medias cajas del choque

/** Inicia la partida. Ver el contrato en arcade.js. */
export function start(canvas, { onScore, onEnd }) {
  const ctx = setup(canvas, W, H);
  const input = binder();
  const frames = [scissorsSprite(1), scissorsSprite(3), scissorsSprite(5)]; // cerrada, media, abierta
  const stripe = stripeTile(48, 8);
  let y = H / 2, vy = 0, score = 0, started = false, over = false, t = 0, scroll = 0;
  let pipes = [], nextX = W + 40;

  /** Aleteo; el primero arranca la partida (antes la tijera flota quieta). */
  const flap = () => {
    if (over) return;
    started = true;
    vy = FLAP;
  };
  input.bind(canvas, 'pointerdown', (e) => { e.preventDefault(); flap(); });
  input.bind(window, 'keydown', (e) => {
    if (!GAME_KEYS.includes(e.key)) return;
    e.preventDefault();
    if (e.key !== 'ArrowDown') flap();
  });

  /** Termina una sola vez y avisa el puntaje. */
  const end = () => {
    if (over) return;
    over = true;
    setTimeout(() => onEnd(score), 450); // un instante para ver el choque
  };

  /** Avanza la simulación: física, peines, puntaje y choques. */
  function update(dt) {
    t += dt;
    if (over) return;
    scroll += SPEED * dt;
    if (!started) {
      y = H / 2 + Math.sin(t * 4) * 3;
      return;
    }
    vy += GRAVITY * dt;
    y += vy * dt;

    while (nextX < W + SPACING) {
      pipes.push({ x: nextX, top: 34 + Math.random() * (GROUND - GAP - 68), passed: false });
      nextX += SPACING;
    }
    const dx = SPEED * dt;
    nextX -= dx;
    pipes.forEach((p) => (p.x -= dx));
    pipes = pipes.filter((p) => p.x > -PIPE_W);

    for (const p of pipes) {
      if (!p.passed && p.x + PIPE_W < BIRD_X - HIT_W) {
        p.passed = true;
        onScore(++score);
      }
      const hitX = BIRD_X + HIT_W > p.x && BIRD_X - HIT_W < p.x + PIPE_W;
      if (hitX && (y - HIT_H < p.top || y + HIT_H > p.top + GAP)) return end();
    }
    if (y + HIT_H > GROUND || y - HIT_H < 0) end();
  }

  /** Un peine: cuerpo claro con sombra, lomo rojo y dientes que apuntan al hueco. `up` = viene desde abajo. */
  function comb(x, edge, up) {
    const body0 = up ? edge + 10 : -4, bodyLen = up ? H - edge : edge - 10 + 4;
    ctx.fillStyle = PAL.line;
    ctx.fillRect(x, body0, PIPE_W, bodyLen);
    ctx.fillStyle = PAL.lather;                 // brillo a la izquierda
    ctx.fillRect(x + 2, body0, 4, bodyLen);
    ctx.fillStyle = PAL.soft;                   // sombra a la derecha
    ctx.fillRect(x + PIPE_W - 4, body0, 4, bodyLen);
    ctx.fillStyle = PAL.red;                    // lomo del peine
    ctx.fillRect(x - 2, up ? edge + 7 : edge - 10, PIPE_W + 4, 3);
    ctx.fillStyle = PAL.lather;                 // dientes
    for (let i = 0; i < 5; i++) ctx.fillRect(x + 1 + i * 5, up ? edge : edge - 7, 3, 7);
  }

  /** Dibuja el fondo, los peines, el suelo con franja de barbería y la tijera. */
  function draw() {
    ctx.fillStyle = PAL.navy;
    ctx.fillRect(0, 0, W, H);
    skyline(ctx, scroll * 0.25, GROUND);

    for (const p of pipes) {
      const x = Math.round(p.x);
      comb(x, Math.round(p.top), false);
      comb(x, Math.round(p.top + GAP), true);
    }

    ctx.fillStyle = PAL.lather;
    ctx.fillRect(0, GROUND, W, 1);
    const off = Math.round(scroll) % 48;
    ctx.drawImage(stripe, -off, GROUND + 1);
    ctx.drawImage(stripe, 48 - off, GROUND + 1);
    ctx.drawImage(stripe, 96 - off, GROUND + 1);
    ctx.drawImage(stripe, 144 - off, GROUND + 1);
    ctx.drawImage(stripe, 192 - off, GROUND + 1);
    ctx.fillStyle = PAL.blue;
    ctx.fillRect(0, GROUND + 9, W, H - GROUND - 9);

    blit(ctx, frames[!started ? Math.floor(t * 6) % 2 * 2 : vy < -60 ? 2 : vy < 120 ? 1 : 0], BIRD_X - 10, y - 8);

    drawText(ctx, score, W / 2 + 1, 13, PAL.deep, 3, 'center');
    drawText(ctx, score, W / 2, 12, PAL.lather, 3, 'center');
    if (!started) drawText(ctx, 'TOCA PARA VOLAR', W / 2, 150, PAL.lather, 2, 'center');
  }

  const stopLoop = loop((dt) => { update(dt); draw(); });
  return () => {
    over = true;
    stopLoop();
    input.unbind();
  };
}
