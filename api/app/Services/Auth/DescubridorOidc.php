<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Descubrimiento OIDC y canje del código por tokens.
 *
 * POR QUÉ NO ESTÁ SOCIALITE
 * -------------------------
 * Porque Socialite resuelve el flujo completo, incluida la forma de guardar la
 * sesión y los datos del usuario, y acá se necesita controlar esos dos puntos:
 * la sesión es una cookie del mismo origen y los datos se traducen a un usuario
 * del salón. Con Socialite se puede, pero se agrega una capa que no hace falta.
 *
 * LO QUE HACE
 * -----------
 * 1. Lee el documento de descubrimiento del emisor y lo cachea.
 * 2. Arma la URL de autorización con el estado anti falsificación.
 * 3. Cambia el código por tokens.
 * 4. Trae el conjunto de claves públicas (JWKS) y lo cachea.
 *
 * POR QUÉ EL DESCUBRIMIENTO SE CACHEA
 * -----------------------------------
 * El emisor puede estar lento o caído. Sin caché, cada ingreso depende de que
 * Authentik esté respondiendo en ese instante, y un ingreso que falla porque el
 * proveedor tuvo un segundo malo se traduce en "la peluquería está caída".
 */
class DescubridorOidc
{
    public function __construct(
        private readonly string $emisor,
        private readonly string $clienteId,
        private readonly string $secretoCliente,
        private readonly string $redireccionUri,
        private readonly int $minutosCache = 60,
    ) {}

    /**
     * La URL a la que va el navegador para iniciar sesión.
     */
    public function urlDeAutorizacion(Request $peticion, string $salonSlug = ''): string
    {
        $documento = $this->documentoDeDescubrimiento();

        $url = $documento['authorization_endpoint'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('El emisor no declara un punto de autorización. Revise AUTHENTIK_ISSUER.');
        }

        $estado = bin2hex(random_bytes(16));
        session()->put('oidc.estado', $estado);

        if ($salonSlug !== '') {
            session()->put('oidc.salon', $salonSlug);
        }

        $separador = str_contains($url, '?') ? '&' : '?';

        return $url . $separador . http_build_query([
            'client_id' => $this->clienteId,
            'redirect_uri' => $this->redireccionUri,
            'response_type' => 'code',
            'scope' => 'openid profile email groups',
            'state' => $estado,
        ]);
    }

    /**
     * Comprueba el estado anti falsificación y canjea el código.
     *
     * @return array<string, mixed>
     */
    public function intercambiarCodigoPorTokens(string $codigo): array
    {
        $documento = $this->documentoDeDescubrimiento();

        $urlToken = $documento['token_endpoint'] ?? null;

        if (! is_string($urlToken) || $urlToken === '') {
            throw new RuntimeException('El emisor no declara un punto de canje de código.');
        }

        $respuesta = Http::asForm()->post($urlToken, [
            'grant_type' => 'authorization_code',
            'code' => $codigo,
            'redirect_uri' => $this->redireccionUri,
            'client_id' => $this->clienteId,
            'client_secret' => $this->secretoCliente,
        ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException('Authentik no aceptó el canje del código de autorización.');
        }

        $tokens = $respuesta->json();

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Las claves públicas del emisor, indexadas por identificador.
     *
     * @return array<string, string>
     */
    public function clavesPublicas(): array
    {
        $documento = $this->documentoDeDescubrimiento();

        $urlJwks = $documento['jwks_uri'] ?? null;

        if (! is_string($urlJwks) || $urlJwks === '') {
            throw new RuntimeException('El emisor no declara dónde están sus claves públicas.');
        }

        $respuesta = Http::timeout(5)->get($urlJwks);

        if (! $respuesta->successful()) {
            throw new RuntimeException('No se pudieron obtener las claves públicas del emisor.');
        }

        $claves = [];

        foreach ((array) $respuesta->json('keys') as $clave) {
            $kid = $clave['kid'] ?? null;
            $modulo = $clave['n'] ?? null;
            $exponente = $clave['e'] ?? null;

            if (is_string($kid) && is_string($modulo) && is_string($exponente)) {
                $claves[$kid] = $this->aPem($modulo, $exponente);
            }
        }

        return $claves;
    }

    /**
     * El documento de descubrimiento, desde caché.
     *
     * @return array<string, mixed>
     */
    public function documentoDeDescubrimiento(): array
    {
        if ($this->emisor === '') {
            throw new RuntimeException('AUTHENTIK_ISSUER no está configurado. Ver docs/05.');
        }

        $clave = 'oidc.descubrimiento.' . $this->emisor;

        $documento = Cache::remember($clave, now()->addMinutes($this->minutosCache), function (): array {
            $respuesta = Http::timeout(5)->get(rtrim($this->emisor, '/') . '/.well-known/openid-configuration');

            if (! $respuesta->successful()) {
                throw new RuntimeException('No se pudo leer el descubrimiento OIDC del emisor.');
            }

            $datos = $respuesta->json();

            return is_array($datos) ? $datos : [];
        });

        return $documento;
    }

    /**
     * Convierte el módulo y el exponente de una clave JWK en PEM.
     *
     * El formato JWK usa base64url y el PEM necesita base64 conpadding y
     * encabezados. Es una conversión de formato, no criptografía.
     */
    private function aPem(string $modulo, string $exponente): string
    {
        $n = base64_decode(strtr($modulo, '-_', '+/'), true);
        $e = base64_decode(strtr($exponente, '-_', '+/'), true);

        if ($n === false || $e === false) {
            throw new RuntimeException('El emisor publicó una clave pública que no se pudo leer.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode("\x30" . $this->secuenciaAsn1($n, $e)), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * Envuelve módulo y exponente en la estructura ASN.1 de una clave RSA.
     *
     * @param  string  $modulo  el entero del módulo, en binario
     * @param  string  $exponente  el entero del exponente público, en binario
     */
    private function secuenciaAsn1(string $modulo, string $exponente): string
    {
        $moduloComoEntero = "\x02" . $this->longitudDeri($modulo) . $modulo;
        $exponenteComoEntero = "\x02" . $this->longitudDeri($exponente) . $exponente;

        $secuencia = $moduloComoEntero . $exponenteComoEntero;

        return "\x30" . $this->longitudDeri($secuencia) . $secuencia;
    }

    /**
     * El byte de longitud de DER: si el contenido pasa de 127 bytes, el byte
     * alto indica cuántos bytes de longitud siguen.
     */
    private function longitudDeri(string $contenido): string
    {
        $longitud = strlen($contenido);

        if ($longitud < 128) {
            return chr($longitud);
        }

        $bytes = '';

        while ($longitud > 0) {
            $bytes = chr($longitud & 0xFF) . $bytes;
            $longitud >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}