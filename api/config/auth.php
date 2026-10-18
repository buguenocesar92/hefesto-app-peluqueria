<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Guard web
    |--------------------------------------------------------------------------
    |
    | Peluqueria no tiene usuarios ni contraseñas propias: la identidad la
    | emite Authentik con OpenID Connect y Laravel la recibe como cookie de
    | sesión (ver App\Services\Auth). El guard `web` es el único que existe.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'usuarios',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Usuario::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reinicio de contraseña
    |--------------------------------------------------------------------------
    |
    | Se deja el bloque porque Laravel lo espera, pero esta aplicación NO tiene
    | contraseñas: el restablecimiento se hace en Authentik. No se debe habilitar
    | esta vía sin una decisión de seguridad previa. Ver docs/07.
    |
    */

    'passwords' => [
        'usuarios' => [
            'provider' => 'usuarios',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiempo de expiración de la sesión
    |--------------------------------------------------------------------------
    |
    | En un salón, donde el equipo se turna en el mismo puesto y la agenda abre
    | fichas con datos de salud, una sesión abierta mucho rato es una sesión
    | abierta a la ficha de la clienta que vino antes. El valor por omisión de
    | Laravel es 120 minutos.
    |
    */

    'expiration' => (int) env('PELUQUERIA_EXPIRACION_SESION', 120),

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

];