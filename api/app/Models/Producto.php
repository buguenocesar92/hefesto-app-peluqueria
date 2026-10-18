<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto: lo que la clienta se lleva puesto.
 *
 * UN SERVICIO Y UN PRODUCTO NO SON LO MISMO
 * -----------------------------------------
 * El corte ocupa la silla y tiene duración. El champú se vende al momento, no
 * ocupa silla y no tiene duración. Por eso van en tablas separadas y por eso la
 * comisión puede calcularlos distinto.
 *
 * NO HAY AGENDAMIENTO DE PRODUCTOS
 * --------------------------------
 * No existe ninguna cita de producto. Un producto se vende en la venta que se
 * abre al cerrar la atención, y no bloquea agenda. Ver App\Services\Ventas.
 */
class Producto extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'productos';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'descripcion',
        'marca',
        'precio_venta',
        'stock',
        'unidad',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_venta' => 'integer',
            'stock' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<VentaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class, 'producto_id');
    }
}