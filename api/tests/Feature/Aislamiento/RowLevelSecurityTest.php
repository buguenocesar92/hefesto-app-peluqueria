<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Cita;
use App\Models\Clienta;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Services\Tenancy\ContextoSalon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El aislamiento entre salones, comprobado en la base y no en el código PHP.
 *
 * POR QUÉ ESTAS PRUEBAS NO PASARÍAN EN SQLITE
 * ------------------------------------------
 * Porque no hay Row Level Security en SQLite. Un sistema SIN aislamiento
 * alguno también daría verde: en SQLite no hay ninguna política que lo niegue.
 * Por eso `phpunit.xml` fija `DB_CONNECTION=pgsql`. Ver docs/10.
 *
 * LO QUE COMPRUEBAN
 * -----------------
 *   1. Que las tablas de negocio tengan RLS FORZADO y su política.
 *   2. Que un salón no vea filas de otro, ni por clave primaria.
 *   3. Que no se pueda escribir una fila declarando otro salón (`WITH CHECK`).
 *   4. Que una consulta SIN contexto falle, en vez de devolver cero filas. Este
 *      último es el más importante: si `app_tenant_id()` devolviera `NULL` en vez
 *      de lanzar excepción, la ausencia de contexto sería indistinguible de "no
 *      hay nada", y el fallo sería silencioso.
 *
 * LO QUE NO SE EJECUTÓ
 * --------------------
 * Nunca se corrieron. Ver docs/10.
 */
class RowLevelSecurityTest extends TestCase
{
    /**
     * Las mismas dieciséis tablas que la migración y que el comando
     * `peluqueria:verificar-aislamiento`. Si una tabla aparece en la migración y
     * no acá, esta suite pasa sin mirarla.
     *
     * @var list<string>
     */
    private const TABLAS = [
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

    #[Test]
    public function todas_las_tablas_de_negocio_tienen_rls_forzado(): void
    {
        foreach (self::TABLAS as $tabla) {
            $fila = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado FROM pg_class WHERE relname = ?',
                [$tabla],
            );

            $this->assertNotNull($fila, "La tabla {$tabla} no existe: faltaría correr las migraciones.");
            $this->assertTrue((bool) $fila->rls, "La tabla {$tabla} no tiene Row Level Security habilitado.");
            $this->assertTrue(
                (bool) $fila->forzado,
                "La tabla {$tabla} no tiene RLS FORZADO: sin FORCE, las políticas no le aplican al dueño.",
            );
        }
    }

    #[Test]
    public function todas_las_tablas_de_negocio_tienen_la_politica_de_aislamiento(): void
    {
        foreach (self::TABLAS as $tabla) {
            $politica = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies
                 WHERE tablename = ? AND policyname = ?',
                [$tabla, 'politica_aislamiento_salon'],
            );

            $this->assertSame(
                1,
                (int) ($politica->total ?? 0),
                "La tabla {$tabla} no tiene la política politica_aislamiento_salon.",
            );
        }
    }

    #[Test]
    public function un_salon_no_ve_las_clientas_de_otro(): void
    {
        $salonA = $this->crearSalon('salon-a');
        $salonB = $this->crearSalon('salon-b');

        $clientaB = $this->crearClienta($salonB, 'Cliente de B');

        $vistas = $this->dentroDe($salonA, fn (): int => Clienta::query()->whereKey($clientaB->id)->count());

        $this->assertSame(0, $vistas, 'El salón A alcanzó a ver una clienta del salón B.');
    }

    #[Test]
    public function un_salon_no_ve_las_profesionales_de_otro(): void
    {
        $salonA = $this->crearSalon('salon-a');
        $salonB = $this->crearSalon('salon-b');

        [, $profesionalB] = $this->crearProfesional($salonB, 'Profesional de B');

        $vistas = $this->dentroDe($salonA, fn (): int => Profesional::query()->whereKey($profesionalB->id)->count());

        $this->assertSame(0, $vistas, 'El salón A alcanzó a ver una profesional del salón B.');
    }

    #[Test]
    public function no_se_puede_escribir_una_fila_declarando_otro_salon(): void
    {
        $salonA = $this->crearSalon('salon-a');
        $salonB = $this->crearSalon('salon-b');

        $rechazada = false;

        try {
            $this->dentroDe($salonA, static function () use ($salonB): void {
                // La fila declara el salón B, pero el contexto de la conexión es
                // A. Sin `WITH CHECK` esto entraría y nadie la vería nunca.
                Profesional::query()->create([
                    'tenant_id' => (string) $salonB->id,
                    'nombre' => 'Intento de escritura cruzada',
                ]);
            });
        } catch (\Throwable) {
            $rechazada = true;
        }

        $this->assertTrue($rechazada, 'Se pudo escribir una fila declarando el salón de otro.');
    }

    #[Test]
    public function sin_contexto_la_consulta_falla_y_no_devuelve_cero_filas(): void
    {
        $salonA = $this->crearSalon('salon-a');
        $this->crearClienta($salonA, 'Clienta de A');

        // Esta es la comprobación que sostiene todo lo demás. Si devolviera
        // cero filas en vez de fallar, el sistema parecería funcionar y no
        // estaría mostrando nada.
        $fallo = false;

        try {
            // Sin `dentroDe`: no hay `app.tenant_id` fijado.
            Clienta::query()->count();
        } catch (\Throwable) {
            $fallo = true;
        }

        $this->assertTrue($fallo, 'Una consulta sin contexto devolvió filas en vez de fallar.');
    }

    #[Test]
    public function el_contexto_de_un_salon_no_sobrevive_a_la_transaccion(): void
    {
        $salonA = $this->crearSalon('salon-a');
        $this->crearClienta($salonA, 'Clienta de A');

        $contexto = app(ContextoSalon::class);

        $this->dentroDe($salonA, function () use ($contexto): void {
            $this->assertSame((string) $salonA->id, $contexto->salonIdEnConexion());
        });

        // Al salir de la transacción, PostgreSQL descarta el valor. Por eso el
        // worker de colas no arrastra el salón de otra petición.
        $this->assertNull($contexto->salonIdEnConexion());
    }

    #[Test]
    public function el_salon_ve_sus_propias_filas(): void
    {
        $salon = $this->crearSalon('salon-propio');

        $this->crearClienta($salon, 'Clienta propia');

        $vistas = $this->dentroDe($salon, fn (): int => Clienta::query()->count());

        $this->assertSame(1, $vistas, 'Un salón tiene que ver sus propias filas: si no, el sistema no sirve.');
    }

    #[Test]
    public function la_vista_publica_expone_solo_tres_columnas(): void
    {
        $salon = $this->crearSalon('salon-visible');
        $this->crearSalon('salon-oculto');

        // Se apaga el de la vista y se consulta desde la vista, sin contexto de
        // salón: es exactamente lo que hace el selector de ingreso.
        DB::table('salones')->where('id', $salon->id)->update(['nombre' => 'Salón Visible']);

        $columnas = DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'vw_salones_publicas'
             ORDER BY ordinal_position"
        );

        $nombres = array_map(static fn ($fila): string => (string) $fila->column_name, $columnas);

        $this->assertSame(['slug', 'nombre', 'activo'], $nombres);

        // Y la vista devuelve datos sin que exista `app.tenant_id`.
        $filas = DB::table('vw_salones_publicas')->where('slug', 'salon-visible')->get();

        $this->assertCount(1, $filas);
        $this->assertSame('Salón Visible', $filas->first()->nombre);
    }

    #[Test]
    public function la_vista_publica_no_muestra_salones_inactivos(): void
    {
        $salon = $this->crearSalon('salon-inactivo');

        DB::table('salones')->where('id', $salon->id)->update(['activo' => false]);

        $filas = DB::table('vw_salones_publicas')->where('slug', 'salon-inactivo')->get();

        $this->assertCount(0, $filas, 'Un salón inactivo no puede aparecer en el selector de ingreso.');
    }

    #[Test]
    public function la_restriccion_de_solapamiento_esta_en_la_base(): void
    {
        $salon = $this->crearSalon('salon-restriccion');
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 60, 15000, $profesional);

        $this->dentroDe($salon, static function () use ($profesional, $clienta, $servicio): void {
            Cita::query()->create([
                'tenant_id' => $profesional->tenant_id,
                'profesional_id' => $profesional->id,
                'clienta_id' => $clienta->id,
                'servicio_id' => $servicio->id,
                'fecha_inicio' => '2026-03-02 10:00:00',
                'fecha_fin' => '2026-03-02 11:00:00',
                'duracion_minutos' => 60,
                'estado' => 'agendada',
            ]);
        });

        // Esta segunda cita se pisa con la primera. Lo que la frena es la
        // restricción `EXCLUDE` de PostgreSQL, no el código PHP.
        $rechazada = false;

        try {
            $this->dentroDe($salon, static function () use ($profesional, $clienta, $servicio): void {
                Cita::query()->create([
                    'tenant_id' => $profesional->tenant_id,
                    'profesional_id' => $profesional->id,
                    'clienta_id' => $clienta->id,
                    'servicio_id' => $servicio->id,
                    'fecha_inicio' => '2026-03-02 10:30:00',
                    'fecha_fin' => '2026-03-02 11:30:00',
                    'duracion_minutos' => 60,
                    'estado' => 'agendada',
                ]);
            });
        } catch (\Throwable) {
            $rechazada = true;
        }

        $this->assertTrue(
            $rechazada,
            'La base aceptó dos citas que se pisan: la restricción EXCLUDE no está puesta.',
        );
    }
}