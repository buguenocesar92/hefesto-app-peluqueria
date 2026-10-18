<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comisiones;

use App\Enums\BaseCalculo;
use App\Enums\RolSalon;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPoliticaComisionRequest;
use App\Jobs\LiquidarComisionesJob;
use App\Models\LiquidacionComision;
use App\Models\PoliticaComision;
use App\Models\Profesional;
use App\Models\Usuario;
use App\Services\Comisiones\RegistradorDeLiquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use DomainException;

/**
 * Comisiones: las políticas y las liquidaciones.
 *
 * LA PANTALLA RESPONDE TRES PREGUNTAS
 * -----------------------------------
 * 1. ¿Cuánto paga este salón?  -> `politicas()`
 * 2. ¿Cuánto le tocaría a esta profesional en este período? -> `previsualizar()`
 * 3. ¿De dónde sale esa cifra? -> `ver()`, que trae el desglose línea por línea.
 *
 * La tercera es la que no se puede omitir. Una comisión que solo muestra el total
 * obliga a creerlo.
 *
 * LIQUIDAR ES DIFERIDO
 * --------------------
 * Calcular un período con historial es trabajo de minutos. Por eso `liquidar()`
 * encola un Job y devuelve de inmediato, en vez de dejar al dueño esperando en un
 * botón. Ver App\Jobs\LiquidarComisionesJob.
 */
class ComisionController extends Controller
{
    public function __construct(
        private readonly RegistradorDeLiquidacion $registrador,
    ) {}

    /**
     * Las políticas vigentes del salón.
     *
     * No hay ningún valor por omisión: si la lista viene vacía, el dueño tiene
     * que declarar la comisión antes de que se pueda liquidar. Ver docs/06.
     */
    public function politicas(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', PoliticaComision::class);

        $hoy = now()->toDateString();

        $politicas = PoliticaComision::query()
            ->with('profesional:id,nombre')
            ->where('vigente_desde', '<=', $hoy)
            ->where(function ($q) use ($hoy): void {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy);
            })
            ->orderByRaw('CASE WHEN profesional_id IS NULL THEN 1 ELSE 0 END')
            ->get();

        return response()->json([
            'datos' => $politicas->map(static fn (PoliticaComision $p): array => [
                'id' => $p->id,
                'profesional_id' => $p->profesional_id,
                'profesional' => $p->profesional?->only(['id', 'nombre']),
                'es_general' => $p->profesional_id === null,
                'porcentaje_servicios' => $p->porcentaje_servicios,
                'porcentaje_productos' => $p->porcentaje_productos,
                'incluye_productos' => $p->incluye_productos,
                'base_calculo' => $p->base_calculo->value,
                'base_calculo_etiqueta' => $p->base_calculo->etiqueta(),
                'vigente_desde' => $p->vigente_desde->toDateString(),
                'vigente_hasta' => $p->vigente_hasta?->toDateString(),
                'notas' => $p->notas,
            ])->values(),
            'bases_posibles' => array_map(
                static fn (BaseCalculo $b): array => ['valor' => $b->value, 'etiqueta' => $b->etiqueta()],
                BaseCalculo::cases(),
            ),
        ]);
    }

    /** Guarda una política nueva. */
    public function guardarPolitica(GuardarPoliticaComisionRequest $peticion): JsonResponse
    {
        $this->authorize('create', PoliticaComision::class);

        $politica = PoliticaComision::query()->create($peticion->validated());

        return response()->json([
            'id' => $politica->id,
            'mensaje' => 'Política guardada.',
        ], 201);
    }

    /**
     * La comisión de una profesional en un período, SIN guardar.
     *
     * Es lo que el dueño mira antes de decidir cerrar el período: la cifra y su
     * desglose, sin dejar rastro.
     */
    public function previsualizar(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', LiquidacionComision::class);

        $datos = $peticion->validate([
            'profesional_id' => ['required', 'uuid', 'exists:profesionales,id'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ], [
            'profesional_id.required' => 'Hay que indicar la profesional.',
            'desde.required' => 'Hay que indicar la fecha inicial del período.',
            'hasta.required' => 'Hay que indicar la fecha final del período.',
        ]);

        $profesional = Profesional::query()->findOrFail($datos['profesional_id']);

        // Cada una puede previsualizar su propia cuenta. La de otra es de
        // administración, y se resuelve acá antes de tocar el cálculo: la
        // respuesta 404 no confirma que exista.
        $this->comprobarAccesoAProfesional($peticion->user(), $profesional);

        try {
            $desglose = $this->registrador->previsualizar(
                $profesional,
                Carbon::parse($datos['desde'])->startOfDay(),
                Carbon::parse($datos['hasta'])->endOfDay(),
            );
        } catch (DomainException $e) {
            // Sin política vigente no hay comisión. El mensaje lo dice claro, en
            // vez de devolver cero, que sería un número que nadie negoció.
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'profesional_id' => $profesional->id,
            'desde' => $datos['desde'],
            'hasta' => $datos['hasta'],
            'desglose' => $desglose->aArray(),
        ]);
    }

    /**
     * Encola el cálculo y el guardado de la liquidación.
     *
     * Devuelve de inmediato. El Job corre dentro de una transacción con el salón
     * fijado, que es lo que hace que la RLS lo proteja.
     */
    public function liquidar(Request $peticion): JsonResponse
    {
        $this->authorize('liquidar', LiquidacionComision::class);

        $datos = $peticion->validate([
            'profesional_id' => ['required', 'uuid', 'exists:profesionales,id'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ], [
            'profesional_id.required' => 'Hay que indicar la profesional.',
            'desde.required' => 'Hay que indicar la fecha inicial del período.',
            'hasta.required' => 'Hay que indicar la fecha final del período.',
        ]);

        $profesional = Profesional::query()->findOrFail($datos['profesional_id']);

        LiquidarComisionesJob::dispatch(
            salonId: (string) $profesional->tenant_id,
            profesionalId: (string) $profesional->id,
            desde: $datos['desde'],
            hasta: $datos['hasta'],
        );

        return response()->json([
            'mensaje' => 'El cálculo quedó encolado. Se avisa cuando termine.',
        ], 202);
    }

    /**
     * Una liquidación con su desglose línea por línea.
     *
     * Esto es lo que permite auditar: de qué venta salió cada cifra y con qué
     * porcentaje se calculó.
     */
    public function ver(LiquidacionComision $liquidacion): JsonResponse
    {
        $this->authorize('view', $liquidacion);

        $liquidacion->load(['profesional:id,nombre', 'items']);

        return response()->json([
            'id' => $liquidacion->id,
            'profesional' => $liquidacion->profesional?->only(['id', 'nombre']),
            'periodo_desde' => $liquidacion->periodo_desde->toDateString(),
            'periodo_hasta' => $liquidacion->periodo_hasta->toDateString(),
            'porcentaje_servicios' => $liquidacion->porcentaje_servicios,
            'porcentaje_productos' => $liquidacion->porcentaje_productos,
            'incluye_productos' => $liquidacion->incluye_productos,
            'base_servicios' => $liquidacion->base_servicios,
            'base_productos' => $liquidacion->base_productos,
            'comision_servicios' => $liquidacion->comision_servicios,
            'comision_productos' => $liquidacion->comision_productos,
            'total_comision' => $liquidacion->total_comision,
            'estado' => $liquidacion->estado->value,
            'estado_etiqueta' => $liquidacion->estado->etiqueta(),
            'lineas' => $liquidacion->items->map(static fn ($item): array => [
                'venta_id' => $item->venta_id,
                'venta_item_id' => $item->venta_item_id,
                'tipo' => $item->tipo->value,
                'descripcion' => $item->descripcion,
                'base_aplicada' => $item->base_aplicada,
                'porcentaje_aplicado' => $item->porcentaje_aplicado,
                'comision_calculada' => $item->comision_calculada,
            ])->values(),
        ]);
    }

    /** Lista de liquidaciones, con filtro por profesional. */
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', LiquidacionComision::class);

        $datos = $peticion->validate([
            'profesional_id' => ['nullable', 'uuid', 'exists:profesionales,id'],
            'estado' => ['nullable', Rule::in(['borrador', 'cerrada', 'anulada'])],
        ]);

        $liquidaciones = LiquidacionComision::query()
            ->with('profesional:id,nombre')
            ->when($datos['profesional_id'] ?? null, fn ($q, $id) => $q->where('profesional_id', $id))
            ->when($datos['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('periodo_desde')
            ->get()
            ->map(static fn (LiquidacionComision $l): array => [
                'id' => $l->id,
                'profesional' => $l->profesional?->only(['id', 'nombre']),
                'periodo_desde' => $l->periodo_desde->toDateString(),
                'periodo_hasta' => $l->periodo_hasta->toDateString(),
                'base_servicios' => $l->base_servicios,
                'base_productos' => $l->base_productos,
                'total_comision' => $l->total_comision,
                'estado' => $l->estado->value,
                'estado_etiqueta' => $l->estado->etiqueta(),
            ]);

        return response()->json(['datos' => $liquidaciones]);
    }

    /** Cierra una liquidación: el desglose queda congelado. */
    public function cerrar(LiquidacionComision $liquidacion): JsonResponse
    {
        $this->authorize('cerrar', $liquidacion);

        try {
            $this->registrador->cerrar($liquidacion);
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['mensaje' => 'Liquidación cerrada.']);
    }

    /** Anula una liquidación, con motivo obligatorio. */
    public function anular(Request $peticion, LiquidacionComision $liquidacion): JsonResponse
    {
        $this->authorize('anular', $liquidacion);

        $datos = $peticion->validate([
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'motivo_anulacion.required' => 'Para anular una liquidación hay que escribir el motivo.',
            'motivo_anulacion.min' => 'El motivo tiene que decir algo: con menos de cinco caracteres no explica nada.',
        ]);

        try {
            $this->registrador->anular($liquidacion, $datos['motivo_anulacion']);
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['mensaje' => 'Liquidación anulada.']);
    }

    /**
     * ¿La persona tiene derecho a ver la cuenta de esta profesional?
     *
     * Administración ve todas. Cada profesional ve la suya. Nadie más, y la
     * respuesta es 404 para no confirmar que la profesional existe.
     */
    private function comprobarAccesoAProfesional(?Usuario $usuario, Profesional $profesional): void
    {
        if ($usuario === null) {
            abort(404, 'No encontrado.');
        }

        if ($usuario->rol === RolSalon::Administrador) {
            return;
        }

        if ($usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $profesional->id) {
            return;
        }

        abort(404, 'No encontrado.');
    }
}