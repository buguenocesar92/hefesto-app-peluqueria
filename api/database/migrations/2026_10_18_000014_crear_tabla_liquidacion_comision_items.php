<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `liquidacion_comision_items`: el desglose, línea por línea.
 *
 * POR QUÉ EXISTE SI `liquidaciones_comision` YA TIENE EL TOTAL
 * -----------------------------------------------------------
 * Porque un total no se explica solo. Esta tabla es la que permite responder
 * "¿por qué le salió esta cifra" mostrando cada venta que entró y qué se le
 * calculó. Sin ella, auditar una comisión es imposible y el dueño no tiene cómo
 * discutirla.
 *
 * QUÉ GUARDA CADA LÍNEA
 * ---------------------
 *   - `venta_id` y `venta_item_id`: de dónde salió la cifra.
 *   - `tipo`: si fue servicio o producto, que es lo que decide el porcentaje.
 *   - `base_aplicada`: el monto sobre el que se calculó.
 *   - `porcentaje_aplicado`: el porcentaje USADO en esa línea, que puede
 *     diferir del general si esa venta quedó fuera por una promoción.
 *   - `comision_calculada`: el resultado de esa línea.
 *
 * QUE SEA INMUTABLE
 * -----------------
 * Una vez cerrada la liquidación, estas líneas no se tocan. Para corregir algo
 * se anula la liquidación y se rehace: una corrección que se edita en silencio
 * no es una corrección, es una cifra que cambió sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidacion_comision_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('liquidacion_comision_id');
            $tabla->uuid('venta_id');
            $tabla->uuid('venta_item_id');

            $tabla->string('tipo', 16);
            $tabla->string('descripcion', 160);
            $tabla->unsignedBigInteger('base_aplicada');
            // Puede diferir del general: una línea bonificada no lleva comisión.
            $tabla->decimal('porcentaje_aplicado', 5, 2);
            $tabla->unsignedBigInteger('comision_calculada');
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('liquidacion_comision_id')->references('id')->on('liquidaciones_comision')->cascadeOnDelete();
            $tabla->foreign('venta_id')->references('id')->on('ventas')->restrictOnDelete();
            $tabla->foreign('venta_item_id')->references('id')->on('venta_items')->restrictOnDelete();

            // Una línea por venta y por ítem: es lo que hace auditable el desglose.
            $tabla->unique(['liquidacion_comision_id', 'venta_item_id']);
            $tabla->index(['tenant_id', 'venta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidacion_comision_items');
    }
};