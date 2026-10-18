<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sesiones`: dónde vive la cookie de sesión.
 *
 * POR QUÉ ESTA TABLA NO LLEVA RLS
 * -------------------------------
 * Porque se consulta ANTES de saber a qué salón pertenece la persona: el
 * guard `web` la necesita para resolver el usuario, y en ese instante todavía
 * no hay `app.tenant_id`. Con RLS, nadie podría iniciar sesión.
 *
 * Es la misma excepción que `salones`, y por la misma razón. La fila contiene
 * un identificador de sesión y el identificador de usuario, que es lo que hay
 * dentro. El contenido de la sesión no va en la base: viaja en la cookie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->uuid('user_id')->nullable();
            $tabla->string('ip_address', 45)->nullable();
            $tabla->text('user_agent')->nullable();
            $tabla->text('payload')->nullable();
            $tabla->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};