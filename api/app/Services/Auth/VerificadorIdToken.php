<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Verificación de la firma del `id_token`.
 *
 * QUÉ COMPRUEBA
 * -------------
 * 1. Que la firma sea válida contra una clave pública del emisor.
 * 2. Que `iss` sea el emisor esperado. Sin esto, un token emitido para OTRA
 *    aplicación del mismo emisor pasaría por válido.
 * 3. Que `aud` incluya a esta aplicación.
 * 4. Que no haya expirado, con un margen de tolerancia para el desfase de
 *    relojes entre el proveedor y este servidor.
 *
 * POR QUÉ NO HAY INTERRUPTORES PARA APAGAR ESTAS COMPROBACIONES
 * --------------------------------------------------------------
 * Porque desactivar cualquiera de ellas hace que el sistema acepte tokens de
 * otros, y eso no es una opción de configuración: es una puerta abierta. No hay
 * forma de desactivarlas.
 */
class VerificadorIdToken
{
    /**
     * @param  array<string, string>  $clavesPublicas  kids del emisor y su clave PEM
     */
    public function __construct(
        private readonly array $clavesPublicas,
        private readonly string $emisorEsperado,
        private readonly string $audienciaEsperada,
        private readonly string $algoritmo = 'RS256',
        private readonly int $margenDeTiempo = 60,
    ) {}

    /**
     * Verifica el token y devuelve sus claims.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si el token no es válido. El mensaje dice QUÉ
     *                          falló, no "error de autenticación": un error
     *                          genérico en el ingreso es imposible de diagnosticar.
     */
    public function verificar(string $idToken): array
    {
        if ($this->emisorEsperado === '' || $this->audienciaEsperada === '') {
            throw new RuntimeException(
                'El emisor o la audiencia de Authentik no están configurados. '
                . 'Complete AUTHENTIK_ISSUER y AUTHENTIK_CLIENT_ID. Ver docs/05.'
            );
        }

        if ($this->clavesPublicas === []) {
            throw new RuntimeException('El emisor no publicó claves públicas. No se puede verificar el token.');
        }

        try {
            $decodificado = JWT::decode(
                $idToken,
                $this->clavesPublicasForToken($idToken),
            );
        } catch (\Throwable $e) {
            throw new RuntimeException('El token de identidad no es válido: ' . $e->getMessage(), 0, $e);
        }

        $claims = (array) $decodificado;

        // `iss` se compara sin barra final a propósito: el emisor puede
        // declararla con o sin barra y son el mismo emisor.
        $emisor = (string) ($claims['iss'] ?? '');

        if (rtrim($emisor, '/') !== rtrim($this->emisorEsperado, '/')) {
            throw new RuntimeException('El token viene de otro emisor de identidad.');
        }

        if (! $this->audienciaCoincide($claims)) {
            throw new RuntimeException('El token no es para esta aplicación.');
        }

        $this->comprobarExpiracion($claims);

        return $claims;
    }

    /**
     * Las claves del emisor, indexadas por identificador de clave.
     *
     * @return array<string, Key>
     */
    private function clavesPublicasForToken(string $idToken): array
    {
        $claves = [];

        foreach ($this->clavesPublicas as $kid => $pem) {
            $claves[$kid] = new Key($pem, $this->algoritmo);
        }

        return $claves;
    }

    /**
     * `aud` puede ser una sola cadena o una lista.
     *
     * @param  array<string, mixed>  $claims
     */
    private function audienciaCoincide(array $claims): bool
    {
        $aud = $claims['aud'] ?? null;

        if (is_string($aud)) {
            return $aud === $this->audienciaEsperada;
        }

        if (is_array($aud)) {
            return in_array($this->audienciaEsperada, $aud, true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function comprobarExpiracion(array $claims): void
    {
        $exp = $claims['exp'] ?? null;

        if (! is_int($exp)) {
            throw new RuntimeException('El token no trae fecha de expiración.');
        }

        if ($exp + $this->margenDeTiempo < time()) {
            throw new RuntimeException('El token venció. Entre de nuevo.');
        }
    }
}