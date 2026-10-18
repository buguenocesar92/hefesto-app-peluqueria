<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de línea de venta: servicio o producto.
 *
 * LA DISTINCIÓN ES DEL DOMINIO, NO UN DETALLE
 * -------------------------------------------
 * Un corte ocupa la silla y tiene duración; un champú se vende al momento. Son
 * cosas distintas con tiempos de venta distintos, y la comisión puede pagar
 * distinto cada una.
 *
 * El enum existe porque esa comparación aparece en el cálculo de comisión, en
 * las políticas y en los cortes de caja, y porque la base tiene una restricción
 * que impide que una línea diga `servicio` y traiga `producto_id`. Ver la
 * migración de `venta_items`.
 */
enum TipoLineaVenta: string
{
    case Servicio = 'servicio';
    case Producto = 'producto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicio => 'Servicio',
            self::Producto => 'Producto',
        };
    }
}