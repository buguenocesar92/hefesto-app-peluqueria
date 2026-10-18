<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RolSalon;
use App\Enums\SeveridadAntecedente;
use App\Enums\TipoAntecedente;
use App\Models\Usuario;
use Illuminate\Validation\Rule;

/**
 * Guardar un antecedente de salud.
 *
 * LA SEVERIDAD LA PUEDE PONER LA PROFESIONAL O LA ADMINISTRACIÓN
 * ---------------------------------------------------------------
 * Recepción registra lo que la clienta informa; no decide cuánto pesa. Por eso el
 * FormRequest NO acepta `severidad` ni `bloquea_servicios` desde una cuenta que no
 * sea de profesional o de administración: se ignora en vez de confiar.
 *
 * En `services_afectados` está en `null` en vez de lista vacía a propósito:
 * `null` significa "afecta a todos los servicios", que es el caso prudente. Una
 * lista vacía significaría "no afecta a ninguno", que es lo contrario de lo que
 * alguien que se saltó el campo quiere decir.
 */
class GuardarAntecedenteRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(array_column(TipoAntecedente::cases(), 'value'))],
            'descripcion' => ['required', 'string', 'max:480'],
            'severidad' => ['required', Rule::in(array_column(SeveridadAntecedente::cases(), 'value'))],
            'servicios_afectados' => ['nullable', 'array', 'max:50'],
            'servicios_afectados.*' => ['uuid', 'exists:servicios,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'descripcion.required' => 'Hay que describir el antecedente: sin descripción no sirve de nada en la agenda.',
            'tipo.required' => 'Hay que indicar el tipo de antecedente.',
            'servicios_afectados.*.exists' => 'Uno de los servicios indicados no existe en este salón.',
        ]);
    }

    /**
     * ¿Puede esta cuenta marcar el antecedente como bloqueante?
     *
     * Lo decide el servidor, no el cliente: un campo que se manda pero se ignora
     * es mejor que un campo que se manda y engaña.
     */
    public function puedeMarcarBloqueo(Usuario $usuario): bool
    {
        return $usuario->rol !== RolSalon::Recepcion;
    }
}