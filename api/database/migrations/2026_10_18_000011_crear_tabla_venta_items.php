<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `venta_items`: las líneas de una venta.
 *
 * EL PRECIO SE COPIA, NO SE LEE DEL CATÁLOGO
 * ------------------------------------------
 * `precio_unitario` queda escrito en la línea. Si mañana el salón sube el
 * precio del corte, la venta de hoy sigue diciendo el precio que se cobró. Una
 * comisión que se recalcula sobre el catálogo actual no se puede auditar: la
 * cifra que salió en marzo tiene que seguir saliendo en septiembre.
 *
 * `tipo` ES LO QUE SEPARA UN SERVICIO DE UN PRODUCTO
 * --------------------------------------------------
 * Es la columna que la comisión lee para aplicar un porcentaje u otro. No se
 * deduce de si el identificador viene de `servicios` o de `productos`, porque
 * un mismo servicio puede haberse vendido como parte de una promoción y no
 * llevar comisión. Ver App\Services\Comisiones\CalculadoraDeComision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('venta_id');
            // `servicio` o `producto`. Es lo que decide el porcentaje.
            $tabla->string('tipo', 16);
            $tabla->uuid('servicio_id')->nullable();
            $tabla->uuid('producto_id')->nullable();
            // Se copia el nombre: la línea tiene que poder leerse aunque el
            // catálogo cambie o se desactive.
            $tabla->string('descripcion', 160);
            $tabla->unsignedInteger('cantidad')->default(1);
            // El precio que efectivamente se cobró, en pesos chilenos.
            $tabla->unsignedBigInteger('precio_unitario')->default(0);
            $tabla->unsignedBigInteger('subtotal')->default(0);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('venta_id')->references('id')->on('ventas')->cascadeOnDelete();
            $tabla->foreign('servicio_id')->references('id')->on('servicios')->nullOnDelete();
            $tabla->foreign('producto_id')->references('id')->on('productos')->nullOnDelete();

            // Una línea de producto no puede traer `servicio_id`: la base lo
            // impide, para que ninguna línea ambigua llegue al cálculo.
            $tabla->check("tipo = 'producto' OR (tipo = 'servicio' AND producto_id IS NULL)");
            $tabla->check("tipo = 'servicio' OR (tipo = 'producto' AND servicio_id IS NULL)");
            $tabla->check('subtotal = cantidad * precio_unitario');

            $tabla->index(['tenant_id', 'venta_id']);
            $tabla->index(['tenant_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_items');
    }
};