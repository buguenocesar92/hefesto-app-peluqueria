<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Auth\VerificadorIdToken;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaces del contenedor de Laravel.
 *
 * POR QUÉ HACE FALTA ESTE ARCHIVO
 * ------------------------------
 * `DescubridorOidc`, `VerificadorIdToken` y `ProveedorOidcAuthentik` reciben
 * escalares en el constructor (la URL del emisor, el identificador del cliente,
 * el secreto). El contenedor no puede adivinar de dónde sale un `string`, así que
 * hay que decírselo explícitamente una vez. Esa es toda la función de este
 * archivo.
 *
 * NO HAY NADA MÁS ACÁ
 * -------------------
 * Los permisos no se registran como Gates: cada ruta vuelve a preguntar a su
 * `Policy`, y es la Policy la que decide. Un Gate global sería un atajo que salta
 * las comprobaciones. Ver docs/03.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DescubridorOidc::class, static fn (): DescubridorOidc => new DescubridorOidc(
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
            secretoCliente: (string) config('authentik.cliente_secreto'),
            redireccionUri: (string) config('authentik.redireccion_uri'),
            minutosCache: (int) config('authentik.minutos_de_cache_descubrimiento', 60),
        ));

        /*
         * El verificador recibe las claves públicas del emisor. Se resuelven
         * acá, y no dentro del verificador, para que el trabajo de red quede en
         * un solo lugar.
         *
         * Es perezoso a propósito: esta instancia solo se construye cuando
         * alguien entra, no cuando arranca el contenedor. Si el emisor está
         * caído, las rutas que no son de autenticación siguen respondiendo.
         */
        $this->app->singleton(VerificadorIdToken::class, static fn ($contenedor): VerificadorIdToken => new VerificadorIdToken(
            clavesPublicas: $contenedor->make(DescubridorOidc::class)->clavesPublicas(),
            emisorEsperado: (string) config('authentik.emisor'),
            audienciaEsperada: (string) config('authentik.audiencia_esperada'),
            algoritmo: (string) config('authentik.algoritmo', 'RS256'),
            margenDeTiempo: (int) config('authentik.margen_de_tiempo', 60),
        ));

        $this->app->singleton(ProveedorOidcAuthentik::class, static fn ($contenedor): ProveedorOidcAuthentik => new ProveedorOidcAuthentik(
            descubridor: $contenedor->make(DescubridorOidc::class),
            emisor: (string) config('authentik.emisor'),
            clienteId: (string) config('authentik.cliente_id'),
            equiposPorRol: (array) config('authentik.equipo_por_rol', []),
        ));
    }

    public function boot(): void
    {
        // Sin policies registradas a mano: Laravel descubre `App\Policies` por
        // convención, así que cada Policy escrita acá queda disponible por su
        // nombre de modelo.
    }
}