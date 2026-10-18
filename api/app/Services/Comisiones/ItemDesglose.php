<?php

declare(strict_types=1);

namespace App\Services\Comisiones;

use App\Enums\TipoLineaVenta;

/**
 * Una línea del desglose: una venta, un ítem, una comisión.
 *
 * CUATRO DATOS Y NADA MÁS
 * -----------------------
 * De dónde sale la cifra, sobre qué base se calculó, con qué porcentaje y qué
 * salió. Con esos cuatro, cualquier persona puede rehacer la cuenta con una
 * calculadora: eso es lo que hace auditable una comisión.
 *
 * `porcentajeAplicado` puede ser `0.00` aunque la política general diga otra
 * cosa. Eso es correcto y es intencional: una línea bonificada o una promoción
 * no lleva comisión, y el desglose tiene que poder mostrarlo sin mentir.
 */
final class ItemDesglose
{
    public function __construct(
        public readonly string $ventaId,
        public readonly string $ventaItemId,
        public readonly TipoLineaVenta $tipo,
        public readonly string $descripcion,
        public readonly int $baseAplicada,
        public readonly string $porcentajeAplicado,
        public readonly int $comisionCalculada,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        return [
            'venta_id' => $this->ventaId,
            'venta_item_id' => $this->ventaItemId,
            'tipo' => $this->tipo->value,
            'tipo_etiqueta' => $this->tipo->etiqueta(),
            'descripcion' => $this->descripcion,
            'base_aplicada' => $this->baseAplicada,
            'porcentaje_aplicado' => $this->porcentajeAplicado,
            'comision_calculada' => $this->comisionCalculada,
        ];
    }
}