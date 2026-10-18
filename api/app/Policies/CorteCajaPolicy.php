<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CorteCaja;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Cortes de caja: el cierre del día.
 *
 * TODAS DECLARAN, SOLO ADMINISTRACIÓN CORRIGE
 * --------------------------------------------
 * Cada profesional cierra su propia caja: es su dinero y es su declaración. Eso
 * lo hacen los tres roles.
 *
 * Pero si una diferencia puede corregirse sobre el mismo corte, el corte deja de
 * ser un ACTO y pasa a ser un número que se acomoda. Por eso corregir una caja
 * cerrada no existe: se deja la diferencia anotada con observación y se habla.
 *
 * Ver App\Services\Caja\ServicioCortesCaja.
 */
class CorteCajaPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'cortar_caja');
    }

    public function view(Usuario $usuario, CorteCaja $corte): bool
    {
        if (! $this->esDelMismoSalon($usuario, $corte)) {
            return false;
        }

        if ($this->puede($usuario, 'administra_peluqueria')) {
            return true;
        }

        // Cada una ve su corte. El dueño ve todos.
        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $corte->profesional_id;
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'cortar_caja');
    }

    /**
     * Cerrar el corte con lo declarado.
     */
    public function update(Usuario $usuario, CorteCaja $corte): bool
    {
        return $this->esDelMismoSalon($usuario, $corte) && $this->puede($usuario, 'cortar_caja');
    }

    /**
     * Un corte cerrado no se vuelve a abrir. No hay `delete` a propósito: borrar
     * un corte es borrar el registro de que ese día pasó.
     */
    public function delete(Usuario $usuario, CorteCaja $corte): bool
    {
        return false;
    }
}