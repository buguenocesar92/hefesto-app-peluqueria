<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AISLAMIENTO ENTRE SALONES: Row Level Security de PostgreSQL.
 *
 * PATRÓN REUTILIZADO, NO INVENTADO
 * --------------------------------
 * Es el mismo patrón de `apps/portal-autoservicio/docs/03-MULTITENANCY.md`,
 * repetido en las demás aplicaciones de Hefesto: columna `tenant_id`, políticas
 * de RLS con `USING` (lectura) y `WITH CHECK` (escritura), y
 * `FORCE ROW LEVEL SECURITY`. No hay un cuarto enfoque.
 *
 * CUATRO COSAS QUE HACE ESTA MIGRACIÓN
 * ------------------------------------
 * 1. `public.app_tenant_id()` Lanza excepción si no hay contexto. Podría
 *    devolver `NULL` y filtrar todo en silencio; se eligió que falle ruidoso,
 *    para que el registro diga exactamente qué operación se saltó el middleware.
 * 2. Política `FOR ALL` con `USING` y `WITH CHECK`. Sin `WITH CHECK` se podría
 *    insertar una fila declarando otro salón: la lectura la ocultaría y nadie
 *    la vería nunca.
 * 3. `FORCE ROW LEVEL SECURITY` en cada tabla, para que la política aplique
 *    también al dueño de la tabla. En desarrollo el rol de la aplicación suele
 *    ser el mismo que creó las tablas; sin `FORCE` el aislamiento sería
 *    decorativo justo donde se prueba.
 * 4. `set_config(..., true)`, con alcance de TRANSACCIÓN. Ver
 *    `App\Services\Tenancy\ContextoSalon`: el worker de colas reutiliza la
 *    conexión y con un `SET` de sesión arrastraría el contexto de otro salón.
 *
 * `salones` NO ESTÁ EN LA LISTA, a propósito: se consulta en el ingreso, antes
 * de que exista sesión. Se compensa con la vista `vw_salones_publicas` y con el
 * comando `php artisan peluqueria:verificar-aislamiento`.
 *
 * REQUISITO DE DESPLIEGUE: el rol de la aplicación no puede ser superusuario ni
 * tener BYPASSRLS. Con superusuario, PostgreSQL ignora las políticas por
 * completo.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio, todas con `tenant_id`.
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

    public function up(): void
    {
        $this->crearFuncionDeContexto();

        foreach (self::TABLAS as $tabla) {
            $this->exigirColumnaTenant($tabla);
            $this->proteger($tabla);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            DB::statement('ALTER TABLE ' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE ' . $tabla . ' DISABLE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS politica_aislamiento_salon ON ' . $tabla);
        }

        DB::statement('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Función que lee el contexto de salón de la petición.
     *
     * `current_setting(..., true)` devuelve `NULL` si la variable no existe, en
     * vez de fallar, que es justo el caso que hay que detectar.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'Falta el contexto de salon (app.tenant_id). '
                        . 'Usa App\Services\Tenancy\ContextoSalon::ejecutarAislado().'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el salon de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     *
     * Sin esta comprobación, un error de tipeo en la lista dejaría una tabla de
     * datos de salud sin protección y la migración pasaría igual.
     */
    private function exigirColumnaTenant(string $tabla): void
    {
        $fila = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = \'public\' AND table_name = ? AND column_name = \'tenant_id\'
             ) AS tiene_columna',
            [$tabla],
        );

        if ($fila === null || ! $fila->tiene_columna) {
            throw new RuntimeException(
                'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                . 'Revise la migración antes de continuar.'
            );
        }
    }

    private function proteger(string $tabla): void
    {
        DB::statement('ALTER TABLE ' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // Sin esto, las políticas no aplican al dueño de la tabla.
        DB::statement('ALTER TABLE ' . $tabla . ' FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            CREATE POLICY politica_aislamiento_salon ON {$tabla}
                FOR ALL
                TO PUBLIC
                USING (tenant_id = public.app_tenant_id())
                WITH CHECK (tenant_id = public.app_tenant_id())
        SQL);
    }
};