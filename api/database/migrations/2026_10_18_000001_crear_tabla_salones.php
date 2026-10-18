<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `salones`: el tenant.
 *
 * POR QUÉ ESTA TABLA NO LLEVA ROW LEVEL SECURITY
 * ---------------------------------------------
 * Se consulta en el ingreso, antes de que exista `app.tenant_id`: sin ella no
 * hay forma de saber a qué salón pertenece la persona que está entrando.
 * Es la excepción consciente del patrón, no un olvido. La compensación es la
 * vista `vw_salones_publicas`, que expone solo tres columnas, y el comando
 * `php artisan peluqueria:verificar-aislamiento`. Ver docs/03.
 *
 * `slug` es el identificador corto que viaja en el equipo de Authentik, igual
 * que en el resto de las aplicaciones de Hefesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 64)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('nombre_corto', 64);
            $tabla->string('direccion', 240)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });

        // El selector de ingreso ordena por nombre, no por `created_at`: la
        // lista que ve quien entra es la lista de salones, no la lista de
        // salones en el orden en que se abrieron.
        Schema::table('salones', function (Blueprint $tabla): void {
            $tabla->index(['activo', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salones');
    }
};