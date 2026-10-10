<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Foto/avatar de un barbero subida desde el panel (/admin/barberos).
 *
 * La imagen nunca se guarda tal como llega: se abre con GD, se recorta al cuadrado del centro, se lleva a
 * 400×400 (el tamaño de las ilustraciones de public/img/barberos) y se vuelve a codificar como WebP. Así se
 * descartan metadatos (EXIF con ubicación) y cualquier contenido que no sea la imagen en sí.
 * Los archivos van a public/img/barberos/subidas/ (fuera del repositorio) con un sufijo al azar en el nombre,
 * para que el navegador y el service worker no sigan mostrando la foto anterior al cambiarla.
 */
final class BarberPhoto
{
    /** Tamaño máximo del archivo subido (antes de procesarlo). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Lado de la imagen final, en píxeles. */
    private const SIZE = 400;

    /** Carpeta pública (relativa a public/) donde quedan las fotos subidas. */
    private const DIR = '/img/barberos/subidas';

    /**
     * Procesa un archivo de $_FILES y devuelve [ruta pública, null] o [null, mensaje de error].
     * Solo acepta JPEG, PNG o WebP (se detecta por el contenido, no por la extensión) de hasta 5 MB y 6000 px.
     * @return array{0: ?string, 1: ?string}
     */
    public static function store(mixed $file, string $slug): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size']) || is_array($file['error'])) {
            return [null, 'No llegó ninguna imagen.'];
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > self::MAX_BYTES) {
            return [null, 'La imagen pesa demasiado (máximo 5 MB).'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [null, 'No se pudo subir la imagen. Probá de nuevo.'];
        }

        $info = @getimagesize($file['tmp_name']);
        $open = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if ($info === false || !isset($open[$info[2]])) {
            return [null, 'La foto tiene que ser JPG, PNG o WebP.'];
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) {
            return [null, 'La imagen es demasiado grande (máximo 6000 px de lado).'];
        }
        $src = @$open[$info[2]]($file['tmp_name']);
        if ($src === false) {
            return [null, 'No se pudo leer la imagen.'];
        }

        // Recorte cuadrado centrado y escalado a SIZE×SIZE, conservando la transparencia de PNG/WebP.
        $side = min($info[0], $info[1]);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($info[0] - $side, 2), intdiv($info[1] - $side, 2),
            self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        $dir = BASE_PATH . '/public' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($dst);
            return [null, 'No se pudo guardar la imagen en el servidor.'];
        }
        $name = $slug . '-' . bin2hex(random_bytes(4)) . '.webp';
        $ok = imagewebp($dst, $dir . '/' . $name, 85);
        imagedestroy($dst);
        if (!$ok) {
            return [null, 'No se pudo guardar la imagen en el servidor.'];
        }
        return [self::DIR . '/' . $name, null];
    }

    /**
     * Borra del disco una foto subida antes (al reemplazarla). Solo toca archivos de la carpeta de subidas:
     * las ilustraciones del repositorio (public/img/barberos/*.webp) nunca se borran.
     */
    public static function delete(?string $photo): void
    {
        if ($photo !== null && preg_match('#^' . preg_quote(self::DIR, '#') . '/[a-z0-9-]+\.webp$#', $photo)) {
            @unlink(BASE_PATH . '/public' . $photo);
        }
    }
}
