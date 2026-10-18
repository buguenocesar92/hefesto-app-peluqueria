<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `politicas_comision`: la decisión del negocio sobre cómo se paga.
 *
 * ESTA TABLA ES LA RAZÓN DEL ENCARGO
 * -----------------------------------
 * En un salón la profesional no tiene sueldo fijo: cobra un porcentaje de lo
 * que vendió. El porcentaje no es un dato técnico, es una DECISIÓN DEL NEGOCIO.
 * Por eso vive en una tabla y no en el código, y por eso no hay ningún valor por
 * omisión: el software no sabe cuánto paga un salón.
 *
 * LO QUE HACE LA TABLA
 * --------------------
 * `porcentaje_servicios` y `porcentaje_productos` son dos porcentajes, no uno,
 * porque hay salones que pagan distinto el trabajo de silla que el producto.
 *
 * LA BASE DE CÁLCULO SE DECLARA, SE SUPONE
 * ----------------------------------------
 * `incluye_productos` es el interruptor que decide si el producto entra o no en
 * la base. Es exactamente la pregunta que un dueño hace al discutir comisiones,
 * y por eso tiene que ser un campo y no un comportamiento escondido en el
 * cálculo. Ver docs/02 y docs/06.
 *
 * VIGENCIA POR FECHAS
 * -------------------
 * Una comisión se acuerda por un período. Cambiarla no debe reescribir lo que ya
 * se liquidó: por eso tiene `vigente_desde` y `vigente_hasta`, y el cálculo usa
 * la política que estaba vigente el día de la venta.
 *
 * `monto_redondeo` dice en qué moneda se redondea la comisión. En Chile se
 * redondea al peso entero, y eso se declara en vez de suponerse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('politicas_comision', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // En `null`, aplica a todo el salón: la política general.
            $tabla->uuid('profesional_id')->nullable();

            // Porcentaje con dos decimales: 35.50 se escribe 35.50, no 0.355.
            // Se guarda como `numeric`, no como entero, porque el acuerdo puede
            // tener decimales.
            $tabla->decimal('porcentaje_servicios', 5, 2);
            $tabla->decimal('porcentaje_productos', 5, 2);

            // Si es `false`, el producto NO entra a la base de la comisión.
            $tabla->boolean('incluye_productos')->default(true);

            // `servicio`, `producto` o `ambos`. Lo que decide qué se liquida.
            $tabla->string('base_calculo', 16)->default('ambos');

            $tabla->date('vigente_desde');
            $tabla->date('vigente_hasta')->nullable();

            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->cascadeOnDelete();

            $tabla->check('porcentaje_servicios >= 0 AND porcentaje_servicios <= 100');
            $tabla->check('porcentaje_productos >= 0 AND porcentaje_productos <= 100');
            $tabla->check("base_calculo IN ('servicio', 'producto', 'ambos')");
            $tabla->check('vigente_hasta IS NULL OR vigente_hasta >= vigente_desde');

            $tabla->index(['tenant_id', 'profesional_id', 'vigente_desde']);
        });

        DB::statement(<<<'SQL'
            COMMENT ON TABLE public.politicas_comision IS
                'La comision es una decision del negocio, no un dato tecnico. No hay valores por omision: el salon los declara. El calculo usa la politica vigente el dia de la venta.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('politicas_comision');
    }
};