<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ventas;

use App\Enums\TipoLineaVenta;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarVentaRequest;
use App\Models\Cita;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\Venta;
use App\Services\Ventas\ServicioVentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DomainException;

/**
 * Ventas: una visita con sus servicios y sus productos.
 *
 * POR QUÉ UN SERVICIO Y UN PRODUCTO EN LA MISMA VENTA
 * --------------------------------------------------
 * Porque ocurrieron en la misma visita, y porque el producto lo llevó la clienta
 * durante una atención hecha por esa profesional. Lo que NO es lo mismo son los
 * porcentajes: cada línea lleva su tipo y el cálculo lo lee.
 *
 * UNA VENTA COBRADA NO SE EDITA
 * -----------------------------
 * Ya entró al corte de caja y puede haber entrado a una liquidación. Para
 * corregirla se anula y se abre otra. Ver App\Services\Ventas\ServicioVentas.
 */
class VentaController extends Controller
{
    public function __construct(private readonly ServicioVentas $ventas) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Venta::class);

        $datos = Venta::query()
            ->with(['profesional:id,nombre', 'clienta:id,nombre,apellido', 'items'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(static fn (Venta $v): array => [
                'id' => $v->id,
                'fecha' => $v->created_at->toDateString(),
                'profesional' => $v->profesional?->only(['id', 'nombre']),
                'clienta' => $v->clienta?->only(['id', 'nombre', 'apellido']),
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->etiqueta(),
                'forma_pago' => $v->forma_pago,
                'total_servicios' => $v->total_servicios,
                'total_productos' => $v->total_productos,
                'total' => $v->total,
                'lineas' => $v->items->map(static fn ($i): array => [
                    'id' => $i->id,
                    'tipo' => $i->tipo->value,
                    'tipo_etiqueta' => $i->tipo->etiqueta(),
                    'descripcion' => $i->descripcion,
                    'cantidad' => $i->cantidad,
                    'precio_unitario' => $i->precio_unitario,
                    'subtotal' => $i->subtotal,
                ])->values(),
            ]);

        return response()->json(['datos' => $datos]);
    }

    /**
     * Abre una venta para una cita atendida y le agrega sus líneas.
     *
     * Todo en una sola operación: una venta a medio hacer no sirve de nada, y
     * dejarla a medias solo confunde a quien la ve después.
     */
    public function store(RegistrarVentaRequest $peticion): JsonResponse
    {
        $this->authorize('create', Venta::class);

        $cita = Cita::query()->findOrFail($peticion->validated('cita_id'));

        try {
            $venta = $this->ventas->abrir($cita);

            foreach ($peticion->lineasLimpias() as $linea) {
                if ($linea['tipo'] === TipoLineaVenta::Servicio->value) {
                    $this->ventas->agregarServicio(
                        $venta,
                        Servicio::query()->findOrFail($linea['servicio_id']),
                        $linea['cantidad'],
                        $linea['precio_unitario'],
                    );

                    continue;
                }

                $this->ventas->agregarProducto(
                    $venta,
                    Producto::query()->findOrFail($linea['producto_id']),
                    $linea['cantidad'],
                    $linea['precio_unitario'],
                );
            }

            $this->ventas->cobrar($venta, $peticion->validated('forma_pago'));
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $venta->id,
            'total_servicios' => $venta->total_servicios,
            'total_productos' => $venta->total_productos,
            'total' => $venta->total,
            'mensaje' => 'Venta cobrada.',
        ], 201);
    }

    public function show(Venta $venta): JsonResponse
    {
        $this->authorize('view', $venta);

        return response()->json([
            'id' => $venta->id,
            'estado' => $venta->estado->value,
            'total_servicios' => $venta->total_servicios,
            'total_productos' => $venta->total_productos,
            'total' => $venta->total,
            'lineas' => $venta->items->map(static fn ($i): array => [
                'id' => $i->id,
                'tipo' => $i->tipo->value,
                'descripcion' => $i->descripcion,
                'cantidad' => $i->cantidad,
                'precio_unitario' => $i->precio_unitario,
                'subtotal' => $i->subtotal,
            ])->values(),
        ]);
    }

    /** Anula una venta, incluso si ya estaba cobrada. */
    public function destroy(Venta $venta): JsonResponse
    {
        $this->authorize('delete', $venta);

        try {
            $this->ventas->anular($venta);
        } catch (DomainException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['mensaje' => 'Venta anulada. Queda el registro.']);
    }
}