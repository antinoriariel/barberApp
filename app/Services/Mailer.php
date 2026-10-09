<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Envío de emails de texto plano.
 *
 * mail.driver = 'log' (por defecto en desarrollo): no envía nada, agrega el mensaje a storage/mail.log
 * para poder copiar el enlace. mail.driver = 'mail': usa mail() de PHP (requiere un servidor de correo configurado).
 */
final class Mailer
{
    /** Envía (o registra) un mensaje. Devuelve false si el envío real falló. */
    public static function send(string $to, string $subject, string $body): bool
    {
        // Evita inyección de cabeceras: ni el destinatario ni el asunto pueden traer saltos de línea.
        if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) Config::get('mail.from');

        if (Config::get('mail.driver') === 'mail') {
            return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body,
                "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $entry = sprintf("[%s] Para: %s\nAsunto: %s\n\n%s\n%s\n", date('c'), $to, $subject, $body, str_repeat('-', 60));
        return @file_put_contents(BASE_PATH . '/storage/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
