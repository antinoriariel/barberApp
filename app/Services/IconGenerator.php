<?php
declare(strict_types=1);

namespace App\Services;

/** Genera el ícono PNG de la PWA con GD: círculo con rayas de poste de barbero sobre azul marino. */
final class IconGenerator
{
    /**
     * Dibuja el ícono y devuelve los bytes del PNG.
     *
     * Lógica: se pinta el fondo azul marino; luego, píxel a píxel dentro del círculo, se elige
     * el color de la franja según la diagonal (x + y) dividida por el ancho de franja, ciclando
     * blanco → rojo → azul; por último se traza el borde blanco del círculo. En la variante
     * "maskable" el círculo es más chico para que Android pueda recortar el ícono sin cortar el dibujo.
     *
     * @param int  $size     lado en px (se limita a 48–1024 para acotar el costo de CPU/memoria)
     * @param bool $maskable más margen alrededor, para íconos adaptables de Android
     */
    public static function png(int $size, bool $maskable = false): string
    {
        $size = max(48, min(1024, $size));
        $img = imagecreatetruecolor($size, $size);
        imageantialias($img, true);

        $navy  = imagecolorallocate($img, 15, 30, 58);
        $red   = imagecolorallocate($img, 215, 38, 61);
        $blue  = imagecolorallocate($img, 36, 87, 197);
        $white = imagecolorallocate($img, 247, 247, 244);
        imagefill($img, 0, 0, $navy);

        $c = $size / 2;                                   // centro
        $r = (int) ($size * ($maskable ? 0.30 : 0.38));   // radio del círculo
        $stripe = $size / 7;                              // ancho de cada franja diagonal

        // Rayas diagonales recortadas al círculo.
        for ($y = (int) ($c - $r); $y <= $c + $r; $y++) {
            for ($x = (int) ($c - $r); $x <= $c + $r; $x++) {
                if (($x - $c) ** 2 + ($y - $c) ** 2 > $r ** 2) {
                    continue; // fuera del círculo
                }
                $band = ((int) floor(($x + $y) / $stripe)) % 3;
                imagesetpixel($img, $x, $y, [$white, $red, $blue][$band]);
            }
        }
        imagearc($img, (int) $c, (int) $c, $r * 2 + 2, $r * 2 + 2, 0, 360, $white);

        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }
}
