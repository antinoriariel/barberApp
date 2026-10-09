<?php
/** Agenda del local. La lee Models\Schedule. */
return [
    // Días de la semana en que el local cierra (date('w'): 0 = domingo, 1 = lunes).
    'closed_weekdays' => [0, 1],

    // Horarios del día: [hora, disponible]. Iguales para todos los días por ahora.
    'slots' => [
        ['10:00', true],  ['10:45', true],  ['11:30', false], ['12:15', true],
        ['14:00', true],  ['14:45', false], ['15:30', true],  ['16:15', true],
        ['17:00', false], ['17:45', true],  ['18:30', true],  ['19:15', true],
    ],
];
