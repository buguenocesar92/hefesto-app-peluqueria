<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVenta;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta: lo que una clienta se llevó en una visita.
 *
 * UNA VENTA PUEDE TRAER SERVICIO Y PRODUCTO
 * -----------------------------------------
 * Una clienta se corta el pelo y lleva un champú. Los dos ocurrieron en la misma
 * visita, así que van en la misma venta, pero en líneas distintas
 * (`venta_items`), y la comisión los trata distinto.
 *
 * POR QUÉ LA VENTA APUNTA AL PROFESIONAL
 * --------------------------------------
 * Porque la comisión es por profesional: el producto lo llevó la clienta durante
 * una atención, y el derecho a comisión es de quien la atendió.
 *
 * SOLO `cobrada` CUENTA
 * ---------------------
 * Una venta en `borrador` no entra ni a la comisión ni al corte de caja. Ver
 * `EstadoVenta`.
 */
class Venta extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'ventas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'clienta_id',
        'cita_id',
        'estado',
        'forma_pago',
        'total_servicios',
        'total_productos',
        'total',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoVenta::class,
            'total_servicios' => 'integer',
            'total_productos' => 'integer',
            'total' => 'integer',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** @return BelongsTo<Clienta, $this> */
    public function clienta(): BelongsTo
    {
        return $this->belongsTo(Clienta::class, 'clienta_id');
    }

    /** @return BelongsTo<Cita, $this> */
    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'cita_id');
    }

    /** @return HasMany<VentaItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class, 'venta_id');
    }

    /** ¿La venta cuenta para comisión y para el corte de caja? */
    public function cuenta(): bool
    {
        return $this->estado->cuentaParaComision();
    }
}