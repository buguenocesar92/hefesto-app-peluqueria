<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clientas`: la ficha.
 *
 * QUÉ ES Y QUÉ NO ES ESTA TABLA
 * -----------------------------
 * Es la IDENTIFICACIÓN: nombre, RUT, teléfono, correo y la ficha de salud que
 * se declara en la tabla contigua. Es el dato que permite llamar a alguien y
 * saber quién es.
 *
 * NO es el historial. El historial de lo que se le hizo es `ventas` y
 * `atenciones`, y se abre en su propia pantalla, con su propio permiso y su
 * propia bitácora de accesos. Separar las dos cosas es lo que permite dar
 * acceso a la agenda sin dar acceso a la historia. Ver docs/03 y docs/07.
 *
 * DATOS PERSONALES Y SENSIBLES
 * ----------------------------
 * Nombre, RUT y teléfono son datos personales. `antecedentes_salud` es dato
 * sensible bajo la Ley 19.628. Los plazos de retención están en configuración y
 * NACEN EN `null`: ver docs/07.
 *
 * `rut` NO se valida con el algoritmo módulo 11 de acá. La validación vive en
 * el FormRequest, que sí lo hace, y el motivo de no hacerlo en la base es que
 * el RUT es un dato de la persona y no un dato de la agenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('apellido', 160)->nullable();
            $tabla->string('rut', 16)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->date('fecha_nacimiento')->nullable();
            $tabla->text('notas')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientas');
    }
};