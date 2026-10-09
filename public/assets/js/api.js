/**
 * api.js — Llamadas a la API JSON de turnos (/api/*, /turnos/*).
 *
 * Las POST llevan el token CSRF de <meta name="csrf-token"> en la cabecera X-CSRF-Token.
 * Si la sesión caducó (401) manda al login. Nunca lanza por errores HTTP: devuelve { ok, status, body }
 * y el llamador decide qué mostrar (body.error trae el mensaje para el cliente).
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** GET con parámetros de query: get('/api/disponibilidad', { fecha: '2026-10-09' }). */
export function get(url, params = {}) {
  return send(`${url}?${new URLSearchParams(params)}`, { headers: { Accept: 'application/json' } });
}

/** POST de un formulario codificado: post('/turnos', { service: 'corte', … }). */
export function post(url, data = {}) {
  return send(url, {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
    body: new URLSearchParams(data),
  });
}

/** fetch + parseo del JSON. Un corte de red se informa como status 0. */
async function send(url, options) {
  let res;
  try {
    res = await fetch(url, { credentials: 'same-origin', ...options });
  } catch {
    return { ok: false, status: 0, body: { error: 'Sin conexión. Probá de nuevo.' } };
  }
  const body = await res.json().catch(() => ({ error: 'Respuesta inesperada del servidor.' }));
  if (res.status === 401) location.href = '/login';
  return { ok: res.ok, status: res.status, body };
}
