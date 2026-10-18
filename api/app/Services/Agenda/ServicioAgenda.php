<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Enums\EstadoCita;
use App\Models\AntecedenteSalud;
use App\Models\Cita;
use App\Models\Clienta;
use App\Models\Profesional;
use App\Models\Servicio;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use DomainException;

/**
 * Agenda: agendar una cita con todas las reglas juntas.
 *
 * EL ORDEN DE LAS COMPROBACIONES
 * ------------------------------
 * 1. Servicio, profesional y clienta existen, y la profesional hace el servicio.
 * 2. Antecedentes de salud. Si hay un obstáculo, se responde con el detalle.
 *    Va PRIMERO, antes que la disponibilidad: si a esta persona no se le puede
 *    hacer ese servicio, no tiene sentido preguntarle si la silla está libre.
 * 3. Disponibilidad: contra otras citas y contra los bloqueos de agenda.
 * 4. Se calcula `fecha_fin` con la duración del servicio y se escribe.
 *
 * LA DURACIÓN NO ES UN PARÁMETRO DE LA CITA
 * -----------------------------------------
 * Sale del servicio y de la profesional. Es la respuesta a que un corte de pelo
 * y un balayage no son lo mismo, y a que la agenda se llena por tiempo de
 * servicio y no por un bloque fijo.
 *
 * LO QUE NO SE COMPRUEBA, Y POR QUÉ
 * ---------------------------------
 * El tiempo de espera entre dos procesos químicos sobre el mismo pelo. Es
 * verdad que existe, pero NO se pudo verificar cuántos días exige cada
 * combinación, así que el software no lo inventa. Lo que sí hace es avisar de
 * los antecedentes registrados. Ver docs/02.
 */
class ServicioAgenda
{
    public function __construct(
        private readonly VerificadorDeDisponibilidad $disponibilidad,
        private readonly RevisorDeAntecedentes $antecedentes,
    ) {}

    /**
     * Agenda una cita.
     *
     * @param  array{ profesional: Profesional, clienta: Clienta, servicio: Servicio, fecha_inicio: DateTimeInterface|string, motivo?: string|null, notas?: string|null }  $datos
     *
     * @throws DomainException si la profesional no hace el servicio, si hay un
     *                          antecedente bloqueante o si hay choque de agenda.
     */
    public function agendar(array $datos): Cita
    {
        /** @var Profesional $profesional */
        $profesional = $datos['profesional'];
        /** @var Clienta $clienta */
        $clienta = $datos['clienta'];
        /** @var Servicio $servicio */
        $servicio = $datos['servicio'];

        $inicio = $datos['fecha_inicio'] instanceof DateTimeInterface
            ? Carbon::instance($datos['fecha_inicio'])
            : Carbon::parse($datos['fecha_inicio']);

        // 1. La profesional tiene que hacer el servicio. Agendar un corte en la
        //    silla de quien solo hace color no es un error de la base: es un
        //    error del salon, y se detecta acá.
        $this->exigirQueHagaElServicio($profesional, $servicio);

        // 2. Antecedentes de salud. Va antes que la disponibilidad por una
        //    razón concreta: cambiar de horario NO resuelve una contraindicación,
        //    y hacer que la recepcionista busque otro horario es hacerle perder
        //    el tiempo.
        $obstaculos = $this->antecedentes->obstaculosDe($clienta, $servicio);

        if ($obstaclos !== []) {
            throw new DomainException($this->antecedentes->mensajeDeBloqueo($obstaclos));
        }

        // 3. La duración sale del servicio PARA ESTA profesional. Puede venir
        //    de `servicios_profesional` o del catálogo.
        $duracion = $profesional->duracionDe($servicio);
        $fin = $inicio->copy()->addMinutes($duracion);

        $conflictos = $this->disponibilidad->conflictos($profesional, $inicio, $fin);

        if ($conflictos !== []) {
            throw new DomainException(implode(' ', $conflictos));
        }

        // 4. Escritura. La restricción de la base vuelve a comprobar el
        //    solapamiento entre citas: si dos recepcionistas guardan a la vez,
        //    una de las dos falla y no se pierde ninguna.
        return Cita::query()->create([
            'tenant_id' => (string) $clienta->tenant_id,
            'profesional_id' => $profesional->id,
            'clienta_id' => $clienta->id,
            'servicio_id' => $servicio->id,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'duracion_minutos' => $duracion,
            'estado' => EstadoCita::Agendada->value,
            'silla' => $profesional->silla,
            'motivo' => $datos['motivo'] ?? null,
            'notas' => $datos['notas'] ?? null,
        ]);
    }

    /**
     * Mueve una cita de hora.
     *
     * Vuelve a comprobar disponibilidad con el mismo criterio que al agendar, y
     * excluye la cita que se está moviendo para que no choque consigo misma.
     */
    public function mover(Cita $cita, DateTimeInterface|string $nuevoInicio): Cita
    {
        $inicio = $nuevoInicio instanceof DateTimeInterface
            ? Carbon::instance($nuevoInicio)
            : Carbon::parse($nuevoInicio);

        $profesional = $cita->profesional;

        if ($profesional === null) {
            throw new DomainException('La cita no tiene profesional asociada.');
        }

        // Al mover se conserva la duración que la cita ya tenía. Si el catálogo
        // cambió, mover no es el momento de redibujar el pasado.
        $fin = $inicio->copy()->addMinutes((int) $cita->duracion_minutos);

        $conflictos = $this->disponibilidad->conflictos($profesional, $inicio, $fin, (string) $cita->id);

        if ($conflictos !== []) {
            throw new DomainException(implode(' ', $conflictos));
        }

        $cita->fecha_inicio = $inicio;
        $cita->fecha_fin = $fin;
        $cita->save();

        return $cita;
    }

    /**
     * La profesional tiene que estar habilitada para ese servicio.
     */
    private function exigirQueHagaElServicio(Profesional $profesional, Servicio $servicio): void
    {
        $loHace = $profesional->servicios()
            ->where('servicios.id', $servicio->id)
            ->where(function ($q): void {
                $q->whereNull('servicios_profesional.activo')
                    ->orWhere('servicios_profesional.activo', true);
            })
            ->exists();

        if (! $loHace) {
            throw new DomainException(sprintf(
                '%s no está habilitada para el servicio "%s".',
                $profesional->nombre,
                $servicio->nombre,
            ));
        }
    }

    /**
     * Antecedentes que avisar antes de la cita, sin bloquear.
     *
     * Se usan para pintar el aviso en la agenda. Un antecedente que bloquea va
     * en `obstaculosDe()`, y esa comprobación es la que impide agendar.
     *
     * @return list<AntecedenteSalud>
     */
    public function avisosDe(Clienta $clienta, Servicio $servicio): array
    {
        return $this->antecedentes->avisosDe($clienta, $servicio);
    }
}