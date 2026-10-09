/**
 * engine.js — Piezas comunes de los minijuegos: bucle de animación, limpieza de eventos y herramientas de pixel art
 * (paleta de la página, sprites desde texto, tipografía 3x5, tijera, fondo y franja de barbería).
 */

/**
 * Corre `frame(dt)` en cada cuadro (dt en segundos, acotado para que una pestaña en segundo plano
 * no provoque saltos enormes). Devuelve la función que corta el bucle.
 */
export function loop(frame) {
  let id = 0, last = performance.now(), alive = true;
  const tick = (now) => {
    if (!alive) return;
    const dt = Math.min((now - last) / 1000, 0.05);
    last = now;
    frame(dt);
    id = requestAnimationFrame(tick);
  };
  id = requestAnimationFrame(tick);
  return () => {
    alive = false;
    cancelAnimationFrame(id);
  };
}

/**
 * Registra listeners y devuelve el objeto de limpieza: bind(target, 'keydown', fn) …; unbind().
 * Así cada juego deja el DOM como lo encontró al cerrarse.
 */
export function binder() {
  const list = [];
  return {
    bind(target, type, fn, opts) {
      target.addEventListener(type, fn, opts);
      list.push(() => target.removeEventListener(type, fn, opts));
    },
    unbind() {
      list.forEach((off) => off());
      list.length = 0;
    },
  };
}

/** Teclas que el juego usa y que no deben hacer scroll en la página. */
export const GAME_KEYS = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '];

/** Paleta de la página (base.css) más los tonos de piel/pelo que necesitan los personajes. */
export const PAL = {
  navy: '#0F1E3A', deep: '#09142A', mid: '#1B2F57', blue: '#2457C5', sky: '#5B86E0',
  red: '#D7263D', lather: '#F2F4F3', line: '#DDE2E6', soft: '#55607A',
  skin: '#F1C6A0', skinD: '#D9A07A', hair: '#6B3F24', gold: '#F2C94C',
};

/**
 * Fija la resolución lógica del canvas (pixel art: pocos píxeles, escalados por CSS sin suavizado)
 * y devuelve su contexto 2D. Cada juego llama a esto primero porque comparten el mismo <canvas>.
 */
export function setup(canvas, w, h) {
  canvas.width = w;
  canvas.height = h;
  canvas.style.aspectRatio = `${w} / ${h}`;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  return ctx;
}

/** Convierte un dibujo en texto (una fila por string, un carácter por píxel) en un canvas; '.' es transparente. */
export function makeSprite(rows, pal) {
  const c = document.createElement('canvas');
  c.width = rows[0].length;
  c.height = rows.length;
  const g = c.getContext('2d');
  rows.forEach((row, y) => [...row].forEach((ch, x) => {
    if (pal[ch]) { g.fillStyle = pal[ch]; g.fillRect(x, y, 1, 1); }
  }));
  return c;
}

/** Dibuja un sprite en píxeles enteros, opcionalmente espejado. */
export function blit(ctx, spr, x, y, flip = false) {
  x = Math.round(x); y = Math.round(y);
  if (!flip) { ctx.drawImage(spr, x, y); return; }
  ctx.save();
  ctx.translate(x + spr.width, y);
  ctx.scale(-1, 1);
  ctx.drawImage(spr, 0, 0);
  ctx.restore();
}

// Tipografía de 3x5 píxeles: cada glifo son 5 dígitos octales (una fila de 3 bits cada uno).
const FONT = {
  A: '25755', B: '65656', C: '34443', D: '65556', E: '74647', F: '74644', G: '34553', H: '55755', I: '72227',
  J: '11152', K: '55655', L: '44447', M: '57755', N: '75555', O: '25552', P: '65644', Q: '25573', R: '65655',
  S: '34216', T: '72222', U: '55557', V: '55552', W: '55775', X: '55255', Y: '55222', Z: '71247',
  0: '75557', 1: '26227', 2: '61247', 3: '61216', 4: '55711', 5: '74616', 6: '34757', 7: '71222', 8: '75757', 9: '75716',
  '!': '22202', '.': '00002', ':': '02020', '-': '00700', '+': '02720',
};

/** Escribe texto en pixel font (`scale` = tamaño del píxel). align: left | center | right. */
export function drawText(ctx, text, x, y, color, scale = 1, align = 'left') {
  const str = String(text).toUpperCase();
  const w = str.length * 4 * scale - scale;
  x = Math.round(align === 'right' ? x - w : align === 'center' ? x - w / 2 : x);
  ctx.fillStyle = color;
  [...str].forEach((ch, i) => {
    const glyph = FONT[ch];
    if (!glyph) return;
    for (let r = 0; r < 5; r++) {
      const bits = Number(glyph[r]);
      for (let c = 0; c < 3; c++) {
        if (bits & (4 >> c)) ctx.fillRect(x + (i * 4 + c) * scale, y + r * scale, scale, scale);
      }
    }
  });
}

/**
 * Sprite de tijera mirando a la derecha (20x17). `open` = apertura de las hojas en píxeles (1 cerrada, 5 abierta).
 * Se arma con líneas de píxeles (Bresenham): hojas claras, anillos rojos y un tornillo azul.
 */
export function scissorsSprite(open) {
  const W = 20, H = 17, cy = 8, hh = Math.max(1, Math.round(open * 0.6));
  const g = Array.from({ length: H }, () => Array(W).fill('.'));
  const put = (x, y, ch) => { if (g[y]?.[x] !== undefined) g[y][x] = ch; };
  const line = (x0, y0, x1, y1, ch) => {
    const dx = Math.abs(x1 - x0), dy = -Math.abs(y1 - y0), sx = x0 < x1 ? 1 : -1, sy = y0 < y1 ? 1 : -1;
    let err = dx + dy;
    for (;;) {
      put(x0, y0, ch);
      if (x0 === x1 && y0 === y1) break;
      const e2 = 2 * err;
      if (e2 >= dy) { err += dy; x0 += sx; }
      if (e2 <= dx) { err += dx; y0 += sy; }
    }
  };
  const ring = (x, y) => { for (let i = 0; i < 4; i++) for (let j = 0; j < 4; j++) if (i === 0 || j === 0 || i === 3 || j === 3) put(x + i, y + j, 'r'); };
  for (const dir of [1, -1]) { // dir 1: hoja de arriba (mango abajo); -1: hoja de abajo
    line(19, cy - dir * open + 1, 4, cy + dir * hh + 1, 'g'); // sombra
    line(19, cy - dir * open, 4, cy + dir * hh, dir === 1 ? 'w' : 'l');
  }
  ring(0, cy + hh - 1);
  ring(0, cy - hh - 2);
  put(9, cy, 'b'); put(10, cy, 'b'); put(9, cy + 1, 'b'); put(10, cy + 1, 'b');
  return makeSprite(g.map((r) => r.join('')), { w: PAL.lather, l: PAL.line, g: PAL.soft, r: PAL.red, b: PAL.blue });
}

/** Siluetas de edificios que se repiten cada 180 px (parallax del fondo). `offset` es el desplazamiento. */
export function skyline(ctx, offset, baseY) {
  const widths = [18, 14, 22, 16, 20, 12, 24, 16, 18, 20], heights = [40, 28, 52, 34, 46, 24, 58, 30, 42, 36];
  const off = Math.round(((offset % 180) + 180) % 180);
  for (const shift of [-off, 180 - off]) {
    let x = shift;
    widths.forEach((w, i) => {
      ctx.fillStyle = PAL.mid;
      ctx.fillRect(x, baseY - heights[i], w, heights[i]);
      ctx.fillStyle = PAL.navy;
      for (let wy = baseY - heights[i] + 5; wy < baseY - 6; wy += 8) {
        for (let wx = x + 3; wx < x + w - 4; wx += 6) ctx.fillRect(wx, wy, 2, 3);
      }
      x += w;
    });
  }
}

/** Franja de barbería (diagonales rojo/blanco/azul) lista para desplazarse: `w` debe ser múltiplo de 16. */
export function stripeTile(w, h) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const g = c.getContext('2d');
  const colors = [PAL.red, PAL.lather, PAL.blue, PAL.lather];
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    g.fillStyle = colors[Math.floor((((x + y) % 16) + 16) % 16 / 4)];
    g.fillRect(x, y, 1, 1);
  }
  return c;
}
