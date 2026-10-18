<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoSalon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el salón de la petición para que la RLS de PostgreSQL sepa a quién
 * pertenece cada fila.
 *
 * Se aplica al grupo `web` ANTES del middleware `auth` de la ruta, así que
 * resuelve el usuario por su cuenta con el guard `web`, que ya lee la sesión.
 *
 * Peticiones sin sesión (inicio de sesión, salud) pasan sin transacción. Si esas
 * rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaría excepción
 * y la petición fallaría: el aislamiento falla cerrado, nunca abierto.
 */
class ResolverSalon
{
    public function __construct(private readonly ContextoSalon $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin salón no puede operar. Se corta antes de tocar la
            // base de datos.
            abort(403, 'La cuenta no está asociada a ningún salón.');
        }

        return $this->contexto->ejecutarAislado(
            (string) $usuario->tenant_id,
            static fn () => $siguiente($peticion)
        );
    }
}