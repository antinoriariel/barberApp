/**
 * theme.js — Botón de la cabecera que alterna entre modo claro y oscuro.
 *
 * El tema vive en <html data-theme="dark|light"> (lo pone theme-init.js al cargar)
 * y los colores salen de los tokens de base.css. La elección se guarda en
 * localStorage, así que es por dispositivo/navegador.
 */
const THEME_COLORS = { dark: '#111111', light: '#F4F4F2' }; // barra del navegador en móvil

/** Aplica `theme`, actualiza el botón y la barra del navegador, y lo guarda si se puede. */
function applyTheme(theme, button) {
  document.documentElement.dataset.theme = theme;
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = THEME_COLORS[theme];
  if (button) button.setAttribute('aria-label', theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
  try { localStorage.setItem('theme', theme); } catch (e) { /* storage bloqueado: solo dura esta visita */ }
}

/** Conecta el botón #theme-toggle (si la página lo tiene) y sincroniza su estado inicial. */
export function initTheme() {
  const button = document.getElementById('theme-toggle');
  if (!button) return;
  const current = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
  button.setAttribute('aria-label', current === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
  button.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    applyTheme(next, button);
  });
}
