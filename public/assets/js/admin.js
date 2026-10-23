/**
 * admin.js — Punto de entrada de las vistas del panel (/admin/*).
 *
 * Conecta el selector de tema claro/oscuro de la cabecera (components/admin-header.php; la preferencia es la misma
 * que la de la app: localStorage 'theme', aplicada por theme-init.js) las tarjetas minimizables (collapse.js), la cuenta regresiva de
 * /admin/hora-muerta (countdown.js) y la barra de secciones deslizable del celular (admin-tabs.js).
 */
import { initTheme } from './theme.js';
import { initCollapse } from './collapse.js';
import { initCountdowns } from './countdown.js';
import { initAdminTabs } from './admin-tabs.js';

initTheme();
initCollapse();
initCountdowns(); // /admin/hora-muerta
initAdminTabs();
