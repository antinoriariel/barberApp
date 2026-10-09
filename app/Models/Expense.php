<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Egresos del local (solo con base de datos): alquiler, insumos, sueldos, etc. */
final class Expense
{
    /** Categorías permitidas (las que ofrece el formulario). */
    public const CATEGORIES = ['Alquiler', 'Insumos', 'Sueldos', 'Servicios', 'Marketing', 'Otros'];

    /**
     * Valida los datos del formulario de alta: fecha real, categoría de la lista, monto entero positivo,
     * concepto (description, máx. 120) y observación libre (notes, opcional, máx. 255).
     * @return array{0: ?array{spent_on: string, category: string, description: string, notes: string, amount: int}, 1: ?string} [datos, error]
     */
    public static function validate(array $in): array
    {
        $date = is_string($in['spent_on'] ?? null) ? $in['spent_on'] : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return [null, 'La fecha no es válida.'];
        }
        $category = (string) ($in['category'] ?? '');
        if (!in_array($category, self::CATEGORIES, true)) {
            return [null, 'Elegí una categoría.'];
        }
        // Acepta el monto con puntos de miles ("15.000", así lo formatea money-input.js) o sólo dígitos.
        $raw = trim((string) ($in['amount'] ?? ''));
        $raw = preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) ? str_replace('.', '', $raw) : $raw;
        $amount = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]);
        if ($amount === false) {
            return [null, 'El monto debe ser un número entero mayor a 0.'];
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) > 120) {
            return [null, 'El concepto es muy largo (máx. 120 caracteres).'];
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 255) {
            return [null, 'La observación es muy larga (máx. 255 caracteres).'];
        }
        return [['spent_on' => $date, 'category' => $category, 'description' => $description, 'notes' => $notes, 'amount' => $amount], null];
    }

    /** Registra un egreso y devuelve su id. */
    public static function create(array $d, ?int $userId): int
    {
        Database::execute(
            'INSERT INTO expenses (spent_on, category, description, notes, amount, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$d['spent_on'], $d['category'], $d['description'], $d['notes'] ?? '', $d['amount'], $userId]
        );
        return Database::lastId();
    }

    /** Borra un egreso; false si no existía. */
    public static function delete(int $id): bool
    {
        return Database::execute('DELETE FROM expenses WHERE id = ?', [$id]) === 1;
    }

    /**
     * Egresos de un mes "YYYY-MM", del más nuevo al más viejo.
     * @return array<int, array{id: int, spent_on: string, category: string, description: string, notes: string, amount: int}>
     */
    public static function forMonth(string $month): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'spent_on' => $r['spent_on'], 'category' => $r['category'],
            'description' => $r['description'], 'notes' => $r['notes'], 'amount' => (int) $r['amount'],
        ], Database::select(
            'SELECT id, spent_on, category, description, notes, amount FROM expenses
              WHERE spent_on >= ? AND spent_on < ? ORDER BY spent_on DESC, id DESC',
            [$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]
        ));
    }

    /** Total de egresos de un día "YYYY-MM-DD" (0 si no hubo); se resta de los ingresos del día en el resumen. */
    public static function totalForDay(string $day): int
    {
        return (int) (Database::select('SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE spent_on = ?', [$day])[0]['total'] ?? 0);
    }

    /**
     * Total de egresos por mes, desde `$first` ("YYYY-MM-01") hasta el fin del mes `$last` ("YYYY-MM").
     * @return array<string, int> "YYYY-MM" => total (los meses sin egresos no aparecen)
     */
    public static function totalsByMonth(string $first, string $last): array
    {
        $rows = Database::select(
            "SELECT DATE_FORMAT(spent_on, '%Y-%m') AS m, SUM(amount) AS total FROM expenses
              WHERE spent_on >= ? AND spent_on < ? GROUP BY m",
            [$first, date('Y-m-d', strtotime($last . '-01 +1 month'))]
        );
        return array_map('intval', array_column($rows, 'total', 'm'));
    }
}
