/**
 * admin-users.js — Comportamiento de /admin/usuarios (todo es mejora progresiva: sin JS la página funciona igual).
 *
 * - En cada formulario con [data-role-form], los campos [data-barber-only] (aviso de la ficha del equipo y secciones del
 *   panel) solo se muestran si el rol elegido es "barbero"; con otro rol el servidor los ignora de todas formas.
 * - El buscador [data-user-search] filtra las tarjetas por nombre, DNI (con o sin puntos) o email.
 * - Los botones con [data-confirm] (dar de baja, Permaban, eliminar) piden confirmación en un diálogo propio antes de enviar.
 * - Los campos con [data-validate] se validan con las mismas reglas y mensajes que el servidor (User::validate,
 *   Password::validate) al salir del campo y al enviar; el error se muestra debajo del campo. El DNI se formatea
 *   con puntos mientras se escribe (30123456 → 30.123.456). El servidor vuelve a validar todo igual.
 */

/** Muestra u oculta los campos de barbero de un formulario según su selector de rol. */
function syncRole(form) {
  const select = form.querySelector('[data-role-select]');
  if (!select) return;
  const isBarber = select.value === 'barbero';
  form.querySelectorAll('[data-barber-only]').forEach((el) => {
    el.hidden = !isBarber;
  });
}

document.querySelectorAll('[data-role-form]').forEach((form) => {
  syncRole(form);
  form.querySelector('[data-role-select]')?.addEventListener('change', () => syncRole(form));
});

/** Filtra las tarjetas de cuentas: coincide si el texto (o el texto sin puntos, para el DNI) aparece en la tarjeta. */
const search = document.querySelector('[data-user-search]');
const cards = [...document.querySelectorAll('[data-user-text]')];
const none = document.querySelector('[data-user-none]');
search?.addEventListener('input', () => {
  const q = search.value.trim().toLowerCase();
  const digits = q.replace(/\./g, '');
  let shown = 0;
  cards.forEach((card) => {
    const text = card.dataset.userText;
    const match = q === '' || text.includes(q) || (digits !== '' && text.includes(digits));
    card.hidden = !match;
    if (match) shown += 1;
  });
  if (none) none.hidden = shown > 0;
});

/**
 * Pregunta con un <dialog> propio de la página y resuelve true si se confirmó. No usa window.confirm(): el navegador
 * puede bloquear esos cuadros ("no permitir más diálogos") y entonces devuelve false en silencio, con lo que el botón
 * parecía no hacer nada. Sin soporte de <dialog> (navegadores muy viejos) cae en window.confirm().
 */
function askConfirm(message, danger) {
  if (typeof HTMLDialogElement !== 'function') return Promise.resolve(window.confirm(message));
  const dialog = document.createElement('dialog');
  dialog.className = 'confirm-dialog';
  const text = document.createElement('p');
  text.textContent = message;
  const actions = document.createElement('div');
  actions.className = 'confirm-actions';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn-small ghost';
  cancel.textContent = 'Cancelar';
  const ok = document.createElement('button');
  ok.type = 'button';
  ok.className = danger ? 'btn-small danger' : 'btn-small';
  ok.textContent = 'Confirmar';
  actions.append(cancel, ok);
  dialog.append(text, actions);
  document.body.append(dialog);

  return new Promise((resolve) => {
    cancel.addEventListener('click', () => dialog.close('cancel'));
    ok.addEventListener('click', () => dialog.close('ok'));
    // Escape o click en el fondo = cancelar.
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close('cancel'); });
    dialog.addEventListener('close', () => {
      resolve(dialog.returnValue === 'ok');
      dialog.remove();
    });
    dialog.showModal();
    cancel.focus(); // lo seguro queda a mano: Enter no dispara una acción irreversible
  });
}

/**
 * Confirmación antes de una acción que deja a alguien sin acceso o borra algo ([data-confirm]). Frena el envío, pregunta
 * y, si se confirma, vuelve a hacer click en el mismo botón (así viaja su name/value, p. ej. active=0) dejándolo pasar.
 */
let confirmed = null;
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-confirm]');
  if (!button) return;
  if (confirmed === button) {
    confirmed = null;
    return;
  }
  event.preventDefault();
  askConfirm(button.dataset.confirm, button.classList.contains('danger')).then((yes) => {
    if (!yes) return;
    confirmed = button;
    button.click();
  });
});

/* ===== Validación de los formularios (mismas reglas que el servidor) ===== */

/** Deja solo dígitos (máx. 8) y los agrupa de a tres desde la derecha: punto de miles y de millones (igual que login.js). */
function formatDni(raw) {
  const digits = raw.replace(/\D/g, '').slice(0, 8);
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/** Nombre y apellido: al menos dos palabras de letras (con tildes), apóstrofo, punto o guion; como User::validate. */
const NAME_RE = /^[\p{L}\p{M}'.-]+( [\p{L}\p{M}'.-]+)+$/u;

/**
 * Reglas por tipo de campo: devuelven el mensaje de error o '' si el valor es válido.
 * Los textos son los del servidor, así el mensaje es el mismo con o sin JS.
 */
const RULES = {
  dni(input) {
    return /^\d{7,8}$/.test(input.value.replace(/\D/g, '')) ? '' : 'Ingresá un DNI de 7 u 8 números.';
  },
  name(input) {
    const value = input.value.trim().replace(/\s+/g, ' ');
    if (!value.includes(' ')) return 'Ingresá nombre y apellido.';
    if (value.length > 120) return 'El nombre es demasiado largo (máximo 120 caracteres).';
    return NAME_RE.test(value) ? '' : 'Usá solo letras, espacios, apóstrofo, punto o guion.';
  },
  email(input) {
    const value = input.value.trim();
    if (value === '') return '';
    return value.length <= 190 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? '' : 'Ingresá un email válido (o dejalo vacío).';
  },
  password(input) {
    const min = input.minLength > 0 ? input.minLength : 8;
    const max = input.maxLength > 0 ? input.maxLength : 72;
    const len = [...input.value].length; // caracteres, no bytes (como mb_strlen)
    if (input.value.includes('\0')) return 'La contraseña tiene caracteres no válidos.';
    if (len < min) return `La contraseña debe tener al menos ${min} caracteres.`;
    if (len > max) return `La contraseña es demasiado larga (máximo ${max} caracteres).`;
    return '';
  },
  confirm(input) {
    const password = input.form.querySelector('[data-validate="password"]');
    if (input.value === '') return 'Repetí la contraseña.';
    return password && input.value !== password.value ? 'Las contraseñas no coinciden.' : '';
  },
};

let msgCount = 0;

/** Muestra (o borra, si message es '') el error de un campo: texto debajo del campo, aria-invalid y validez nativa. */
function showError(input, message) {
  const label = input.closest('label') ?? input.parentElement;
  let note = label.querySelector('.field-msg');
  if (message && !note) {
    note = document.createElement('small');
    note.className = 'field-msg';
    note.id = `field-msg-${(msgCount += 1)}`;
    label.append(note);
  }
  if (note) {
    note.textContent = message;
    note.hidden = message === '';
  }
  input.setCustomValidity(message);
  if (message) {
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', note.id);
  } else {
    input.removeAttribute('aria-invalid');
    input.removeAttribute('aria-describedby');
  }
}

/** Valida un campo con su regla y muestra el resultado; devuelve true si es válido. */
function check(input) {
  const rule = RULES[input.dataset.validate];
  const message = rule ? rule(input) : '';
  showError(input, message);
  return message === '';
}

document.querySelectorAll('form').forEach((form) => {
  const fields = [...form.querySelectorAll('[data-validate]')];
  if (fields.length === 0) return;
  // Con JS se muestran estos mensajes en lugar de los globos del navegador (sin JS quedan los nativos).
  form.noValidate = true;

  fields.forEach((input) => {
    const type = input.dataset.validate;
    // Al salir del campo: se normaliza (espacios de más, email sin espacios) y se valida si escribió algo.
    input.addEventListener('blur', () => {
      if (type === 'name') input.value = input.value.trim().replace(/\s+/g, ' ');
      if (type === 'email') input.value = input.value.trim();
      if (input.value !== '' || input.hasAttribute('aria-invalid')) check(input);
    });
    // Mientras escribe: el DNI se formatea con puntos y, si el campo ya marcaba error, se revalida para sacarlo apenas se corrige.
    input.addEventListener('input', () => {
      if (type === 'dni') input.value = formatDni(input.value);
      if (input.hasAttribute('aria-invalid')) check(input);
      if (type === 'password') {
        const confirm = form.querySelector('[data-validate="confirm"]');
        if (confirm?.value) check(confirm);
      }
    });
    if (input.tagName === 'SELECT') input.addEventListener('change', () => check(input));
  });

  // Al enviar se validan todos; si alguno falla no se envía y el foco va al primero con error.
  form.addEventListener('submit', (event) => {
    const invalid = fields.filter((input) => !check(input));
    if (invalid.length > 0) {
      event.preventDefault();
      invalid[0].focus();
    }
  });
});
