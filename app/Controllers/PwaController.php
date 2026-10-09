<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Services\IconGenerator;

/** Recursos de la PWA que se generan dinámicamente: manifest e íconos. */
final class PwaController extends Controller
{
    /** GET /manifest.webmanifest — manifest de la PWA armado con los datos de config/app.php. */
    public function manifest(Request $request): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name'             => Config::get('app.name'),
            'short_name'       => 'Barbería',
            'description'      => Config::get('app.description'),
            'lang'             => Config::get('app.lang'),
            'start_url'        => '/',
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => Config::get('app.background_color'),
            'theme_color'      => Config::get('app.theme_color'),
            'icons'            => [
                ['src' => '/icon.png?s=192',      'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon.png?s=512',      'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon.png?s=512&m=1',  'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /icon.png?s=<tamaño>[&m=1] — ícono PNG; m=1 genera la variante "maskable" con más margen.
     * El tamaño lo limita IconGenerator (48–1024), así que un valor absurdo no agota recursos.
     * Se cachea un día: el dibujo es determinista y público.
     */
    public function icon(Request $request): void
    {
        $size = (int) $request->query('s', 192);
        $png = IconGenerator::png($size, $request->query('m') !== null);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        echo $png;
    }
}
