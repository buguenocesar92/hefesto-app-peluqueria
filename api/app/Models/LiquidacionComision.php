<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoLiquidacion;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Liquidación de comisión: el resultado del cálculo, con su desglose.
 *
 * POR QUÉ COPIA LOS PORCENTAJES
 * -----------------------------
 * Si guardara solo el total, un cambio de política en el futuro volvería esta
 * liquidación inexplicable. Al copiar el porcentaje aplicado, la cifra queda
 * explicada aunque la política cambie mañana. Ver
 * App\Services\Comisiones\CalculadoraDeComision.
 *
 * `base_servicios` Y `base_productos` ESTÁN SEPARADOS
 * ---------------------------------------------------
 * Porque el dueño pregunta por separado "¿cuánto hice de servicios" y
 * "¿cuánto hice de productos".
 */
class LiquidacionComision extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'liquidaciones_comision';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'politica_comision_id',
        'periodo_desde',
        'periodo_hasta',
        'porcentaje_servicios',
        'porcentaje_productos',
        'incluye_productos',
        'base_servicios',
        'base_productos',
        'comision_servicios',
        'comision_productos',
        'total_comision',
        'estado',
        'motivo_anulacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'porcentaje_servicios' => 'decimal:2',
            'porcentaje_productos' => 'decimal:2',
            'incluye_productos' => 'boolean',
            'periodo_desde' => 'date',
            'periodo_hasta' => 'date',
            'base_servicios' => 'integer',
            'base_productos' => 'integer',
            'comision_servicios' => 'integer',
            'comision_productos' => 'integer',
            'total_comision' => 'integer',
            'estado' => EstadoLiquidacion::class,
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** @return BelongsTo<PoliticaComision, $this> */
    public function politica(): BelongsTo
    {
        return $this->belongsTo(PoliticaComision::class, 'politica_comision_id');
    }

    /** @return HasMany<LiquidacionComisionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LiquidacionComisionItem::class, 'liquidacion_comision_id');
    }

    /** ¿El desglose ya no se toca? */
    public function congelada(): bool
    {
        return $this->estado->congelada();
    }
}