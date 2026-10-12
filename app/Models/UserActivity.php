<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Actividad de las cuentas para el panel del superusuario: navegadores desde los que entró cada una (user_devices,
 * con IP, user agent y última conexión) y sus últimas acciones (audit_log). Solo con base de datos.
 */
final class UserActivity
{
    /** Nombre legible de cada evento de auditoría; uno que no esté acá se muestra con su código. */
    public const EVENTS = [
        'login_ok' => 'Inició sesión', 'login_fail' => 'Login fallido', 'login_throttled' => 'Login bloqueado por intentos',
        'logout' => 'Cerró sesión', 'logout_all' => 'Cerró sesión en todos lados', 'register' => 'Creó su cuenta',
        'password_reset_request' => 'Pidió recuperar la contraseña', 'password_reset' => 'Cambió la contraseña (recuperación)',
        'appointment_create' => 'Reservó un turno', 'appointment_cancel' => 'Canceló un turno',
        'appointment_reschedule' => 'Reprogramó un turno',
        'admin_user_create' => 'Creó una cuenta', 'admin_user_update' => 'Editó una cuenta',
        'admin_user_password' => 'Cambió la contraseña de una cuenta', 'admin_user_enable' => 'Reactivó una cuenta',
        'admin_user_disable' => 'Suspendió una cuenta', 'admin_user_delete' => 'Eliminó una cuenta',
        'queue_take' => 'Sacó número en la fila', 'queue_leave' => 'Dejó la fila',
        'admin_appointment_done' => 'Cerró un turno como atendido', 'admin_appointment_no_show' => 'Marcó un turno como ausente',
        'admin_barber_hours' => 'Editó horarios de un barbero', 'admin_shop_days' => 'Cambió los días de apertura',
        'admin_expense_create' => 'Cargó un egreso', 'admin_expense_delete' => 'Borró un egreso',
        'admin_income_create' => 'Cargó un ingreso extra', 'admin_income_delete' => 'Borró un ingreso extra',
        'admin_service_create' => 'Creó un servicio', 'admin_service_update' => 'Editó un servicio',
        'admin_service_show' => 'Mostró un servicio', 'admin_service_hide' => 'Ocultó un servicio',
        'admin_product_create' => 'Creó un producto', 'admin_product_update' => 'Editó un producto',
        'admin_product_show' => 'Mostró un producto', 'admin_product_hide' => 'Ocultó un producto',
        'admin_queue_call' => 'Llamó a alguien de la fila', 'admin_queue_no_show' => 'Marcó ausente en la fila',
    ];

    /** Etiqueta de un evento (ver EVENTS). */
    public static function label(string $event): string
    {
        return self::EVENTS[$event] ?? $event;
    }

    /**
     * Navegadores de todas las cuentas, agrupados por cuenta y el más reciente primero. El primero de cada lista
     * es la última conexión (IP, user agent y fecha).
     * @return array<int, array<int, array{device_hash: string, ip: string, user_agent: string, first_seen_at: string, last_seen_at: string}>>
     */
    public static function devicesByUser(): array
    {
        $out = [];
        foreach (Database::select(
            'SELECT user_id, device_hash, ip, user_agent, first_seen_at, last_seen_at FROM user_devices ORDER BY last_seen_at DESC'
        ) as $r) {
            $out[(int) $r['user_id']][] = $r;
        }
        return $out;
    }

    /**
     * Últimas $limit acciones de cada cuenta (audit_log), la más reciente primero, en una sola consulta
     * (ROW_NUMBER por cuenta).
     * @return array<int, array<int, array{event: string, ip: string, user_agent: ?string, detail: ?string, created_at: string}>>
     */
    public static function recentActions(int $limit = 3): array
    {
        $out = [];
        foreach (Database::select(
            'SELECT user_id, event, ip, user_agent, detail, created_at FROM (
                SELECT a.*, ROW_NUMBER() OVER (PARTITION BY a.user_id ORDER BY a.id DESC) AS n
                  FROM audit_log a WHERE a.user_id IS NOT NULL
             ) t WHERE n <= ? ORDER BY user_id, n',
            [$limit]
        ) as $r) {
            $out[(int) $r['user_id']][] = $r;
        }
        return $out;
    }

    /** Navegador concreto de una cuenta (para el Permaban de un solo dispositivo), o null si no existe. */
    public static function device(int $userId, string $deviceHash): ?array
    {
        return Database::selectOne(
            'SELECT device_hash, ip, user_agent FROM user_devices WHERE user_id = ? AND device_hash = ?',
            [$userId, $deviceHash]
        );
    }

    /**
     * Todos los navegadores conocidos de una cuenta.
     * @return array<int, array{device_hash: string, ip: string, user_agent: string}>
     */
    public static function devicesOf(int $userId): array
    {
        return Database::select('SELECT device_hash, ip, user_agent FROM user_devices WHERE user_id = ?', [$userId]);
    }

    /**
     * Resume un user agent en "Navegador · Sistema" (Chrome · Android, Safari · iPhone…) para leerlo de un vistazo;
     * el texto completo queda en el title. Devuelve "Desconocido" si viene vacío.
     */
    public static function browser(string $ua): string
    {
        if ($ua === '') {
            return 'Desconocido';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Otro navegador',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'otro sistema',
        };
        return $browser . ' · ' . $os;
    }
}
