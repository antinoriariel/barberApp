<?php
/**
 * helpers.php — Funciones globales de presentación, pensadas para usar en las vistas.
 */

use App\Core\Config;
use App\Core\Security;
use App\Core\View;
use App\Support\Dni;

/** Escapa texto para HTML (comillas incluidas). Usar con TODO texto que venga de datos o del usuario. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Formatea pesos argentinos: 12000 → "$12.000". */
function money(int $n): string
{
    return '$' . number_format($n, 0, ',', '.');
}

/**
 * Pesos en formato corto para lugares angostos (gráfico del panel en celular):
 * 1234567 → "$1,2M", 85000 → "$85k", 950 → "$950". El monto completo va aparte (title / tabla).
 */
function money_short(int $n): string
{
    if ($n >= 1_000_000) {
        return '$' . rtrim(rtrim(number_format($n / 1_000_000, 1, ',', ''), '0'), ',') . 'M';
    }
    if ($n >= 1_000) {
        return '$' . round($n / 1_000) . 'k';
    }
    return '$' . $n;
}

/** Iniciales de un nombre completo (primera y última palabra): "Mateo Fernández" → "MF". */
function initials(string $name): string
{
    $parts = explode(' ', $name);
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
}

/** DNI con puntos: "40123456" → "40.123.456". */
function fmt_dni(string $dni): string
{
    return Dni::format($dni);
}

/** URL de un archivo en public/assets/: asset('css/base.css') → "/assets/css/base.css". */
function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

/** Renderiza un componente o plantilla parcial y devuelve su HTML. Uso: <?= partial('components/ticket', [...]) ?> */
function partial(string $template, array $data = []): string
{
    return View::render($template, $data);
}

/** Campo oculto con el token CSRF; va dentro de todo <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Security::csrfToken()) . '">';
}

/**
 * Valida un color antes de ponerlo en un atributo style="--tone: …".
 * Solo acepta #RGB o #RRGGBB; cualquier otra cosa devuelve un gris neutro. Así, aunque el dato
 * venga algún día de una base de datos editable, no se puede inyectar CSS ("red;background:url(…)").
 */
function css_color(string $color, string $fallback = '#A6A6A6'): string
{
    return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color) ? $color : $fallback;
}

/** Paths SVG (trazo, viewBox 24x24) de los íconos de la interfaz. */
const ICON_PATHS = [
    'home'     => '<path d="M3 11.5 12 4l9 7.5M5.5 10v9.5h13V10"/>',
    'scissors' => '<circle cx="6" cy="6.5" r="2.5"/><circle cx="6" cy="17.5" r="2.5"/><path d="M8 8l12 9M8 16 20 7"/>',
    'chair'    => '<path d="M7 4h10v8H7zM5 12h14v3H5zM9 15v5M15 15v5M7 20h10"/>',
    'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1-4 4-6 8-6s7 2 8 6"/>',
    'plus'     => '<path d="M12 5v14M5 12h14"/>',
    'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'pin'      => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'star'     => '<path d="m12 3.5 2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.8 6.8 19.5l1-5.8L3.6 9.6l5.8-.8z"/>',
    'bell'     => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15zM10 21h4"/>',
    'logout'   => '<path d="M10 4H5v16h5M15 8l4 4-4 4M19 12H9"/>',
    'jar'      => '<path d="M8 3.5h8M7 6.5h10M6.5 6.5h11v12a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2zM9.5 11h5M9.5 14h5"/>',
    'qr'       => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 14h2M14 18v2h2M18 18h2v2M16 16h2v2"/>',
    'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
    'moon'     => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
];

/**
 * <svg> inline del ícono $name; $class agrega clases CSS.
 * $name y $class los escribe siempre el código de las vistas (el SVG se imprime sin escapar).
 */
function icon(string $name, string $class = ''): string
{
    return '<svg class="ic ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ICON_PATHS[$name] . '</svg>';
}

/** Bloque gris animado de skeleton repetido $times veces; $cls define forma y tamaño (ver components/skeleton.css). */
function sk(string $cls, int $times = 1): string
{
    return str_repeat('<div class="sk ' . $cls . '"></div>', $times);
}

/** Nombre del mes en español (1-12), en minúscula: 10 → "octubre". */
function month_es(int $n): string
{
    return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$n - 1];
}

/** Fecha larga en español: "2026-10-08" → "jueves 8 de octubre". */
function date_es(string $day): string
{
    $ts = strtotime($day);
    $weekdays = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    return $weekdays[(int) date('w', $ts)] . ' ' . date('j', $ts) . ' de ' . month_es((int) date('n', $ts));
}

/**
 * Texto y clase CSS de una variación (Dashboard::delta): ['up', '▲ 12,5% · +$1.000 vs ayer'].
 * @param string $vs con qué se compara ("ayer", "septiembre")
 * @return array{0: string, 1: string} [clase up|down|flat, texto]
 */
function delta_text(array $d, bool $money, string $vs): array
{
    $fmt = static fn (int $n): string => $money ? money(abs($n)) : (string) abs($n);
    $pct = static fn (?float $p): string => $p !== null ? str_replace('.', ',', (string) abs($p)) . '% · ' : '';
    return match ($d['trend']) {
        'new'   => ['up', 'Nuevo · ' . $vs . ' 0'],
        'up'    => ['up', '▲ ' . $pct($d['pct']) . '+' . $fmt($d['diff']) . ' vs ' . $vs],
        'down'  => ['down', '▼ ' . $pct($d['pct']) . '−' . $fmt($d['diff']) . ' vs ' . $vs],
        default => ['flat', '= igual que ' . $vs],
    };
}
