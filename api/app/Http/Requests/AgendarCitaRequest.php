<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoCita;

/**
 * Agendar o mover una cita.
 *
 * `fecha_inicio` ES LO ÚNICO QUE SE ENVÍA
 * -----------------------------------------
 * No se manda `fecha_fin` ni `duracion_minutos`. La duración sale del servicio y
 * de la profesional: si el cliente pudiera mandarla, podría agendar un corte de
 * tres horas. Ver App\Services\Agenda\ServicioAgenda.
 */
class AgendarCitaRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profesional_id' => ['required', 'uuid', 'exists:profesionales,id'],
            'clienta_id' => ['required', 'uuid', 'exists:clientas,id'],
            'servicio_id' => ['required', 'uuid', 'exists:servicios,id'],
            'fecha_inicio' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:240'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * La hora de término que muestra la pantalla, calculada acá.
     *
     * No es una validación: es una ayuda para que quien agenda vea el bloque
     * completo antes de confirmar. La cifra que manda es la que calcula el
     * servicio.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'profesional_id.exists' => 'La profesional indicada no existe en este salón.',
            'clienta_id.exists' => 'La clienta indicada no existe en este salón.',
            'servicio_id.exists' => 'El servicio indicado no existe en este salón.',
            'fecha_inicio.required' => 'Hay que indicar la hora de inicio.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'profesional_id' => 'la profesional',
            'clienta_id' => 'la clienta',
            'servicio_id' => 'el servicio',
            'fecha_inicio' => 'la hora de inicio',
        ]);
    }
}