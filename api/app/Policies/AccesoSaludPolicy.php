<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccesoSalud;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Bitácora de accesos a la salud.
 *
 * SOLO LECTURA, Y SOLO ADMINISTRACIÓN
 * -----------------------------------
 * La bitácora dice quién abrió qué ficha de salud. Es la herramienta con la que
 * un dueño responde "yo no miré eso" y con la que se demuestra lo contrario.
 *
 * Por eso NO hay `create`, `update` ni `delete`: las entradas las escribe el
 * middleware de auditoría y nadie más. Una bitácora que se puede editar no es una
 * bitácora.
 */
class AccesoSaludPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_auditoria');
    }

    public function view(Usuario $usuario, AccesoSalud $acceso): bool
    {
        return $this->esDelMismoSalon($usuario, $acceso) && $this->puede($usuario, 'ver_auditoria');
    }

    public function create(Usuario $usuario): bool
    {
        return false;
    }

    public function update(Usuario $usuario, AccesoSalud $acceso): bool
    {
        return false;
    }

    public function delete(Usuario $usuario, AccesoSalud $acceso): bool
    {
        return false;
    }
}