<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una liquidación de comisión.
 *
 * `Cerrada` es la que congela el desglose. Editar una liquidación cerrada en
 * silencio sería una cifra que cambia sola, que es justo lo que el dueño tiene
 * que poder detectar.
 */
enum EstadoLiquidacion: string
{
    case Borrador = 'borrador';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Cerrada => 'Cerrada',
            self::Anulada => 'Anulada',
        };
    }

    /** El desglose ya no se toca. */
    public function congelada(): bool
    {
        return $this !== self::Borrador;
    }
}