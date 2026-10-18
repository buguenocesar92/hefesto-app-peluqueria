<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolSalon;
use App\Models\Concerns\PerteneceAlSalon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usuario: la copia de trabajo de la identidad de Authentik.
 *
 * POR QUÉ ESTA TABLA Y NO SOLO EL TOKEN
 * -------------------------------------
 * El token dice QUIÉN es la persona, no qué puede hacer en ESTE salón. Eso hay
 * que guardarlo, y ese lado es esta tabla. La fuente de verdad sigue siendo
 * Authentik: el rol se vuelve a leer en cada ingreso.
 *
 * POR QUÉ NO HAY CONTRASEÑAS
 * -------------------------
 * Authentik ya resuelve contraseñas, segundo factor, recuperación y bloqueo.
 * Reimplementarlo acá sería peor en todos los sentidos. La columna `password`
 * existe porque Laravel la espera y queda SIEMPRE en `null`: no hay ningún
 * camino en esta aplicación que la escriba. Ver docs/07.
 *
 * `profesional_id` ENLAZA LA CUENTA CON QUIEN ATIENDE
 * ---------------------------------------------------
 * Una profesional que además usa el sistema es una sola persona con dos caras:
 * la que entra a mirar su corte y la que atiende.
 */
class Usuario extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlSalon;

    protected $table = 'usuarios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'correo',
        'rol',
        'profesional_id',
        'authentik_id',
        'activo',
        'ultimo_ingreso',
    ];

    /**
     * `password` NO está en `$fillable` a propósito: aunque la columna exista,
     * ninguna ruta de esta aplicación puede escribirla.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol' => RolSalon::class,
            'activo' => 'boolean',
            'ultimo_ingreso' => 'datetime',
        ];
    }

    /** @return BelongsTo<Profesional, $this> */
    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class, 'profesional_id');
    }

    /** ¿La cuenta tiene ficha de profesional asociada? */
    public function esProfesional(): bool
    {
        return $this->rol === RolSalon::Profesional;
    }
}