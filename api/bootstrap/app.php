<?php

declare(strict_types=1);

use App\Http\Middleware\AuditarSalud;
use App\Http\Middleware\ResolverSalon;
use App\Http\Middleware\SoloAdministrador;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Punto de arranque de la API de Peluqueria.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto
 * (artisan, public/index.php, storage) lo genera Composer. Ver docs/04.
 *
 * Todas las rutas cuelgan del grupo `web` de Laravel porque la interfaz
 * (Nuxt) se autentica con cookie de sesión: la cookie viaja en el mismo origen y
 * el token CSRF se reenvía como cabecera X-XSRF-TOKEN. Eso es lo que hace que no
 * haya ningún token en el código del navegador. Ver docs/01.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withProviders([
        // Enlaza los servicios de OIDC con sus escalares de configuración. Los
        // permisos NO se registran acá: cada ruta vuelve a preguntar a su
        // Policy. Ver docs/03.
        AppServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // ResolverSalon envuelve la petición autenticada en una transacción con
        // el salón ya fijado, para que la Row Level Security sepa de quién son
        // las filas. Corre ANTES que `auth`, por eso resuelve el usuario por su
        // cuenta con el guard `web`.
        $middleware->web(append: [
            ResolverSalon::class,
        ]);

        $middleware->alias([
            // Marca las rutas que abren los antecedentes de salud de una
            // clienta. El middleware anota cada intento en `accesos_salud`,
            // permitido o denegado, y corta con 404 antes de que la acción
            // llegue al controlador.
            'salud.auditado' => AuditarSalud::class,

            // Administración del salón: catálogos, políticas de comisión,
            // liquidaciones y reportes.
            'admin' => SoloAdministrador::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un rechazo de autorización dentro de la API responde 404, no 403: un
        // 403 confirmaría que el recurso existe. En una ficha con datos de salud
        // eso importa más que en un portal: no se le dice a quien preguntó si la
        // clienta existe en otro salón.
        $exceptions->render(function (AuthorizationException $e, Request $peticion) {
            if ($peticion->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });

        // Un error del DOMINIO DE NEGOCIO —un choque de agenda, un bloqueo de
        // salud, una venta que ya se cobró, una política de comisión que no
        // existe— se traduce a 422 con un mensaje que la persona puede leer. Un
        // 500 haría pensar que la aplicación se cayó, y lo que pasó es que el
        // sistema hizo lo que debía.
        //
        // Solo `DomainException`. Un `RuntimeException` sigue siendo un 500,
        // porque un error de infraestructura —que no haya contexto de salón,
        // por ejemplo— tiene que verse como lo que es y no vestirse de error de
        // negocio.
        $exceptions->render(function (DomainException $e, Request $peticion) {
            if ($peticion->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => $e->getMessage(),
                ], 422);
            }

            return null;
        });
    })
    ->create();