<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cita;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Citas: la agenda.
 *
 * QUÉ PUEDE HACER CADA ROL
 * -------------------------
 * Recepción agenda, mueve y anula: es su trabajo. Cerrar la cita como atendida
 * también lo hace recepción, porque la clienta está en la silla y es quien la
 * recibe. La comisión no se toca desde acá: la liquidación es del dueño.
 *
 * LA PROFESIONAL VE LO SUYO
 * ------------------------
 * Puede ver la agenda completa del salón (para saber quién sigue) pero solo
 * cambiar sus propias citas. Ver lo demás no es de ella, y la fila se filtra en
 * la base igual.
 */
class CitaPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_agenda');
    }

    public function view(Usuario $usuario, Cita $cita): bool
    {
        return $this->esDelMismoSalon($usuario, $cita) && $this->puede($usuario, 'ver_agenda');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'agendar_citas');
    }

    public function update(Usuario $usuario, Cita $cita): bool
    {
        if (! $this->esDelMismoSalon($usuario, $cita)) {
            return false;
        }

        if ($this->puede($usuario, 'agendar_citas')) {
            return true;
        }

        // Una profesional puede tocar sus propias citas, no las de otras.
        return $this->esSuPropiaCita($usuario, $cita);
    }

    public function delete(Usuario $usuario, Cita $cita): bool
    {
        return $this->esDelMismoSalon($usuario, $cita) && $this->puede($usuario, 'anular_citas');
    }

    /**
     * Registrar cómo terminó de verdad la cita, con su retraso.
     *
     * Es distinto de editar: no cambia lo agendado, cambia lo que pasó. Por eso
     * tiene su propio método y no se resuelve por `update`.
     */
    public function registrarRealidad(Usuario $usuario, Cita $cita): bool
    {
        return $this->esDelMismoSalon($usuario, $cita) && $this->puede($usuario, 'anular_citas');
    }

    private function esSuPropiaCita(Usuario $usuario, Cita $cita): bool
    {
        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $cita->profesional_id;
    }
}