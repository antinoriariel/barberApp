<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/** Datos del local: nombre, dirección, horarios y teléfono. */
final class Shop
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $hours,
        public readonly string $phone,
    ) {
    }

    /**
     * Franjas de atención por día de la semana (date('w'): 0 = domingo). Cada franja es [apertura, cierre] "HH:MM".
     * Tienen que coincidir con el texto de horarios de data/shop.php.
     */
    private const OPENING = [
        0 => [['15:00', '20:00']],
        1 => [['10:00', '13:00'], ['17:00', '21:30']],
        2 => [['10:00', '13:00'], ['17:00', '21:30']],
        3 => [['10:00', '21:00']],
        4 => [['10:00', '13:00'], ['17:00', '21:30']],
        5 => [['10:00', '22:00']],
        6 => [['10:00', '22:00']],
    ];

    /**
     * ¿Está abierto ahora? Compara la hora con las franjas del día (el cierre es exclusivo).
     * @return array{open: bool, label: string, until: string} label: "Abierto hasta las 13" / "Abierto hasta las 21:30" /
     *         "Cerrado"; until: hora de cierre de la franja actual ("13", "21:30") o '' si está cerrado (la cabecera la
     *         muestra en un segundo renglón para no ocupar tanto ancho)
     */
    public static function status(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $time = $now->format('H:i');
        foreach (self::OPENING[(int) $now->format('w')] as [$from, $to]) {
            if ($time >= $from && $time < $to) {
                $hour = substr($to, 3) === '00' ? (string) (int) substr($to, 0, 2) : $to;
                return ['open' => true, 'label' => 'Abierto hasta las ' . $hour, 'until' => $hour];
            }
        }
        return ['open' => false, 'label' => 'Cerrado', 'until' => ''];
    }

    /** Datos del único local de la app (tabla `shop`, una sola fila; o data/shop.php con driver files). */
    public static function get(): self
    {
        $d = Database::enabled()
            ? (Database::selectOne('SELECT name, address, hours, phone FROM shop LIMIT 1')
                ?? throw new \RuntimeException('Falta la fila de `shop`: corré bin/seed.php.'))
            : Data::get('shop');
        return new self($d['name'], $d['address'], $d['hours'], $d['phone']);
    }
}
