<?php

declare(strict_types=1);

use App\Http\Controllers\Agenda\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Caja\CorteCajaController;
use App\Http\Controllers\Catalogo\CatalogoController;
use App\Http\Controllers\Clientes\AntecedenteController;
use App\Http\Controllers\Clientes\ClientaController;
use App\Http\Controllers\Comisiones\ComisionController;
use App\Http\Controllers\Panel\PanelController;
use App\Http\Controllers\Reportes\ReporteController;
use App\Http\Controllers\Ventas\VentaController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API de Peluqueria
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php) y del prefijo
| `/api/v1`, que se aplica acá y no en el esqueleto: queda a la vista que TODA la
| superficie de la API está versionada en una sola línea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvía las
| cookies del navegador. Ver docs/01 y docs/09.
|
| EL CONTROL FINO NO ESTÁ ACÁ
| --------------------------
| Las rutas declaran QUÉ recurso se toca y CON QUÉ MIDDLEWARE. Quién puede
| tocarlo lo decide `App\Policies`. El orden importa y es el siguiente:
|
|   1. `auth`                confirma la sesión
|   2. `salud.auditado`      anota el intento, permitido o denegado
|   3. `admin`               corta las rutas de administración
|   4. Policy                permiso fino, con 404 si rechaza
|
| Un rechazo de Policy responde 404 y no 403: un 403 confirmaría que el recurso
| existe. Ver bootstrap/app.php y docs/03.
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |---------------------------------------------------------------------------
    | Autenticación (OIDC contra Authentik)
    |---------------------------------------------------------------------------
    | Las dos primeras rutas corren SIN sesión y SIN contexto de salón. Por eso
    | leen `vw_salones_publicas`, que expone solo slug, nombre y activo.
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('salones', [AuthController::class, 'salones'])->name('salones');
        Route::get('entrar/{slug}', [AuthController::class, 'entrar'])->name('entrar');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('salir', [AuthController::class, 'salir'])->name('salir');

        Route::middleware('auth')->group(function (): void {
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    Route::middleware('auth')->group(function (): void {

        /*
        |-----------------------------------------------------------------------
        | Panel: lo primero que se ve
        |-----------------------------------------------------------------------
        */
        Route::get('panel', PanelController::class)->name('panel');

        /*
        |-----------------------------------------------------------------------
        | Agenda
        |-----------------------------------------------------------------------
        | La agenda se llena por TIEMPO DE SERVICIO: el `fecha_fin` lo calcula el
        | servicio para esa profesional, no el cliente. Y los bloqueos no se
        | pisan: la comprobación entre citas y bloqueos está en
        | App\Services\Agenda\VerificadorDeDisponibilidad, porque PostgreSQL no
        | puede escribir una exclusión entre dos tablas.
        */
        Route::get('agenda', [AgendaController::class, 'show'])->name('agenda');
        Route::get('agenda/disponibilidad', [AgendaController::class, 'disponibilidad'])->name('agenda.disponibilidad');

        // Las profesionales que se pueden elegir al agendar. No es el listado de
        // administración: es lo mínimo que necesita la pantalla de agenda.
        Route::get('agenda/profesionales', [AgendaController::class, 'profesionales'])
            ->name('agenda.profesionales');

        Route::post('citas', [AgendaController::class, 'store'])->name('citas.store');
        Route::get('citas/{cita}', [AgendaController::class, 'cita'])->name('citas.show');
        Route::patch('citas/{cita}', [AgendaController::class, 'update'])->name('citas.update');
        Route::delete('citas/{cita}', [AgendaController::class, 'destroy'])->name('citas.destroy');

        // Registrar cómo terminó de verdad, con el retraso. No es editar la cita:
        // es escribir lo que pasó. Por eso es otra ruta y otro método.
        Route::post('citas/{cita}/realidad', [AgendaController::class, 'registrarRealidad'])
            ->name('citas.realidad');

        /*
        |-----------------------------------------------------------------------
        | Clientas: ficha de IDENTIFICACIÓN
        |-----------------------------------------------------------------------
        | Nombre, RUT, teléfono. Los tres roles entran. El historial de salud NO
        | está acá: ver el grupo auditado más abajo. Ver docs/03.
        */
        Route::get('clientas', [ClientaController::class, 'index'])->name('clientas.index');
        Route::post('clientas', [ClientaController::class, 'store'])->name('clientas.store');
        Route::get('clientas/{clienta}', [ClientaController::class, 'show'])->name('clientas.show');
        Route::put('clientas/{clienta}', [ClientaController::class, 'update'])->name('clientas.update');
        Route::delete('clientas/{clienta}', [ClientaController::class, 'destroy'])->name('clientas.destroy');

        /*
        |-----------------------------------------------------------------------
        | ANTECEDENTES DE SALUD: TODO ESTO VA AUDITADO
        |-----------------------------------------------------------------------
        | Cada intento de abrirlos queda en `accesos_salud`, permitido o denegado.
        | El middleware corta con 404 antes de que la acción llegue al
        | controlador, y por eso el intento denegado también queda anotado.
        |
        | Ver App\Http\Middleware\AuditarSalud y docs/07.
        */
        Route::middleware('salud.auditado:ver_antecedentes')->group(function (): void {
            Route::get('clientas/{clienta}/antecedentes', [AntecedenteController::class, 'index'])
                ->name('clientas.antecedentes.index');
        });

        Route::middleware('salud.auditado:registrar_antecedente')->group(function (): void {
            Route::post('clientas/{clienta}/antecedentes', [AntecedenteController::class, 'store'])
                ->name('clientas.antecedentes.store');
            Route::put('antecedentes/{antecedente}', [AntecedenteController::class, 'update'])
                ->name('antecedentes.update');
            Route::delete('antecedentes/{antecedente}', [AntecedenteController::class, 'destroy'])
                ->name('antecedentes.destroy');
        });

        /*
        |-----------------------------------------------------------------------
        | Ventas: una visita, sus servicios y sus productos
        |-----------------------------------------------------------------------
        */
        Route::get('ventas', [VentaController::class, 'index'])->name('ventas.index');
        Route::post('ventas', [VentaController::class, 'store'])->name('ventas.store');
        Route::get('ventas/{venta}', [VentaController::class, 'show'])->name('ventas.show');
        Route::delete('ventas/{venta}', [VentaController::class, 'destroy'])->name('ventas.destroy');

        /*
        |-----------------------------------------------------------------------
        | Cortes de caja: el cierre del día, por profesional
        |-----------------------------------------------------------------------
        */
        Route::get('cortes', [CorteCajaController::class, 'index'])->name('cortes.index');
        Route::get('cortes/{profesional}', [CorteCajaController::class, 'show'])->name('cortes.show');
        Route::post('cortes/{profesional}/cerrar', [CorteCajaController::class, 'cerrar'])->name('cortes.cerrar');

        /*
        |-----------------------------------------------------------------------
        | ADMINISTRACIÓN DEL SALÓN
        |-----------------------------------------------------------------------
        | Estas rutas cuelgan del middleware `admin`, que corta para quien no
        | administra ANTES de llegar a la Policy. Son dos capas: el middleware es
        | la puerta, la Policy es la regla. Ver bootstrap/app.php.
        */
        Route::middleware('admin')->group(function (): void {

            Route::get('administracion/profesionales', [CatalogoController::class, 'profesionales'])
                ->name('administracion.profesionales.index');
            Route::post('administracion/profesionales', [CatalogoController::class, 'guardarProfesional'])
                ->name('administracion.profesionales.store');

            Route::get('administracion/servicios', [CatalogoController::class, 'servicios'])
                ->name('administracion.servicios.index');
            Route::post('administracion/servicios', [CatalogoController::class, 'guardarServicio'])
                ->name('administracion.servicios.store');
            Route::put('administracion/servicios/{servicio}', [CatalogoController::class, 'actualizarServicio'])
                ->name('administracion.servicios.update');

            Route::get('administracion/productos', [CatalogoController::class, 'productos'])
                ->name('administracion.productos.index');
            Route::post('administracion/productos', [CatalogoController::class, 'guardarProducto'])
                ->name('administracion.productos.store');
            Route::put('administracion/productos/{producto}', [CatalogoController::class, 'actualizarProducto'])
                ->name('administracion.productos.update');

            /*
            |-------------------------------------------------------------------
            | COMISIONES: la decisión del negocio
            |-------------------------------------------------------------------
            | Los porcentajes, la base de cálculo y las liquidaciones. Ver las
            | Policies: leer la política vigente es de todos; cambiarla y liquidar
            | es de administración.
            */
            Route::get('comisiones/politicas', [ComisionController::class, 'politicas'])
                ->name('comisiones.politicas.index');
            Route::post('comisiones/politicas', [ComisionController::class, 'guardarPolitica'])
                ->name('comisiones.politicas.store');

            Route::get('comisiones', [ComisionController::class, 'index'])->name('comisiones.index');
            Route::post('comisiones/liquidar', [ComisionController::class, 'liquidar'])
                ->name('comisiones.liquidar');
            Route::post('comisiones/liquidaciones/{liquidacion}/cerrar', [ComisionController::class, 'cerrar'])
                ->name('comisiones.liquidaciones.cerrar');
            Route::post('comisiones/liquidaciones/{liquidacion}/anular', [ComisionController::class, 'anular'])
                ->name('comisiones.liquidaciones.anular');

            /*
            |-------------------------------------------------------------------
            | Reportes y bitácora
            |-------------------------------------------------------------------
            */
            Route::get('reportes/ventas', [ReporteController::class, 'ventas'])->name('reportes.ventas');
            Route::get('reportes/cortes', [ReporteController::class, 'cortes'])->name('reportes.cortes');
            Route::get('reportes/retrasos', [ReporteController::class, 'retrasos'])->name('reportes.retrasos');
            Route::get('reportes/comisiones', [ReporteController::class, 'comisiones'])->name('reportes.comisiones');
            Route::get('reportes/auditoria', [ReporteController::class, 'auditoria'])->name('reportes.auditoria');
        });

        /*
        |-----------------------------------------------------------------------
        | COMISIONES: LEER LA CUENTA PROPIA ES DE TODOS
        |-----------------------------------------------------------------------
        | La profesional puede ver su liquidación y SU desglose, y puede pedir la
        | previsualización de un período sin guardar nada. Eso es lo que hace
        | la comisión AUDITABLE: la persona que la recibe tiene que poder
        | rehacer la cuenta con una calculadora.
        |
        | Lo que cambia un porcentaje o produce la liquidación cerrada NO está
        | acá: es de administración y está arriba.
        |
        | El acceso se resuelve en `LiquidacionComisionPolicy::view`, que deja
        | pasar la liquidación propia y solo deja ver las de otras a quien
        | administra.
        */
        Route::get('comisiones/previsualizar', [ComisionController::class, 'previsualizar'])
            ->name('comisiones.previsualizar');
        Route::get('comisiones/liquidaciones/{liquidacion}', [ComisionController::class, 'ver'])
            ->name('comisiones.liquidaciones.show');
    });
});