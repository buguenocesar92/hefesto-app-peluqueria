<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `servicios_profesional`: qué hace cada profesional.
 *
 * POR QUÉ NO SE USA UNA TABLA INTERMEDIA SIMPLE
 * --------------------------------------------
 * Porque no alcanza con decir "esta persona hace este servicio". En un salón la
 * duración y el precio cambian según quién los hace: una decoloración con
 * peluquera junior no dura lo mismo que con la encargada. Por eso la tabla
 * intermedia trae su propio `duracion_minutos` y su propio `precio`, que son
 * opcionales: en `null` se usa lo del catálogo de `servicios`.
 *
 * ES LA RESPUESTA A "UN CORTE Y UN BALAYAGE NO SON LO MISMO"
 * ---------------------------------------------------------
 * La agenda necesita saber cuánto ocupa CADA servicio en la silla de CADA
 * profesional. Ver App\Services\Agenda\ServicioAgenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios_profesional', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('servicio_id');
            // En `null` manda `servicios.duracion_minutos`.
            $tabla->unsignedSmallInteger('duracion_minutos')->nullable();
            // En `null` manda `servicios.precio_base`.
            $tabla->unsignedBigInteger('precio')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->cascadeOnDelete();
            $tabla->foreign('servicio_id')->references('id')->on('servicios')->cascadeOnDelete();

            // Una fila por par. Es lo que hace que el servicio no se repita en
            // el selector de la agenda.
            $tabla->unique(['profesional_id', 'servicio_id']);
            $tabla->index(['tenant_id', 'profesional_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios_profesional');
    }
};