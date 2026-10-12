<?php
declare(strict_types=1);

namespace App\Services;

/** Error de negocio al reservar/cancelar: el mensaje se le muestra al cliente y $status es el código HTTP. */
final class BookingException extends \RuntimeException
{
    /**
     * $message es el texto en castellano que AppointmentController devuelve tal cual en el JSON; $status es el código
     * HTTP de la respuesta (422 = datos inválidos por defecto, 409 = horario ya tomado, 404 = turno inexistente o ajeno).
     */
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
