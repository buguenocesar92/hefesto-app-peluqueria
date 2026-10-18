<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `accesos_salud`: quién abrió los antecedentes de salud y con qué resultado.
 *
 * QUÉ SE ANOTA
 * ------------
 * Cada intento de abrir `antecedentes_salud`, PERMITIDO o DENEGADO, con quién,
 * cuándo y desde dónde. El denegado también se anota: un intento que falla es
 * información, y en un salón con datos sensibles es la más importante.
 *
 * POR QUÉ UNA TABLA Y NO SOLO EL REGISTRO
 * ----------------------------------------
 * El registro general guarda una línea. Esto guarda QUIÉN ABRIÓ QUÉ FICHA, que
 * es la pregunta que hace un dueño y, en un caso de denuncia, la que hace un
 * fiscalizador.
 *
 * NO HAY RLS CONVENCIONAL AQUÍ
 * ---------------------------
 * Esta tabla tiene RLS como las demás, pero el intento denegado a veces se
 * escribe cuando la petición NO tiene contexto de salón. En ese caso el
 * registro va por una conexión aparte, `auditoria`, para que sobreviva a la
 * reversa de la transacción. Ver App\Services\Tenancy\AuditorDeAcceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_salud', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('clienta_id');
            // Quien intentó. En un intento denegado puede quedar en `null`: no
            // siempre hay sesión detrás del intento.
            $tabla->uuid('usuario_id')->nullable();
            // `permitido` o `denegado`.
            $tabla->string('resultado', 16);
            $tabla->string('accion', 64);
            $tabla->string('ip_address', 45)->nullable();
            $tabla->string('ruta', 240)->nullable();
            $tabla->text('detalle')->nullable();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('clienta_id')->references('id')->on('clientas')->cascadeOnDelete();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();

            $tabla->index(['tenant_id', 'creado_en']);
            $tabla->index(['tenant_id', 'usuario_id', 'resultado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_salud');
    }
};