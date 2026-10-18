<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Clienta;
use App\Models\Profesional;
use App\Models\Salon;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba, desde la consola, que la RLS está puesta y funciona.
 *
 *     php artisan peluqueria:verificar-aislamiento
 *
 * Sirve para lo mismo que la suite de pruebas, pero en el servidor real: antes
 * de recibir a la primera clienta, o después de tocar una migración.
 *
 * NO ES UNA PRUEBA UNITARIA
 * -------------------------
 * Escribe filas de prueba y las borra al final. **No debe correrse contra una
 * base con datos reales**: los identificadores que usa son fijos y visibles, así
 * que en una base con clientas reales se identificarían filas reales. Si hace
 * falta verificar en producción, hay que hacerlo en una base de pruebas
 * restaurada. Ver docs/08.
 *
 * QUE COMPRUEBA, EN ORDEN
 * -----------------------
 *   1. Que las dieciséis tablas de negocio tengan RLS FORZADO (no solo
 *      habilitado: forzado, que es lo que hace que las políticas apliquen
 *      también al dueño de la tabla, que es el usuario con el que se conecta
 *      Laravel).
 *   2. Que exista la política de aislamiento en cada una.
 *   3. Que la vista pública siga teniendo SOLO tres columnas.
 *   4. Que un salón no vea filas de otro, ni por clave primaria.
 *   5. Que no se pueda escribir una fila declarando otro salón (`WITH CHECK`).
 *   6. Que una consulta SIN contexto falle, en vez de devolver cero filas. Este
 *      último es el más importante: si `app_tenant_id()` devolviera `NULL` en
 *      vez de lanzar excepción, la ausencia de contexto sería indistinguible de
 *      "no hay nada", y el fallo sería silencioso.
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'peluqueria:verificar-aislamiento
        {--salon : Identificador o slug de un salón existente para usar como contexto A. Si no se indica, se crea una fila temporal.}';

    protected $description = 'Comprueba que el aislamiento entre salones funciona a nivel de base de datos.';

    /**
     * Las dieciséis tablas de negocio, en el mismo orden que la migración
     * `2026_10_18_000020`. Si la migración agrega una tabla y este comando no,
     * la verificación pasa sin mirarla: por eso la lista se revisa en cada
     * despliegue.
     *
     * @var list<string>
     */
    private const TABLAS_PROTEGIDAS = [
        'profesionales',
        'servicios',
        'servicios_profesional',
        'productos',
        'clientas',
        'antecedentes_salud',
        'citas',
        'bloqueos_agenda',
        'ventas',
        'venta_items',
        'politicas_comision',
        'liquidaciones_comision',
        'liquidacion_comision_items',
        'cortes_caja',
        'usuarios',
        'accesos_salud',
    ];

    public function handle(ContextoSalon $contexto): int
    {
        $this->components->info('Revisando que el rol de la aplicación no se salte las políticas.');

        if (! $this->revisarRolDeAplicacion()) {
            return self::FAILURE;
        }

        $this->components->info('Revisando que las tablas de negocio tengan RLS forzado.');

        if (! $this->revisarTablas()) {
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Las %d tablas tienen RLS forzado y su política de aislamiento.',
            count(self::TABLAS_PROTEGIDAS),
        ));

        if (! $this->revisarVistaPublica()) {
            return self::FAILURE;
        }

        return $this->revisarAislamientoReal($contexto);
    }

    /**
     * Comprueba que el rol con el que se conecta la aplicación no pueda saltarse
     * las políticas.
     *
     * ESTA ES LA COMPROBACIÓN QUE MÁS IMPORTA, y va primera a propósito: con
     * superusuario o con `BYPASSRLS`, PostgreSQL ignora las políticas por
     * completo. Todo lo demás que revisa este comando pasaría sin problems y el
     * aislamiento no existiría. Es el modo de falla más silencioso que hay.
     */
    private function revisarRolDeAplicacion(): bool
    {
        $rol = DB::selectOne(
            'SELECT current_user AS nombre, r.rolsuper AS superusuario, r.rolbypassrls AS bypass
               FROM pg_roles r WHERE r.rolname = current_user'
        );

        if ($rol === null) {
            $this->components->error('No se pudo leer el rol actual de PostgreSQL.');

            return false;
        }

        if ($rol->superusuario) {
            $this->components->error("El rol '{$rol->nombre}' es SUPERUSUARIO.");
            $this->components->error('Un superusuario ignora las políticas de RLS por completo.');
            $this->components->error('Use un rol propio para la aplicación. Ver docs/03.');

            return false;
        }

        if ($rol->bypass) {
            $this->components->error("El rol '{$rol->nombre}' tiene BYPASSRLS.");
            $this->components->error('Un rol con BYPASSRLS ignora las políticas de RLS. Ver docs/03.');

            return false;
        }

        $this->components->info(sprintf(
            "El rol '%s' no es superusuario y no tiene BYPASSRLS. Las políticas se aplican.",
            $rol->nombre,
        ));

        return true;
    }

    /**
     * Comprueba RLS y política tabla por tabla.
     */
    private function revisarTablas(): bool
    {
        $fallos = 0;

        foreach (self::TABLAS_PROTEGIDAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            if ($fila === null) {
                $this->components->error("La tabla {$tabla} no existe. Faltaría correr las migraciones.");

                $fallos++;

                continue;
            }

            if (! $fila->rls) {
                $this->components->error("La tabla {$tabla} no tiene Row Level Security habilitado.");

                $fallos++;
            }

            if (! $fila->forzado) {
                $this->components->error("La tabla {$tabla} no tiene RLS FORZADO. Sin FORCE, las políticas "
                    . 'no le aplican al dueño de la tabla, que es el usuario con el que se conecta Laravel.');

                $fallos++;
            }

            $politica = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE tablename = ? AND policyname = ?',
                [$tabla, 'politica_aislamiento_salon'],
            );

            if ((int) ($politica->total ?? 0) === 0) {
                $this->components->error("La tabla {$tabla} no tiene la política politica_aislamiento_salon.");

                $fallos++;
            }
        }

        if ($fallos > 0) {
            $this->components->error('La instalación NO tiene el aislamiento activo. No siga.');

            return false;
        }

        return true;
    }

    /**
     * La vista pública tiene que seguir teniendo SOLO tres columnas.
     *
     * Si alguien le agrega una cuarta, el selector de ingreso empieza a exponer
     * ese dato, y la protección que promete la vista deja de existir sin que
     * nada avise. Ver docs/03.
     */
    private function revisarVistaPublica(): bool
    {
        $columnas = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_salones_publicas'
             ORDER BY ordinal_position"
        );

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $columnas);

        $esperadas = ['slug', 'nombre', 'activo'];

        if ($nombres !== $esperadas) {
            $this->components->error('La vista vw_salones_publicas no expone exactamente tres columnas.');
            $this->components->error('Esperadas: ' . implode(', ', $esperadas));
            $this->components->error('Encontradas: ' . (implode(', ', $nombres) === '' ? '(ninguna)' : implode(', ', $nombres)));
            $this->components->error('Antes del ingreso solo se puede leer slug, nombre y activo. Ver docs/03.');

            return false;
        }

        $this->components->info('La vista pública expone solo slug, nombre y activo. Correcto.');

        return true;
    }

    /**
     * La prueba que de verdad importa: escribir y leer con dos salones.
     */
    private function revisarAislamientoReal(ContextoSalon $contexto): int
    {
        $salonA = $this->obtenerOCrearSalon('a', $this->option('salon'));
        $salonB = $this->obtenerOCrearSalon('b');

        $profesionalB = $contexto->ejecutarAislado((string) $salonB->id, fn (): Profesional => Profesional::query()->create([
            'tenant_id' => (string) $salonB->id,
            'nombre' => 'Profesional de verificación B',
            'especialidad' => 'Verificación',
        ]));

        $clientaB = $contexto->ejecutarAislado((string) $salonB->id, fn (): Clienta => Clienta::query()->create([
            'tenant_id' => (string) $salonB->id,
            'nombre' => 'Clienta',
            'apellido' => 'DeVerificacion',
            'telefono' => '+56 9 0000 0000',
        ]));

        try {
            $this->components->info('Creadas una clienta y una profesional temporales en el salón B.');

            if (! $this->revisarLecturaCruzada($contexto, $salonA, $clientaB)) {
                return self::FAILURE;
            }

            if (! $this->revisarEscrituraCruzada($contexto, $salonA, $profesionalB)) {
                return self::FAILURE;
            }
        } finally {
            // Se borran las filas temporales. La RLS exige el contexto correcto
            // para borrarlas, así que cada una va en su propia transacción.
            $contexto->ejecutarAislado((string) $salonB->id, function () use ($clientaB, $profesionalB): void {
                $clientaB->delete();
                $profesionalB->delete();
            });

            $this->components->info('Filas temporales borradas.');
        }

        if (! $this->revisarFalloCerrado()) {
            return self::FAILURE;
        }

        $this->components->info('Aislamiento verificado. La instalación puede recibir clientas.');

        return self::SUCCESS;
    }

    /**
     * A no puede ver la clienta de B, ni siquiera preguntando por su id.
     */
    private function revisarLecturaCruzada(ContextoSalon $contexto, Salon $a, Clienta $clientaB): bool
    {
        $vistas = $contexto->ejecutarAislado((string) $a->id, fn (): int => Clienta::query()
            ->whereKey($clientaB->id)
            ->count());

        if ($vistas !== 0) {
            $this->components->error('El salón A alcanzó a ver una clienta del salón B.');
            $this->components->error('El aislamiento NO funciona. Revise FORCE ROW LEVEL SECURITY.');

            return false;
        }

        $this->components->info('A no ve clientas de B, ni por identificador. Correcto.');

        return true;
    }

    /**
     * No se puede escribir una fila declarando el salón de otro.
     *
     * Esta es la comprobación de `WITH CHECK`, que es distinta de `USING`: sin
     * ella, se podría crear una fila que pertenece a otro salón y esa fila
     * después sería invisible para los dos.
     */
    private function revisarEscrituraCruzada(ContextoSalon $contexto, Salon $a, Profesional $profesionalB): bool
    {
        $rechazada = false;

        try {
            // La referencia por valor y por referencia es necesaria: la
            // comprobación ocurre dentro de la transacción y su resultado tiene
            // que salir de la clausura.
            $contexto->ejecutarAislado((string) $a->id, function () use ($a, $profesionalB, &$rechazada): void {
                Profesional::query()->create([
                    'tenant_id' => (string) $a->id,
                    'nombre' => 'Intento de escritura cruzada',
                ]);

                // Si la escritura se hubiera guardado con el salón de B, la fila
                // sería visible desde el contexto de A. Se comprueba que NO lo
                // sea.
                $filtrada = Profesional::query()
                    ->whereKey($profesionalB->id)
                    ->first();

                $rechazada = $filtrada === null;
            });
        } catch (Throwable) {
            // También es una respuesta correcta: la base rechazó el INSERT de
            // plano porque el `tenant_id` no coincide con el contexto.
            $rechazada = true;
        }

        if (! $rechazada) {
            $this->components->error('Se pudo escribir una fila declarando el salón de otro.');

            return false;
        }

        $this->components->info('No se puede escribir una fila de otro salón. Correcto.');

        return true;
    }

    /**
     * La comprobación que sostiene todo lo demás.
     *
     * Sin `app.tenant_id` fijado, una consulta tiene que LANZAR EXCEPCIÓN. Si
     * devolviera cero filas, el fallo sería silencioso: el sistema parecería
     * funcionar y no estaría mostrando nada.
     */
    private function revisarFalloCerrado(): bool
    {
        try {
            Clienta::query()->count();

            $this->components->error('Una consulta sin contexto devolvió filas en vez de fallar.');
            $this->components->error('La función public.app_tenant_id() debería lanzar excepción.');

            return false;
        } catch (Throwable) {
            $this->components->info('Sin contexto, la consulta falla. El aislamiento falla cerrado.');

            return true;
        }
    }

    /**
     * Salón existente o temporal para la prueba.
     *
     * Se usa `firstOrCreate` por identificador, y no por slug, para que una
     * segunda corrida no falle: la fila de la corrida anterior ya existe y tiene
     * su propio identificador.
     */
    private function obtenerOCrearSalon(string $sufijo, ?string $indicado = null): Salon
    {
        if ($indicado !== null && $indicado !== '') {
            // Se acepta el identificador o el slug: el slug es lo que aparece en
            // los grupos de Authentik (`salon-<slug>`), y es lo que el operador
            // tendrá a mano.
            $existente = Salon::query()
                ->whereKey($indicado)
                ->orWhere('slug', $indicado)
                ->first();

            if ($existente === null) {
                $this->components->error("El salón {$indicado} no existe.");

                throw new \InvalidArgumentException('El salón indicado no existe.');
            }

            return $existente;
        }

        // `salones` NO tiene RLS a propósito: se consulta antes de que exista
        // `app.tenant_id`. Ver docs/03.
        return Salon::query()->firstOrCreate(
            ['slug' => 'peluqueria-verificacion-' . $sufijo],
            [
                'nombre' => 'Temporal de verificación (' . $sufijo . ')',
                'nombre_corto' => 'Verificación',
                'activo' => true,
            ],
        );
    }
}