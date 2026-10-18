<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeveridadAntecedente;
use App\Enums\TipoAntecedente;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antecedente de salud: lo que hay que saber ANTES de tocar el pelo.
 *
 * DATO SENSIBLE BAJO LA LEY 19.628
 * ---------------------------------
 * Por eso está en su propia tabla y no en `clientas`: no es lo mismo abrir el
 * teléfono de alguien que abrir su historia de alergias. Separarlas permite
 * que recepción agende sin abrir esto.
 *
 * LA REGLA DE ESPERA ENTRE PROCESOS NO ESTÁ ACÁ
 * ---------------------------------------------
 * Es cierto que hay procesos que se repelen. Cuántos días exige cada uno NO se
 * pudo verificar, y por eso esta tabla no tiene una columna de días: el software
 * no puede inventar ese dato. Ver docs/02.
 */
class AntecedenteSalud extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'antecedentes_salud';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'clienta_id',
        'tipo',
        'descripcion',
        'severidad',
        'bloquea_servicios',
        'servicios_afectados',
        'registrado_por',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoAntecedente::class,
            'severidad' => SeveridadAntecedente::class,
            'bloquea_servicios' => 'boolean',
            'servicios_afectados' => 'array',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clienta, $this> */
    public function clienta(): BelongsTo
    {
        return $this->belongsTo(Clienta::class, 'clienta_id');
    }

    /** @return BelongsTo<Profesional, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'registrado_por');
    }

    /**
     * ¿Este antecedente afecta a este servicio?
     *
     * En `null` significa que afecta a todos, que es el caso prudente: una
     * alergia a un tinte sin servicio declarado tiene que avisar igual.
     *
     * `servicios_afectados` es una columna `jsonb` con identificadores de
     * servicio, NO una tabla intermedia. No hay tabla `taggable` en esta base,
     * y agregar una sería inventar una relación que nadie pidió.
     */
    public function afectaA(Servicio $servicio): bool
    {
        if ($this->servicios_afectados === null || $this->servicios_afectados === []) {
            return true;
        }

        return in_array((string) $servicio->id, $this->servicios_afectados, true);
    }
}