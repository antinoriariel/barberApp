<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Ingresos extra del local (solo con base de datos): plata que entra por fuera de los turnos atendidos,
 * como un corte cobrado sin reserva. Se suman a los ingresos de los turnos en el resultado del día y del mes.
 */
final class Income
{
    /** Categorías permitidas (las que ofrece el formulario). */
    public const CATEGORIES = ['Corte extra', 'Otros'];

    /**
     * Valida los datos del formulario de alta: fecha real, categoría de la lista, monto entero positivo
     * (acepta puntos de miles, "15.000") y observación libre (notes, opcional, máx. 255).
     * @return array{0: ?array{received_on: string, category: string, notes: string, amount: int}, 1: ?string} [datos, error]
     */
    public static function validate(array $in): array
    {
        $date = is_string($in['received_on'] ?? null) ? $in['received_on'] : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return [null, 'La fecha no es válida.'];
        }
        $category = is_string($in['category'] ?? null) ? $in['category'] : '';
        if (!in_array($category, self::CATEGORIES, true)) {
            return [null, 'Elegí una categoría.'];
        }
        $raw = is_string($in['amount'] ?? null) ? trim($in['amount']) : '';
        $raw = preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) ? str_replace('.', '', $raw) : $raw;
        $amount = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]);
        if ($amount === false) {
            return [null, 'El monto debe ser un número entero mayor a 0.'];
        }
        $notes = is_string($in['notes'] ?? null) ? trim($in['notes']) : '';
        if (mb_strlen($notes) > 255) {
            return [null, 'La observación es muy larga (máx. 255 caracteres).'];
        }
        return [['received_on' => $date, 'category' => $category, 'notes' => $notes, 'amount' => $amount], null];
    }

    /** Registra un ingreso extra y devuelve su id. */
    public static function create(array $d, ?int $userId): int
    {
        Database::execute(
            'INSERT INTO incomes (received_on, category, notes, amount, created_by) VALUES (?, ?, ?, ?, ?)',
            [$d['received_on'], $d['category'], $d['notes'] ?? '', $d['amount'], $userId]
        );
        return Database::lastId();
    }

    /** Borra un ingreso extra; false si no existía. */
    public static function delete(int $id): bool
    {
        return Database::execute('DELETE FROM incomes WHERE id = ?', [$id]) === 1;
    }

    /**
     * Ingresos extra de un mes "YYYY-MM", del más nuevo al más viejo.
     * @return array<int, array{id: int, received_on: string, category: string, notes: string, amount: int}>
     */
    public static function forMonth(string $month): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'received_on' => $r['received_on'], 'category' => $r['category'],
            'notes' => $r['notes'], 'amount' => (int) $r['amount'],
        ], Database::select(
            'SELECT id, received_on, category, notes, amount FROM incomes
              WHERE received_on >= ? AND received_on < ? ORDER BY received_on DESC, id DESC',
            [$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]
        ));
    }

    /** Total de ingresos extra de un día "YYYY-MM-DD" (0 si no hubo); se suma al resultado del día en el resumen. */
    public static function totalForDay(string $day): int
    {
        return (int) (Database::select('SELECT COALESCE(SUM(amount), 0) AS total FROM incomes WHERE received_on = ?', [$day])[0]['total'] ?? 0);
    }

    /**
     * Total de ingresos extra por mes, desde `$first` ("YYYY-MM-01") hasta el fin del mes `$last` ("YYYY-MM").
     * @return array<string, int> "YYYY-MM" => total (los meses sin ingresos extra no aparecen)
     */
    public static function totalsByMonth(string $first, string $last): array
    {
        $rows = Database::select(
            "SELECT DATE_FORMAT(received_on, '%Y-%m') AS m, SUM(amount) AS total FROM incomes
              WHERE received_on >= ? AND received_on < ? GROUP BY m",
            [$first, date('Y-m-d', strtotime($last . '-01 +1 month'))]
        );
        return array_map('intval', array_column($rows, 'total', 'm'));
    }
}
