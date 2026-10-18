<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Servicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogos: profesionales, servicios y productos.
 *
 * POR QUÉ UN SOLO CONTROLADOR PARA LOS TRES
 * ------------------------------------------
 * Porque los tres se administran igual y se consultan juntos: la pantalla de
 * agenda arma el combo con la profesional, sus servicios y la duración. Separar
 * los tres en tres controladores produce tres pantallas donde la gente espera.
 *
 * MODIFICAR ES DE ADMINISTRACIÓN
 * ------------------------------
 * Cambiar la duración de un servicio cambia la agenda de todas las citas futuras
 * que usen ese servicio. Cambiar el precio de un producto cambia lo que entra a
 * la base de la comisión. Ninguna de las dos cosas es de recepción.
 */
class CatalogoController extends Controller
{
    /* ----------------------------------------------------------------------
     | Profesionales
     | ---------------------------------------------------------------------- */

    public function profesionales(): JsonResponse
    {
        $this->authorize('viewAny', Profesional::class);

        $profesionales = Profesional::query()
            ->with('servicios:id,nombre,duracion_minutos,precio_base')
            ->orderBy('nombre')
            ->get()
            ->map(static fn (Profesional $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'especialidad' => $p->especialidad,
                'silla' => $p->silla,
                'correo' => $p->correo,
                'telefono' => $p->telefono,
                'registro_profesional' => $p->registro_profesional,
                'registro_verificado' => $p->registro_verificado,
                'color_agenda' => $p->color_agenda,
                'activo' => $p->activo,
                'servicios' => $p->servicios->map(static fn (Servicio $s): array => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'duracion_minutos' => $s->pivot?->duracion_minutos ?? $s->duracion_minutos,
                    'precio' => $s->pivot?->precio ?? $s->precio_base,
                ])->values(),
            ]);

        return response()->json(['datos' => $profesionales]);
    }

    public function guardarProfesional(Request $peticion): JsonResponse
    {
        $this->authorize('create', Profesional::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'especialidad' => ['nullable', 'string', 'max:120'],
            'silla' => ['nullable', 'string', 'max:24'],
            'correo' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'registro_profesional' => ['nullable', 'string', 'max:64'],
            'registro_verificado' => ['nullable', 'boolean'],
            'color_agenda' => ['nullable', 'string', 'max:7'],
            'activo' => ['nullable', 'boolean'],
            'servicios' => ['nullable', 'array'],
            'servicios.*.servicio_id' => ['uuid', 'exists:servicios,id'],
            'servicios.*.duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:600'],
            'servicios.*.precio' => ['nullable', 'integer', 'min:0'],
        ], [
            'nombre.required' => 'La profesional necesita un nombre.',
            'servicios.*.servicio_id.exists' => 'Uno de los servicios indicados no existe en este salón.',
            'servicios.*.duracion_minutos.min' => 'Un servicio de menos de cinco minutos no es un servicio.',
        ]);

        $profesional = Profesional::query()->create([
            'nombre' => $datos['nombre'],
            'especialidad' => $datos['especialidad'] ?? null,
            'silla' => $datos['silla'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'registro_profesional' => $datos['registro_profesional'] ?? null,
            'registro_verificado' => (bool) ($datos['registro_verificado'] ?? false),
            'color_agenda' => $datos['color_agenda'] ?? '#2f6f6f',
            'activo' => (bool) ($datos['activo'] ?? true),
        ]);

        $this->sincronizarServicios($profesional, $datos['servicios'] ?? []);

        return response()->json(['id' => $profesional->id], 201);
    }

    /* ----------------------------------------------------------------------
     | Servicios
     | ---------------------------------------------------------------------- */

    public function servicios(): JsonResponse
    {
        $this->authorize('viewAny', Servicio::class);

        $servicios = Servicio::query()
            ->orderBy('nombre')
            ->get()
            ->map(static fn (Servicio $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'descripcion' => $s->descripcion,
                'duracion_minutos' => $s->duracion_minutos,
                'precio_base' => $s->precio_base,
                'categoria' => $s->categoria,
                'es_reposicion' => $s->es_reposicion,
                'activo' => $s->activo,
            ]);

        return response()->json(['datos' => $servicios]);
    }

    public function guardarServicio(Request $peticion): JsonResponse
    {
        $this->authorize('create', Servicio::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:480'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:600'],
            'precio_base' => ['required', 'integer', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'es_reposicion' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'nombre.required' => 'El servicio necesita un nombre.',
            'duracion_minutos.required' => 'La duración es obligatoria: la agenda se llena por tiempo de servicio.',
            'duracion_minutos.min' => 'Un servicio de menos de cinco minutos no es un servicio.',
            'precio_base.required' => 'Hay que declarar el precio. El sistema no lo inventa.',
        ]);

        $servicio = Servicio::query()->create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'duracion_minutos' => $datos['duracion_minutos'],
            'precio_base' => $datos['precio_base'],
            'categoria' => $datos['categoria'] ?? null,
            'es_reposicion' => (bool) ($datos['es_reposicion'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? true),
        ]);

        return response()->json(['id' => $servicio->id], 201);
    }

    public function actualizarServicio(Request $peticion, Servicio $servicio): JsonResponse
    {
        $this->authorize('update', $servicio);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:480'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:600'],
            'precio_base' => ['required', 'integer', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'es_reposicion' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'duracion_minutos.min' => 'Un servicio de menos de cinco minutos no es un servicio.',
            'precio_base.required' => 'Hay que declarar el precio.',
        ]);

        $servicio->update($datos + ['activo' => (bool) ($datos['activo'] ?? true)]);

        return response()->json([
            'id' => $servicio->id,
            'mensaje' => 'Servicio guardado. Las citas ya agendadas conservan la duración con la que se agendaron.',
        ]);
    }

    /* ----------------------------------------------------------------------
     | Productos
     | ---------------------------------------------------------------------- */

    public function productos(): JsonResponse
    {
        $this->authorize('viewAny', Producto::class);

        $productos = Producto::query()
            ->orderBy('nombre')
            ->get()
            ->map(static fn (Producto $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'marca' => $p->marca,
                'precio_venta' => $p->precio_venta,
                'stock' => $p->stock,
                'unidad' => $p->unidad,
                'activo' => $p->activo,
            ]);

        return response()->json(['datos' => $productos]);
    }

    public function guardarProducto(Request $peticion): JsonResponse
    {
        $this->authorize('create', Producto::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:480'],
            'marca' => ['nullable', 'string', 'max:120'],
            'precio_venta' => ['required', 'integer', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:24'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'nombre.required' => 'El producto necesita un nombre.',
            'precio_venta.required' => 'Hay que declarar el precio. El sistema no lo inventa.',
        ]);

        $producto = Producto::query()->create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'marca' => $datos['marca'] ?? null,
            'precio_venta' => $datos['precio_venta'],
            'stock' => $datos['stock'] ?? null,
            'unidad' => $datos['unidad'] ?? 'unidad',
            'activo' => (bool) ($datos['activo'] ?? true),
        ]);

        return response()->json(['id' => $producto->id], 201);
    }

    public function actualizarProducto(Request $peticion, Producto $producto): JsonResponse
    {
        $this->authorize('update', $producto);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:480'],
            'marca' => ['nullable', 'string', 'max:120'],
            'precio_venta' => ['required', 'integer', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:24'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $producto->update($datos + ['activo' => (bool) ($datos['activo'] ?? true)]);

        return response()->json(['id' => $producto->id]);
    }

    /**
     * Sincroniza qué servicios hace cada profesional, con su duración y precio.
     *
     * Los que vienen en `null` se guardan como `null`, y eso significa "manda el
     * del catálogo". No es lo mismo que no guardar la fila: la fila existe para
     * que el servicio aparezca en el selector de esa profesional.
     *
     * @param  list<array{servicio_id: string, duracion_minutos?: int|null, precio?: int|null}>  $servicios
     */
    private function sincronizarServicios(Profesional $profesional, array $servicios): void
    {
        $ids = [];

        foreach ($servicios as $fila) {
            if (! isset($fila['servicio_id'])) {
                continue;
            }

            $ids[] = $fila['servicio_id'];

            $profesional->servicios()->syncWithoutDetaching([
                $fila['servicio_id'] => [
                    'tenant_id' => $profesional->tenant_id,
                    'duracion_minutos' => $fila['duracion_minutos'] ?? null,
                    'precio' => $fila['precio'] ?? null,
                    'activo' => true,
                ],
            ]);
        }

        // Los que la profesional dejó de hacer quedan inactivos, no borrados: las
        // ventas ya cobradas los nombran.
        $profesional->servicios()
            ->whereNotIn('servicios.id', $ids === [] ? ['-'] : $ids)
            ->update(['servicios_profesional.activo' => false]);
    }
}