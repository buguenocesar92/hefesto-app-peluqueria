<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corte de caja: el cierre del día, por profesional.
 *
 * POR QUÉ POR PROFESIONAL Y NO GENERAL DEL SALÓN
 * ----------------------------------------------
 * Porque en un salón cada quien tiene su caja: es quien cobró y es a quien se
 * le entrega al final del día. Un corte general no diría qué pasó con la caja de
 * cada una.
 *
 * LA DIFERENCIA ES EL NÚMERO QUE IMPORTA
 * --------------------------------------
 * `total_esperado` sale de las ventas cobradas. `total_declarado` es lo que la
 * persona dice tener. `diferencia` es la resta, y se GUARDA: un corte que se
 * recalcula al leer puede cambiar de número después sin que nadie se entere.
 *
 * `ventas_incluidas` CONGELA QUÉ SE CONTÓ
 * ----------------------------------------
 * Si mañana se anula una venta de hoy, este corte sigue diciendo cuántas contó.
 */
class CorteCaja extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'cortes_caja';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'fecha',
        'total_esperado',
        'total_declarado',
        'diferencia',
        'ventas_incluidas',
        'total_efectivo',
        'total_tarjeta',
        'estado',
        'observaciones',
        'cerrado_por',
        'cerrado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'total_esperado' => 'integer',
            'total_declarado' => 'integer',
            'diferencia' => 'integer',
            'ventas_incluidas' => 'integer',
            'total_efectivo' => 'integer',
            'total_tarjeta' => 'integer',
            'cerrado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrado_por');
    }

    /**
     * Las ventas cobradas de esa profesional en ese día.
     *
     * La relación existe para que la pantalla del corte muestre de dónde sale
     * `total_esperado`. NO se usa para recalcular el total guardado: el corte es
     * un registro inmutable y la cifra con la que se cerró es la que queda.
     *
     * @return HasMany<Venta, $this>
     */
    public function ventasDelDia(): HasMany
    {
        return $this->hasMany(Venta::class, 'profesional_id')
            ->where('ventas.estado', 'cobrada')
            ->whereDate('ventas.created_at', $this->fecha);
    }

    /** ¿El corte ya no se toca? */
    public function cerrado(): bool
    {
        return $this->estado === 'cerrado';
    }
}