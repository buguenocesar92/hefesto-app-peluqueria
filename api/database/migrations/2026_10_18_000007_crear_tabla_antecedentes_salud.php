<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `antecedentes_salud`: lo que hay que saber ANTES de tocar el pelo.
 *
 * POR QUÉ ESTÁ SEPARADA DE `clientas`
 * -----------------------------------
 * Porque no es lo mismo. Nombre y teléfono son datos personales: los necesita
 * cualquiera que atienda. Una alergia a tintes o una contraindicación es dato
 * SENSIBLE bajo la Ley 19.628, y la ley trata esos datos de otra manera.
 *
 * Mezclarlas en una tabla obliga a dar permiso sobre el teléfono para poder ver
 * la alergia. Separarlas permite que recepción agende sin abrir esto, y que la
 * pantalla que lo abre quede auditada. Ver docs/03 y docs/07.
 *
 * EL ROL LO PONE LA PROFESIONAL
 * ------------------------------
 * `severidad` y `bloquea_servicios` los declara quien atiende, que es quien
 * puede juzgarlos. Recepción registra lo que la clienta informa; no decide si
 * eso bloquea o no.
 *
 * LA REGLA DE ESPERA ENTRE PROCESOS QUEDA PENDIENTE
 * -------------------------------------------------
 * Es verdad que un tinte y un retoque de color no se pueden hacer el mismo día
 * sobre el mismo pelo, y que hay procesos que exigen espera. Lo que NO se pudo
 * verificar en este entorno es cuántos días exige cada combinación, ni si la
 * regla depende de la técnica o del fabricante. Por eso esta tabla NO trae una
 * columna de días: el software no puede inventar ese dato. Ver docs/02.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antecedentes_salud', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('clienta_id');
            // `alergia`, `contraindicacion`, `condicion`, `sensibilidad`.
            $tabla->string('tipo', 32);
            $tabla->string('descripcion', 480);
            // `leve`, `moderada`, `grave`. La pone la profesional.
            $tabla->string('severidad', 16)->default('moderada');
            // Si está activo, la agenda avisa antes de agendar.
            $tabla->boolean('bloquea_servicios')->default(false);
            // Servicios concretos a los que aplica. En `null`, a todos.
            $tabla->jsonb('servicios_afectados')->nullable();
            $tabla->uuid('registrado_por')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('clienta_id')->references('id')->on('clientas')->cascadeOnDelete();
            $tabla->foreign('registrado_por')->references('id')->on('profesionales')->nullOnDelete();

            $tabla->index(['tenant_id', 'clienta_id', 'activo']);
        });

        DB::statement(<<<'SQL'
            COMMENT ON TABLE public.antecedentes_salud IS
                'Dato sensible de salud segun la Ley 19.628. No se aqui la cantidad de dias que hay que esperar entre dos procesos quimicos: ese dato no se pudo verificar y queda pendiente. Ver docs/02.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('antecedentes_salud');
    }
};