<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Base sobre la que se calcula la comisión.
 *
 * POR QUÉ ES UN ENUM Y NO UN BOOLEANO
 * -----------------------------------
 * Un booleano obliga a decidir el caso "solo servicios" con el valor
 * `incluye_productos = false`, que es ambiguo: ¿el producto no entra a la base
 * o no se liquida? Con tres casos explícitos la pregunta del dueño tiene
 * respuesta directa, y la columna de la base se puede validar contra esta lista.
 *
 * ESTOS TRES CASOS SON TODOS LOS QUE HAY
 * --------------------------------------
 * No se inventó un cuarto. Si un salón necesita algo más raro (por ejemplo, un
 * porcentaje distinto para un producto en particular), eso no se modela como un
 * valor del enum sino como una política por profesional. Ver docs/06.
 */
enum BaseCalculo: string
{
    /** Solo entra el servicio a la base. El producto se vende y no se liquida. */
    case SoloServicio = 'servicio';

    /** Solo entra el producto a la base. Es raro, pero existe. */
    case SoloProducto = 'producto';

    /** Entran los dos, cada uno con su propio porcentaje. */
    case Ambos = 'ambos';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SoloServicio => 'Solo servicios',
            self::SoloProducto => 'Solo productos',
            self::Ambos => 'Servicios y productos',
        };
    }

    /** ¿Este tipo de línea entra a la base de la comisión? */
    public function incluye(string $tipoLinea): bool
    {
        return match ($this) {
            self::SoloServicio => $tipoLinea === TipoLineaVenta::Servicio->value,
            self::SoloProducto => $tipoLinea === TipoLineaVenta::Producto->value,
            self::Ambos => true,
        };
    }
}