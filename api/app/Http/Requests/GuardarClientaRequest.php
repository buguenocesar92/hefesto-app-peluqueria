<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Guardar una clienta.
 *
 * EL RUT SE VALIDA AQUÍ, NO EN LA BASE
 * -----------------------------------
 * La base guarda el dato; el FormRequest lo comprueba. Un RUT con dígito
 * verificador malo es un error de tipeo que se detecta al escribir, no tres meses
 * después cuando aparece una orden de compra con el nombre equivocado.
 */
class GuardarClientaRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'apellido' => ['nullable', 'string', 'max:160'],
            'rut' => ['nullable', 'string', 'max:16', 'regex:/^[0-9Kk]{1,8}-?[0-9Kk]{0,1}$/'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'correo' => ['nullable', 'email', 'max:160'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'rut.regex' => 'El RUT no tiene un formato válido. Se escribe sin puntos: 12345678-9.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'nombre.required' => 'La clienta necesita un nombre.',
        ]);
    }

    /**
     * El RUT limpio, sin puntos ni guion, para guardarlo.
     */
    public function rutNormalizado(): ?string
    {
        $rut = $this->input('rut');

        if (! is_string($rut) || trim($rut) === '') {
            return null;
        }

        return strtoupper(str_replace(['.', ' ', '-'], '', trim($rut)));
    }
}