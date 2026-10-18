<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\AccesoSalud;
use App\Models\Cita;
use App\Models\CorteCaja;
use App\Models\LiquidacionComision;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reportes: lo que el dueño necesita mirar.
 *
 * SON CUENTAS, NO JUZGIOS
 * ----------------------
 * Los números dicen qué pasó. No dicen si el trabajo estuvo bien hecho ni si
 * alguien está robando: eso se conversa mirando los cortes y las liquidaciones,
 * que son los que tienen el detalle.
 *
 * LO QUE NO HAY AQUÍ
 * ------------------
 * Un reporte de "productividad por profesional" que rankee a la gente. Con
 * comisiones, un ranking de ventas es exactamente la forma de que alguien empiece
 * a empujar ventas que la clienta no necesita. Ver docs/06.
 */
class ReporteController extends Controller
{
    /**
     * Ventas del período, separadas por tipo.
     *
     * Servicios y productos van juntos en el total pero separados en el
     * detalle, que es como el dueño pregunta.
     */
    public function ventas(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Venta::class);

        $datos = $peticion->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ], [
            'desde.required' => 'Hay que indicar la fecha inicial.',
            'hasta.required' => 'Hay que indicar la fecha final.',
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();

        $ventas = Venta::query()
            ->where('estado', 'cobrada')
            ->whereBetween('created_at', [$desde, $hasta])
            ->get();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'ventas' => $ventas->count(),
            'total_servicios' => (int) $ventas->sum('total_servicios'),
            'total_productos' => (int) $ventas->sum('total_productos'),
            'total' => (int) $ventas->sum('total'),
        ]);
    }

    /**
     * Cierres de caja del período, con las diferencias.
     *
     * Es el reporte que más se mira y el que menos se quiere que esté feo: por
     * eso las diferencias salen separadas y no promediadas.
     */
    public function cortes(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', CorteCaja::class);

        $datos = $peticion->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();

        $cortes = CorteCaja::query()
            ->with('profesional:id,nombre')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('estado', 'cerrado')
            ->orderBy('fecha')
            ->get();

        $conDiferencia = $cortes->filter(static fn (CorteCaja $c): bool => (int) $c->diferencia !== 0);

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'cortes' => $cortes->count(),
            'con_diferencia' => $conDiferencia->count(),
            'diferencia_total' => (int) $cortes->sum('diferencia'),
            'datos' => $cortes->map(static fn (CorteCaja $c): array => [
                'fecha' => $c->fecha->toDateString(),
                'profesional' => $c->profesional?->only(['id', 'nombre']),
                'total_esperado' => $c->total_esperado,
                'total_declarado' => $c->total_declarado,
                'diferencia' => $c->diferencia,
                'ventas_incluidas' => $c->ventas_incluidas,
                'observaciones' => $c->observaciones,
            ])->values(),
        ]);
    }

    /**
     * Retrasos del período.
     *
     * El retraso no es una falla de la profesional: es un número del sistema. Por
     * eso se reporta por día y no por persona en contra.
     */
    public function retrasos(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Cita::class);

        $datos = $peticion->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();

        $citas = Cita::query()
            ->with(['servicio:id,nombre,duracion_minutos'])
            ->whereBetween('fecha_inicio', [$desde, $hasta])
            ->whereNotNull('minutos_retraso')
            ->get();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'atenciones' => $citas->count(),
            'minutos_retraso_promedio' => $citas->count() === 0
                ? 0
                : (int) round($citas->avg('minutos_retraso')),
            'minutos_retraso_total' => (int) $citas->sum('minutos_retraso'),
            'datos' => $citas->map(static fn (Cita $c): array => [
                'fecha_inicio' => $c->fecha_inicio->toIso8601String(),
                'fecha_fin' => $c->fecha_fin->toIso8601String(),
                'servicio' => $c->servicio?->only(['id', 'nombre']),
                'duracion_prevista' => $c->duracion_minutos,
                'minutos_retraso' => $c->minutos_retraso,
            ])->values(),
        ]);
    }

    /**
     * Comisiones liquidadas en el período.
     *
     * Sin el desglose: el detalle está en la pantalla de cada liquidación, que
     * es donde se audita una por una.
     */
    public function comisiones(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', LiquidacionComision::class);

        $datos = $peticion->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();

        $liquidaciones = LiquidacionComision::query()
            ->with('profesional:id,nombre')
            ->whereBetween('periodo_desde', [$desde->toDateString(), $hasta->toDateString()])
            ->where('estado', 'cerrada')
            ->get();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'liquidaciones' => $liquidaciones->count(),
            'base_servicios' => (int) $liquidaciones->sum('base_servicios'),
            'base_productos' => (int) $liquidaciones->sum('base_productos'),
            'total_comisiones' => (int) $liquidaciones->sum('total_comision'),
            'datos' => $liquidaciones->map(static fn (LiquidacionComision $l): array => [
                'id' => $l->id,
                'profesional' => $l->profesional?->only(['id', 'nombre']),
                'periodo_desde' => $l->periodo_desde->toDateString(),
                'periodo_hasta' => $l->periodo_hasta->toDateString(),
                'porcentaje_servicios' => $l->porcentaje_servicios,
                'porcentaje_productos' => $l->porcentaje_productos,
                'base_servicios' => $l->base_servicios,
                'base_productos' => $l->base_productos,
                'total_comision' => $l->total_comision,
            ])->values(),
        ]);
    }

    /**
     * Quién abrió qué antecedentes de salud, y con qué resultado.
     *
     * Es el reporte que responde "yo no miré eso". Ver docs/07.
     */
    public function auditoria(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', AccesoSalud::class);

        $datos = $peticion->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
            'resultado' => ['nullable', 'in:permitido,denegado'],
        ]);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->endOfDay();

        $accesos = AccesoSalud::query()
            ->with(['usuario:id,nombre,rol', 'clienta:id,nombre,apellido'])
            ->whereBetween('creado_en', [$desde, $hasta])
            ->when($datos['resultado'] ?? null, fn ($q, $r) => $q->where('resultado', $r))
            ->orderByDesc('creado_en')
            ->limit(500)
            ->get();

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total' => $accesos->count(),
            'denegados' => $accesos->where('resultado', 'denegado')->count(),
            'datos' => $accesos->map(static fn (AccesoSalud $a): array => [
                'creado_en' => $a->creado_en?->toIso8601String(),
                'resultado' => $a->resultado,
                'accion' => $a->accion,
                'usuario' => $a->usuario?->only(['id', 'nombre', 'rol']),
                'clienta' => $a->clienta?->only(['id', 'nombre', 'apellido']),
                'ruta' => $a->ruta,
                'ip' => $a->ip_address,
                'detalle' => $a->detalle,
            ])->values(),
        ]);
    }
}