<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;

/**
 * Tarjeta de fidelidad: se pagan `goal - 1` cortes y el número `goal` es gratis (con goal = 7: 6 pagos + el 7° gratis).
 * Funciona por ciclos: `visits` es el total histórico de turnos atendidos y los sellos son `visits % goal`, así que
 * cuando el admin marca como atendido el corte gratis (la visita número `goal`) la tarjeta vuelve a quedar vacía.
 */
final class LoyaltyCard
{
    public function __construct(
        public readonly int $visits,
        public readonly int $goal,
    ) {
    }

    /** Arma la tarjeta de un cliente con la meta configurada en config/app.php (loyalty_goal). */
    public static function for(User $user): self
    {
        return new self($user->visits, (int) Config::get('loyalty_goal'));
    }

    /** Cortes pagos necesarios para ganar el gratis (la meta menos el casillero del premio). */
    public function paid(): int
    {
        return max(1, $this->goal - 1);
    }

    /** Sellos de la tarjeta actual: visitas desde el último corte gratis cobrado (0 si la meta no es válida). */
    public function stamps(): int
    {
        return $this->goal > 0 ? $this->visits % $this->goal : $this->visits;
    }

    /** Visitas que faltan para el corte gratis en la tarjeta actual (nunca negativo). */
    public function visitsLeft(): int
    {
        return max(0, $this->paid() - $this->stamps());
    }

    /** ¿Ya alcanzó la meta? */
    public function isComplete(): bool
    {
        return $this->visitsLeft() === 0;
    }

    /** Avance de la tarjeta actual de 0 a 100 (tope en 100; paid() nunca es 0, así que no divide por cero). */
    public function percent(): int
    {
        return (int) min(100, round($this->stamps() / $this->paid() * 100));
    }

    /** Título de la tarjeta: cambia cuando el corte gratis ya está ganado. */
    public function title(): string
    {
        return $this->isComplete() ? '¡Tu próximo corte es gratis!' : 'Tu séptimo corte es gratis';
    }

    /** Texto de estado según las visitas que faltan ("Te falta 1 visita", "Te faltan 3 visitas", "Meta cumplida"). */
    public function status(): string
    {
        $left = $this->visitsLeft();
        return match (true) {
            $left === 0 => 'Meta cumplida',
            $left === 1 => 'Te falta 1 visita',
            default     => "Te faltan {$left} visitas",
        };
    }
}
