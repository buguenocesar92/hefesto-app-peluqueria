<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `citas`: la agenda, medida en tiempo de servicio.
 *
 * `fecha_fin` NO ES `fecha_inicio` MÁS UNA CONSTANTE
 * --------------------------------------------------
 * Sale de la duración real del servicio para esa profesional, que puede venir
 * del catálogo o de la tabla `servicios_profesional`. Un corte de pelo y un
 * balayage no ocupan lo mismo, y agendar en bloques iguales es lo que hace que
 * un salón se atrase. Ver App\Services\Agenda\ServicioAgenda.
 *
 * LA DURACIÓN SE COPIA, NO SE CALCULA AL LEER
 * -------------------------------------------
 * `duracion_minutos` queda guardado en la cita. Si mañana el salón cambia la
 * duración de un servicio, la cita de hoy sigue siendo la cita de hoy: un
 * cambio de catálogo no debe reescribir el pasado.
 *
 * EL RETRASO ES LA REGLA, NO LA EXCEPCIÓN
 * ---------------------------------------
 * En un salón el servicio se alarga. `hora_inicio_real` y `hora_fin_real` son
 * la hora a la que la clienta se sentó y a la que se levantó. Se registran
 * cuando se cierra la cita, y de ahí sale el retraso, que es el número que el
 * dueño necesita ver. Ver docs/02.
 *
 * EL ÍNDICE EXCLUSIVO ES LO QUE IMPIDE EL DOBLE AGENDADO
 * -----------------------------------------------------
 * `EXCLUDE USING gist` con `&&` sobre el rango de tiempo obliga a que dos citas
 * del mismo profesional no se solapen. Vive en la base: no depende de que el
 * controlador haya comprobado bien. Requiere la extensión `btree_gist`.
 *
 * EL BLOQUEO DE AGENDA NO ESTÁ EN ESTA TABLA
 * ------------------------------------------
 * Un bloqueo (colación, descanso) y una cita son cosas distintas y PostgreSQL
 * no puede escribir una exclusión entre dos tablas. Eso se comprueba en
 * App\Services\Agenda\VerificadorDeDisponibilidad, que es software y por lo
 * tanto puede tener un error. La exclusión de acá no puede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('clienta_id');
            $tabla->uuid('servicio_id');
            $tabla->timestampTz('fecha_inicio');
            $tabla->timestampTz('fecha_fin');
            $tabla->unsignedSmallInteger('duracion_minutos');
            // `agendada`, `confirmada`, `en_curso`, `atendida`, `no_asistio`,
            // `anulada`.
            $tabla->string('estado', 16)->default('agendada');
            $tabla->string('silla', 24)->nullable();
            $tabla->timestampTz('hora_inicio_real')->nullable();
            $tabla->timestampTz('hora_fin_real')->nullable();
            // Minutos de retraso. Se calcula al cerrar, no se estima al agendar.
            $tabla->unsignedSmallInteger('minutos_retraso')->nullable();
            $tabla->string('motivo', 240)->nullable();
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('clienta_id')->references('id')->on('clientas')->restrictOnDelete();
            $tabla->foreign('servicio_id')->references('id')->on('servicios')->restrictOnDelete();

            $tabla->index(['tenant_id', 'profesional_id', 'fecha_inicio']);
            $tabla->index(['tenant_id', 'clienta_id', 'fecha_inicio']);
        });

        // `btree_gist` permite combinar un `uuid` con el operador de rango del
        // índice GiST. Sin esto, la exclusión de solapamiento no se puede
        // escribir. Es una extensión estándar de PostgreSQL.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE citas
            ADD CONSTRAINT citas_sin_solapamiento
            EXCLUDE USING gist (
                tenant_id WITH =,
                profesional_id WITH =,
                tstzrange(fecha_inicio, fecha_fin, '[)') WITH &&
            )
            WHERE (estado IN ('agendada', 'confirmada', 'en_curso'));
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON CONSTRAINT citas_sin_solapamiento ON public.citas IS
                'Impide que dos citas del mismo profesional se solapen en el tiempo. Las citas anuladas y las que la clienta no vino quedan fuera.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};