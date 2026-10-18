<?php

declare(strict_types=1);

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarClientaRequest;
use App\Models\Cita;
use App\Models\Clienta;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;

/**
 * Clientas: la ficha de IDENTIFICACIÓN.
 *
 * QUÉ NO ABRE ESTE CONTROLADOR
 * ---------------------------
 * Los antecedentes de salud. Esos están en otro controlador, con otro permiso y
 * con auditoría. Si la ficha de identificación dijera "esta clienta tiene una
 * alergia", dar acceso a la agenda sería dar acceso a la salud.
 *
 * Ver docs/03.
 */
class ClientaController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Clienta::class);

        $clientas = Clienta::query()
            ->orderBy('nombre')
            ->limit(500)
            ->get()
            ->map(static fn (Clienta $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'apellido' => $c->apellido,
                'nombre_completo' => $c->nombreCompleto(),
                'rut' => $c->rut,
                'telefono' => $c->telefono,
                'correo' => $c->correo,
                'activo' => $c->activo,
            ]);

        return response()->json(['datos' => $clientas]);
    }

    public function store(GuardarClientaRequest $peticion): JsonResponse
    {
        $this->authorize('create', Clienta::class);

        $datos = $peticion->validated();
        $datos['rut'] = $peticion->rutNormalizado();

        $clienta = Clienta::query()->create($datos);

        return response()->json(['id' => $clienta->id], 201);
    }

    /**
     * La ficha de una clienta, con su historial resumido.
     *
     * El historial es una LISTA, no la ficha de salud: son las citas y las ventas
     * que ya están registradas. Los antecedentes viven aparte.
     */
    public function show(Clienta $clienta): JsonResponse
    {
        $this->authorize('view', $clienta);

        $citas = Cita::query()
            ->with('servicio:id,nombre,duracion_minutos')
            ->where('clienta_id', $clienta->id)
            ->orderByDesc('fecha_inicio')
            ->limit(50)
            ->get()
            ->map(static fn (Cita $c): array => [
                'id' => $c->id,
                'fecha_inicio' => $c->fecha_inicio->toIso8601String(),
                'servicio' => $c->servicio?->only(['id', 'nombre']),
                'estado' => $c->estado->value,
                'estado_etiqueta' => $c->estado->etiqueta(),
                'minutos_retraso' => $c->minutos_retraso,
            ]);

        $ventas = Venta::query()
            ->with('profesional:id,nombre')
            ->where('clienta_id', $clienta->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(static fn (Venta $v): array => [
                'id' => $v->id,
                'fecha' => $v->created_at->toDateString(),
                'profesional' => $v->profesional?->only(['id', 'nombre']),
                'total' => $v->total,
                'total_servicios' => $v->total_servicios,
                'total_productos' => $v->total_productos,
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->etiqueta(),
            ]);

        return response()->json([
            'id' => $clienta->id,
            'nombre' => $clienta->nombre,
            'apellido' => $clienta->apellido,
            'nombre_completo' => $clienta->nombreCompleto(),
            'rut' => $clienta->rut,
            'telefono' => $clienta->telefono,
            'correo' => $clienta->correo,
            'fecha_nacimiento' => $clienta->fecha_nacimiento?->toDateString(),
            'notas' => $clienta->notas,
            'activo' => $clienta->activo,
            'citas' => $citas,
            'ventas' => $ventas,
        ]);
    }

    public function update(GuardarClientaRequest $peticion, Clienta $clienta): JsonResponse
    {
        $this->authorize('update', $clienta);

        $datos = $peticion->validated();
        $datos['rut'] = $peticion->rutNormalizado();

        $clienta->update($datos);

        return response()->json(['mensaje' => 'Ficha guardada.']);
    }

    /**
     * Desactiva una clienta. No se borra.
     *
     * Sus ventas ya entraron en liquidaciones y en cortes de caja; borrar la
     * fila rompería esos números.
     */
    public function destroy(Clienta $clienta): JsonResponse
    {
        $this->authorize('delete', $clienta);

        $clienta->activo = false;
        $clienta->save();

        return response()->json(['mensaje' => 'Clienta desactivada.']);
    }
}