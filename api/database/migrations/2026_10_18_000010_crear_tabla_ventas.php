<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ventas`: lo que una clienta se llevó en una visita.
 *
 * UNA VENTA PUEDE TRAER SERVICIO Y PRODUCTO
 * -----------------------------------------
 * Una clienta se corta el pelo y lleva un champú. El corte ocupa la silla; el
 * champú no. Los dos van en la MISMA venta porque ocurrieron en la misma visita,
 * pero en líneas distintas (`venta_items`), y la comisión los trata distinto.
 *
 * POR QUÉ LA VENTA APUNTA AL PROFESIONAL
 * --------------------------------------
 * Porque la comisión es por profesional. El producto lo llevó la clienta durante
 * una atención, y el derecho a comisión es de quien la atendió, no de quien
 * cobró en caja. Por eso `profesional_id` está en la venta y no en el producto.
 *
 * ESTADO
 * ------
 * `borrador` se puede editar. `cobrada` ya no: es lo que entra al cálculo de
 * comisión y al corte de caja, y esos dos números tienen que poder explicarse.
 * Ver App\Services\Comisiones\CalculadoraDeComision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('profesional_id');
            $tabla->uuid('clienta_id');
            $tabla->uuid('cita_id')->nullable();
            // `borrador`, `cobrada`, `anulada`.
            $tabla->string('estado', 16)->default('borrador');
            // `efectivo`, `debito`, `credito`, `transferencia`.
            $tabla->string('forma_pago', 24)->nullable();
            $tabla->unsignedBigInteger('total_servicios')->default(0);
            $tabla->unsignedBigInteger('total_productos')->default(0);
            $tabla->unsignedBigInteger('total')->default(0);
            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('salones')->cascadeOnDelete();
            $tabla->foreign('profesional_id')->references('id')->on('profesionales')->restrictOnDelete();
            $tabla->foreign('clienta_id')->references('id')->on('clientas')->restrictOnDelete();
            $tabla->foreign('cita_id')->references('id')->on('citas')->nullOnDelete();

            $tabla->index(['tenant_id', 'profesional_id', 'estado']);
            $tabla->index(['tenant_id', 'clienta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};