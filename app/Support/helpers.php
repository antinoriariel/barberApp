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
 * Precio con el descuento de la "Hora muerta" ($pct %), redondeado al peso: misma cuenta que Appointment::insert,
 * que la aplica sobre el total del turno. Con $pct 0 devuelve el precio tal cual.
 */
function sale_price(int $price, int $pct): int
{
    return $pct > 0 ? (int) round($price * (100 - $pct) / 100) : $price;
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

/** Teléfono guardado (10 dígitos) para mostrar: "3781401440" → "+54 9 3781 40-1440" (ver Support\Phone). */
function fmt_phone(string $phone): string
{
    return \App\Support\Phone::format($phone);
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
    'help'     => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.5M12 16.8v.2"/>',
    // Panel de administración: sello de la marca, pestañas de secciones y medallones de los KPIs.
    'razor'    => '<path d="M3.5 13.5 14 3l3.5 3.5L9.5 14.5z"/><path d="M9.5 14.5 12 17a3 3 0 0 0 4.2 0l3.3-3.3-3.2-3.2M6.5 10.5l3 3"/>',
    'chart'    => '<path d="M4 20h16M7 16.5v-5M12 16.5V7M17 16.5v-8"/>',
    'calendar' => '<rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
    'hourglass'=> '<path d="M7 3.5h10M7 20.5h10M8 3.5c0 4 4 5.5 4 8.5s-4 4.5-4 8.5M16 3.5c0 4-4 5.5-4 8.5s4 4.5 4 8.5"/>',
    'ticket'   => '<path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5V10a2 2 0 0 0 0 4v2.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5V14a2 2 0 0 0 0-4z"/><path d="M14.5 6.5v2M14.5 11v2M14.5 15.5v2"/>',
    'wallet'   => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H17v3"/><rect x="4" y="8" width="16" height="11" rx="2"/><path d="M15.5 13.5h1.5"/>',
    'trend-up' => '<path d="M4 17l5.5-5.5 3.5 3.5L20 8M15 8h5v5"/>',
    'receipt'  => '<path d="M6 3.5h12v17l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5z"/><path d="M9 8h6M9 11.5h6M9 15h4"/>',
    'tag'      => '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8a1.5 1.5 0 0 1 0 2.1l-7 7a1.5 1.5 0 0 1-2.1 0z"/><circle cx="8" cy="8" r="1.5"/>',
    'users'    => '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 19.5c.8-3.3 3.3-5 6.5-5s5.7 1.7 6.5 5M15.5 5.2a3.5 3.5 0 0 1 0 6.6M17.5 14.8c2 .6 3.4 2.2 4 4.7"/>',
    'shield'   => '<path d="M12 3.5 19 6v5.5c0 4.3-3 7.6-7 9-4-1.4-7-4.7-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
    'list'     => '<path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>',
    'id'       => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 15.5c.6-1.5 1.7-2.2 3-2.2s2.4.7 3 2.2M14.5 10h3M14.5 13h3"/>',
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

/**
 * Slug ASCII para URLs y claves a partir de un nombre: "Corte & Barba Ñandú" → "corte-barba-nandu" (máximo 24
 * caracteres; $fallback si no queda nada). Usa strtr y no iconv//TRANSLIT porque su resultado cambia según la
 * plataforma (en Windows deja "n~").
 */
function slugify(string $text, string $fallback = 'item'): string
{
    $ascii = strtolower(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                                      'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n']));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    return substr($slug !== '' ? $slug : $fallback, 0, 24);
}

/**
 * Segundos que faltan en una cuenta regresiva como "m:ss" o, desde una hora, "h:mm:ss" (95 → "1:35", 3700 →
 * "1:01:40"). Es el texto inicial que después actualiza js/countdown.js con el mismo formato.
 */
function countdown_text(int $seconds): string
{
    $s = max(0, $seconds);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s % 60) : sprintf('%d:%02d', $m, $s % 60);
}
