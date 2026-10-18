<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Usuarios del salón.
 *
 * TODO ES DE ADMINISTRACIÓN
 * -------------------------
 * No hay excepción: crear cuentas, desactivar cuentas o enlazar una cuenta con
 * una ficha de profesional cambia quién entra al sistema.
 *
 * UN USUARIO NO SE DESACTIVA SOLO
 * -------------------------------
 * La cuenta se desactiva con una ruta explícita que deja registro. Borrarla
 * rompería la trazabilidad de quién agendó qué, y esa trazabilidad es la que
 * hace auditable una comisión.
 */
class UsuarioPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_usuarios');
    }

    public function view(Usuario $usuario, Usuario $objetivo): bool
    {
        return $this->esDelMismoSalon($usuario, $objetivo)
            && $this->puede($usuario, 'gestionar_usuarios');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_usuarios');
    }

    public function update(Usuario $usuario, Usuario $objetivo): bool
    {
        return $this->esDelMismoSalon($usuario, $objetivo)
            && $this->puede($usuario, 'gestionar_usuarios');
    }

    /**
     * Enlazar una cuenta con una ficha de profesional.
     *
     * Tiene método propio porque es lo que hace que una persona exista en la
     * agenda y en el sistema a la vez. Es el paso que más confunde si queda
     * suelto dentro de `update`.
     */
    public function vincularProfesional(Usuario $usuario, Usuario $objetivo): bool
    {
        return $this->update($usuario, $objetivo);
    }

    public function desactivar(Usuario $usuario, Usuario $objetivo): bool
    {
        return $this->update($usuario, $objetivo);
    }

    /**
     * No se borran cuentas: se desactivan. Ver la nota del encabezado.
     */
    public function delete(Usuario $usuario, Usuario $objetivo): bool
    {
        return false;
    }
}