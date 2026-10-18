<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Servicio;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Servicios: el catálogo de lo que se hace.
 *
 * LEER ES ABIERTO
 * ---------------
 * Todo el salón necesita ver el catálogo con su duración y su precio: la
 * pantalla de agenda arma el combo con esos datos.
 *
 * MODIFICAR ES DE ADMINISTRACIÓN
 * ------------------------------
 * Cambiar la duración de un servicio cambia la agenda de todas las citas futuras
 * que usen ese servicio. Es una decisión del dueño, no de recepción.
 *
 * LO QUE NO SE TOCA
 * -----------------
 * Las citas ya agendadas. Un cambio de duración no reescribe el pasado: cada
 * cita guarda la duración con la que se agendó. Ver App\Models\Cita.
 */
class ServicioPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, Servicio $servicio): bool
    {
        return $this->esDelMismoSalon($usuario, $servicio) && $this->puede($usuario, 'ver_agenda');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_servicios');
    }

    public function update(Usuario $usuario, Servicio $servicio): bool
    {
        return $this->esDelMismoSalon($usuario, $servicio)
            && $this->puede($usuario, 'gestionar_servicios');
    }

    /**
     * Desactivar un servicio.
     *
     * No se borra: las ventas ya cobradas lo nombran y un ítem de venta que no
     * apunta a ningún servicio queda huérfano.
     */
    public function delete(Usuario $usuario, Servicio $servicio): bool
    {
        return $this->update($usuario, $servicio);
    }
}