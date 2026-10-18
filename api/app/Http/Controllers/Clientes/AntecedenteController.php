<?php

declare(strict_types=1);

namespace App\Http\Controllers\Clientes;

use App\Enums\SeveridadAntecedente;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarAntecedenteRequest;
use App\Models\AntecedenteSalud;
use App\Models\Clienta;
use App\Models\Usuario;
use App\Services\Agenda\RevisorDeAntecedentes;
use App\Services\Permisos\CapacidadesDeUsuario;
use Illuminate\Http\JsonResponse;

/**
 * Antecedentes de salud: todo esto va AUDITADO.
 *
 * POR QUÉ ESTE CONTROLADOR ES DISTINTO
 * ------------------------------------
 * Las rutas que llegan acá pasan por el middleware `salud.auditado`, que anota
 * cada intento, permitido o denegado. Un intento que falla es información, y en
 * un salón con datos sensibles es la más importante. Ver
 * App\Http\Middleware\AuditarSalud.
 *
 * LO QUE NO SE COMPRUEBA Y SE DICE
 * --------------------------------
 * La espera mínima entre dos procesos químicos sobre el mismo pelo. No se pudo
 * verificar el dato, así que el sistema no lo calcula y lo dice. Ver
 * App\Services\Agenda\RevisorDeAntecedentes y docs/02.
 */
class AntecedenteController extends Controller
{
    public function __construct(
        private readonly RevisorDeAntecedentes $revisor,
        private readonly CapacidadesDeUsuario $capacidades,
    ) {}

    public function index(Clienta $clienta): JsonResponse
    {
        $this->authorize('viewAny', AntecedenteSalud::class);

        $antecedentes = AntecedenteSalud::query()
            ->where('clienta_id', $clienta->id)
            ->orderByDesc('activo')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (AntecedenteSalud $a): array => [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'descripcion' => $a->descripcion,
                'severidad' => $a->severidad->value,
                'severidad_etiqueta' => $a->severidad->etiqueta(),
                'bloquea_servicios' => $a->bloquea_servicios,
                'servicios_afectados' => $a->servicios_afectados,
                'activo' => $a->activo,
            ]);

        return response()->json([
            'datos' => $antecedentes,
            // La espera entre procesos NO se comprueba. Se dice, en vez de
            // devolver un campo vacío que parece un dato perdido.
            'espera_entre_procesos_comprobada' => $this->revisor->puedeComprobarEsperaEntreProcesos(),
            'aviso_espera' => $this->revisor->avisoDeEsperaNoImplementada(),
        ]);
    }

    public function store(GuardarAntecedenteRequest $peticion, Clienta $clienta): JsonResponse
    {
        $this->authorize('create', AntecedenteSalud::class);

        $usuario = $peticion->user();

        // `bloquea_servicios` solo se guarda si quien escribe puede decidir eso.
        // Recepción registra lo que la clienta informa; no decide cuánto pesa.
        $puedeMarcarBloqueo = $usuario instanceof Usuario && $peticion->puedeMarcarBloqueo($usuario);

        $antecedente = AntecedenteSalud::query()->create([
            'tenant_id' => $clienta->tenant_id,
            'clienta_id' => $clienta->id,
            'tipo' => $peticion->validated('tipo'),
            'descripcion' => $peticion->validated('descripcion'),
            'severidad' => SeveridadAntecedente::from($peticion->validated('severidad')),
            'bloquea_servicios' => $puedeMarcarBloqueo
                ? (bool) $peticion->boolean('bloquea_servicios')
                : false,
            'servicios_afectados' => $peticion->validated('servicios_afectados'),
            'registrado_por' => $usuario instanceof Usuario ? $usuario->profesional_id : null,
            'activo' => true,
        ]);

        return response()->json([
            'id' => $antecedente->id,
            'mensaje' => $puedeMarcarBloqueo
                ? 'Antecedente registrado.'
                : 'Antecedente registrado. Quedó sin marcar como bloqueante: esa decisión la toma una profesional.',
        ], 201);
    }

    public function update(GuardarAntecedenteRequest $peticion, AntecedenteSalud $antecedente): JsonResponse
    {
        $this->authorize('update', $antecedente);

        $usuario = $peticion->user();
        $puedeMarcarBloqueo = $usuario instanceof Usuario && $peticion->puedeMarcarBloqueo($usuario);

        $antecedente->fill([
            'tipo' => $peticion->validated('tipo'),
            'descripcion' => $peticion->validated('descripcion'),
            'severidad' => SeveridadAntecedente::from($peticion->validated('severidad')),
            'servicios_afectados' => $peticion->validated('servicios_afectados'),
        ]);

        if ($puedeMarcarBloqueo) {
            $antecedente->bloquea_servicios = $peticion->boolean('bloquea_servicios');
        }

        $antecedente->save();

        return response()->json(['mensaje' => 'Antecedente guardado.']);
    }

    /**
     * Cierra un antecedente.
     *
     * Se marca `activo = false`: deja de avisar en la agenda, pero el registro
     * queda. Un antecedente que se borra no se puede consultar después.
     */
    public function destroy(AntecedenteSalud $antecedente): JsonResponse
    {
        $this->authorize('delete', $antecedente);

        $antecedente->activo = false;
        $antecedente->save();

        return response()->json(['mensaje' => 'Antecedente cerrado.']);
    }
}