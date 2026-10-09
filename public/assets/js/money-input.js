/**
 * money-input.js — Campos de monto con puntos de miles (input[data-thousands]).
 *
 * Son type="text" a propósito: un type="number" cambia el valor con la rueda del mouse
 * y no admite separadores. Acá sólo se aceptan dígitos y se muestran agrupados de a tres
 * ("1.500", "25.000", "100.000.000"); el servidor quita los puntos al validar.
 */

/** Agrupa los dígitos de `digits` de a tres con punto ("1234567" → "1.234.567"). */
function group(digits) {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/**
 * Reformatea el campo dejando sólo dígitos (sin ceros a la izquierda) y mantiene el cursor
 * detrás de la misma cantidad de dígitos que tenía antes, para que escribir o borrar en el medio no salte.
 */
function format(input) {
  const caret = input.selectionStart ?? input.value.length;
  const digitsBefore = input.value.slice(0, caret).replace(/\D/g, '').length;
  const digits = input.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, 9);
  input.value = group(digits);
  let pos = 0;
  for (let seen = 0; pos < input.value.length && seen < digitsBefore; pos++) {
    if (/\d/.test(input.value[pos])) seen++;
  }
  input.setSelectionRange(pos, pos);
}

/** Conecta todos los input[data-thousands] de la página. */
export function initMoneyInputs() {
  document.querySelectorAll('input[data-thousands]').forEach((input) => {
    input.addEventListener('input', () => format(input));
    if (input.value) format(input);
  });
}

initMoneyInputs();
