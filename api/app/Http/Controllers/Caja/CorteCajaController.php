<?php

declare(strict_types=1);

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Http\Requests\CerrarCorteCajaRequest;
use App\Models\CorteCaja;
use App\Models\Profesional;
use App\Models\Usuario;
use App\Services\Caja\ServicioCortesCaja;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use DomainException;

/**
 * Cortes de caja: el cierre del día, por profesional.
 *
 * POR QUIÉN CIERRA CADA UNA
 * -------------------------
 * En un salón cada quien tiene su caja. Es lo que la profesional declara y es lo
 * que el dueño revisa. Por eso el corte es por profesional y por día, y la base
 * tiene una clave única que impide cerrar dos veces el mismo día.
 *
 * LA DIFERENCIA SE MUESTRA, NO SE CORRIGE
 * ---------------------------------------
 * Si el sistema permitiera corregir un corte cerrado, dejaría de ser un acto y
 * pasaría a ser un número que se acomoda. Ver
 * App\Services\Caja\ServicioCortesCaja.
 */
class CorteCajaController extends Controller
{
    public function __construct(
        private readonly ServicioCortesCaja $cortes,
    ) {}

    /**
     * Los cortes de un día, de todas las profesionales. Es lo que ve el dueño.
     */
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', CorteCaja::class);

        $fecha = Carbon::parse((string) $peticion->query('fecha', now()->toDateString()))->startOfDay();

        $cortes = $this->cortes->cortesDelDia((string) $peticion->user()->tenant_id, $fecha);

        return response()->json([
            'fecha' => $fecha->toDateString(),
            'total_con_diferencia' => $cortes->filter(fn (CorteCaja $c): bool => $this->cortes->tieneDiferencia($c))->count(),
            'datos' => $cortes->map(static fn (CorteCaja $c): array => [
                'id' => $c->id,
                'profesional' => $c->profesional?->only(['id', 'nombre']),
                'total_esperado' => $c->total_esperado,
                'total_declarado' => $c->total_declarado,
                'diferencia' => $c->diferencia,
                'ventas_incluidas' => $c->ventas_incluidas,
                'total_efectivo' => $c->total_efectivo,
                'total_tarjeta' => $c->total_tarjeta,
                'estado' => $c->estado,
                'observaciones' => $c->observaciones,
                'cerrado_en' => $c->cerrado_en?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * El corte de una profesional para un día, con las ventas que cuenta.
     */
    public function show(Request $peticion, Profesional $profesional): JsonResponse
    {
        $fecha = Carbon::parse((string) $peticion->query('fecha', now()->toDateString()))->startOfDay();

        $corte = $this->cortes->abrir($profesional, $fecha);

        $this->authorize('view', $corte);

        // Mientras el corte está abierto, el esperado se recalcula: la caja está
        // abierta y todavía puede entrar una venta.
        if (! $corte->cerrado()) {
            $this->cortes->calcularEsperado($corte);
        }

        return response()->json([
            'id' => $corte->id,
            'profesional' => $profesional->only(['id', 'nombre']),
            'fecha' => $corte->fecha->toDateString(),
            'estado' => $corte->estado,
            'total_esperado' => $corte->total_esperado,
            'total_declarado' => $corte->total_declarado,
            'diferencia' => $corte->diferencia,
            'ventas_incluidas' => $corte->ventas_incluidas,
            'ventas' => $this->cortes->ventasCobradasDelDia($corte)
                ->map(static fn ($v): array => [
                    'id' => $v->id,
                    'total' => $v->total,
                    'total_servicios' => $v->total_servicios,
                    'total_productos' => $v->total_productos,
                    'forma_pago' => $v->forma_pago,
                    'hora' => $v->created_at->format('H:i'),
                ])->values(),
        ]);
    }

    /**
     * Cierra el corte con lo declarado.
     *
     * `total_declarado` va aparte del esperado porque son dos hechos: uno lo dice
     * el sistema y otro la persona. La diferencia es lo que hay que mirar.
     */
    public function cerrar(CerrarCorteCajaRequest $peticion, Profesional $profesional): JsonResponse
    {
        $fecha = Carbon::parse((string) $peticion->query('fecha', now()->toDateString()))->startOfDay();

        $corte = $this->cortes->abrir($profesional, $fecha);

        $this->authorize('update', $corte);

        $usuario = $peticion->user();

        try {
            $this->cortes->cerrar(
                corte: $corte,
                totalDeclarado: $peticion->validated('total_declarado'),
                totalEfectivo: $peticion->validated('total_efectivo'),
                totalTarjeta: $peticion->validated('total_tarjeta'),
                quienCierra: $usuario,
                observaciones: $peticion->validated('observaciones'),
            );
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => $corte->cerrado() ? 'Corte cerrado.' : 'El corte no quedó cerrado.',
            'total_esperado' => $corte->total_esperado,
            'total_declarado' => $corte->total_declarado,
            'diferencia' => $corte->diferencia,
            'con_diferencia' => $this->cortes->tieneDiferencia($corte),
        ]);
    }
}