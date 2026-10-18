<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentik (identidad de Hefesto)
|------------------------------------------------------------------------------
|
| Peluqueria NO tiene contraseñas propias: la identidad la emite Authentik con
| OpenID Connect. Ver docs/01 y docs/05.
|
| CÓMO SE LEE EL SALÓN
| ---------------------
| De los grupos del `id_token`. El grupo `salon-<identificador>` declara a qué
| salón pertenece la persona, y el rol se toma del equipo correspondiente. Sin
| grupo de salón no hay ingreso: una cuenta sin salón no puede operar. Ver
| App\Services\Auth\ProveedorOidcAuthentik.
|
| POR QUÉ EL ROL SE RESUELVE EN CADA INGRESO
| ------------------------------------------
| Si en Authentik le quitan un equipo a alguien, en Peluqueria pierde el acceso
| en la sesión siguiente. Guardar el rol para siempre deja ventanas de acceso que
| nadie cerraba. Por eso `usuarios.rol` es una copia de trabajo, no la fuente de
| verdad: la fuente es Authentik.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Emisor y cliente
    |----------------------------------------------------------------------
    |
    | URL pública del emisor, SIN barra final. El descubrimiento OIDC se lee de
    | `<emisor>/.well-known/openid-configuration`.
    |
    */
    'emisor' => env('AUTHENTIK_ISSUER', ''),

    'cliente_id' => env('AUTHENTIK_CLIENT_ID', ''),

    'cliente_secreto' => env('AUTHENTIK_CLIENT_SECRET', ''),

    /*
    | Debe coincidir exactamente con la URL de retorno configurada en el
    | proveedor de Authentik. Si no coincide, el canje del código falla y el
    | error es difícil de entender: es el primer lugar donde mirar cuando el
    | ingreso rebota.
    */
    'redireccion_uri' => env('AUTHENTIK_REDIRECT_URI', ''),

    /*
    |----------------------------------------------------------------------
    | Verificación del token
    |----------------------------------------------------------------------
    |
    | Socialite no está en el flujo de esta aplicación: el canje del código y la
    | verificación de la firma los hacen App\Services\Auth\DescubridorOidc y
    | App\Services\Auth\VerificadorIdToken, con `firebase/php-jwt` y las claves
    | públicas que publica el propio emisor.
    |
    | Se verifican `iss`, `aud` y `exp` SIEMPRE, sin opción de apagarlos: sin eso,
    | un token emitido para otra aplicación del mismo emisor pasaría por válido.
    |
    */
    'algoritmo' => env('AUTHENTIK_ALGORITMO', 'RS256'),

    'audiencia_esperada' => env('AUTHENTIK_AUDIENCE', env('AUTHENTIK_CLIENT_ID', '')),

    // Segundos de tolerancia para el desfase de relojes al verificar `exp`.
    'margen_de_tiempo' => (int) env('AUTHENTIK_LEEWAY', 60),

    'minutos_de_cache_descubrimiento' => (int) env('AUTHENTIK_CACHE_METADATA', 60),

    'minutos_de_cache_claves' => (int) env('AUTHENTIK_CACHE_JWKS', 1440),

    /*
    |----------------------------------------------------------------------
    | Equipos que declaran el rol
    |----------------------------------------------------------------------
    |
    | `equipo_por_rol` mapea el nombre del equipo de Authentik al rol interno.
    |
    | Hay que completar esto al instalar. Ver docs/05.
    |
    */
    'prefijo_salon' => env('AUTHENTIK_GRUPO_POR_SALON', 'salon'),

    'equipo_por_rol' => [
        'recepcion' => env('AUTHENTIK_EQUIPO_RECEPCION', 'Recepcion de Peluqueria'),
        'profesional' => env('AUTHENTIK_EQUIPO_PROFESIONAL', 'Profesionales de Peluqueria'),
        'administrador' => env('AUTHENTIK_EQUIPO_ADMINISTRADOR', 'Administradores de Peluqueria'),
    ],

];