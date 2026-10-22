/**
 * main.js — Punto de entrada de la app (pages/shell.php).
 * Cada módulo se encarga de una sola cosa; acá solo se inicializan.
 */
import { initNavigation } from './navigation.js';
import { initToast } from './toast.js';
import { initBooking } from './booking.js';
import { initArcade } from './arcade.js';
import { registerServiceWorker } from './pwa.js';
import { initTheme } from './theme.js';
import { initCatalog } from './catalog.js';
import { initScanner } from './scanner.js';
import { initQueue } from './queue.js';
import { initTour } from './tour.js';
import { initCountdowns } from './countdown.js';

initTheme();
initCatalog();
initScanner();
initQueue();
initNavigation();
initToast();
initBooking();
initArcade();
initCountdowns(); // aviso de "Hora muerta"
initTour(); // al final: la guía puede abrirse sola y necesita la navegación ya iniciada
registerServiceWorker();
