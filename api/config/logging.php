<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Depuración
    |--------------------------------------------------------------------------
    |
    | Con `LOG_LEVEL=info`, el registro de la petición NO guarda el cuerpo. En un
    | salón el cuerpo de una petición puede ser la descripción de una atención con
    | datos de salud de la clienta. El nivel `info` ya guarda ruta, método y
    | duración, que es lo justo para diagnosticar sin copiar información de salud
    | al registro.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single,stderr')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/peluqueria.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],

        // Salida a consola: es la que leen los contenedores.
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
        ],

        /*
        | Bitácora de intentos de acceso a los antecedentes de salud a los que no
        | se les dio acceso. Ocurre cuando la petición ni siquiera tiene salón
        | (no hay sesión, o la cuenta no tiene salón asociado): en ese momento no
        | hay `tenant_id` con el que escribir, así que el intento queda acá. Ver
        | App\Services\Tenancy\AuditorDeAcceso.
        */
        'accesos_rechazados' => [
            'driver' => 'single',
            'path' => storage_path('logs/accesos-rechazados.log'),
            'level' => 'warning',
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/peluqueria.log'),
        ],

    ],

];