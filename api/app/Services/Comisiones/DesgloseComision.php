<?php

declare(strict_types=1);

namespace App\Services\Comisiones;

use App\Enums\BaseCalculo;

/**
 * El desglose de una comisión: una línea por venta que entró.
 *
 * POR QUÉ ESTO ES UNA CLASE Y NO UN ARRAY
 * --------------------------------------
 * Porque el dueño necesita ver POR QUÉ le salió tal cifra a una profesional. Un
 * total sin desglose no es auditable: no se puede mostrar de dónde salió, ni
 * detectar que una venta entró dos veces, ni ver que el producto seExcludeió.
 *
 * Es inmutable a propósito. Una vez que el cálculo está hecho, el resultado no
 * se modifica: si el dueño lo cambia, tiene que rehacerlo, y el rastro queda.
 *
 * LO QUE CONTIENE CADA LÍNEA
 * --------------------------
 *   - De dónde sale: qué venta y qué ítem.
 *   - Sobre qué base: el subtotal que entró al cálculo.
 *   - Con qué porcentaje: el porcentaje APLICADO en esa línea, que puede
 *     diferir del general.
 *   - Qué salió: la comisión de esa línea.
 *
 * Con esos cuatro datos, cualquier cifra se puede reconstruir a mano con una
 * calculadora.
 */
final class DesgloseComision
{
    /**
     * @param  list<ItemDesglose>  $lineas  una por venta y por ítem
     */
    public function __construct(
        public readonly BaseCalculo $baseCalculo,
        public readonly string $porcentajeServicios,
        public readonly string $porcentajeProductos,
        public readonly bool $incluyeProductos,
        public readonly array $lineas,
        public readonly int $totalServicios,
        public readonly int $totalProductos,
        public readonly int $totalComision,
    ) {}

    /**
     * La misma estructura, lista para la API y para la pantalla.
     *
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        return [
            'base_calculo' => $this->baseCalculo->value,
            'base_calculo_etiqueta' => $this->baseCalculo->etiqueta(),
            'porcentaje_servicios' => $this->porcentajeServicios,
            'porcentaje_productos' => $this->porcentajeProductos,
            'incluye_productos' => $this->incluyeProductos,
            'total_servicios' => $this->totalServicios,
            'total_productos' => $this->totalProductos,
            'total_comision' => $this->totalComision,
            'lineas' => array_map(
                static fn (ItemDesglose $linea): array => $linea->aArray(),
                $this->lineas,
            ),
        ];
    }

    /** Cuántas ventas entraron al cálculo. Se muestra junto al total. */
    public function cantidadDeLineas(): int
    {
        return count($this->lineas);
    }

    /** Base total, sin separar. Se usa en los reportes. */
    public function baseTotal(): int
    {
        return $this->totalServicios + $this->totalProductos;
    }
}