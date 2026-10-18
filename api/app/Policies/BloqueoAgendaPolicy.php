<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BloqueoAgenda;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Bloqueos de agenda: los tramos en que nadie atiende.
 *
 * POR QUÉ CUALQUIERA PUEDE PONER UN BLOQUEO
 * -----------------------------------------
 * Porque un bloqueo es una restricción, no un permiso: no abre nada, y quien lo
 * pone se está cerrando a sí misma. Si poner una colación exigiera ser
 * administración, la recepcionista la pondría por otro camino y el sistema
 * dejaría de reflejar la realidad del día.
 *
 * EL BLOQUEO DE SALÓN ES DE ADMINISTRACIÓN
 * ----------------------------------------
 * Un bloqueo con `profesional_id` en `null` afecta a todo el salón. Cerrar el
 * salón entero un día cualquiera es decisión del dueño.
 */
class BloqueoAgendaPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, BloqueoAgenda $bloqueo): bool
    {
        return $this->esDelMismoSalon($usuario, $bloqueo) && $this->puede($usuario, 'ver_agenda');
    }

    /**
     * Crear un bloqueo.
     *
     * Si el bloqueo es de todo el salón, se exige administración: es lo único
     * que no es reversible con una llamada de la propia profesional.
     */
    public function create(Usuario $usuario): bool
    {
        if (! $this->puede($usuario, 'marcar_bloqueos')) {
            return false;
        }

        return true;
    }

    /**
     * ¿Puede poner un bloqueo para el salón entero?
     */
    public function bloquearTodoElSalon(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'administra_peluqueria');
    }

    public function update(Usuario $usuario, BloqueoAgenda $bloqueo): bool
    {
        return $this->esDelMismoSalon($usuario, $bloqueo) && $this->puede($usuario, 'marcar_bloqueos');
    }

    /**
     * Quitar un bloqueo es urgente: la agenda está cerrada y alguien tiene que
     * abrirla. Por eso se puede borrar, a diferencia de las citas.
     *
     * Un bloqueo no es un hecho económico ni clínico: es una intención. Quitarla
     * no borra historia.
     */
    public function delete(Usuario $usuario, BloqueoAgenda $bloqueo): bool
    {
        if (! $this->esDelMismoSalon($usuario, $bloqueo)) {
            return false;
        }

        if ($bloqueo->profesional_id === null) {
            return $this->puede($usuario, 'administra_peluqueria');
        }

        if ($this->puede($usuario, 'marcar_bloqueos')) {
            return true;
        }

        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $bloqueo->profesional_id;
    }
}