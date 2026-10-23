/**
 * su-heatmap.js — Dibuja el mapa de calor de /su/uso (pages/su-uso.php) sin dependencias.
 *
 * Datos: el JSON de #hm-data (UiUsage::heat + UiUsage::boxes): puntos [x, y, peso] con x en milésimos del ancho e
 * y en px de referencia (capa 'page', alto = data.height) o milésimos del alto de la ventana (capa 'fixed').
 *
 * Técnica (la de simpleheat/heatmap.js, en ~60 líneas): cada punto estampa un círculo difuminado en escala de
 * alfa sobre un lienzo; las zonas con muchos puntos se acumulan. Después cada píxel se colorea según su alfa con una
 * rampa secuencial de un solo tono (azul claro → oscuro en modo claro, al revés en oscuro, tomada de las variables
 * --heat-* de su.css), así "más intenso" siempre se lee igual y no depende de distinguir colores.
 * Debajo, el plano: la caja promedio de cada componente (con su nombre al pasar el mouse) para saber qué hay en cada
 * lugar sin una captura de pantalla. Las filas de la tabla de componentes resaltan su caja.
 *
 * Se vuelve a dibujar al cambiar el tamaño (ResizeObserver) o el tema (atributo data-theme de <html>).
 */
const dataEl = document.getElementById('hm-data');
const stage = document.getElementById('hm-stage');
const canvas = document.getElementById('hm-canvas');
const boxesEl = document.getElementById('hm-boxes');
const tip = document.getElementById('hm-tip');

/** Los filtros se aplican apenas se cambian (el botón "Aplicar" queda para cuando no hay JS). */
document.getElementById('hm-filters')?.addEventListener('change', (e) => e.currentTarget.requestSubmit?.() ?? e.currentTarget.submit());

if (dataEl && stage && canvas) {
  const data = JSON.parse(dataEl.textContent);
  // Alto del plano en las unidades de y: px de referencia, o milésimos (capa fija) llevados a un alto de celular.
  const yScale = data.layer === 'fixed' ? data.fixedHeight / 1000 : 1;
  const height = data.height;

  // Escala del calor: el percentil 98 de los pesos, para que una celda excepcional no apague todo lo demás.
  const weights = data.points.map((p) => p[2]).sort((a, b) => a - b);
  const max = weights[Math.floor((weights.length - 1) * 0.98)] || 1;

  /** Rampa de 256 colores a partir de las paradas --heat-1..4 de su.css (con el tema actual). */
  function palette() {
    const css = getComputedStyle(stage);
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 1;
    const g = c.getContext('2d');
    const grad = g.createLinearGradient(0, 0, 256, 0);
    ['--heat-1', '--heat-2', '--heat-3', '--heat-4'].forEach((name, i) => grad.addColorStop(i / 3, css.getPropertyValue(name).trim()));
    g.fillStyle = grad;
    g.fillRect(0, 0, 256, 1);
    return g.getImageData(0, 0, 256, 1).data;
  }

  /** Círculo difuminado (negro con alfa) que se estampa por cada punto. */
  function stamp(r, blur) {
    const c = document.createElement('canvas');
    const size = (r + blur) * 2;
    c.width = c.height = size;
    const g = c.getContext('2d');
    // La sombra desplazada fuera del lienzo deja solo el círculo difuminado dentro.
    g.shadowOffsetX = g.shadowOffsetY = size * 2;
    g.shadowBlur = blur;
    g.shadowColor = 'black';
    g.beginPath();
    g.arc(-size * 2 + size / 2, -size * 2 + size / 2, r, 0, Math.PI * 2);
    g.fill();
    return c;
  }

  /** Dibuja el calor al tamaño actual del escenario. */
  function draw() {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = Math.round(stage.clientWidth * dpr);
    const h = Math.round(stage.clientHeight * dpr);
    if (!w || !h) return;
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const unit = w / 400; // px del lienzo por px de referencia
    const r = Math.max(4, Math.round(11 * unit));
    const blur = Math.max(4, Math.round(13 * unit));
    const dot = stamp(r, blur);
    const half = dot.width / 2;

    for (const [x, y, weight] of data.points) {
      ctx.globalAlpha = Math.min(1, Math.max(0.06, weight / max));
      ctx.drawImage(dot, (x / 1000) * w - half, ((y * yScale) / height) * h - half);
    }

    // Color según el alfa acumulado; el alfa final tiene un piso para que lo poco igual se vea sobre el plano.
    const img = ctx.getImageData(0, 0, w, h);
    const px = img.data;
    const ramp = palette();
    for (let i = 3; i < px.length; i += 4) {
      const a = px[i];
      if (!a) continue;
      const j = a * 4;
      px[i - 3] = ramp[j];
      px[i - 2] = ramp[j + 1];
      px[i - 1] = ramp[j + 2];
      px[i] = Math.min(235, 60 + a);
    }
    ctx.putImageData(img, 0, 0);
  }

  /** Arma las cajas del plano (en % del escenario, así no hay que recalcularlas al cambiar el tamaño). */
  function drawBoxes() {
    const top = Math.max(1, ...data.boxes.map((b) => b.clicks));
    for (const b of data.boxes) {
      const el = document.createElement('div');
      el.className = 'hm-box';
      el.dataset.comp = b.comp;
      el.style.left = `${(b.x / 1000) * 100}%`;
      el.style.top = `${((b.y * yScale) / height) * 100}%`;
      el.style.width = `${(Math.max(b.w, 8) / 1000) * 100}%`;
      el.style.height = `${((Math.max(b.h, 6) * yScale) / height) * 100}%`;
      el.dataset.label = `${b.label} · ${b.clicks} click${b.clicks === 1 ? '' : 's'}`;
      if (b.clicks / top > 0.15) {
        const span = document.createElement('span');
        span.textContent = b.label.split(' · ').pop();
        el.append(span);
      }
      boxesEl.append(el);
    }
  }

  /** Tooltip: el componente bajo el puntero (la caja más chica que lo contiene). */
  function hover(event) {
    const rect = stage.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;
    let best = null;
    for (const el of boxesEl.children) {
      const b = el.getBoundingClientRect();
      const inside = event.clientX >= b.left && event.clientX <= b.right && event.clientY >= b.top && event.clientY <= b.bottom;
      if (inside && (!best || b.width * b.height < best.area)) best = { el, area: b.width * b.height };
    }
    highlight(best?.el.dataset.comp ?? null);
    if (!best) {
      tip.hidden = true;
      return;
    }
    tip.textContent = best.el.dataset.label;
    tip.hidden = false;
    tip.style.left = `${Math.min(x + 12, rect.width - tip.offsetWidth - 4)}px`;
    tip.style.top = `${Math.max(4, y - tip.offsetHeight - 10)}px`;
  }

  /** Resalta la caja y la fila de la tabla de un componente (o ninguna). */
  function highlight(comp) {
    for (const el of document.querySelectorAll('.hm-box.is-on, .hm-table tr.is-on')) el.classList.remove('is-on');
    if (!comp) return;
    for (const el of document.querySelectorAll(`[data-comp="${CSS.escape(comp)}"]`)) el.classList.add('is-on');
  }

  drawBoxes();
  draw();
  new ResizeObserver(() => draw()).observe(stage);
  new MutationObserver(() => draw()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  stage.addEventListener('pointermove', hover);
  stage.addEventListener('pointerleave', () => { tip.hidden = true; highlight(null); });
  document.querySelector('.hm-table')?.addEventListener('pointerover', (e) => {
    const row = e.target.closest('tr[data-comp]');
    if (row) highlight(row.dataset.comp);
  });
  document.querySelector('.hm-table')?.addEventListener('pointerleave', () => highlight(null));
}
