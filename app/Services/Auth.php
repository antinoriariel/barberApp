<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\DbSessionHandler;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Models\Registration;
use App\Models\Superuser;
use App\Models\User;
use App\Support\Dni;
use App\Support\Password;
use App\Support\Phone;

/**
 * Autenticación por DNI + contraseña y alta de cuentas (con celular argentino, una por navegador). La contraseña
 * olvidada no se recupera por la app: "Olvidé mi contraseña" abre un WhatsApp de soporte (supportWhatsappUrl).
 *
 * - Un único mensaje de error en el login (ERROR_CREDENTIALS) para DNI inválido, inexistente o contraseña
 *   incorrecta, y se verifica un hash falso cuando el DNI no existe, así no se enumeran cuentas ni por el
 *   texto ni por el tiempo de respuesta.
 * - Los intentos fallidos se limitan por IP y por DNI con RateLimiter.
 * - Todo evento relevante queda en Audit.
 * - Con driver files (maqueta) solo se puede iniciar sesión (con la contraseña de demostración);
 *   el alta requiere base de datos.
 */
final class Auth
{
    public const ERROR_CREDENTIALS = 'DNI o contraseña incorrectos.';
    public const ERROR_THROTTLED = 'Demasiados intentos. Esperá unos minutos y probá de nuevo.';
    /** Comienzo de todo mensaje de bloqueo (el del login agrega el tiempo de espera); el controlador lo usa para responder 429. */
    public const THROTTLED_PREFIX = 'Demasiados intentos.';

    /** Segundos de espera del último bloqueo de login (para la cabecera Retry-After); 0 si no hubo bloqueo. */
    private static int $retryAfter = 0;

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
        if (!$user?->active) {
            return null;
        }
        Device::touch($user, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $user;
    }

    /**
     * ¿La sesión actual es del superusuario? Además de la marca en la sesión exige que:
     *  - la contraseña no haya cambiado desde que entró (session_version, ver Superuser);
     *  - no haya pasado más de security.superuser_idle segundos sin actividad (la sesión de superusuario dura poco,
     *    aunque la cookie viva 30 días como la del resto).
     * Si algo no se cumple, borra la marca: hay que volver a entrar.
     */
    public static function isSuperuser(): bool
    {
        $version = Session::get('su');
        if (!is_int($version)) {
            return false;
        }
        $last = (int) Session::get('su_seen');
        if ($version !== Superuser::sessionVersion() || $last < time() - (int) Config::get('security.superuser_idle')) {
            Session::remove('su');
            Session::remove('su_seen');
            return false;
        }
        Session::set('su_seen', time());
        return true;
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
     * Si en el campo DNI se escribe "superuser" (Superuser::matches) se verifica contra la contraseña del superusuario,
     * con los mismos límites (la clave del contador es "login:dni:superuser") y el mismo mensaje de error; si sale
     * bien la sesión queda marcada como de superusuario (startSuperSession) en vez de asociarse a una cuenta.
     *
     * @return string|null null si salió bien, o el mensaje de error para mostrar
     */
    public static function attempt(string $rawDni, string $password, string $ip): ?string
    {
        $isSu = Superuser::matches($rawDni);
        $dni = $isSu ? Superuser::LOGIN : Dni::normalize(substr($rawDni, 0, 32));
        $window = (int) Config::get('security.login_window');
        $ipKey = 'login:ip:' . RateLimiter::ipBucket($ip);
        $dniKey = 'login:dni:' . $dni;
        $validDni = $isSu || Dni::isValid($dni);
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
        $hash = $usable ? ($isSu ? Superuser::passwordHash() : User::passwordHash($dni)) : null;
        $ok = Password::verify($usable ? $password : '', $hash ?? self::dummyHash()) && $hash !== null;

        if ($isSu && $ok) {
            RateLimiter::release($ipKey);
            RateLimiter::clear($dniKey);
            self::startSuperSession();
            Audit::log('su_login_ok');
            return null;
        }
        $user = $ok && !$isSu ? User::find($dni) : null;

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

    /**
     * Sesión de superusuario: id nuevo (anti-fijación), CSRF nuevo, sin cuenta asociada (no hay dni ni user_id) y con
     * la versión vigente de la contraseña (ver isSuperuser).
     */
    private static function startSuperSession(): void
    {
        Session::regenerate();
        Session::remove('csrf');
        Session::remove('dni');
        Session::set('su', Superuser::sessionVersion());
        Session::set('su_seen', time());
        DbSessionHandler::bindUser(null);
    }

    /** Hash falso (calculado una vez por petición) contra el que se verifica cuando el DNI no existe. */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Password::hash('contraseña-de-relleno');
    }

    /** Mensaje cuando el navegador ya creó una cuenta (ver register). */
    public const ERROR_DEVICE_USED = 'Desde este navegador ya se creó una cuenta. Ingresá con tu DNI y contraseña.';

    /**
     * Crea una cuenta nueva y deja la sesión iniciada.
     *
     * Valida todo en el servidor:
     *  - DNI, nombre y apellido, y un celular argentino (Phone::normalize: se guarda siempre igual, así el índice
     *    único de users.phone impide que dos cuentas tengan el mismo número aunque lo escriban distinto);
     *  - una sola cuenta por navegador: el navegador tiene que traer su cookie (Device::known; sin ella cada pedido
     *    sería un navegador "nuevo") y no haber creado otra cuenta (Registration::deviceUsed). El alta se anota en
     *    device_registrations en la misma transacción que crea la cuenta; su índice único frena dos altas en paralelo;
     *  - como mucho security.register_max_ip_day altas por IP cada 24 h (si borran la cookie o usan incógnito, la IP
     *    sigue siendo la misma).
     * Los rechazos por navegador o IP quedan auditados (register_blocked_device / register_blocked_ip) para el
     * superusuario. Un DNI o teléfono repetido da un mensaje genérico ("ya existe una cuenta"): el DNI es lo que se usa
     * para entrar, así que no hay forma de evitar del todo que se sepa si está registrado; el límite por IP frena el
     * barrido masivo.
     *
     * @return string|null null si salió bien, o el mensaje de error
     */
    public static function register(string $rawDni, string $fullName, string $rawPhone, string $password, string $confirm, string $ip): ?string
    {
        // Reserva atómica (ver attempt): en paralelo no se pueden colar más intentos que el límite.
        $key = 'register:ip:' . RateLimiter::ipBucket($ip);
        if (RateLimiter::hit($key, (int) Config::get('security.login_window')) > (int) Config::get('security.register_max_attempts_ip')) {
            return self::ERROR_THROTTLED;
        }

        $dni = Dni::normalize($rawDni);
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
        $phone = Phone::normalize($rawPhone);

        if (!Dni::isValid($dni)) {
            return 'Ingresá un DNI de 7 u 8 números.';
        }
        if (!preg_match("/^[\p{L}\p{M}'.-]+( [\p{L}\p{M}'.-]+)+$/u", $fullName) || mb_strlen($fullName) > 120) {
            return 'Ingresá tu nombre y apellido.';
        }
        if ($phone === null) {
            return Phone::ERROR;
        }
        if ($error = Password::validate($password)) {
            return $error;
        }
        if (!hash_equals($password, $confirm)) {
            return 'Las contraseñas no coinciden.';
        }
        if (!Device::known()) {
            return 'Tu navegador no guardó la cookie de la app. Activá las cookies (o salí del modo incógnito) y volvé a intentar.';
        }
        $device = Device::hash();
        if (Registration::deviceUsed($device)) {
            Audit::log('register_blocked_device', null, 'DNI ' . $dni . ' · navegador ' . substr($device, 0, 12));
            return self::ERROR_DEVICE_USED;
        }
        if (Registration::countFromIp($ip) >= (int) Config::get('security.register_max_ip_day')) {
            Audit::log('register_blocked_ip', null, 'DNI ' . $dni);
            return 'Desde esta conexión ya se crearon varias cuentas hoy. Probá más tarde o pedí ayuda por WhatsApp.';
        }

        try {
            $user = Database::transaction(function () use ($dni, $fullName, $phone, $password, $device, $ip) {
                $user = User::create($dni, $fullName, $phone, Password::hash($password));
                Registration::record($device, $user->id, $dni, $fullName, $phone, $ip, Device::userAgent());
                return $user;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            if (Registration::deviceUsed($device)) {
                return self::ERROR_DEVICE_USED; // otra alta desde el mismo navegador ganó la carrera
            }
            return 'Ya existe una cuenta con ese DNI o ese teléfono.';
        }
        self::startSession($user);
        Audit::log('register', $user->id, 'navegador ' . substr($device, 0, 12));
        return null;
    }

    /**
     * ¿Este navegador todavía puede crear una cuenta? (no creó ninguna o el superusuario la liberó). Sin base de datos
     * no hay registro, así que da false.
     */
    public static function canRegisterHere(): bool
    {
        return Database::enabled() && !Registration::deviceUsed(Device::hash());
    }

    /**
     * Enlace de WhatsApp de soporte para "Olvidé mi contraseña": el número y el mensaje salen de config (support.*).
     * La contraseña no se recupera sola: la cambia el administrador (o el superusuario) desde el panel.
     */
    public static function supportWhatsappUrl(): string
    {
        return 'https://wa.me/' . preg_replace('/\D+/', '', (string) Config::get('support.whatsapp'))
            . '?text=' . rawurlencode((string) Config::get('support.forgot_message'));
    }

    /** Cierra la sesión actual. */
    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Audit::log('logout', $user->id);
        } elseif (self::isSuperuser()) {
            Audit::log('su_logout');
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
