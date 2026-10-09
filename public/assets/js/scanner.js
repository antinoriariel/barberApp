/**
 * scanner.js — Lector del QR del mostrador (botón central de la barra → components/qr-scanner.php).
 *
 * Abre la cámara trasera y busca un QR con la API nativa BarcodeDetector (Chrome/Edge en Android). Cuando lee el
 * QR de la fila (una URL de este mismo sitio que va a /fila), navega ahí para tomar número. Si el navegador no
 * tiene esa API, o la página no es segura (la cámara exige HTTPS o localhost), explica cómo hacerlo con la app de
 * cámara del celular, que abre el mismo enlace.
 */
import { showToast } from './toast.js';

const SCAN_EVERY_MS = 250;

let dialog, video, hint, stream = null, timer = null, detector = null;

/** Apaga la cámara y deja de buscar. */
function stop() {
  clearTimeout(timer);
  timer = null;
  if (stream) stream.getTracks().forEach((t) => t.stop());
  stream = null;
  video.srcObject = null;
}

/**
 * ¿Es el QR de la fila? Solo se aceptan enlaces de este mismo sitio a /fila (un QR ajeno no puede llevar a otra
 * página). Devuelve la ruta interna a abrir, o null.
 */
function queueTarget(text) {
  try {
    const url = new URL(text, location.origin);
    return url.pathname === '/fila' && url.searchParams.has('c') ? '/fila' + url.search : null;
  } catch {
    return null;
  }
}

/** Busca un QR en el cuadro actual del video y repite hasta encontrar uno válido o cerrar. */
async function scan() {
  if (!stream) return;
  try {
    const codes = await detector.detect(video);
    for (const { rawValue } of codes) {
      const target = queueTarget(rawValue);
      if (target) {
        stop();
        location.href = target;
        return;
      }
      hint.textContent = 'Ese QR no es el de la fila. Apuntá al código del mostrador.';
    }
  } catch { /* cuadro todavía no listo: se reintenta */ }
  timer = setTimeout(scan, SCAN_EVERY_MS);
}

/** Muestra por qué no se puede usar la cámara desde la app y cómo seguir. */
function fallback(reason) {
  hint.textContent = `${reason} Abrí la cámara de tu celular y apuntá al QR del mostrador: te lleva directo a tomar número.`;
}

/** Abre el diálogo y arranca la cámara (si se puede). */
async function open() {
  dialog.showModal();
  hint.textContent = 'Apuntá al código que está en el local. Te damos el siguiente número de la fila.';
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    return fallback('Desde acá no se puede usar la cámara (la página necesita HTTPS).');
  }
  if (!('BarcodeDetector' in window)) {
    return fallback('Tu navegador no puede leer QR desde la app.');
  }
  try {
    detector ??= new window.BarcodeDetector({ formats: ['qr_code'] });
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    if (!dialog.open) return stop(); // se cerró mientras pedía permiso
    video.srcObject = stream;
    await video.play();
    scan();
  } catch {
    stop();
    fallback('No pudimos abrir la cámara (¿le diste permiso?).');
  }
}

/** Conecta el botón central y el diálogo (si están en la página). */
export function initScanner() {
  dialog = document.getElementById('scanner');
  const openBtn = document.getElementById('scan-open');
  if (!dialog || !openBtn) return;
  video = document.getElementById('scanner-video');
  hint = document.getElementById('scanner-hint');

  openBtn.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') return showToast('Tu navegador no soporta el lector de QR.');
    open();
  });
  document.getElementById('scanner-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', stop); // también con Esc o el botón atrás
}
