/**
 * admin.js — Punto de entrada del panel de administración (/admin/*).
 *
 * Lo carga components/admin-header.php, así que corre en todas las vistas del panel.
 * Por ahora solo conecta el botón de modo claro/oscuro (el mismo theme.js de la app:
 * la preferencia se comparte vía localStorage y theme-init.js la aplica antes de pintar).
 */
import { initTheme } from './theme.js';

initTheme();
