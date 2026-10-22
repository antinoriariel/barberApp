/**
 * admin.js — Punto de entrada de las vistas del panel (/admin/*).
 *
 * Por ahora sólo conecta el selector de tema claro/oscuro de la cabecera (components/admin-header.php);
 * la preferencia es la misma que la de la app (localStorage 'theme', aplicada por theme-init.js).
 */
import { initTheme } from './theme.js';

initTheme();
