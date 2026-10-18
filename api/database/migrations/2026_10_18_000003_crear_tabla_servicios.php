<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `servicios`: catálogo de lo que se hace en la silla.
 *
 * LA DURACIÓN ES EL DATO CENTRAL
 * ------------------------------
 * Un corte de pelo y un balayage no ocupan lo mismo, y agendar todo en bloques
 * iguales es exactamente lo que hace que un salón se atrase. Por eso
 * `duracion_minutos` es obligatorio y la cita la copia desde acá: la agenda se
 * llena por TIEMPO DE SERVICIO, no por una hora fija.
 *
 * `precio_base` NO tiene valor por omisión a propósito. El precio de cada
 * servicio lo declara el salón; el software no lo inventa. Ver docs/05.
 *
 * `es_reposicion` marca los servicios que dejan el pelo en un estado del que
 * hay que esperar antes de aplicar otro proceso químico. La regla concreta
 * (qué servicios se repelen entre sí y cuántos días hay que esperar) NO está
 * escrita en el software: es conocimiento químico capilar que queda pendiente
 * de verificación. Ver docs/02 y docs/06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('descripcion', 480)->nullable();
            $tabla->unsignedSmallInteger('duracion_minutos');
            // Pesos chilenos, sin decimales. Un salon no cobra fracciones de peso.
            $tabla->unsignedBigInteger('precio_base')->default(0);
            $tabla->string('categoria', 80)->nullable();
            $tabla->boolean('es_reposicion')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};