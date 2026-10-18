<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de antecedente de salud de una clienta.
 *
 * `Alergia` y `Contraindicacion` no son sinónimos: una es una reacción a una
 * sustancia concreta, la otra es una razón por la que ese procedimiento no se
 * puede hacer. Se guardan separadas porque la primera se previene evitando la
 * sustancia y la segunda evitando el procedimiento.
 */
enum TipoAntecedente: string
{
    case Alergia = 'alergia';
    case Contraindicacion = 'contraindicacion';
    case Condicion = 'condicion';
    case Sensibilidad = 'sensibilidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Alergia => 'Alergia',
            self::Contraindicacion => 'Contraindicación',
            self::Condicion => 'Condición de salud',
            self::Sensibilidad => 'Sensibilidad',
        };
    }
}