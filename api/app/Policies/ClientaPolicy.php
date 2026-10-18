<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Clientas: la ficha de identificación.
 *
 * QUÉ PUEDE VER RECEPCIÓN
 * ----------------------
 * Nombre, RUT, teléfono: lo que necesita para llamar a alguien y confirmar una
 * cita. Eso es todo, y es lo suficiente para su trabajo.
 *
 * QUÉ NO ABRE DESDE AQUÍ
 * ----------------------
 * Los antecedentes de salud se abren en otra pantalla, con otro permiso y con
 * auditoría. No son lo mismo, y separarlos es lo que hace que dar acceso a la
 * agenda NO sea dar acceso a la historia. Ver docs/03.
 *
 * NO SE BORRA UNA CLIENTA
 * -----------------------
 * Tiene ventas, citas y antecedentes. Se desactiva con `activo = false`: el
 * registro se queda porque la comisión y el corte de caja lo necesitan.
 */
class ClientaPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_clientes');
    }

    public function view(Usuario $usuario, Clienta $clienta): bool
    {
        return $this->esDelMismoSalon($usuario, $clienta) && $this->puede($usuario, 'gestionar_clientes');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_clientes');
    }

    public function update(Usuario $usuario, Clienta $clienta): bool
    {
        return $this->esDelMismoSalon($usuario, $clienta) && $this->puede($usuario, 'gestionar_clientes');
    }

    /**
     * Desactivar una clienta.
     *
     * No se elimina: sus ventas ya entraron en liquidaciones y en cortes de
     * caja, y borrar la fila rompería esos números.
     */
    public function delete(Usuario $usuario, Clienta $clienta): bool
    {
        return $this->esDelMismoSalon($usuario, $clienta) && $this->puede($usuario, 'gestionar_clientes');
    }
}