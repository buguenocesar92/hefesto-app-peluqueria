<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bloqueos_agenda`: los tramos en que una profesional NO atiende.
 *
 * POR QUÉ EXISTE ESTA TABLA
 * -------------------------
 * Porque nadie atiende todo el día. La colación, el descanso, la atención a
 * otra persona, el curso: son tramos reales en que la agenda tiene que estar
 * cerrada, y no se pueden pisar.
 *
 * QUE NO HAY QUE CONFUNDIR
 * ------------------------
 * Un bloqueo NO es una cita anulada. La cita anulada era una atención que se
 * costó; el bloqueo es tiempo que nunca se ocupará. Se guardan en tablas
 * separadas a propósito, y por eso no se pueden borrar al mismo tiempo.
 *
 * POR QUÉ NO SE RESUELVE CON UNA RESTRICCIÓN
 * ------------------------------------------
 * PostgreSQL no puede escribir una exclusión de rango ENTRE DOS TABLAS. La
 * restricción `EXCLUDE` de `citas` protege contra el solapamiento entre citas;
 * el solapamiento entre una cita y un bloqueo lo tiene que mirar el software,
 * en App\Services\Agenda\VerificadorDeDisponibilidad. Es la parte que puede
 * tener un error, y por eso hay una prueba dedicada. Ver docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueos_agenda', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            // En `null`, el bloqueo aplica a todo el salón: es el caso de un
            // feriado o de un corte de luz, que no es de una profesional.
            $tabla->uuid('profesional_id')->nullable();
            $tabla->timestampTz('fecha_inicio');
            $tabla->timestampTz('fecha_fin');
            // `colacion`, `descanso`, `capacitacion`, `otro`.
            $tabla->string('motivo', 120);
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'profesional_id', 'fecha_inicio']);
            $tabla->index(['tenant_id', 'fecha_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueos_agenda');
    }
};