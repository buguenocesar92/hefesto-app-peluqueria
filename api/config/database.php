<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Base de datos
|------------------------------------------------------------------------------
|
| PostgreSQL, y no otra cosa. El aislamiento entre salones está implementado con
| Row Level Security, que es una función de PostgreSQL: en otro motor no
| existiría, y las pruebas de aislamiento pasarían sin comprobar nada.
| Ver docs/03.
|
| LA CONEXIÓN DE AUDITORÍA
| ------------------------
| `auditoria` es la misma base por defecto, pero con su propio juego de
| variables. Es a propósito: la petición a la que se le deniega el acceso a los
| antecedentes de salud termina revertida, y el registro del intento tiene que
| sobrevivir a esa reversa. Con la misma conexión se perdería justo el registro
| que más importa. Ver App\Services\Tenancy\AuditorDeAcceso.
|
| Se pueden separar físicamente con `DB_AUDITORIA_*`: es una de las separaciones
| que suele pedir una auditoría externa. Ver docs/07.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'peluqueria'),
            'username' => env('DB_USERNAME', 'peluqueria'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            // La aplicación no usa SSL contra PostgreSQL porque los contenedores
            // se comunican por la red interna de Compose. Si la base queda
            // afuera, hay que poner `DB_SSLMODE=require` y revisar la cadena
            // de conexión. Ver docs/04.
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'auditoria' => [
            'driver' => 'pgsql',
            'host' => env('DB_AUDITORIA_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_AUDITORIA_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_AUDITORIA_DATABASE', env('DB_DATABASE', 'peluqueria')),
            'username' => env('DB_AUDITORIA_USERNAME', env('DB_USERNAME', 'peluqueria')),
            'password' => env('DB_AUDITORIA_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_AUDITORIA_SSLMODE', env('DB_SSLMODE', 'prefer')),
        ],

    ],

    /*
    | Lecturas MySQL y SQLite quedan para que la estructura sea la misma que en
    | las demás aplicaciones de Hefesto. Peluqueria NO los usa: la RLS es de
    | PostgreSQL y no se negocia. Ver docs/01.
    */
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'peluqueria'),
        'username' => env('DB_USERNAME', 'peluqueria'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
    ],

    'sqlite' => [
        'driver' => 'sqlite',
        'database' => env('DB_DATABASE', database_path('database.sqlite')),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];