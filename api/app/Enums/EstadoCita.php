<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una cita de la agenda.
 *
 * `EnCurso` y `Terminada` son los dos finales reales: la clienta pasó por la
 * silla o no pasó. Entre medias está `Confirmada`, que es la que obliga a
 * mirar los antecedentes de salud antes del día.
 */
enum EstadoCita: string
{
    case Agendada = 'agendada';
    case Confirmada = 'confirmada';
    case EnCurso = 'en_curso';
    case Atendida = 'atendida';
    case NoAsistio = 'no_asistio';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agendada => 'Agendada',
            self::Confirmada => 'Confirmada',
            self::EnCurso => 'En curso',
            self::Atendida => 'Atendida',
            self::NoAsistio => 'No asistió',
            self::Anulada => 'Anulada',
        };
    }

    /**
     * La cita ocupa la agenda y no se puede agendar otra en ese mismo tramo.
     *
     * La lista de estados que ocupan coincide EXACTAMENTE con el filtro de la
     * restricción `citas_sin_solapamiento` de la base. Si se agrega un estado
     * acá y no allá, la base y el software dejan de estar de acuerdo.
     */
    public function ocupaAgenda(): bool
    {
        return in_array($this, [self::Agendada, self::Confirmada, self::EnCurso], true);
    }

    /** La cita ya pasó y produjo un hecho: se puede cobrar y liquidar. */
    public function esHecho(): bool
    {
        return $this === self::Atendida;
    }
}