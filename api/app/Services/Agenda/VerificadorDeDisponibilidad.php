<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Enums\EstadoCita;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Profesional;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Comprobación de disponibilidad de una profesional.
 *
 * POR QUÉ EXISTE, SI LA BASE YA LO PROHÍBE
 * -----------------------------------------
 * En `citas` hay una restricción `EXCLUDE USING gist` que impide el solapamiento
 * entre dos citas de la misma profesional. Esa restricción es la garantía real y
 * no se puede esquivar.
 *
 * Esta clase existe para el otro caso: las citas y los bloqueos de agenda viven
 * en tablas distintas, y PostgreSQL NO puede escribir una exclusión entre dos
 * tablas. Un bloqueo de colación y una cita pueden pisarse sin que ninguna
 * restricción lo note, así que hay que mirarlo antes.
 *
 * Esa es exactamente la parte que puede tener un error, y por eso hay una
 * prueba dedicada. Ver tests/Feature/Agenda/BloqueosNoSePisanTest.
 *
 * QUÉ DEVUELVE
 * ------------
 * No un booleano: las líneas del conflicto. Un "no se puede" sin explicación
 * hace que la recepcionista pruebe otros horarios a ciegas, que es justo lo que
 * atrasa la agenda.
 */
class VerificadorDeDisponibilidad
{
    /**
     * Choques de una cita que se quiere agendar.
     *
     * El criterio de solapamiento es `inicio < fin_buscado AND fin > inicio_buscado`.
     * Es el intervalo semiabierto `[inicio, fin)`: una cita que termina a las
     * 11:00 NO se pisa con otra que empieza a las 11:00. Sin ese detalle, un
     * día entero queda bloqueado por la mañana.
     *
     * @return list<string> líneas con el motivo del conflicto
     */
    public function conflictos(
        Profesional $profesional,
        Carbon $inicio,
        Carbon $fin,
        ?string $citaIdAModificar = null,
    ): array {
        $estadosQueOcupan = array_map(
            static fn (EstadoCita $estado): string => $estado->value,
            array_filter(
                EstadoCita::cases(),
                static fn (EstadoCita $estado): bool => $estado->ocupaAgenda(),
            ),
        );

        $choquesCita = Cita::query()
            ->where('profesional_id', $profesional->id)
            ->whereIn('estado', $estadosQueOcupan)
            ->where('fecha_inicio', '<', $fin)
            ->where('fecha_fin', '>', $inicio)
            ->when($citaIdAModificar !== null, static fn ($q) => $q->whereKeyNot($citaIdAModificar))
            ->with('clienta')
            ->orderBy('fecha_inicio')
            ->get();

        $lineas = [];

        foreach ($choquesCita as $cita) {
            $lineas[] = sprintf(
                'La profesional ya tiene una cita de %s a %s (%s).',
                $cita->fecha_inicio->format('d/m/Y H:i'),
                $cita->fecha_fin->format('H:i'),
                $cita->clienta?->nombreCompleto() ?? 'clienta sin nombre',
            );
        }

        $choquesBloqueo = $this->bloqueosQueChocan($profesional, $inicio, $fin);

        foreach ($choquesBloqueo as $bloqueo) {
            $lineas[] = sprintf(
                'La profesional tiene un bloqueo de %s a %s (%s).',
                $bloqueo->fecha_inicio->format('d/m/Y H:i'),
                $bloqueo->fecha_fin->format('H:i'),
                $bloqueo->motivo,
            );
        }

        return $lineas;
    }

    /**
     * ¿Se puede agendar sin chocar?
     */
    public function estaLibre(Profesional $profesional, Carbon $inicio, Carbon $fin, ?string $citaIdAModificar = null): bool
    {
        return $this->conflictos($profesional, $inicio, $fin, $citaIdAModificar) === [];
    }

    /**
     * Bloqueos que se pisan con el tramo pedido.
     *
     * El `profesional_id IS NULL` está explícito y no se deja al azar: un
     * bloqueo de salón (feriado, corte de luz) tiene que detener a todas las
     * profesionales, y si se olvidara esa línea el sistema agenta en un día que
     * el salón entero está cerrado.
     *
     * @return Collection<int, BloqueoAgenda>
     */
    public function bloqueosQueChocan(Profesional $profesional, Carbon $inicio, Carbon $fin): Collection
    {
        return BloqueoAgenda::query()
            ->where(function ($q) use ($profesional): void {
                $q->where('profesional_id', $profesional->id)
                    ->orWhereNull('profesional_id');
            })
            ->where('fecha_inicio', '<', $fin)
            ->where('fecha_fin', '>', $inicio)
            ->orderBy('fecha_inicio')
            ->get();
    }
}