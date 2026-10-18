<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCita;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cita: la agenda, medida en tiempo de servicio.
 *
 * `fecha_fin` NO ES `fecha_inicio` MÁS UNA CONSTANTE
 * --------------------------------------------------
 * Sale de la duración real del servicio para esa profesional, que puede venir
 * del catálogo o de `servicios_profesional`. Es la razón por la que un corte y
 * un balayage no ocupan lo mismo.
 *
 * LA DURACIÓN SE COPIA
 * -------------------
 * Un cambio mañana en el catálogo no debe reescribir la cita de hoy. Por eso
 * `duracion_minutos` queda guardado.
 *
 * EL RETRASO ES LA REGLA
 * ----------------------
 * `hora_inicio_real` y `hora_fin_real` son las horas de verdad. `minutos_retraso`
 * sale de compararlas con lo agendado, y se escribe al cerrar la cita. En un
 * salón el retraso es lo normal, no la excepción, y el dueño lo necesita ver.
 */
class Cita extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'citas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'profesional_id',
        'clienta_id',
        'servicio_id',
        'fecha_inicio',
        'fecha_fin',
        'duracion_minutos',
        'estado',
        'silla',
        'hora_inicio_real',
        'hora_fin_real',
        'minutos_retraso',
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
            'hora_inicio_real' => 'datetime',
            'hora_fin_real' => 'datetime',
            'duracion_minutos' => 'integer',
            'minutos_retraso' => 'integer',
            'estado' => EstadoCita::class,
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

    /** @return BelongsTo<Servicio, $this> */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    /** @return BelongsTo<Venta, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'cita_id');
    }

    /**
     * Registra cómo terminó realmente la cita y calcula el retraso.
     *
     * El retraso se mide contra `fecha_fin` agendada: si la clienta se levantó
     * diez minutos más tarde de lo que el catálogo decía, eso son diez minutos
     * de retraso, y el dueño tiene que verlo. Nunca es negativo: terminar antes
     * de lo previsto no es retraso, y guardarlo como cero es lo correcto.
     *
     * @param  \DateTimeInterface|null  $inicioReal  cuándo se sentó
     * @param  \DateTimeInterface|null  $finReal  cuándo se levantó
     */
    public function registrarRealidad(?\DateTimeInterface $inicioReal, ?\DateTimeInterface $finReal): void
    {
        $this->hora_inicio_real = $inicioReal;
        $this->hora_fin_real = $finReal;

        if ($finReal === null) {
            $this->minutos_retraso = null;

            return;
        }

        $diferencia = $finReal->getTimestamp() - $this->fecha_fin->getTimestamp();

        $this->minutos_retraso = max(0, (int) round($diferencia / 60));
    }
}