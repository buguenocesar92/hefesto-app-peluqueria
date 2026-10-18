<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Profesional;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Profesionales del salón.
 *
 * LEER ES ABIERTO DENTRO DEL SALÓN
 * ---------------------------------
 * Toda la gente del salón necesita ver quién atiende para agendar. Ocultarlo
 * haría que la agenda fuera menos útil que una hoja de papel.
 *
 * MODIFICAR ES DE ADMINISTRACIÓN
 * ------------------------------
 * Crear una profesional, cambiarle el turno o desactivarla cambia el trabajo de
 * otras personas: es una decisión del dueño.
 */
class ProfesionalPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, Profesional $profesional): bool
    {
        return $this->esDelMismoSalon($usuario, $profesional) && $this->puede($usuario, 'ver_agenda');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_profesionales');
    }

    public function update(Usuario $usuario, Profesional $profesional): bool
    {
        return $this->esDelMismoSalon($usuario, $profesional)
            && $this->puede($usuario, 'gestionar_profesionales');
    }

    /**
     * Una profesional puede corregir su propia ficha de especialidad y color.
     *
     * No su nombre ni su estado: eso lo decide el dueño. Por eso hay un método
     * aparte en vez de dejar `update` abierto.
     */
    public function actualizarPresentacion(Usuario $usuario, Profesional $profesional): bool
    {
        if (! $this->esDelMismoSalon($usuario, $profesional)) {
            return false;
        }

        if ($this->puede($usuario, 'gestionar_profesionales')) {
            return true;
        }

        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $profesional->id;
    }
}