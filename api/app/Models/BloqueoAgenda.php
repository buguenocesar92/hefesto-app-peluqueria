<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo de agenda: un tramo en que una profesional NO atiende.
 *
 * POR QUÉ NO ES UNA CITA
 * ----------------------
 * Porque una cita anulada fue una atención que se costó; un bloqueo es tiempo
 * que nunca se ocupará. Son hechos distintos y van en tablas distintas, y por
 * eso no se pueden borrar juntos.
 *
 * `profesional_id` EN `null` APLICA A TODO EL SALÓN
 * -------------------------------------------------
 * Un feriado o un corte de luz no es de una profesional: es del salón. Por eso
 * la columna acepta `null`, y la agenda lo tiene en cuenta igual.
 */
class BloqueoAgenda extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'bloqueos_agenda';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /**
     * ¿Este bloqueo aplica a esta profesional?
     *
     * Un bloqueo de salón aplica a todas, y por eso se pregunta así y no con
     * un `where profesional_id = ?` solamente.
     */
    public function aplicaA(Profesional $profesional): bool
    {
        return $this->profesional_id === null
            || (string) $this->profesional_id === (string) $profesional->id;
    }
}