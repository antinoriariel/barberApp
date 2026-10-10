<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\DbSessionHandler;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Models\User;
use App\Support\Dni;
use App\Support\Password;

/**
 * Autenticación por DNI + contraseña, alta de cuentas y recuperación de contraseña.
 *
 * - Un único mensaje de error en el login (ERROR_CREDENTIALS) para DNI inválido, inexistente o contraseña
 *   incorrecta, y se verifica un hash falso cuando el DNI no existe, así no se enumeran cuentas ni por el
 *   texto ni por el tiempo de respuesta.
 * - Los intentos fallidos se limitan por IP y por DNI con RateLimiter.
 * - Todo evento relevante queda en Audit.
 * - Con driver files (maqueta) solo se puede iniciar sesión (con la contraseña de demostración);
 *   el alta y la recuperación requieren base de datos.
 */
final class Auth
{
    public const ERROR_CREDENTIALS = 'DNI o contraseña incorrectos.';
    public const ERROR_THROTTLED = 'Demasiados intentos. Esperá unos minutos y probá de nuevo.';
    /** Comienzo de todo mensaje de bloqueo (el del login agrega el tiempo de espera); el controlador lo usa para responder 429. */
    public const THROTTLED_PREFIX = 'Demasiados intentos.';

    /** Segundos de espera del último bloqueo de login (para la cabecera Retry-After); 0 si no hubo bloqueo. */
    private static int $retryAfter = 0;
    public const ERROR_RESET_INVALID = 'El enlace no es válido o venció. Pedí uno nuevo.';

    /** Hash de relleno para igualar el tiempo de respuesta cuando el DNI no existe. */
    private static ?string $dummyHash = null;

    /**
     * Usuario de la sesión actual o null si no hay sesión, el DNI guardado ya no existe o la cuenta fue dada de baja
     * (la baja también borra sus sesiones; esto cubre una sesión que siga viva en el navegador).
     */
    public static function user(): ?User
    {
        $dni = Session::get('dni');
        $user = is_string($dni) ? User::find($dni) : null;
        return $user?->active ? $user : null;
    }

    /**
     * Intenta iniciar sesión.
     *
     *  1. Reserva el intento ANTES de verificar: suma uno a la IP y al DNI de forma atómica y mira el número
     *     que le tocó. Si pasa el límite → mensaje de bloqueo con el tiempo de espera (sin consultar
     *     contraseñas) y devuelve la reserva. Así un bot que manda muchas contraseñas en paralelo no puede
     *     colarlas todas entre "consultar" y "sumar": como mucho pasan tantas como el límite.
     *  2. Cualquier fallo (formato, DNI inexistente, contraseña mala) deja el intento sumado y devuelve
     *     ERROR_CREDENTIALS más cuántos quedan (el menor entre IP y DNI); si era el último, el de bloqueo.
     *     El conteo es igual exista o no el DNI, así que no sirve para averiguar cuentas.
     *  3. Éxito → borra el contador del DNI, devuelve la reserva de la IP (un login correcto no cuenta como
     *     fallo, pero los fallos anteriores de esa IP siguen: un atacante no la reinicia con una cuenta propia),
     *     actualiza el hash si quedó viejo, regenera el id de sesión (anti-fijación), rota el CSRF y guarda el DNI.
     *
     * Contraseñas vacías, más largas que Password::MAX o con bytes nulos nunca se verifican (cuentan como fallo):
     * el hash falso se calcula igual para que el tiempo de respuesta no delate nada.
     *
     * @return string|null null si salió bien, o el mensaje de error para mostrar
     */
    public static function attempt(string $rawDni, string $password, string $ip): ?string
    {
        $dni = Dni::normalize(substr($rawDni, 0, 32));
        $window = (int) Config::get('security.login_window');
        $ipKey = 'login:ip:' . RateLimiter::ipBucket($ip);
        $dniKey = 'login:dni:' . $dni;
        $validDni = Dni::isValid($dni);
        $maxIp = (int) Config::get('security.login_max_attempts_ip');
        $maxDni = (int) Config::get('security.login_max_attempts_dni');
        self::$retryAfter = 0;

        $ipCount = RateLimiter::hit($ipKey, $window);
        $dniCount = $validDni ? RateLimiter::hit($dniKey, $window) : 0;
        if ($ipCount > $maxIp || $dniCount > $maxDni) {
            // Ya estaba bloqueado: este pedido no cuenta, así no se consume el cupo de la IP (wifi compartido).
            RateLimiter::release($ipKey);
            if ($validDni) {
                RateLimiter::release($dniKey);
            }
            Audit::log('login_throttled', null, mb_substr($dni, 0, 12));
            return self::throttled($ipKey, $validDni ? $dniKey : null);
        }

        $usable = $validDni && $password !== '' && mb_strlen($password) <= Password::MAX && !str_contains($password, "\0");
        $hash = $usable ? User::passwordHash($dni) : null;
        $ok = Password::verify($usable ? $password : '', $hash ?? self::dummyHash()) && $hash !== null;
        $user = $ok ? User::find($dni) : null;

        if ($user === null) {
            Audit::log('login_fail', null, mb_substr($dni, 0, 12));
            $left = $maxIp - $ipCount;
            if ($validDni) {
                $left = min($left, $maxDni - $dniCount);
            }
            if ($left <= 0) {
                return self::throttled($ipKey, $validDni ? $dniKey : null);
            }
            return self::ERROR_CREDENTIALS . ' ' . ($left === 1 ? 'Te queda 1 intento.' : "Te quedan $left intentos.");
        }

        if (Database::enabled() && Password::needsRehash($hash)) {
            User::setPassword($user->id, Password::hash($password));
        }
        RateLimiter::release($ipKey);
        RateLimiter::clear($dniKey);
        self::startSession($user);
        Audit::log('login_ok', $user->id);
        return null;
    }

    /**
     * Mensaje de bloqueo del login con el tiempo de espera: el mayor de las claves bloqueadas (IP y, si hay, DNI),
     * redondeado a minutos hacia arriba. Lo guarda en $retryAfter para la cabecera HTTP.
     */
    private static function throttled(string $ipKey, ?string $dniKey): string
    {
        $wait = max(RateLimiter::retryAfter($ipKey), $dniKey !== null ? RateLimiter::retryAfter($dniKey) : 0);
        self::$retryAfter = max(1, $wait);
        $minutes = (int) ceil(self::$retryAfter / 60);
        return self::THROTTLED_PREFIX . ' Podés volver a intentar en ' . ($minutes === 1 ? '1 minuto.' : "$minutes minutos.");
    }

    /** Segundos de espera del último bloqueo de login, o 0 si el último intento no quedó bloqueado. */
    public static function retryAfter(): int
    {
        return self::$retryAfter;
    }

    /** Regenera la sesión (anti-fijación), rota el CSRF y la asocia al usuario. */
    private static function startSession(User $user): void
    {
        Session::regenerate();
        Session::remove('csrf');
        Session::set('dni', $user->dni);
        DbSessionHandler::bindUser($user->id ?: null);
    }

    /** Hash falso (calculado una vez por petición) contra el que se verifica cuando el DNI no existe. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Password::hash('contraseña-de-relleno');
    }

    /**
     * Crea una cuenta nueva y deja la sesión iniciada.
     *
     * Valida todo en el servidor. Un DNI o email repetido da un mensaje genérico ("ya existe una cuenta"):
     * el DNI es lo que se usa para entrar, así que no hay forma de evitar del todo que se sepa si está
     * registrado; el límite por IP frena el barrido masivo.
     *
     * @return string|null null si salió bien, o el mensaje de error
     */
    public static function register(string $rawDni, string $fullName, string $email, string $password, string $confirm, string $ip): ?string
    {
        // Reserva atómica (ver attempt): en paralelo no se pueden colar más altas que el límite.
        $key = 'register:ip:' . RateLimiter::ipBucket($ip);
        if (RateLimiter::hit($key, (int) Config::get('security.login_window')) > (int) Config::get('security.register_max_attempts_ip')) {
            return self::ERROR_THROTTLED;
        }

        $dni = Dni::normalize($rawDni);
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
        $email = mb_strtolower(trim($email));

        if (!Dni::isValid($dni)) {
            return 'Ingresá un DNI de 7 u 8 números.';
        }
        if (!preg_match("/^[\p{L}\p{M}'.-]+( [\p{L}\p{M}'.-]+)+$/u", $fullName) || mb_strlen($fullName) > 120) {
            return 'Ingresá tu nombre y apellido.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return 'Ingresá un email válido.';
        }
        if ($error = Password::validate($password)) {
            return $error;
        }
        if (!hash_equals($password, $confirm)) {
            return 'Las contraseñas no coinciden.';
        }

        try {
            $user = User::create($dni, $fullName, $email, Password::hash($password));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'Ya existe una cuenta con ese DNI o email.';
            }
            throw $e;
        }
        self::startSession($user);
        Audit::log('register', $user->id);
        return null;
    }

    /**
     * Pide recuperar la contraseña: si el email tiene cuenta, genera un token de un solo uso (se guarda
     * hasheado, vence en auth.reset_ttl) y manda el enlace. El resultado hacia el usuario es siempre el mismo,
     * exista o no la cuenta. Devuelve false solo si se superó el límite por IP.
     */
    public static function requestReset(string $email, string $ip): bool
    {
        // Reserva atómica (ver attempt): en paralelo no se pueden mandar más mails que el límite.
        $key = 'reset:ip:' . RateLimiter::ipBucket($ip);
        if (RateLimiter::hit($key, (int) Config::get('security.login_window')) > (int) Config::get('security.reset_max_attempts_ip')) {
            return false;
        }

        $user = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? User::findByEmail(trim($email)) : null;
        if ($user === null || $user->email === '') {
            return true;
        }
        $token = bin2hex(random_bytes(32));
        Database::execute(
            'INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
            [hash('sha256', $token), $user->id, date('Y-m-d H:i:s', time() + (int) Config::get('auth.reset_ttl'))]
        );
        Mailer::send($user->email, 'Recuperá tu contraseña · Barbería',
            "Hola {$user->name},\n\nPara elegir una contraseña nueva entrá a este enlace (vale 1 hora y se usa una sola vez):\n\n"
            . rtrim((string) Config::get('url'), '/') . '/restablecer?token=' . $token
            . "\n\nSi no lo pediste vos, ignorá este mensaje.");
        Audit::log('password_reset_request', $user->id);
        return true;
    }

    /** ¿El token de recuperación existe y no venció? */
    public static function resetTokenValid(string $token): bool
    {
        return self::resetUserId($token) !== null;
    }

    /** Id del usuario dueño de un token vigente, o null. */
    private static function resetUserId(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $r = Database::selectOne(
            'SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > ?',
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
        return $r ? (int) $r['user_id'] : null;
    }

    /**
     * Cambia la contraseña con un token de recuperación: valida, guarda el hash nuevo, borra los tokens del usuario
     * y cierra todas sus sesiones abiertas.
     * @return string|null null si salió bien, o el mensaje de error
     */
    public static function resetPassword(string $token, string $password, string $confirm): ?string
    {
        $userId = self::resetUserId($token);
        if ($userId === null) {
            return self::ERROR_RESET_INVALID;
        }
        if ($error = Password::validate($password)) {
            return $error;
        }
        if (!hash_equals($password, $confirm)) {
            return 'Las contraseñas no coinciden.';
        }
        Database::transaction(function () use ($userId, $password) {
            User::setPassword($userId, Password::hash($password));
            Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            DbSessionHandler::revokeUser($userId);
        });
        Audit::log('password_reset', $userId);
        return null;
    }

    /** Cierra la sesión actual. */
    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Audit::log('logout', $user->id);
        }
        Session::destroy();
    }

    /** Cierra la sesión en todos los dispositivos (borra todas las sesiones del usuario) y la actual. */
    public static function logoutEverywhere(User $user): void
    {
        if (Database::enabled()) {
            DbSessionHandler::revokeUser($user->id);
        }
        Audit::log('logout_all', $user->id);
        Session::destroy();
    }
}
