<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LiquidacionComision;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Liquidaciones de comisión: el resultado que se le paga a cada una.
 *
 * VER LA SUYA ES DE TODOS
 * -----------------------
 * Que una profesional vea su liquidación es parte del trato: es su dinero y tiene
 * que poder revisarlo con el desglose. Ver las de otras es de administración.
 *
 * LIQUIDAR ES DE ADMINISTRACIÓN
 * -----------------------------
 * Calcular no es ver. Es producir la cifra que se le paga a alguien, y eso lo
 * decide el dueño.
 */
class LiquidacionComisionPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'ver_comisiones');
    }

    public function view(Usuario $usuario, LiquidacionComision $liquidacion): bool
    {
        if (! $this->esDelMismoSalon($usuario, $liquidacion)) {
            return false;
        }

        if ($this->puede($usuario, 'liquidar_comisiones')) {
            return true;
        }

        // Cada una ve la suya.
        return $usuario->profesional_id !== null
            && (string) $usuario->profesional_id === (string) $liquidacion->profesional_id;
    }

    /**
     * Calcular y guardar la liquidación de un período.
     */
    public function liquidar(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'liquidar_comisiones');
    }

    /**
     * Cerrar una liquidación: el desglose queda congelado.
     */
    public function cerrar(Usuario $usuario, LiquidacionComision $liquidacion): bool
    {
        return $this->esDelMismoSalon($usuario, $liquidacion)
            && $this->puede($usuario, 'liquidar_comisiones');
    }

    /**
     * Anular una liquidación, con motivo obligatorio.
     */
    public function anular(Usuario $usuario, LiquidacionComision $liquidacion): bool
    {
        return $this->esDelMismoSalon($usuario, $liquidacion)
            && $this->puede($usuario, 'liquidar_comisiones');
    }
}