<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BaseCalculo;
use DomainException;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Política de comisión: la decisión del negocio sobre cómo se paga.
 *
 * POR QUÉ NO HAY UN VALOR POR OMISIÓN
 * -----------------------------------
 * Porque el software no sabe cuánto paga un salón. Si hubiera un valor por
 * omisión, la primera liquidación de una instalación nueva saldría con un
 * número que nadie acordó. `PoliticaVigenteEn()` lanza excepción si no hay
 * ninguna: es preferible que el cálculo falle a que pague una cifra inventada.
 *
 * LA VIGENCIA ES POR FECHAS
 * -------------------------
 * Una comisión se acuerda por un período. Cambiarla no debe reescribir lo que ya
 * se liquidó: por eso el cálculo busca la política que estaba vigente el día de
 * la venta, no la de hoy.
 *
 * `profesional_id` EN `null` ES LA POLÍTICA GENERAL
 * ------------------------------------------------
 * La primera busca una política propia de la profesional; si no hay, usa la del
 * salón. Ese orden importa y está escrito en la búsqueda, no supuesto.
 */
class PoliticaComision extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'politicas_comision';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'porcentaje_servicios',
        'porcentaje_productos',
        'incluye_productos',
        'base_calculo',
        'vigente_desde',
        'vigente_hasta',
        'notas',
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
            'base_calculo' => BaseCalculo::class,
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** @return HasMany<LiquidacionComision, $this> */
    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionComision::class, 'politica_comision_id');
    }

    /**
     * La política que rigió en una fecha concreta.
     *
     * Primero busca una propia de la profesional; si no hay, la del salón. Se
     * escriben las dos condiciones en la consulta porque el orden importa: una
     * profesional con acuerdo propio no puede quedar con la del salón.
     *
     * @throws \DomainException si no hay ninguna política vigente.
     */
    public static function vigenteEn(string $tenantId, ?string $profesionalId, Carbon $fecha): self
    {
        $politica = self::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q
                ->where('profesional_id', $profesionalId)
                ->orWhere('profesional_id', null))
            ->where('vigente_desde', '<=', $fecha->toDateString())
            ->where(fn ($q) => $q
                ->whereNull('vigente_hasta')
                ->orWhere('vigente_hasta', '>=', $fecha->toDateString()))
            // La propia de la profesional va primero: `orderByRaw` deja la fila
            // más específica adelante en vez de confiar en el orden del motor.
            ->orderByRaw('CASE WHEN profesional_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('vigente_desde')
            ->first();

        if ($politica === null) {
            throw new \DomainException(
                'No hay política de comisión vigente para la fecha indicada. '
                . 'La comisión no se calcula con un valor por omisión: el salón tiene que declararla.'
            );
        }

        return $politica;
    }
}