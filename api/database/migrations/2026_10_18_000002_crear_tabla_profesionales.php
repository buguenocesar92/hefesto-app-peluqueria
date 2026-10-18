<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `profesionales`: quién atiende.
 *
 * UNA SILLA POR PROFESIONAL
 * -------------------------
 * En un salón la silla es un recurso físico y por lo tanto escaso: no puede
 * haber dos profesionales trabajando en la misma silla a la vez. La agenda lo
 * modela con `silla`, pero acá solo se declara qué silla usa cada una.
 *
 * `registro_verificado` NO se valida contra ninguna fuente oficial. Es un dato
 * que declara el salón, y por eso no se llena solo: queda en `false` hasta que
 * alguien lo confirme a mano. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profesionales', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('especialidad', 120)->nullable();
            $tabla->string('silla', 24)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('telefono', 32)->nullable();
            // Texto libre. NO se valida contra ninguna fuente oficial.
            $tabla->string('registro_profesional', 64)->nullable();
            $tabla->boolean('registro_verificado')->default(false);
            $tabla->string('color_agenda', 7)->default('#2f6f6f');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
        });

        DB::statement(<<<'SQL'
            COMMENT ON TABLE public.profesionales IS
                'Quien atiende en el salon. La silla es un recurso fisico compartido, por eso se declara por persona y la agenda la respeta.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('profesionales');
    }
};