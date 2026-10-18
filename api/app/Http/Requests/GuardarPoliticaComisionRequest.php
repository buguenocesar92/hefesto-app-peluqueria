<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BaseCalculo;
use Illuminate\Validation\Rule;

/**
 * Guardar una política de comisión.
 *
 * LOS PORCENTAJES SON DEL NEGOCIO, NO DEL CÓDIGO
 * ---------------------------------------------
 * No hay valor por omisión ni rango "razonable" impuesto. El salón declara lo que
 * paga. El único límite acá es 0 a 100, que es un hecho aritmético y no una
 * opinión.
 *
 * `base_calculo` ES LA DECISIÓN QUE EL DUEÑO DISCUTE
 * -------------------------------------------------
 * Por eso es un campo obligatorio y no un comportamiento escondido en el
 * cálculo. Ver App\Enums\BaseCalculo.
 */
class GuardarPoliticaComisionRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profesional_id' => ['nullable', 'uuid', 'exists:profesionales,id'],
            'porcentaje_servicios' => ['required', 'numeric', 'min:0', 'max:100'],
            'porcentaje_productos' => ['required', 'numeric', 'min:0', 'max:100'],
            'incluye_productos' => ['required', 'boolean'],
            'base_calculo' => ['required', Rule::in(array_column(BaseCalculo::cases(), 'value'))],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'porcentaje_servicios.required' => 'Hay que declarar el porcentaje de servicios.',
            'porcentaje_productos.required' => 'Hay que declarar el porcentaje de productos.',
            'base_calculo.required' => 'Hay que decidir si la base incluye servicios, productos o ambos.',
            'vigente_desde.required' => 'Hay que indicar desde cuándo rige esta política.',
            'porcentaje_servicios.max' => 'El porcentaje de servicios no puede pasar de 100.',
            'porcentaje_productos.max' => 'El porcentaje de productos no puede pasar de 100.',
        ]);
    }
}