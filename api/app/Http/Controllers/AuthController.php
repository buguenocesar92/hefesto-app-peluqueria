<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Models\Usuario;
use App\Services\Auth\ProveedorOidcAuthentik;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Autenticación contra Authentik (OIDC).
 *
 * ANTES DEL INGRESO, SIN SALÓN
 * ---------------------------
 * Las peticiones de esta pantalla son las únicas que corren sin contexto de
 * salón. Por eso no pueden tocar ninguna tabla de negocio: si lo hicieran,
 * `public.app_tenant_id()` lanzaría excepción. Para ubicar el salón se lee la
 * vista `vw_salones_publicas`, que expone SOLO `slug`, `nombre` y `activo`.
 *
 * LA VISTA SE USA, NO SE DECLARA
 * -----------------------------
 * `salones()` y `entrar()` leen de la vista. No de la tabla. Esa es la
 * diferencia entre prometer una protección y tenerla: si alguien le agrega una
 * columna a la vista, el selector de ingreso empieza a exponer ese dato, y el
 * comando `peluqueria:verificar-aislamiento` lo detecta.
 *
 * Ver la migración de la vista y docs/03.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly ProveedorOidcAuthentik $proveedor,
        private readonly CapacidadesDeUsuario $capacidades,
    ) {}

    /**
     * Los salones activos, para el selector de la pantalla de ingreso.
     *
     * Se lee la VISTA, no la tabla: son tres columnas a propósito.
     */
    public function salones(): JsonResponse
    {
        return response()->json([
            'salones' => DB::table('vw_salones_publicas')
                ->orderBy('nombre')
                ->get(['slug', 'nombre']),
        ]);
    }

    /**
     * Redirige al proveedor de identidad para un salón concreto.
     *
     * El salón se valida contra la vista antes de redirigir: mandar a Authentik
     * un salón que no existe produce un error de vuelta que nadie entiende.
     */
    public function entrar(Request $peticion, string $slug): JsonResponse
    {
        $existe = DB::table('vw_salones_publicas')->where('slug', $slug)->first();

        if ($existe === null) {
            return response()->json([
                'mensaje' => 'No hay ningún salón activo con ese identificador.',
            ], 404);
        }

        return response()->json([
            'redirigir_a' => $this->proveedor->urlDeAutorizacion($peticion, $slug),
        ]);
    }

    /**
     * Callback del proveedor: verifica el token y abre la sesión.
     */
    public function callback(Request $peticion): RedirectResponse
    {
        $salonSlug = (string) session('oidc.salon', '');

        if ($salonSlug === '') {
            return redirect('/entrar?error=El ingreso no indicó a qué salón pertenece.');
        }

        // El estado anti falsificación se compara antes de tocar nada.
        $estadoEsperado = (string) session('oidc.estado', '');
        $estadoRecibido = (string) $peticion->query('state', '');

        if ($estadoEsperado === '' || ! hash_equals($estadoEsperado, $estadoRecibido)) {
            return redirect('/entrar?error=La solicitud de ingreso no es válida. Intente de nuevo.');
        }

        $peticion->session()->forget(['oidc.estado', 'oidc.salon']);

        try {
            $claims = $this->proveedor->validarCallback($peticion);

            $usuario = $this->proveedor->usuarioDesdeClaims($claims, $salonSlug);
        } catch (\Throwable $e) {
            report($e);

            return redirect('/entrar?error=' . urlencode('No se pudo completar el ingreso.'));
        }

        if ($usuario === null) {
            return redirect('/entrar?error=' . urlencode(
                'La cuenta no está habilitada en este salón, o el salón está inactivo.'
            ));
        }

        Auth::guard('web')->login($usuario, true);
        $peticion->session()->regenerate();

        return redirect('/');
    }

    /** Cierre de sesión. */
    public function logout(Request $peticion): RedirectResponse
    {
        Auth::guard('web')->logout();

        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        return redirect('/entrar');
    }

    /**
     * Quién está entró, con qué rol y qué puede hacer.
     *
     * Las capacidades vienen del servidor: la interfaz las usa para esconder
     * botones, pero no son una medida de seguridad. Ver docs/03.
     */
    public function usuario(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesión abierta.'], 401);
        }

        $salon = $usuario->tenant_id !== null ? Salon::query()->find($usuario->tenant_id) : null;

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'rol' => $usuario->rol->value,
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            // En `null` salvo que esta cuenta sea una profesional del salón. La
            // pantalla de comisiones lo usa para abrir en la cuenta propia sin
            // que nadie tenga que elegir a mano en una lista que no debería ver.
            'profesional_id' => $usuario->profesional_id === null
                ? null
                : (string) $usuario->profesional_id,
            'salon' => $salon === null ? null : [
                'id' => $salon->id,
                'nombre' => $salon->nombre,
                'nombre_corto' => $salon->nombre_corto,
            ],
            'capacidades' => $this->capacidades->de($usuario),
        ]);
    }

    /**
     * Cierra de sesión desde la interfaz, para el cliente de Nuxt.
     */
    public function salir(): JsonResponse
    {
        Auth::guard('web')->logout();

        session()->invalidate();
        session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }
}