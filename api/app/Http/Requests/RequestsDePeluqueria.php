<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de los FormRequest de Peluqueria.
 *
 * POR QUÉ ESTA CLASE EXISTE
 * -------------------------
 * Para que la validación tenga un solo lugar y para que el rechazo por permiso
 * responda 404. Acá solo va lo que se puede comprobar sin mirar la base de
 * datos del salón; lo que necesita el contexto va en el servicio.
 *
 * CADA FORMREQUEST DECLARA SUS REGLAS
 * -----------------------------------
 * No hay reglas globales: una regla global aplicada a todos los formularios
 * termina validando campos que no existen en ninguno. Ver docs/09.
 */
abstract class RequestsDePeluqueria extends FormRequest
{
    public function authorize(): bool
    {
        // La comprobación fina la hace la Policy del recurso. Acá se devuelve
        // `true` para que la validación corra y el error de permiso llegue desde
        // la Policy, que responde 404 y no 403. Ver docs/03.
        return true;
    }

    /**
     * Mensajes de validación en el idioma de quien atiende.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'date' => 'El campo :attribute debe ser una fecha.',
            'date_format' => 'El campo :attribute no tiene el formato de fecha esperado.',
            'min' => [
                'string' => 'El campo :attribute debe tener al menos :min caracteres.',
                'numeric' => 'El campo :attribute debe ser al menos :min.',
                'array' => 'El campo :attribute debe tener al menos :min elementos.',
            ],
            'exists' => 'El valor seleccionado en :attribute no existe.',
            'in' => 'El valor seleccionado en :attribute no es válido.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'array' => 'El campo :attribute debe ser una lista.',
        ];
    }

    /**
     * Nombres de campo legibles en los mensajes.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'el nombre',
            'apellido' => 'el apellido',
            'rut' => 'el RUT',
            'telefono' => 'el teléfono',
            'correo' => 'el correo',
            'servicio_id' => 'el servicio',
            'profesional_id' => 'la profesional',
            'clienta_id' => 'la clienta',
            'fecha_inicio' => 'la hora de inicio',
            'fecha_fin' => 'la hora de término',
            'motivo' => 'el motivo',
            'duracion_minutos' => 'la duración',
            'precio' => 'el precio',
            'porcentaje_servicios' => 'el porcentaje de servicios',
            'porcentaje_productos' => 'el porcentaje de productos',
        ];
    }

    /**
     * ¿Está pidiendo solo una lectura?
     */
    protected function esLectura(): bool
{
    return in_array($this->method(), ['GET', 'HEAD'], true);
}
}