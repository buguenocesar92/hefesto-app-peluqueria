<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoCita;
use Illuminate\Validation\Rule;

/**
 * Registrar cómo terminó de verdad una cita.
 *
 * ESTO NO ES EDITAR LA CITA
 * -------------------------
 * No cambia lo agendado: cambia lo que pasó. Por eso son rutas y métodos
 * separados, y por eso se escriben las horas reales y el retraso se CALCULA.
 *
 * `minutos_retraso` NO SE ENVÍA
 * ----------------------------
 * Se calcula acá. Si el cliente lo mandara, podría reportar cero retraso en un
 * día con veinte minutos de retraso, que es justamente el número que el dueño
 * necesita ver bien.
 */
class RegistrarRealidadCitaRequest extends RequestsDePeluqueria
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in([
                EstadoCita::Atendida->value,
                EstadoCita::NoAsistio->value,
                EstadoCita::Anulada->value,
            ])],
            'hora_inicio_real' => ['nullable', 'date', 'before_or_equal:hora_fin_real'],
            'hora_fin_real' => ['nullable', 'date', 'after:hora_inicio_real'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'estado.required' => 'Hay que indicar cómo terminó la cita.',
            'hora_inicio_real.before_or_equal:hora_fin_real' => 'La clienta no puede haberse levantado antes de sentarse.',
            'hora_fin_real.after:hora_inicio_real' => 'La clienta no puede haberse levantado antes de sentarse.',
        ]);
    }
}