<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Profesional;
use App\Services\Comisiones\RegistradorDeLiquidacion;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Calcula y guarda la liquidación de una profesional para un período.
 *
 * POR QUÉ ESTÁ DIFERIDA
 * --------------------
 * Recorrer todas las ventas del período y guardar el desglose línea por línea
 * es trabajo de minutos cuando el salón tiene historial. Si eso corriera dentro
 * de una petición, el dueño esperaría en la pantalla, y una espera larga en un
 * botón de "liquidar" se lee como que el sistema se colgó.
 *
 * Y es trabajo diferido DE VERDAD
 * ------------------------------
 * El cálculo tiene que correr dentro de una transacción con el salón fijado, con
 * el alcance de `set_config(..., true)`. Por eso este Job NO se despacha directo
 * sobre la conexión del worker: llama a `ContextoSalon::ejecutarAislado()`, que
 * es el mismo camino que usa cada petición.
 *
 * SIN ESTILO `Final`: el contenedor de Laravel necesita instanciar la clase.
 */
class LiquidarComisionesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $salonId  identificador del salón, no el modelo
     * @param  string  $profesionalId  identificador de la profesional
     * @param  string  $desde  fecha inicial del período, en formato Y-m-d
     * @param  string  $hasta  fecha final del período, en formato Y-m-d
     */
    public function __construct(
        public readonly string $salonId,
        public readonly string $profesionalId,
        public readonly string $desde,
        public readonly string $hasta,
    ) {}

    public function handle(ContextoSalon $contexto, RegistradorDeLiquidacion $registrador): void
    {
        $contexto->ejecutarAislado($this->salonId, function () use ($registrador): void {
            $profesional = Profesional::query()->findOrFail($this->profesionalId);

            $registrador->liquidar(
                $profesional,
                Carbon::parse($this->desde)->startOfDay(),
                Carbon::parse($this->hasta)->endOfDay(),
            );
        });
    }

    /**
     * Lo que falla queda en el registro y vuelve a la cola.
     *
     * Es importante que el fallo NO se trague: una liquidación que no se pudo
     * calcular es un mes de sueldo sin pagar, y eso tiene que ser visible.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('no se pudo liquidar la comisión', [
            'salon_id' => $this->salonId,
            'profesional_id' => $this->profesionalId,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'error' => $e->getMessage(),
        ]);
    }
}