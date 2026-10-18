<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\RolSalon;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;

/**
 * Base de todas las Policies.
 *
 * POR QUÉ `ES DEL MISMO SALÓN` VIENE PRIMERO
 * ------------------------------------------
 * Antes de preguntar qué puede hacer la persona hay que confirmar que el recurso
 * es suyo. Si se invirtiera el orden, una profesional de otro salón podría
 * entrar a preguntar "¿puedo ver esta cita?" sobre una cita que no es suya, y la
 * respuesta sería informativa aunque fuera un "no".
 *
 * EL RECURSO TRAE SU `tenant_id`
 * -----------------------------
 * Cada modelo de negocio usa el trait `PerteneceAlSalon`, así que la comparación
 * es la misma en todas las Policies. La Row Level Security igual impide el
 * acceso en la base: esto es la segunda barrera, no la única.
 */
trait PolicyDeSalon
{
    /**
     * ¿La persona y el recurso pertenecen al mismo salón?
     *
     * @param  object  $recurso  un modelo con `tenant_id`
     */
    protected function esDelMismoSalon(Usuario $usuario, object $recurso): bool
    {
        if (! property_exists($recurso, 'tenant_id')) {
            return false;
        }

        return (string) $recurso->tenant_id === (string) $usuario->tenant_id;
    }

    /**
     * ¿La persona tiene esta capacidad?
     *
     * Se pregunta por la capacidad y no por el rol: el rol dice cómo se organiza
     * el salón, la capacidad dice qué hace falta. Ver
     * App\Services\Permisos\CapacidadesDeUsuario.
     */
    protected function puede(Usuario $usuario, string $capacidad): bool
    {
        $capacidades = app(CapacidadesDeUsuario::class)->de($usuario);

        return ($capacidades[$capacidad] ?? false) === true;
    }

    /** ¿La persona administra el salón? */
    protected function esAdministrador(Usuario $usuario): bool
    {
        return $usuario->rol === RolSalon::Administrador;
    }
}