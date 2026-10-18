<?php

declare(strict_types=1);

namespace App\Services\Permisos;

use App\Enums\RolSalon;
use App\Models\Usuario;

/**
 * Qué puede hacer cada persona, según su rol.
 *
 * POR QUÉ NO HAY GATES
 * --------------------
 * Cada ruta vuelve a preguntar a su `Policy`. Un Gate global sería un atajo que
 * se puede saltar por un lado, y una capacidad mal definida termina dando
 * permiso a alguien que no lo tiene. Ver docs/03.
 *
 * POR QUÉ NO ESTÁ EN EL ROL
 * -------------------------
 * El rol dice cómo se organiza el salón. La capacidad dice qué necesita hacer
 * la persona. Mañana el rol puede cambiar sin que cambie lo que se necesita, y
 * esta clase es el único lugar que hay que tocar.
 *
 * LO QUE RECEPTIÓN NO PUEDE HACER, Y POR QUÉ
 * ------------------------------------------
 * Recepción agenda y cobra. No declara una alergia grave ni cierra un período de
 * comisión: son decisiones que tienen consequences sobre el trabajo y el
 * bolsillo de otras personas.
 */
class CapacidadesDeUsuario
{
    /**
     * @return array<string, bool>
     */
    public function de(Usuario $usuario): array
    {
        $esAdmin = $usuario->rol === RolSalon::Administrador;
        $esProfesional = $usuario->rol === RolSalon::Profesional
            && $usuario->profesional_id !== null;

        return [
            'ver_agenda' => true,
            'agendar_citas' => true,
            'anular_citas' => true,
            'cerrar_ventas' => true,
            'cobrar_ventas' => true,
            'gestionar_clientes' => true,
            'ver_antecedentes' => true,
            'registrar_antecedentes' => true,
            'marcar_bloqueos' => true,
            'cortar_caja' => true,
            'ver_comisiones' => true,
            'liquidar_comisiones' => $esAdmin,
            'administra_peluqueria' => $esAdmin,
            'gestionar_servicios' => $esAdmin,
            'gestionar_productos' => $esAdmin,
            'gestionar_profesionales' => $esAdmin,
            'gestionar_politicas_comision' => $esAdmin,
            'gestionar_usuarios' => $esAdmin,
            'ver_auditoria' => $esAdmin,
            'ver_reportes' => $esAdmin,
        ];
    }

    /**
     * ¿La persona puede ver los antecedentes de salud de una clienta?
     *
     * Los tres roles sí, y es una decisión consciente: si recepción no puede
     * ver una alergia al agendar, el sistema es PEOR que una hoja de papel. La
     * vista que no se abre es la que no existe. Ver docs/03.
     */
    public function puedeVerAntecedentes(Usuario $usuario): bool
    {
        return $this->de($usuario)['ver_antecedentes'];
    }

    /**
     * El profesional tiene que tener ficha para hacer su trabajo.
     *
     * @return bool
     */
    public function puedeOperarComoProfesional(Usuario $usuario): bool
    {
        return $usuario->rol === RolSalon::Profesional && $usuario->profesional_id !== null;
    }
}