<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TipoLineaVenta;
use Illuminate\Validation\Rule;

/**
 * Registrar una venta con sus servicios y productos.
 *
 * UNA VENTA TRAE SERVICIOS, PRODUCTOS O LOS DOS
 * ---------------------------------------------
 * Es lo normal: una clienta se corta el pelo y lleva un champú. La línea lleva su
 * tipo, que es lo que hace que la comisión los calcule distinto.
 *
 * NO SE MANDA EL SUBTOTAL
 * ----------------------
 * Viene de `cantidad * precio_unitario`. Mandarlo sería abrir la puerta a que
 * una línea diga 1000 pesos por un producto de 20.000.
 */
class RegistrarVentaRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cita_id' => ['nullable', 'uuid', 'exists:citas,id'],
            'forma_pago' => ['nullable', 'string', 'max:24'],
            'lineas' => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*.tipo' => ['required', Rule::in(array_column(TipoLineaVenta::cases(), 'value'))],
            'lineas.*.servicio_id' => ['nullable', 'uuid', 'exists:servicios,id'],
            'lineas.*.producto_id' => ['nullable', 'uuid', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'lineas.*.precio_unitario' => ['nullable', 'integer', 'min:0'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'lineas.required' => 'Una venta necesita al menos una línea.',
            'lineas.min' => 'Una venta necesita al menos una línea.',
            'lineas.*.tipo.required' => 'Cada línea dice si es un servicio o un producto.',
            'lineas.*.cantidad.min' => 'La cantidad tiene que ser al menos uno.',
            'lineas.*.precio_unitario.min' => 'El precio no puede ser negativo.',
            'lineas.*.servicio_id.exists' => 'Uno de los servicios indicados no existe en este salón.',
            'lineas.*.producto_id.exists' => 'Uno de los productos indicados no existe en este salón.',
        ]);
    }

    /**
     * Las líneas, ya filtradas: sin `servicio_id` en las de producto ni con
     * `producto_id` en las de servicio.
     *
     * La base también lo rechaza, pero un error de SQL es más difícil de leer que
     * un mensaje que diga qué línea está mal.
     *
     * @return list<array<string, mixed>>
     */
    public function lineasLimpias(): array
    {
        $salida = [];

        foreach ((array) $this->input('lineas', []) as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $tipo = (string) ($linea['tipo'] ?? '');

            $salida[] = [
                'tipo' => $tipo,
                'servicio_id' => $tipo === TipoLineaVenta::Servicio->value
                    ? ($linea['servicio_id'] ?? null)
                    : null,
                'producto_id' => $tipo === TipoLineaVenta::Producto->value
                    ? ($linea['producto_id'] ?? null)
                    : null,
                'cantidad' => (int) ($linea['cantidad'] ?? 1),
                'precio_unitario' => isset($linea['precio_unitario'])
                    ? (int) $linea['precio_unitario']
                    : null,
            ];
        }

        return $salida;
    }
}