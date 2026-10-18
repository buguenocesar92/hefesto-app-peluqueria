<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PoliticaComision;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Políticas de comisión: los porcentajes que rigen.
 *
 * POR QUÉ ES TODO DE ADMINISTRACIÓN
 * ---------------------------------
 * El porcentaje de comisión es una decisión que afecta el bolsillo de otras
 * personas. Que recepción pueda cambiarlo no es un atajo: es un paso directo al
 * conflicto en el salón.
 *
 * LEER ES MÁS ABIERTO QUE ESCRIBIR
 * --------------------------------
 * Toda la gente del salón necesita ver la política vigente: es la respuesta a
 * "¿cuánto me paga lo que vendí?". Ver la cifra es lo que hace que la comisión
 * sea discutible en vez de misteriosa.
 */
class PoliticaComisionPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_comisiones');
    }

    public function view(Usuario $usuario, PoliticaComision $politica): bool
    {
        return $this->esDelMismoSalon($usuario, $politica) && $this->puede($usuario, 'ver_comisiones');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_politicas_comision');
    }

    /**
     * Una política que ya rigió no se edita.
     *
     * Cambiar el pasado es la forma más fácil de dejar una cifra sin explicación.
     * Para cambiar un acuerdo se crea otra política con otra vigencia.
     */
    public function update(Usuario $usuario, PoliticaComision $politica): bool
    {
        return $this->esDelMismoSalon($usuario, $politica)
            && $this->puede($usuario, 'gestionar_politicas_comision');
    }
}