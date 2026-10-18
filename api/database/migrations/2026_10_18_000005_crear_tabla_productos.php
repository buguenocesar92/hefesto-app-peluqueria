<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `productos`: lo que la clienta se lleva puestos.
 *
 * UN SERVICIO Y UN PRODUCTO NO SON LO MISMO
 * -----------------------------------------
 * El corte se agenda: ocupa la silla y tiene duración. El champú se vende al
 * momento, no ocupa silla y no tiene duración. Son cosas distintas con tiempos
 * de venta distintos, y por eso van en tablas distintas.
 *
 * La diferencia importa para la comisión: el profesional puede tener porcentajes
 * distintos para cada tipo, y el cálculo necesita saber cuál es cuál. Ver
 * App\Services\Comisiones\CalculadoraDeComision.
 *
 * `precio_venta` lo declara el salón. No hay valor por omisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('descripcion', 480)->nullable();
            $tabla->string('marca', 120)->nullable();
            $tabla->unsignedBigInteger('precio_venta')->default(0);
            $tabla->integer('stock')->nullable();
            $tabla->string('unidad', 24)->default('unidad');
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};