<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Clienta;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;
use App\Services\Tenancy\AuditorDeAcceso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca las rutas que abren los antecedentes de salud de una clienta.
 *
 * CADA INTENTO SE ANOTA, PERMITIDO O DENEGADO
 * -------------------------------------------
 * Un intento denegado es información: en un salón con datos sensibles es la más
 * importante. Por eso el corte por permiso pasa POR ESTE MIDDLEWARE y no dentro
 * de la Policy: así el intento queda anotado aunque la Policy no llegue a
 * ejecutarse.
 *
 * EL RECURSO PUEDE NO EXISTIR
 * ---------------------------
 * Si la clienta no existe, no hay nada que auditar: se responde 404 y no se
 * anota. Anotar un intento sobre un identificador inventado llena el registro de
 * ruido y esconde los intentos de verdad.
 *
 * EL PERMITIDO SE ANOTA DESPUÉS DE RESPONDER
 * ------------------------------------------
 * Si la operación falla, se anota el denegado; si llega a completarse, se anota
 * el permitido. Anotar antes sería mentir cuando el controlador después revienta.
 *
 * Ver docs/03 y docs/07.
 */
class AuditarSalud
{
    public function __construct(
        private readonly AuditorDeAcceso $auditor,
        private readonly CapacidadesDeUsuario $capacidades,
    ) {}

    public function handle(Request $peticion, Closure $siguiente, string $accion): Response
    {
        $clienta = $peticion->route('clienta');

        if (! $clienta instanceof Clienta) {
            abort(404, 'No encontrado.');
        }

        $usuario = $peticion->user();

        $this->comprobarPermiso($usuario, $clienta, $accion, $peticion);

        try {
            $respuesta = $siguiente($peticion);
        } catch (\Throwable $e) {
            $this->auditor->registrarDenegado(
                tenantId: $usuario instanceof Usuario ? (string) $usuario->tenant_id : (string) $clienta->tenant_id,
                clientaId: (string) $clienta->id,
                usuarioId: $usuario instanceof Usuario ? (string) $usuario->id : null,
                accion: $accion,
                detalle: 'La operación falló: ' . $e->getMessage(),
                ruta: $peticion->path(),
                ip: $peticion->ip(),
            );

            throw $e;
        }

        if ($usuario instanceof Usuario) {
            $this->auditor->registrarPermitido(
                clienta: $clienta,
                usuario: $usuario,
                accion: $accion,
                ruta: $peticion->path(),
                ip: $peticion->ip(),
            );
        }

        return $respuesta;
    }

    private function comprobarPermiso(?Usuario $usuario, Clienta $clienta, string $accion, Request $peticion): void
    {
        if ($usuario !== null && $this->capacidades->puedeVerAntecedentes($usuario)) {
            return;
        }

        $this->auditor->registrarDenegado(
            tenantId: $usuario instanceof Usuario ? (string) $usuario->tenant_id : (string) $clienta->tenant_id,
            clientaId: (string) $clienta->id,
            usuarioId: $usuario instanceof Usuario ? (string) $usuario->id : null,
            accion: $accion,
            detalle: 'La sesión no tiene la capacidad de ver antecedentes de salud.',
            ruta: $peticion->path(),
            ip: $peticion->ip(),
        );

        abort(404, 'No encontrado.');
    }
}