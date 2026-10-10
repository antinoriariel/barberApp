<?php
declare(strict_types=1);

namespace App\Core;

/** Respuestas HTTP de error compartidas por el router, los controladores y el front controller. */
final class Response
{
    /** Responde JSON (las rutas /api y /turnos) y termina la petición. Nunca se cachea. */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Responde con la página de error genérica y termina la petición.
     *
     * @param int    $status  código HTTP (404, 405, 419, 429, 500…)
     * @param string $heading título visible
     * @param string $message explicación opcional para el usuario (nunca detalles internos)
     */
    public static function error(int $status, string $heading, string $message = ''): never
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        echo View::page('pages/error', [
            'title'     => $heading . ' · ' . Config::get('app.name'),
            'bodyClass' => 'error-page',
            'status'    => $status,
            'heading'   => $heading,
            'message' => $message,
        ]);
        exit;
    }
}
