<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolSalon;
use App\Models\Venta;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Ventas.
 *
 * TODAS DECLARAN SU VENTA
 * -----------------------
 * Los tres roles abren y cobran ventas: es la caja del día. No es un dato
 * reservado a administración.
 *
 * VER LAS DE OTRA ES OTRO ASUNTO
 * ------------------------------
 * En un salón chico todo el mundo ve todo. En uno grande, ver cuánto vendió otra
 * persona no es asunto de quien atiende.
 */
class VentaPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_clientes');
    }

    public function view(Usuario $usuario, Venta $venta): bool
    {
        if (! $this->esDelMismoSalon($usuario, $venta)) {
            return false;
        }

        if ($this->puede($usuario, 'administra_peluqueria')) {
            return true;
        }

        // Cada quien ve lo que vendió y lo que atendió con esa profesional.
        if ($usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $venta->profesional_id) {
            return true;
        }

        return $usuario->rol !== RolSalon::Profesional;
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'cobrar_ventas');
    }

    public function update(Usuario $usuario, Venta $venta): bool
    {
        return $this->esDelMismoSalon($usuario, $venta) && $this->puede($usuario, 'cobrar_ventas');
    }

    /**
     * Anular una venta.
     *
     * No se borra. Anular deja el registro, que es lo que permite entender por
     * qué el corte de caja de ese día dio la diferencia que dio.
     */
    public function delete(Usuario $usuario, Venta $venta): bool
    {
        return $this->esDelMismoSalon($usuario, $venta)
            && $this->puede($usuario, 'administra_peluqueria');
    }
}