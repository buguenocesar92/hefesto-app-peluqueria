<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severidad de un antecedente de salud.
 *
 * LA PONE LA PROFESIONAL, NO EL SISTEMA
 * -------------------------------------
 * El software no sabe si una alergia es grave. Eso lo sabe quien la va a ver
 * todos los días. Por eso la severidad se registra, no se calcula, y por eso
 * recepción puede registrar lo que la clienta informa sin decidir cuánto pesa.
 */
enum SeveridadAntecedente: string
{
    case Leve = 'leve';
    case Moderada = 'moderada';
    case Grave = 'grave';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Leve => 'Leve',
            self::Moderada => 'Moderada',
            self::Grave => 'Grave',
        };
    }

    /**
     * Una alergia grave tiene que verse antes de que la clienta se siente.
     *
     * Por eso la agenda la muestra pegada a la cita y no en una pantalla aparte.
     */
    public function requiereAvisoEnAgenda(): bool
    {
        return $this === self::Grave;
    }
}