/**
 * theme-init.js — Aplica el tema guardado (claro/oscuro) antes de pintar la página.
 *
 * Se carga como script clásico y bloqueante en el <head> (la CSP no permite scripts
 * inline) para que no haya un "flash" del tema equivocado. Lee la preferencia de
 * localStorage; si no hay ninguna (o el navegador bloquea el storage), queda el
 * tema oscuro por defecto. El botón que la cambia está en theme.js.
 */
(function () {
  var theme = 'dark';
  try {
    if (localStorage.getItem('theme') === 'light') theme = 'light';
  } catch (e) { /* storage bloqueado: se usa el oscuro */ }
  document.documentElement.dataset.theme = theme;
  // Color de la barra del navegador en móvil (el <meta> va antes que este script en el <head>).
  var meta = document.querySelector('meta[name="theme-color"]');
  if (meta && theme === 'light') meta.content = '#F4F4F2';
})();
