<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RolSalon;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de administración para quien no administra.
 *
 * ES LA PUERTA, NO LA REGLA
 * -------------------------
 * Este middleware responde rápido y con un mensaje claro; el control fino lo
 * hace la Policy de cada recurso. Son dos capas a propósito: si alguien olvida
 * la Policy, la puerta sigue cerrada.
 *
 * EL 403 SÍ SE USA ACÁ, Y A PROPÓSITO
 * ------------------------------------
 * En el resto de la API un rechazo es 404 para no confirmar que un recurso
 * existe. Acá no hay un recurso que pueda revelar: la respuesta dice "tu rol no
 * llega acá", que es información sobre la persona, no sobre los datos del
 * salón. Ver docs/03.
 */
class SoloAdministrador
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof Usuario || $usuario->rol !== RolSalon::Administrador) {
            abort(403, 'Esta sección es solo para administración del salón.');
        }

        return $siguiente($peticion);
    }
}