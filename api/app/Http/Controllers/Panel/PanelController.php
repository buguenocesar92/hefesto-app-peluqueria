<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AccesoSalud;
use App\Models\AntecedenteSalud;
use App\Models\Cita;
use App\Models\CorteCaja;
use App\Models\Profesional;
use App\Models\Salon;
use App\Models\Usuario;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * El panel de inicio: qué hay que mirar hoy.
 *
 * POR QUÉ SON TARJETAS Y NO NÚMEROS SUELTOS
 * ----------------------------------------
 * Un "hay 214 clientas" no dice nada. "Hay 3 avisos de salud con cita hoy" sí.
 * Por eso cada tarjeta trae `requiere_revision` y se pinta sola: lo que hay que
 * mirar queda a la vista sin que nadie tenga que buscarlo.
 *
 * LO QUE NO SE MUESTRA ACÁ
 * -------------------------
 * Ningún dato de salud en detalle. El panel CUENTA; la ficha se abre en su
 * pantalla, con su permiso y su auditoría. Ver docs/03.
 */
class PanelController extends Controller
{
    public function __construct(private readonly CapacidadesDeUsuario $capacidades) {}

    public function show(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        if (! $usuario instanceof Usuario) {
            return response()->json(['mensaje' => 'No hay sesión abierta.'], 401);
        }

        $hoy = Carbon::now()->startOfDay();
        $finHoy = $hoy->copy()->endOfDay();

        $salon = $usuario->tenant_id !== null ? Salon::query()->find($usuario->tenant_id) : null;

        $citasHoy = Cita::query()
            ->whereBetween('fecha_inicio', [$hoy, $finHoy])
            ->whereIn('estado', ['agendada', 'confirmada', 'en_curso'])
            ->count();

        // El retraso es lo que más duele en un salón: no es que la clienta llegue
        // tarde, es que la siguiente espera.
        $citasConRetraso = Cita::query()
            ->whereDate('fecha_inicio', $hoy->toDateString())
            ->whereNotNull('minutos_retraso')
            ->where('minutos_retraso', '>', 0)
            ->count();

        $avisosSalud = AntecedenteSalud::query()
            ->where('activo', true)
            ->whereIn('severidad', ['grave'])
            ->whereHas('clienta', fn ($q) => $q
                ->whereHas('citas', fn ($c) => $c
                    ->whereBetween('fecha_inicio', [$hoy, $finHoy])
                    ->whereIn('estado', ['agendada', 'confirmada'])))
            ->count();

        $cortesConDiferencia = CorteCaja::query()
            ->whereDate('fecha', $hoy->toDateString())
            ->where('estado', 'cerrado')
            ->where('diferencia', '!=', 0)
            ->count();

        $accesosDenegados = AccesoSalud::query()
            ->where('resultado', 'denegado')
            ->whereDate('creado_en', $hoy->toDateString())
            ->count();

        return response()->json([
            'salon' => $salon?->only(['id', 'nombre', 'nombre_corto']),
            'fecha' => $hoy->toDateString(),
            'rol_etiqueta' => $usuario->rol->etiqueta(),
            'tarjetas' => [
                'citas_hoy' => [
                    'total' => $citasHoy,
                    'requiere_revision' => false,
                    'mensaje' => 'Citas en la agenda de hoy.',
                ],
                'citas_con_retraso' => [
                    'total' => $citasConRetraso,
                    'requiere_revision' => $citasConRetraso > 0,
                    'mensaje' => $citasConRetraso > 0
                        ? 'Hoy hay atenciones que se pasaron de la hora prevista.'
                        : 'Hoy ninguna atención se pasó de la hora.',
                ],
                'avisos_de_salud' => [
                    'total' => $avisosSalud,
                    'requiere_revision' => $avisosSalud > 0,
                    'mensaje' => $avisosSalud > 0
                        ? 'Clientas con un antecedente grave y cita hoy.'
                        : 'Sin antecedentes graves con cita hoy.',
                ],
                'cortes_con_diferencia' => [
                    'total' => $cortesConDiferencia,
                    'requiere_revision' => $cortesConDiferencia > 0,
                    'mensaje' => $cortesConDiferencia > 0
                        ? 'Cajas cerradas con diferencia entre lo esperado y lo declarado.'
                        : 'Las cajas de hoy cuadran.',
                ],
                'accesos_denegados' => [
                    'total' => $accesosDenegados,
                    'requiere_revision' => $accesosDenegados > 0,
                    'mensaje' => $accesosDenegados > 0
                        ? 'Intentos de abrir antecedentes de salud a los que se les dijo que no.'
                        : 'Sin intentos denegados hoy.',
                ],
            ],
            'profesionales_activas' => Profesional::query()->where('activo', true)->count(),
            'capacidades' => $this->capacidades->de($usuario),
            // La espera entre procesos químicos no se comprueba. Se declara acá,
            // en la pantalla que todos ven, y no en una nota al pie de la
            // documentación. Ver docs/02.
            'espera_entre_procesos_comprobada' => false,
        ]);
    }
}