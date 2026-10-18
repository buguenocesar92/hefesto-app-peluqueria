<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RolSalon;
use App\Models\Salon;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Identidad contra Authentik por OIDC, con cookies de sesión.
 *
 * POR QUÉ NO HAY CONTRASEÑAS EN ESTA APLICACIÓN
 * ---------------------------------------------
 * Authentik ya resuelve contraseñas, segundo factor, recuperación y bloqueo de
 * cuentas. Reimplementarlo acá sería peor en todos los sentidos, y sin una
 * revisión de seguridad es exactamente el tipo de cosa que no se improvisa.
 *
 * EL SALÓN VIENE DEL TOKEN
 * -----------------------
 * Del grupo `salon-<identificador>`. Sin grupo de salón no hay ingreso: un
 * usuario sin salón no puede operar y terminaría en un 403 más adelante, que es
 * más difícil de entender.
 *
 * EL SALÓN SE VALIDA CONTRA LA VISTA PÚBLICA
 * ------------------------------------------
 * Antes de crear la sesión se comprueba que el salón exista y esté activo, y se
 * lee de `vw_salones_publicas`, que expone solo `slug`, `nombre` y `activo`. No
 * se lee la tabla cruda: antes del ingreso no hay motivo para leer RUT,
 * dirección, correo ni teléfono. Ver la migración de la vista.
 */
class ProveedorOidcAuthentik
{
    /** Prefijo del grupo que declara el salón. */
    public const PREFIJO_GRUPO_SALON = 'salon-';

    public function __construct(
        private readonly DescubridorOidc $descubridor,
        private readonly string $emisor = '',
        private readonly string $clienteId = '',
        /** @var array<string, string> */
        private readonly array $equiposPorRol = [],
    ) {}

    /**
     * Redirige el navegador a la pantalla de Authentik.
     */
    public function redirigir(Request $peticion, string $salonSlug = ''): RedirectResponse
    {
        return redirect()->away($this->urlDeAutorizacion($peticion, $salonSlug));
    }

    /**
     * La URL a la que va el navegador, sin redirigir todavía.
     *
     * La usa el controlador para validar el salón antes de mandar a nadie a
     * Authentik: mandar a un salón que no existe produce un error de vuelta que
     * nadie entiende.
     */
    public function urlDeAutorizacion(Request $peticion, string $salonSlug = ''): string
    {
        return $this->descubridor->urlDeAutorizacion($peticion, $salonSlug);
    }

    /**
     * Cambia el código de autorización por tokens y valida el id_token.
     *
     * @return array<string, mixed> claims verificados
     */
    public function validarCallback(Request $peticion): array
    {
        $codigo = $peticion->query('code');

        if (! is_string($codigo) || $codigo === '') {
            throw new RuntimeException('Authentik no devolvió un código de autorización.');
        }

        $tokens = $this->descubridor->intercambiarCodigoPorTokens($codigo);

        if (! isset($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw new RuntimeException('La respuesta de Authentik no trae id_token.');
        }

        return (new VerificadorIdToken(
            clavesPublicas: $this->descubridor->clavesPublicas(),
            emisorEsperado: $this->emisor,
            audienciaEsperada: $this->clienteId,
        ))->verificar($tokens['id_token']);
    }

    /**
     * Traduce los claims a un usuario del salón, o `null` si la cuenta no está
     * registrada.
     *
     * Busca por correo dentro del salón que declara el token. El correo es el
     * identificador de negocio; el `sub` de Authentik se guarda aparte, para no
     * atar el historial de la persona a un identificador del proveedor.
     *
     * @param  array<string, mixed>  $claims
     */
    public function usuarioDesdeClaims(array $claims, string $salonSlug): ?Usuario
    {
        $correo = $this->correoDesde($claims);

        if ($correo === '') {
            return null;
        }

        // El salón se valida contra la VISTA, no contra la tabla.
        $salon = DB::table('vw_salones_publicas')->where('slug', $salonSlug)->first();

        if ($salon === null) {
            Log::warning('ingreso con un salon que no existe o esta inactivo', ['salon' => $salonSlug]);

            return null;
        }

        $grupos = $this->gruposDesde($claims);
        $salonId = $this->salonIdDesdeGrupos($grupos, $salonSlug);

        if ($salonId === null) {
            return null;
        }

        $usuario = Usuario::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $salonId)
            ->where('correo', $correo)
            ->first();

        if ($usuario === null || ! $usuario->activo) {
            return null;
        }

        /*
         * El rol se vuelve a leer en CADA ingreso, desde los equipos de Authentik.
         * Guardarlo para siempre deja ventanas de acceso: alguien a quien le
         * quitan el equipo en Authentik seguiría entrando con el rol viejo
         * indefinidamente. Ver docs/03.
         */
        $rol = $this->rolDesdeGrupos($grupos);

        if ($rol !== $usuario->rol) {
            $usuario->rol = $rol;
        }

        $usuario->authentik_id = $this->subDesde($claims);
        $usuario->ultimo_ingreso = now();
        $usuario->save();

        return $usuario;
    }

    /**
     * Identificador del salón (UUID) a partir de los grupos del token.
     *
     * @param  list<string>  $grupos
     */
    public function salonIdDesdeGrupos(array $grupos, string $salonSlug): ?string
    {
        $prefijo = self::PREFIJO_SALON . $salonSlug;

        foreach ($grupos as $grupo) {
            if (strcasecmp($grupo, $prefijo) === 0) {
                $salon = Salon::query()->where('slug', $salonSlug)->first();

                return $salon?->id;
            }
        }

        return null;
    }

    /**
     * El rol, según los equipos de Authentik.
     *
     * @param  list<string>  $grupos
     */
    public function rolDesdeGrupos(array $grupos): RolSalon
    {
        foreach (RolSalon::cases() as $rol) {
            $equipo = $this->equiposPorRol[$rol->value] ?? $rol->equipoAuthentik();

            foreach ($grupos as $grupo) {
                if (strcasecmp($grupo, $equipo) === 0) {
                    return $rol;
                }
            }
        }

        // Sin equipo reconociendo el rol se entra como recepción, que es lo
        // menos que puede hacer una persona: agendar y cobrar.
        return RolSalon::Recepcion;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function gruposDesde(array $claims): array
    {
        $grupos = $claims['groups'] ?? [];

        return is_array($grupos) ? array_values(array_map('strval', $grupos)) : [];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function correoDesde(array $claims): string
    {
        $correo = $claims['email'] ?? '';

        return is_string($correo) ? strtolower(trim($correo)) : '';
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function subDesde(array $claims): ?string
    {
        $sub = $claims['sub'] ?? null;

        return is_string($sub) ? $sub : null;
    }
}