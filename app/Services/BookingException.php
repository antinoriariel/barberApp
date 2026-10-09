<?php
declare(strict_types=1);

namespace App\Services;

/** Error de negocio al reservar/cancelar: el mensaje se le muestra al cliente y $status es el código HTTP. */
final class BookingException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
