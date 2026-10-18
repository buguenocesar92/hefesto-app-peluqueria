<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Cerrar el corte de caja del día.
 *
 * `total_declarado` ES LO QUE DICE LA PERSONA
 * --------------------------------------------
 * Va aparte del esperado a propósito: son dos hechos distintos. El esperado lo
 * calcula el sistema desde las ventas; el declarado lo dice quien tiene la caja
 * en la mano. La diferencia entre los dos es el número que hay que mirar, y no
 * se puede calcular si solo se manda uno.
 */
class CerrarCorteCajaRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'total_declarado' => ['required', 'integer', 'min:0'],
            'total_efectivo' => ['required', 'integer', 'min:0'],
            'total_tarjeta' => ['required', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'total_declarado.required' => 'Hay que declarar cuánto hay en la caja.',
            'total_efectivo.required' => 'Hay que declarar cuánto hay en efectivo.',
            'total_tarjeta.required' => 'Hay que declarar cuánto hay en tarjeta.',
            'total_declarado.min' => 'El monto declarado no puede ser negativo.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'total_declarado' => 'el total declarado',
            'total_efectivo' => 'el efectivo',
            'total_tarjeta' => 'lo cobrado en tarjeta',
        ]);
    }
}