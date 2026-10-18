<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\AccesoSalud;
use App\Models\Clienta;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de accesos a los antecedentes de salud.
 *
 * POR QUÉ UNA CONEXIÓN APARTE PARA EL INTENTO DENEGADO
 * ---------------------------------------------------
 * Una petición que se deniega termina revertida, y el registro del intento tiene
 * que SOBREVIVIR a esa reversa. Con la misma conexión se perdería justo el
 * registro que más importa. Por eso `registrarDenegado()` escribe por la
 * conexión `auditoria`, que tiene su propio juego de variables.
 *
 * CUANDO HAY TENANT Y CUANDO NO
 * ----------------------------
 * Con `app.tenant_id` fijado, el registro va en la transacción normal y se
 * protege con la RLS de siempre. Sin contexto —una petición que ni siquiera
 * tiene sesión— no hay `tenant_id` con el que escribir, y el registro queda en
 * el canal de rechazos, que es texto plano y no necesita la base.
 */
class AuditorDeAcceso
{
    /**
     * Anota un acceso permitido.
     *
     * @param  string  $accion  por ejemplo `ver_antecedentes`
     */
    public function registrarPermitido(
        Clienta $clienta,
        Usuario $usuario,
        string $accion,
        ?string $ruta = null,
        ?string $ip = null,
    ): void {
        AccesoSalud::query()->create([
            'tenant_id' => $clienta->tenant_id,
            'clienta_id' => $clienta->id,
            'usuario_id' => $usuario->id,
            'resultado' => 'permitido',
            'accion' => $accion,
            'ruta' => $ruta,
            'ip_address' => $ip,
        ]);
    }

    /**
     * Anota un acceso DENEGADO.
     *
     * @param  string|null  $tenantId  `null` si la petición no tenía salón
     */
    public function registrarDenegado(
        ?string $tenantId,
        ?string $clientaId,
        ?string $usuarioId,
        string $accion,
        string $detalle,
        ?string $ruta = null,
        ?string $ip = null,
    ): void {
        /*
         * Sin salón no hay `tenant_id` con el que escribir en una tabla que tiene
         * RLS: la escritura fallaría y, con ella, la petición. Por eso el caso
         * sin contexto va al canal de rechazos, que es texto.
         */
        if ($tenantId === null) {
            Log::warning('acceso a antecedentes de salud sin contexto de salon', [
                'accion' => $accion,
                'detalle' => $detalle,
                'ruta' => $ruta,
                'ip' => $ip,
            ]);

            return;
        }

        try {
            DB::connection('auditoria')->table('accesos_salud')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'clienta_id' => $clientaId,
                'usuario_id' => $usuarioId,
                'resultado' => 'denegado',
                'accion' => $accion,
                'ip_address' => $ip,
                'ruta' => $ruta,
                'detalle' => $detalle,
                'creado_en' => now(),
            ]);
        } catch (Throwable $e) {
            /*
             * Un registro que no se puede escribir NO puede tumbar la petición:
             * la denegación ya ocurrió y la respuesta va igual. Perder el
             * registro es malo, pero tumbar la pantalla de la profesional que
             * intenta trabajar es peor. Se deja rastro en el registro general.
             */
            Log::error('no se pudo registrar el intento denegado de acceso a salud', [
                'tenant_id' => $tenantId,
                'accion' => $accion,
                'error' => $e->getMessage(),
            ]);
        }
    }
}