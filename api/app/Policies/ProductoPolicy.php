<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Producto;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeSalon;

/**
 * Productos: lo que la clienta se lleva puesto.
 *
 * LEER ES ABIERTO
 * ---------------
 * Toda la gente del salón necesita ver el catálogo para vender en la caja.
 *
 * MODIFICAR ES DE ADMINISTRACIÓN
 * ------------------------------
 * El precio de un producto es lo que entra a la base de la comisión. Que quien
 * está en la caja lo cambie sería darle a esa persona el control de su propio
 * sueldo, que es exactamente lo que el dueño necesita evitar.
 *
 * VENDER NO ES MODIFICAR
 * ----------------------
 * Registrar una venta de producto usa la capacidad `cobrar_ventas`, que tienen
 * los tres roles. Vender no es cambiar el precio: es distinto.
 */
class ProductoPolicy
{
    use PolicyDeSalon;

    public function viewAny(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_clientes');
    }

    public function view(Usuario $usuario, Producto $producto): bool
    {
        return $this->esDelMismoSalon($usuario, $producto) && $this->puede($usuario, 'gestionar_clientes');
    }

    public function create(Usuario $usuario): bool
    {
        return $this->puede($usuario, 'gestionar_productos');
    }

    public function update(Usuario $usuario, Producto $producto): bool
    {
        return $this->esDelMismoSalon($usuario, $producto)
            && $this->puede($usuario, 'gestionar_productos');
    }

    public function delete(Usuario $usuario, Producto $producto): bool
    {
        return $this->update($usuario, $producto);
    }
}