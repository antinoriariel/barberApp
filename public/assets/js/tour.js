/**
 * tour.js — Modo guía: recorrido paso a paso que le explica al cliente cada función de la app.
 *
 * Usa driver.js (servido desde assets/vendor/driver, porque la CSP no permite scripts de otros orígenes).
 * Se abre con cualquier botón [data-tour-start] (el "?" de la cabecera y "Guía de la app" en Perfil) y
 * sola la primera vez que alguien entra (marca en localStorage; si el storage está bloqueado, no se abre sola).
 *
 * Los pasos recorren las pantallas de la app: antes de resaltar un elemento se muestra su pantalla con
 * show() de navigation.js (sin tocar el historial), porque un elemento dentro de una pantalla oculta no
 * tiene tamaño y driver.js no lo podría ubicar. Al terminar se vuelve a la pantalla donde estaba el usuario.
 * Los pasos cuyo elemento no existe para esta cuenta (p. ej. el número de la fila o el panel) se omiten.
 */
import { driver } from '../vendor/driver/driver.esm.js';
import { show } from './navigation.js';

const SEEN_KEY = 'navaja-tour'; // valor 'visto' una vez que se abrió la guía en este navegador
let running = false;            // evita abrir una segunda guía encima de la que está en curso

/**
 * Pasos de la guía, en orden. screen = pantalla que tiene que estar visible, element = selector CSS
 * (sin element, el globo sale centrado), side = lado del globo (sin side lo elige driver.js según el lugar libre).
 * Los elementos más altos que la pantalla se evitan: se resalta una tarjeta de ejemplo en vez de toda la lista.
 */
const STEPS = [
  {
    screen: 'inicio',
    title: 'Bienvenido a la barbería',
    text: 'Te mostramos en un minuto para qué sirve cada parte de la app. Podés avanzar con <b>Siguiente</b> o con las flechas del teclado, y salir cuando quieras con la cruz.',
  },
  {
    screen: 'inicio', element: '.topbar .open-pill', side: 'bottom',
    title: 'Abierto o cerrado',
    text: 'Te dice si el local está atendiendo ahora y hasta qué hora.',
  },
  {
    screen: 'inicio', element: '#theme-toggle', side: 'bottom',
    title: 'Modo claro u oscuro',
    text: 'Cambiá los colores de la app. Se recuerda en este teléfono.',
  },
  {
    screen: 'inicio', element: '#tour-open', side: 'bottom',
    title: 'Esta guía',
    text: 'Si te olvidás de algo, tocá este botón y la guía vuelve a empezar.',
  },
  {
    screen: 'inicio', element: '#queue-card', side: 'bottom',
    title: 'Tu número en la fila',
    text: 'Cuando sacás número en el local, acá ves tu número, cuántos tenés adelante y a quién están atendiendo. Se actualiza solo.',
  },
  {
    screen: 'inicio', element: '#view-inicio .ticket', side: 'bottom',
    title: 'Tu próximo turno',
    text: 'Muestra el día, la hora, el servicio y el barbero de tu próximo turno. Desde acá lo podés <b>reprogramar</b> o <b>cancelar</b>. Si no tenés turno, te lleva a reservar.',
  },
  {
    screen: 'inicio', element: '#view-inicio .loyalty', side: 'top',
    title: 'Tarjeta de fidelidad',
    text: 'Cada visita suma un sello. Cuando completás la tarjeta, el próximo corte es gratis.',
  },
  {
    screen: 'inicio', element: '#view-inicio .rail', side: 'top',
    title: 'Lo más pedido',
    text: 'Los servicios favoritos de los clientes. Deslizá para ver más y tocá uno para reservarlo directo.',
  },
  {
    screen: 'inicio', element: '#arcade', side: 'top',
    title: 'Minijuegos',
    text: 'Para matar el rato mientras esperás. Si ganás el Pac-Barber te llevás un cupón (uno por día), que aparece en <b>Tus premios</b>.',
  },
  {
    screen: 'inicio', element: '#view-inicio .info-list', side: 'top',
    title: 'Dónde y cuándo',
    text: 'La dirección del local y los horarios de atención.',
  },
  {
    screen: 'inicio', element: '.tabbar', side: 'top',
    title: 'Menú principal',
    text: 'Desde acá vas a Inicio, Servicios, Barberos y tu Perfil.',
  },
  {
    screen: 'inicio', element: '#scan-open', side: 'top',
    title: 'Tomar número',
    text: 'Abre la cámara para escanear el QR del mostrador y sacar número en la fila, sin turno. Los días sin turnos se atiende así, por orden de llegada.',
  },
  {
    screen: 'servicios', element: '.catalog-tabs', side: 'bottom',
    title: 'Servicios y productos',
    text: 'Elegí entre los servicios de la barbería y los productos para el pelo y la barba, que se compran en el mostrador.',
  },
  {
    screen: 'servicios', element: '#panel-servicios .svc-grid > li:first-child',
    title: 'Precios',
    text: 'Cada servicio con su precio. Tocá <b>Reservar</b> en el que quieras y pasás a elegir el horario.',
  },
  {
    screen: 'reservar', element: '#view-reservar .step:nth-of-type(1)',
    title: '1. Qué te hacés',
    text: 'Marcá uno o más servicios para el mismo turno.',
  },
  {
    screen: 'reservar', element: '#view-reservar .step:nth-of-type(2)',
    title: '2. Con quién',
    text: 'Elegí tu barbero, o <b>Aleatorio</b> para que te atienda el primero libre en tu horario.',
  },
  {
    screen: 'reservar', element: '#view-reservar .step:nth-of-type(3)',
    title: '3. Qué día',
    text: 'Los días disponibles para reservar. Los días sin turnos se atiende por orden de llegada con el QR de la fila.',
  },
  {
    screen: 'reservar', element: '#view-reservar .step:nth-of-type(4)',
    title: '4. A qué hora',
    text: 'Los horarios libres de ese día. Los que aparecen apagados ya están ocupados.',
  },
  {
    screen: 'reservar', element: '#summary',
    title: 'Confirmar',
    text: 'Revisá el resumen y tocá <b>Confirmar turno</b>. Lo vas a ver enseguida en Inicio.',
  },
  {
    screen: 'barberos', element: '#view-barberos .crew > li:first-child',
    title: 'El equipo',
    text: 'Cada tarjeta es un barbero, con su especialidad, sus años de oficio y su puntaje. Con su botón <b>Reservar</b> pasás directo a elegir el horario.',
  },
  {
    screen: 'perfil', element: '#view-perfil .me-card', side: 'bottom',
    title: 'Tu perfil',
    text: 'Tus datos, cuántas visitas llevás y cuánto te falta para el corte gratis.',
  },
  {
    screen: 'perfil', element: '#view-perfil .visit:first-child, #view-perfil .prof-empty',
    title: 'Tus visitas',
    text: 'El historial de tus últimos cortes, con el barbero y el precio.',
  },
  {
    screen: 'perfil', element: '#view-perfil .prof-list',
    title: 'Ajustes',
    text: 'Activá los avisos de turno, volvé a ver esta guía o cerrá sesión. Si perdiste el teléfono, cerrá la sesión en todos los dispositivos.',
  },
  {
    screen: 'inicio',
    title: '¡Listo!',
    text: 'Eso es todo. Cuando quieras repasar, tocá el <b>?</b> de arriba o <b>Guía de la app</b> en tu Perfil.',
  },
];

/** Muestra la pantalla `name` sin agregar una entrada al historial (el hash queda coherente si se recarga). */
function openScreen(name) {
  history.replaceState(null, '', '#' + name);
  show(name);
}

/** Pasos que aplican a esta página: los sin elemento siempre; los demás solo si su elemento existe. */
function availableSteps() {
  return STEPS.filter((s) => !s.element || document.querySelector(s.element));
}

/** Arranca la guía desde el primer paso y la marca como vista (si ya hay una abierta, no hace nada). */
export function startTour() {
  if (running) return;
  running = true;
  const steps = availableSteps();
  const origin = location.hash.slice(1) || 'inicio';
  try { localStorage.setItem(SEEN_KEY, 'visto'); } catch (e) { /* storage bloqueado: igual se puede ver */ }

  // Avanza o retrocede: primero muestra la pantalla del paso destino y después lo resalta.
  const move = (dir) => {
    const target = tour.getActiveIndex() + dir;
    if (target < 0) return;
    if (target >= steps.length) { tour.destroy(); return; }
    openScreen(steps[target].screen);
    tour.moveTo(target);
  };

  const tour = driver({
    steps: steps.map((s) => ({
      element: s.element,
      popover: { title: s.title, description: s.text, side: s.side, align: 'center' },
    })),
    showProgress: true,
    progressText: '{{current}} de {{total}}',
    nextBtnText: 'Siguiente',
    prevBtnText: 'Anterior',
    doneBtnText: 'Listo',
    popoverClass: 'tour-popover',
    overlayOpacity: 0.72,
    stagePadding: 6,
    stageRadius: 14,
    smoothScroll: false, // con scroll suave el globo se ubica antes de que termine el desplazamiento y tapa el elemento
    onNextClick: () => move(1),
    onPrevClick: () => move(-1),
    // Al cerrar (Listo, la cruz, Escape o tocando afuera) se vuelve a la pantalla de origen.
    onDestroyed: () => { running = false; openScreen(origin); },
  });

  openScreen(steps[0].screen);
  tour.drive(0);
}

/** Conecta los botones [data-tour-start] y abre la guía sola en la primera visita a este navegador. */
export function initTour() {
  document.querySelectorAll('[data-tour-start]').forEach((btn) => btn.addEventListener('click', startTour));

  let seen = true;
  try { seen = localStorage.getItem(SEEN_KEY) === 'visto'; } catch (e) { /* sin storage no se insiste */ }
  // Solo si entra a Inicio: si llegó con un enlace a otra pantalla, no se le interrumpe lo que vino a hacer.
  const hash = location.hash.slice(1);
  if (!seen && (hash === '' || hash === 'inicio')) startTour();
}
