<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una venta.
 *
 * `Cobrada` es el estado que hace que la venta ENTRE en el cálculo de comisión y
 * en el corte de caja. Antes de eso es un borrador y no cuenta para nada.
 *
 * POR QUÉ NO SE BORRA UNA VENTA COBRADA
 * ------------------------------------
 * Porque ya tuvo un efecto económico. Se anula con `estado = anulada` y queda el
 * registro, que es lo que permite entender por qué el corte de caja de ese día
 * dio la diferencia que dio.
 */
enum EstadoVenta: string
{
    case Borrador = 'borrador';
    case Cobrada = 'cobrada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Cobrada => 'Cobrada',
            self::Anulada => 'Anulada',
        };
    }

    /** Solo la venta cobrada entra en la comisión y en el corte de caja. */
    public function cuentaParaComision(): bool
    {
        return $this === self::Cobrada;
    }

    /** Un borrador se puede editar entero. Una venta cobrada, no. */
    public function editable(): bool
    {
        return $this === self::Borrador;
    }
}