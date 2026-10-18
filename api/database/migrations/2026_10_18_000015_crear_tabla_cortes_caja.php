<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cortes_caja`: el cierre del día, por profesional.
 *
 * LO QUE CIERRA EL DÍA EN UN SALÓN
 * ---------------------------------
 * Cada profesional tiene su caja: es quien cobró, y es a quien se le entrega al
 * final del día. El corte es por profesional y por día, no un corte general del
 * salón. Por eso la clave única es `(profesional_id, fecha)`: dos cajas el mismo
 * día no se cierran en el mismo registro.
 *
 * LA DIFERENCIA ES EL NÚMERO QUE IMPORTA
 * --------------------------------------
 * `total_esperado` sale de las ventas cobradas de esa profesional. `total_declarado`
 * es lo que la persona dice tener. `diferencia` es la resta. No se recalcula al
 * leer: se guarda el valor con el que se cerró, porque un corte que se recalcula
 * puede cambiar de número después sin que nadie se entere.
 *
 * UNA RELACIÓN CON LA COMISIÓN, NO UNA MISMA
 * ------------------------------------------
 * El corte dice cuánto dinero hubo. La comisión dice cuánto le corresponde a la
 * profesional por ese dinero. Son dos cifras distintas y se calculan sobre
 * bases distintas, así que van en tablas distintas. Ver docs/02.
 *
 * `ventas_incluidas` CONGELA QUÉ SE CONTÓ
 * ----------------------------------------
 * Cuántas ventas entran en este corte. Si mañana se anula una venta de hoy, este
 * corte sigue diciendo cuántas contó, y la diferencia se refleja en el corte
 * siguiente. Un corte que cambia solo no sirve para nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cortes_caja', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->date('fecha');

            // Suma de las ventas cobradas del día, en el momento del corte.
            $tabla->unsignedBigInteger('total_esperado')->default(0);
            // Lo que la profesional declara tener.
            $tabla->unsignedBigInteger('total_declarado')->default(0);
            // Se guarda, no se deriva: el registro de un cierre es inmutable.
            $tabla->bigInteger('diferencia')->default(0);
            $tabla->unsignedInteger('ventas_incluidas')->default(0);
            $tabla->unsignedBigInteger('total_efectivo')->default(0);
            $tabla->unsignedBigInteger('total_tarjeta')->default(0);

            // `abierto`, `cerrado`.
            $tabla->string('estado', 16)->default('abierto');
            $tabla->text('observaciones')->nullable();
            $tabla->uuid('cerrado_por')->nullable();
            $tabla->timestampTz('cerrado_en')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('cerrado_por')->references('id')->on('usuarios')->nullOnDelete();

            // Un corte por profesional y por día. Es lo que impide cerrar dos
            // veces el mismo día y que la segunda cifra pise la primera.
            $tabla->unique(['profesional_id', 'fecha']);
            $tabla->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cortes_caja');
    }
};