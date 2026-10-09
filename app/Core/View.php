<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Motor de vistas: renderiza plantillas de app/Views/ con PHP plano.
 *
 * - render(): devuelve el HTML de una plantilla o componente.
 * - page():   renderiza una plantilla y la envuelve en un layout, que recibe
 *             el resultado como $content.
 */
final class View
{
    /**
     * Renderiza `app/Views/<template>.php` con las variables de `$data` y devuelve el HTML.
     *
     * La plantilla se ejecuta dentro de una función anónima estática, así solo ve las
     * variables de $data (extract con EXTR_SKIP: no pisa variables existentes) y no el estado
     * de la clase. La salida se captura con ob_start. Los nombres de plantilla los define
     * siempre el código, nunca la entrada del usuario.
     *
     * @throws \RuntimeException si la plantilla no existe
     */
    public static function render(string $template, array $data = []): string
    {
        $file = BASE_PATH . "/app/Views/{$template}.php";
        if (!is_file($file)) {
            throw new \RuntimeException("Vista no encontrada: {$template}");
        }

        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        })();
    }

    /**
     * Renderiza una página: primero la plantilla (queda en $content) y luego el layout que la envuelve.
     * Ambos reciben las mismas variables ($title, $scripts, etc.).
     */
    public static function page(string $template, array $data = [], string $layout = 'layouts/base'): string
    {
        $content = self::render($template, $data);
        return self::render($layout, $data + ['content' => $content]);
    }
}
