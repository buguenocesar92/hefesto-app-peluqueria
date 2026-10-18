<?php

declare(strict_types=1);

namespace App\Services\Ventas;

use App\Enums\EstadoVenta;
use App\Enums\TipoLineaVenta;
use App\Models\Cita;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Facades\DB;
use DomainException;

/**
 * Ventas: una visita, sus servicios y sus productos.
 *
 * POR QUÉ SERVICIO Y PRODUCTO EN LA MISMA VENTA
 * --------------------------------------------
 * Porque ocurrieron en la misma visita, y la comisión se calcula sobre la venta.
 * Lo que NO es lo mismo son los porcentajes: la línea lleva su `tipo`, y el
 * cálculo lo lee. Ver App\Services\Comisiones\CalculadoraDeComision.
 *
 * EL PRECIO SE COPIA EN LA LÍNEA
 * ------------------------------
 * El precio se escribe en `venta_items.precio_unitario` al crear la línea. Si
 * mañana sube el precio del corte, la venta de hoy sigue diciendo lo que se
 * cobró, y por eso la comisión de hoy se puede auditar dentro de un año.
 *
 * UNA VENTA COBRADA NO SE EDITA
 * -----------------------------
 * Porque ya tuvo efecto económico: entró al corte de caja y puede haber entrado
 * a una liquidación. Para corregirla se anula y se rehace. Ver `EstadoVenta`.
 */
class ServicioVentas
{
    /**
     * Abre una venta en `borrador` para una atención.
     *
     * @throws DomainException si la cita no está atendida.
     */
    public function abrir(Cita $cita): Venta
    {
        if (! $cita->estado->esHecho()) {
            throw new DomainException(
                'Solo se puede abrir una venta de una cita atendida. Esta cita está en estado '
                . $cita->estado->etiqueta() . '.'
            );
        }

        return Venta::query()->create([
            'tenant_id' => (string) $cita->tenant_id,
            'profesional_id' => $cita->profesional_id,
            'clienta_id' => $cita->clienta_id,
            'cita_id' => $cita->id,
            'estado' => EstadoVenta::Borrador->value,
        ]);
    }

    /**
     * Agrega una línea de SERVICIO.
     *
     * El precio sale de la profesional, porque es ella quien lo cobra: puede
     * tener el suyo propio o el del catálogo.
     */
    public function agregarServicio(Venta $venta, Servicio $servicio, int $cantidad = 1, ?int $precioUnitario = null): VentaItem
    {
        $this->exigirEditable($venta);

        $profesional = $venta->profesional;

        $precio = $precioUnitario ?? $profesional?->precioDe($servicio) ?? (int) $servicio->precio_base;

        return $this->agregarLinea($venta, TipoLineaVenta::Servicio, $servicio->id, null, $servicio->nombre, $cantidad, $precio);
    }

    /**
     * Agrega una línea de PRODUCTO.
     *
     * Un producto no tiene duración ni silla: se vende al momento. Por eso no
     * aparece en `citas` en ningún lado.
     */
    public function agregarProducto(Venta $venta, Producto $producto, int $cantidad = 1, ?int $precioUnitario = null): VentaItem
    {
        $this->exigirEditable($venta);

        $precio = $precioUnitario ?? (int) $producto->precio_venta;

        // El producto va en el espacio de `producto_id`. Si se colgara del otro,
        // la restricción de la base rechaza la línea y el error dice "tipo o
        // servicio_id", que no ayuda a nadie.
        return $this->agregarLinea($venta, TipoLineaVenta::Producto, null, $producto->id, $producto->nombre, $cantidad, $precio);
    }

    /**
     * Quita una línea.
     *
     * Solo en borrador: una línea de una venta cobrada no se borra, se anula la
     * venta completa.
     */
    public function quitarLinea(Venta $venta, VentaItem $item): void
    {
        $this->exigirEditable($venta);

        if ((string) $item->venta_id !== (string) $venta->id) {
            throw new DomainException('La línea no pertenece a esta venta.');
        }

        $item->delete();
        $this->recalcularTotales($venta);
    }

    /**
     * Cobra la venta.
     *
     * Es el momento en que la venta entra al corte de caja y a la comisión. Una
     * venta sin líneas no se cobra: cobrar el cero es un hecho sin sentido.
     */
    public function cobrar(Venta $venta, ?string $formaPago = null): Venta
    {
        $this->exigirEditable($venta);

        if ($venta->items()->count() === 0) {
            throw new DomainException('No se puede cobrar una venta sin líneas.');
        }

        $this->recalcularTotales($venta);

        $venta->estado = EstadoVenta::Cobrada;
        $venta->forma_pago = $formaPago;
        $venta->save();

        return $venta;
    }

    /**
     * Anula una venta, incluso si ya estaba cobrada.
     *
     * No se borra: queda el registro. Un corte de caja que mostró una venta y
     * después la hizo desaparecer no cuadra, y el dueño necesita saber por qué.
     */
    public function anular(Venta $venta): Venta
    {
        $venta->estado = EstadoVenta::Anulada;
        $venta->save();

        return $venta;
    }

    /**
     * Recalcula los totales de la venta a partir de sus líneas.
     *
     * Servicios y productos van separados porque el dueño pregunta por separado
     * cuánto se hizo de cada uno.
     */
    public function recalcularTotales(Venta $venta): Venta
    {
        $servicios = (int) $venta->items()
            ->where('tipo', TipoLineaVenta::Servicio->value)
            ->sum('subtotal');

        $productos = (int) $venta->items()
            ->where('tipo', TipoLineaVenta::Producto->value)
            ->sum('subtotal');

        $venta->total_servicios = $servicios;
        $venta->total_productos = $productos;
        $venta->total = $servicios + $productos;
        $venta->save();

        return $venta;
    }

    private function agregarLinea(
        Venta $venta,
        TipoLineaVenta $tipo,
        ?string $servicioId,
        ?string $productoId,
        string $descripcion,
        int $cantidad,
        int $precio,
    ): VentaItem {
        if ($cantidad < 1) {
            throw new DomainException('La cantidad tiene que ser al menos uno.');
        }

        if ($precio < 0) {
            throw new DomainException('El precio no puede ser negativo.');
        }

        return DB::transaction(function () use ($venta, $tipo, $servicioId, $productoId, $descripcion, $cantidad, $precio): VentaItem {
            $item = VentaItem::query()->create([
                'tenant_id' => (string) $venta->tenant_id,
                'venta_id' => $venta->id,
                'tipo' => $tipo->value,
                'servicio_id' => $servicioId,
                'producto_id' => $productoId,
                'descripcion' => $descripcion,
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'subtotal' => $cantidad * $precio,
            ]);

            $this->recalcularTotales($venta);

            return $item;
        });
    }

    private function exigirEditable(Venta $venta): void
    {
        if (! $venta->estado->editable()) {
            throw new DomainException(
                'La venta está en estado ' . $venta->estado->etiqueta()
                . ' y ya no se edita. Para corregirla hay que anularla y abrir otra.'
            );
        }
    }
}