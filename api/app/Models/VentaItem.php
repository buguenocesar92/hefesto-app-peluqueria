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
 * Línea de venta: un servicio vendido o un producto vendido.
 *
 * EL PRECIO SE COPIA
 * ------------------
 * `precio_unitario` queda escrito en la línea. Si mañana el salón sube el precio
 * del corte, esta línea sigue diciendo el precio que se cobró. Una comisión
 * recalculada sobre el catálogo actual no se puede auditar.
 *
 * `tipo` ES LO QUE LA COMISIÓN LEE
 * ---------------------------------
 * Es lo que decide el porcentaje. No se deduce de cuál identificador viene
 * lleno, porque el tipo es una decisión del negocio: un producto puede haber
 * salido como parte de una promoción y no llevar comisión. Ver
 * App\Services\Comisiones\CalculadoraDeComision.
 */
class VentaItem extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'venta_items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'venta_id',
        'tipo',
        'servicio_id',
        'producto_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoLineaVenta::class,
            'cantidad' => 'integer',
            'precio_unitario' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /** @return BelongsTo<Servicio, $this> */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Calcula el subtotal a partir de cantidad y precio. */
    public function recalcularSubtotal(): void
    {
        $this->subtotal = $this->cantidad * $this->precio_unitario;
    }
}