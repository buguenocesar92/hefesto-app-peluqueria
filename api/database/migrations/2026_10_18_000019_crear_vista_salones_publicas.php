<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista pública de salones: la excepción consciente de la RLS.
 *
 * `salones` NO lleva Row Level Security, porque hay que leerla antes del ingreso,
 * cuando todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner.
 *
 * ANTES DEL INGRESO NO SE LEE `salones` CRUDO
 * ------------------------------------------
 * Se lee esta vista, que expone TRES columnas (`slug`, `nombre`, `activo`) y
 * solo de los salones activos. No expone RUT, dirección, correo ni teléfono:
 * antes del ingreso no hay motivo para que el navegador vea nada de eso.
 *
 * ESTA VISTA SE USA DE VERDAD
 * --------------------------
 * La consulta al login selector está en `AuthController::salones()`, que lee
 * `vw_salones_publicas`. No es decorativa: es lo que el selector de la pantalla
 * de ingreso muestra, y lo que decide si un salón puede atender un ingreso.
 * El comando `php artisan peluqueria:verificar-aislamiento` comprueba que la
 * vista siga teniendo exactamente esas tres columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_salones_publicas AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.salones
            WHERE activo = true
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_salones_publicas IS
                'Lo unico que se puede leer de salones antes del ingreso: slug, nombre y si esta activo. No expone datos de contacto.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_salones_publicas');
    }
};