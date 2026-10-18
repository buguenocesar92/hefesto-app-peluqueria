<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Servicio: lo que se hace en la silla.
 *
 * DE AQUÍ SALE LA DURACIÓN DE LA CITA
 * ------------------------------------
 * Un corte y un balayage no ocupan lo mismo. La agenda se llena por tiempo de
 * servicio, y ese tiempo sale de acá o de la tabla `servicios_profesional`
 * cuando la profesional tiene el suyo declarado.
 *
 * `es_reposicion` MARCA LO QUE NECESITA ESPERA
 * --------------------------------------------
 * Un tinte deja el pelo en un estado del que hay que esperar antes de aplicar
 * otro proceso químico. Qué servicios se repelen entre sí, y cuántos días hay
 * que esperar, NO está escrito en el software: es conocimiento químico capilar
 * que no se pudo verificar. Ver docs/02.
 */
class Servicio extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'servicios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'descripcion',
        'duracion_minutos',
        'precio_base',
        'categoria',
        'es_reposicion',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duracion_minutos' => 'integer',
            'precio_base' => 'integer',
            'es_reposicion' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Cita, $this> */
    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'servicio_id');
    }

    /** @return BelongsToMany<Profesional, $this> */
    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(
            Profesional::class,
            'servicios_profesional',
            'servicio_id',
            'profesional_id',
        )->withPivot(['duracion_minutos', 'precio', 'activo'])->withTimestamps();
    }
}