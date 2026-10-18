<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agenda;

use App\Enums\EstadoCita;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgendarCitaRequest;
use App\Http\Requests\RegistrarRealidadCitaRequest;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Clienta;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Services\Agenda\ServicioAgenda;
use App\Services\Agenda\VerificadorDeDisponibilidad;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use DomainException;

/**
 * La agenda: citas, bloqueos y disponibilidad.
 *
 * POR QUÉ EL 404 Y NO EL 403
 * --------------------------
 * Cuando una Policy rechaza, el error sale como 404. Un 403 confirmaría que esa
 * cita existe, y en una agenda compartida eso ya es información. Ver
 * bootstrap/app.php y docs/03.
 *
 * LOS CONFLICTOS SE RESPONDEN CON 422 Y TEXTO
 * -------------------------------------------
 * "La profesional ya tiene una cita de 10:00 a 10:30" es un dato que la
 * recepcionista puede usar. Un 500 la deja probando horarios a ciegas, que es lo
 * que hace que un salón se atrase.
 */
class AgendaController extends Controller
{
    public function __construct(
        private readonly ServicioAgenda $agenda,
        private readonly VerificadorDeDisponibilidad $disponibilidad,
    ) {}

    /**
     * La agenda de un día, con los bloqueos y los avisos de salud pegados.
     *
     * Los avisos van juntos con la cita y no en una pantalla aparte: un aviso
     * aparte se lee una vez y después deja de leerse. Si la profesional no ve la
     * alergia antes de que la clienta se siente, el sistema es PEOR que una hoja
     * de papel.
     */
    public function show(Request $peticion): JsonResponse
    {
        $fecha = Carbon::parse((string) $peticion->query('fecha', now()->toDateString()))->startOfDay();

        $citas = Cita::query()
            ->with([
                'profesional:id,nombre,color_agenda,silla',
                'servicio:id,nombre,duracion_minutos',
                'clienta:id,nombre,apellido,rut',
            ])
            ->whereBetween('fecha_inicio', [$fecha, $fecha->copy()->endOfDay()])
            ->orderBy('fecha_inicio')
            ->get();

        $bloqueos = BloqueoAgenda::query()
            ->whereBetween('fecha_inicio', [$fecha, $fecha->copy()->endOfDay()])
            ->with('profesional:id,nombre')
            ->orderBy('fecha_inicio')
            ->get();

        $lineas = [];

        foreach ($citas as $cita) {
            // Los avisos solo se pueden calcular si la cita trae su clienta y su
            // servicio. Con RLS activa ambos existen siempre; el guard evita que
            // una relación nula rompa la pantalla con un error de tipo.
            $avisos = ($cita->clienta !== null && $cita->servicio !== null)
                ? $this->agenda->avisosDe($cita->clienta, $cita->servicio)
                : [];

            $lineas[] = [
                'id' => $cita->id,
                'fecha_inicio' => $cita->fecha_inicio->toIso8601String(),
                'fecha_fin' => $cita->fecha_fin->toIso8601String(),
                'duracion_minutos' => $cita->duracion_minutos,
                'estado' => $cita->estado->value,
                'estado_etiqueta' => $cita->estado->etiqueta(),
                'silla' => $cita->silla,
                'hora_inicio_real' => $cita->hora_inicio_real?->toIso8601String(),
                'hora_fin_real' => $cita->hora_fin_real?->toIso8601String(),
                'minutos_retraso' => $cita->minutos_retraso,
                'profesional' => $cita->profesional?->only(['id', 'nombre', 'color_agenda', 'silla']),
                'servicio' => $cita->servicio?->only(['id', 'nombre', 'duracion_minutos']),
                'clienta' => $cita->clienta?->only(['id', 'nombre', 'apellido', 'rut']),
                'avisos_salud' => $avisos,
                'requiere_revision' => $avisos !== [],
            ];
        }

        return response()->json([
            'fecha' => $fecha->toDateString(),
            'total' => count($lineas),
            'con_avisos' => count(array_filter($lineas, static fn (array $l): bool => $l['requiere_revision'])),
            'citas' => $lineas,
            'bloqueos' => $bloqueos->map(static fn (BloqueoAgenda $b): array => [
                'id' => $b->id,
                'fecha_inicio' => $b->fecha_inicio->toIso8601String(),
                'fecha_fin' => $b->fecha_fin->toIso8601String(),
                'motivo' => $b->motivo,
                'todo_el_salon' => $b->profesional_id === null,
                'profesional' => $b->profesional?->only(['id', 'nombre']),
            ])->values(),
            'espera_entre_procesos_comprobada' => false,
        ]);
    }

    /**
     * Agenda una cita.
     *
     * El `fecha_fin` lo calcula el servicio, no el cliente. Ver
     * `AgendarCitaRequest`.
     */
    public function store(AgendarCitaRequest $peticion): JsonResponse
    {
        $this->authorize('create', Cita::class);

        try {
            $cita = $this->agenda->agendar([
                'profesional' => Profesional::query()->findOrFail($peticion->validated('profesional_id')),
                'clienta' => Clienta::query()->findOrFail($peticion->validated('clienta_id')),
                'servicio' => Servicio::query()->findOrFail($peticion->validated('servicio_id')),
                'fecha_inicio' => $peticion->validated('fecha_inicio'),
                'motivo' => $peticion->validated('motivo'),
                'notas' => $peticion->validated('notas'),
            ]);
        } catch (DomainException $e) {
            // Un error del dominio no es una caída: es el sistema haciendo lo que
            // debía. 422 con el motivo que hay que leer.
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($this->aArreglo($cita), 201);
    }

    /** Una cita puntual. */
    public function cita(Cita $cita): JsonResponse
    {
        $this->authorize('view', $cita);

        return response()->json($this->aArreglo($cita));
    }

    /** Mueve una cita de hora, conservando su duración. */
    public function update(Request $peticion, Cita $cita): JsonResponse
    {
        $this->authorize('update', $cita);

        $datos = $peticion->validate([
            'fecha_inicio' => ['required', 'date'],
        ], [
            'fecha_inicio.required' => 'Hay que indicar la nueva hora de inicio.',
        ]);

        try {
            $this->agenda->mover($cita, $datos['fecha_inicio']);
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($this->aArreglo($cita->refresh()));
    }

    /**
     * Anula una cita.
     *
     * Anular no es borrar: la cita queda con estado `anulada` y sale de la
     * restricción de solapamiento, pero sigue en el registro.
     */
    public function destroy(Cita $cita): JsonResponse
    {
        $this->authorize('delete', $cita);

        $cita->estado = EstadoCita::Anulada;
        $cita->save();

        return response()->json(['mensaje' => 'Cita anulada.']);
    }

    /**
     * Registra cómo terminó de verdad, con el retraso que se produjo.
     *
     * `minutos_retraso` se calcula acá, no se recibe. Ver
     * `RegistrarRealidadCitaRequest`.
     */
    public function registrarRealidad(RegistrarRealidadCitaRequest $peticion, Cita $cita): JsonResponse
    {
        $this->authorize('registrarRealidad', $cita);

        $inicioReal = $peticion->validated('hora_inicio_real');
        $finReal = $peticion->validated('hora_fin_real');

        $cita->estado = EstadoCita::from($peticion->validated('estado'));

        $cita->registrarRealidad(
            $inicioReal instanceof DateTimeInterface ? Carbon::parse($inicioReal) : null,
            $finReal instanceof DateTimeInterface ? Carbon::parse($finReal) : null,
        );

        $cita->save();

        return response()->json($this->aArreglo($cita->refresh()));
    }

    /**
     * Las profesionales que se pueden elegir al agendar.
     *
     * POR QUÉ NO ES EL LISTADO DE ADMINISTRACIÓN
     * -------------------------------------------
     * `administracion/profesionales` cuelga del middleware `admin` y trae correo,
     * teléfono y registro profesional. Agendar solo necesita saber quién atiende:
     * nombre, silla, color y, sobre todo, la duración que le hace falta a cada
     * servicio para ESA profesional, porque de eso depende la hora de término.
     *
     * Poner un listado de agenda detrás del permiso de administrar haría que
     * recepción no pudiera agendar, que es justamente lo que hace. Y abrir el
     * listado de administración a recepción le daría datos que no necesita.
     */
    public function profesionales(): JsonResponse
    {
        $this->authorize('viewAny', Profesional::class);

        $profesionales = Profesional::query()
            ->with(['servicios:id,nombre,duracion_minutos,precio_base'])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'datos' => $profesionales->map(static fn (Profesional $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'especialidad' => $p->especialidad,
                'silla' => $p->silla,
                'color_agenda' => $p->color_agenda,
                'activo' => $p->activo,
                // La duración efectiva sale del puente: si la profesional tiene
                // una propia para ese servicio, manda esa. Ver
                // `Profesional::duracionDe()`.
                'servicios' => $p->servicios->map(static fn (Servicio $s): array => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'duracion_minutos' => $p->duracionDe($s),
                    'precio' => $p->precioDe($s),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Por qué no se puede agendar este tramo.
     *
     * La pantalla la consulta ANTES de intentar agendar, para que el motivo del
     * conflicto no sea un error que aparece después de apretar el botón.
     */
    public function disponibilidad(Request $peticion): JsonResponse
    {
        $peticion->validate([
            'profesional_id' => ['required', 'uuid', 'exists:profesionales,id'],
            'fecha_inicio' => ['required', 'date'],
            'servicio_id' => ['nullable', 'uuid', 'exists:servicios,id'],
            'cita_id' => ['nullable', 'uuid', 'exists:citas,id'],
        ], [
            'profesional_id.required' => 'Hay que indicar la profesional.',
            'fecha_inicio.required' => 'Hay que indicar la hora de inicio.',
        ]);

        $profesional = Profesional::query()->findOrFail($peticion->validated('profesional_id'));

        $inicio = Carbon::parse($peticion->validated('fecha_inicio'));

        $servicio = $peticion->filled('servicio_id')
            ? Servicio::query()->findOrFail($peticion->validated('servicio_id'))
            : null;

        $duracion = $servicio !== null ? $profesional->duracionDe($servicio) : 0;
        $fin = $inicio->copy()->addMinutes($duracion);

        $conflictos = $this->disponibilidad->conflictos(
            $profesional,
            $inicio,
            $fin,
            $peticion->validated('cita_id'),
        );

        return response()->json([
            'puede_agendar' => $conflictos === [],
            'conflictos' => $conflictos,
            'duracion_minutos' => $duracion,
            'fecha_fin_estimada' => $fin->toIso8601String(),
            'espera_entre_procesos_comprobada' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aArreglo(Cita $cita): array
    {
        return [
            'id' => $cita->id,
            'fecha_inicio' => $cita->fecha_inicio->toIso8601String(),
            'fecha_fin' => $cita->fecha_fin->toIso8601String(),
            'duracion_minutos' => $cita->duracion_minutos,
            'estado' => $cita->estado->value,
            'estado_etiqueta' => $cita->estado->etiqueta(),
            'silla' => $cita->silla,
            'hora_inicio_real' => $cita->hora_inicio_real?->toIso8601String(),
            'hora_fin_real' => $cita->hora_fin_real?->toIso8601String(),
            'minutos_retraso' => $cita->minutos_retraso,
            'motivo' => $cita->motivo,
            'profesional' => $cita->profesional?->only(['id', 'nombre', 'color_agenda', 'silla']),
            'servicio' => $cita->servicio?->only(['id', 'nombre', 'duracion_minutos']),
            'clienta' => $cita->clienta?->only(['id', 'nombre', 'apellido', 'rut']),
        ];
    }
}