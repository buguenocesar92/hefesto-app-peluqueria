<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona dentro del salón.
 *
 * Mismos tres roles que el resto de las aplicaciones de Hefesto: recepción,
 * profesionales y administración. Lo que cambia NO son los roles sino lo que se
 * puede hacer con ellos, y eso está en
 * `App\Services\Permisos\CapacidadesDeUsuario`.
 */
enum RolSalon: string
{
    case Recepcion = 'recepcion';
    case Profesional = 'profesional';
    case Administrador = 'administrador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepción',
            self::Profesional => 'Profesional',
            self::Administrador => 'Administración',
        };
    }

    /**
     * Equipo en Authentik que otorga este rol.
     *
     * La comparación en el ingreso es insensible a mayúsculas porque Authentik
     * entrega los equipos con el nombre tal como se escribió en la consola.
     */
    public function equipoAuthentik(): string
    {
        return match ($this) {
            self::Recepcion => 'Recepcion de Peluqueria',
            self::Profesional => 'Profesionales de Peluqueria',
            self::Administrador => 'Administradores de Peluqueria',
        };
    }
}