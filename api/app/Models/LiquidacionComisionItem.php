<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLineaVenta;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del desglose de una liquidación.
 *
 * ES LO QUE HACE LA COMISIÓN AUDITABLE
 * ------------------------------------
 * El total de `LiquidacionComision` no se explica solo. Esta línea dice de qué
 * venta salió la cifra, sobre qué base se calculó y con qué porcentaje, que son
 * los tres datos que permiten rehacer la cuenta con una calculadora.
 *
 * `porcentaje_aplicado` PUEDE SER CERO Y ESTO ES CORRECTO
 * -------------------------------------------------------
 * Una línea bonificada no lleva comisión, y el desglose tiene que poder mostrarlo
 * sin mentir. Por eso el tipo es de la línea, no un porcentaje derivado del
 * total.
 */
class LiquidacionComisionItem extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'liquidacion_comision_items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'liquidacion_comision_id',
        'venta_id',
        'venta_item_id',
        'tipo',
        'descripcion',
        'base_aplicada',
        'porcentaje_aplicado',
        'comision_calculada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoLineaVenta::class,
            'base_aplicada' => 'integer',
            'porcentaje_aplicado' => 'decimal:2',
            'comision_calculada' => 'integer',
        ];
    }

    /** @return BelongsTo<LiquidacionComision, $this> */
    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionComision::class, 'liquidacion_comision_id');
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /** @return BelongsTo<VentaItem, $this> */
    public function ventaItem(): BelongsTo
    {
        return $this->belongsTo(VentaItem::class, 'venta_item_id');
    }
}