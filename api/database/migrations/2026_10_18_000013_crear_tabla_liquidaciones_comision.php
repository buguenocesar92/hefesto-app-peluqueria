<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `liquidaciones_comision`: el resultado del cálculo, con su desglose.
 *
 * ESTA TABLA ES LA QUE HACE LA COMISIÓN AUDITABLE
 * ------------------------------------------------
 * El dueño necesita saber POR QUÉ a una profesional le salió tal cifra. Un
 * número solo no sirve. Por eso la liquidación guarda, línea por línea, qué
 * venta entró, qué porcentaje se aplicó y de dónde salió ese porcentaje.
 *
 * `porcentaje_servicios` y `porcentaje_productos` SE COPIAN
 * ----------------------------------------------------------
 * Si se guardara solo el total, un cambio de política en el futuro haría que
 * esta liquidación quedara inexplicable. Al copiar el porcentaje aplicado, la
 * cifra queda explicada aunque la política cambie mañana.
 *
 * `base_servicios` Y `base_productos` ESTÁN SEPARADOS
 * ---------------------------------------------------
 * Porque el dueño pregunta por separado "¿cuánto hice de servicios" y
 * "¿cuánto hice de productos". Juntarlos en un solo número pierde la pregunta.
 *
 * NO SE BORRA UNA LIQUIDACIÓN
 * ---------------------------
 * Es un hecho económico. Se anula con `estado = anulada` y motivo, nunca se
 * elimina. Ver docs/08.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_comision', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('politica_comision_id');

            $tabla->date('periodo_desde');
            $tabla->date('periodo_hasta');

            // Se copian de la política para que la liquidación se pueda explicar
            // aunque la política cambie después.
            $tabla->decimal('porcentaje_servicios', 5, 2);
            $tabla->decimal('porcentaje_productos', 5, 2);
            $tabla->boolean('incluye_productos');

            $tabla->unsignedBigInteger('base_servicios')->default(0);
            $tabla->unsignedBigInteger('base_productos')->default(0);
            $tabla->unsignedBigInteger('comision_servicios')->default(0);
            $tabla->unsignedBigInteger('comision_productos')->default(0);
            $tabla->unsignedBigInteger('total_comision')->default(0);

            // `borrador`, `cerrada`, `anulada`.
            $tabla->string('estado', 16)->default('borrador');
            $tabla->text('motivo_anulacion')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('politica_comision_id')->references('id')->on('politicas_comision')->restrictOnDelete();

            $tabla->index(['tenant_id', 'profesional_id', 'periodo_desde']);
            $tabla->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_comision');
    }
};