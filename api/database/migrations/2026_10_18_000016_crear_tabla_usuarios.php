<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `usuarios`: la copia de trabajo de la identidad de Authentik.
 *
 * POR QUÉ ESTA TABLA Y NO SOLO EL TOKEN
 * -------------------------------------
 * El token de Authentik dice quién es la persona, no qué puede hacer en ESTE
 * salón. Para saber eso hay que guardarlo en algún lado, y ese lado es esta
 * tabla. La fuente de verdad sigue siendo Authentik: el rol se vuelve a leer en
 * cada ingreso, para que quitarle un equipo a alguien en Authentik le cierre la
 * puerta en la sesión siguiente. Ver docs/03.
 *
 * POR QUÉ NO HAY CONTRASEÑAS
 * -------------------------
 * Authentik ya resuelve contraseñas, segundo factor, recuperación y bloqueo de
 * cuentas. Reimplementarlo acá sería peor en todos los sentidos. La columna
 * `password` existe porque Laravel la espera, y queda SIEMPRE en `null`: no hay
 * ningún camino en esta aplicación que la escriba. Ver docs/07.
 *
 * `profesional_id` ENLAZA LA CUENTA CON QUIEN ATIENDE
 * ---------------------------------------------------
 * Una profesional que además es usuaria del sistema es una sola persona con dos
 * caras: la que entra a mirar su corte y la que atiende. Enlazarlas evita que
 * haya dos registros de la misma persona, que es lo que pasa cuando se separa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('nombre', 160);
            $tabla->string('correo', 160);
            // `recepcion`, `profesional`, `administrador`.
            $tabla->string('rol', 24);
            $tabla->uuid('profesional_id')->nullable();
            // El `sub` de Authentik. Se guarda aparte para no atar el historial
            // de la persona a un identificador del proveedor.
            $tabla->string('authentik_id', 160)->nullable();
            // SIEMPRE en `null`. Ver la nota del encabezado.
            $tabla->string('password')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_ingreso')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->nullOnDelete();

            // El correo identifica a la persona DENTRO de un salón: dos salones
            // pueden tener a la misma persona, y una persona puede estar en dos.
            $tabla->unique(['tenant_id', 'correo']);
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};