<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Profesional: quien atiende en el salón.
 *
 * LA SILLA ES UN RECURSO COMPARTIDO
 * ---------------------------------
 * Cada profesional declara qué silla usa. La agenda lo tiene en cuenta al
 * agendar, porque dos personas no pueden trabajar en la misma silla a la vez.
 *
 * `registro_profesional` NO se valida contra ninguna fuente oficial. Es texto
 * que declara el salón, y por eso se registra aparte de `registro_verificado`:
 * el texto puede estar y no estar verificado, y confundirlos hace que alguien
 * crea que hay un respaldo donde no lo hay.
 */
class Profesional extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'profesionales';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'especialidad',
        'silla',
        'correo',
        'telefono',
        'registro_profesional',
        'registro_verificado',
        'color_agenda',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registro_verificado' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Servicio, $this> */
    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(
            Servicio::class,
            'servicios_profesional',
            'profesional_id',
            'servicio_id',
        )->withPivot(['duracion_minutos', 'precio', 'activo'])->withTimestamps();
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'profesional_id');
    }

    /** @return HasMany<BloqueoAgenda, $this> */
    public function bloqueos(): HasMany
    {
        return $this->hasMany(BloqueoAgenda::class, 'profesional_id');
    }

    /** @return HasMany<Venta, $this> */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'profesional_id');
    }

    /** @return HasMany<LiquidacionComision, $this> */
    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionComision::class, 'profesional_id');
    }

    /** @return HasOne<Usuario, $this> */
    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class, 'profesional_id');
    }

    /**
     * Duración efectiva de un servicio para ESTA profesional.
     *
     * Sale de `servicios_profesional.duracion_minutos` si está declarado, y si
     * no, del catálogo. Es la respuesta a por qué la agenda se llena por tiempo
     * de servicio y no por bloques fijos.
     */
    public function duracionDe(Servicio $servicio): int
    {
        $pivot = $this->servicios()
            ->where('servicios.id', $servicio->id)
            ->first()?->pivot;

        $propia = $pivot?->duracion_minutos;

        return $propia !== null ? (int) $propia : (int) $servicio->duracion_minutos;
    }

    /**
     * Precio efectivo de un servicio para ESTA profesional.
     *
     * Misma regla que la duración: si la profesional tiene su propio precio, ese
     * manda. Si no, el del catálogo.
     */
    public function precioDe(Servicio $servicio): int
    {
        $pivot = $this->servicios()
            ->where('servicios.id', $servicio->id)
            ->first()?->pivot;

        $propio = $pivot?->precio;

        return $propio !== null ? (int) $propio : (int) $servicio->precio_base;
    }
}