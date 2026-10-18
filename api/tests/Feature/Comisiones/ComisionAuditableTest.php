<?php

declare(strict_types=1);

namespace Tests\Feature\Comisiones;

use App\Enums\BaseCalculo;
use App\Enums\EstadoCita;
use App\Enums\TipoLineaVenta;
use App\Models\Cita;
use App\Models\Clienta;
use App\Models\LiquidacionComision;
use App\Models\PoliticaComision;
use App\Models\Producto;
use App\Models\Profesional;
use App\Models\Salon;
use App\Models\Servicio;
use App\Services\Comisiones\RegistradorDeLiquidacion;
use App\Services\Ventas\ServicioVentas;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La comisión es AUDITABLE: el desglose queda guardado y explica la cifra.
 *
 * POR QUÉ ESTA PRUEBA Y LA UNITARIA SON DISTINTAS
 * ----------------------------------------------
 * La prueba unitaria comprueba la aritmética sin base de datos. Esta comprueba
 * que el desglose SOBREVIVE: que queda escrito, que cada línea dice de qué venta
 * vino, y que la suma de las líneas es el total que se le paga a la
 * profesional.
 *
 * ESO es lo que contesta "por qué me salió esta cifra".
 *
 * LO QUE NO SE EJECUTÓ
 * --------------------
 * Nunca se corrieron: en el equipo donde se escribió este código no hay PHP, ni
 * Composer, ni PostgreSQL. Ver docs/10.
 */
class ComisionAuditableTest extends TestCase
{
    private RegistradorDeLiquidacion $registrador;

    private ServicioVentas $ventas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrador = app(RegistradorDeLiquidacion::class);
        $this->ventas = app(ServicioVentas::class);
    }

    #[Test]
    public function sin_politica_vigente_no_se_liquida_nada(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);

        // No hay ninguna política declarada. La respuesta NO puede ser cero: un
        // cero es un número que nadie negoció, y el dueño lo cobraría como si
        // fuera real.
        $this->esperarFallo($salon, fn () => $this->registrador->liquidar(
            $profesional,
            $this->marzo(),
            $this->finDeMarzo(),
        ));
    }

    #[Test]
    public function el_desglose_queda_guardado_una_linea_por_item(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);

        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);
        $producto = $this->crearProducto($salon, 'Shampoo', 12000);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');

        $this->vender($salon, $profesional, $clienta, $servicio, $producto);

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertCount(2, $liquidacion->items);

        // Cada línea sabe de qué venta vino y con qué porcentaje se calculó.
        $porTipos = $liquidacion->items->keyBy(static fn ($l): string => $l->tipo->value);

        $servicioLinea = $porTipos[TipoLineaVenta::Servicio->value];
        $this->assertNotNull($servicioLinea->venta_id);
        $this->assertNotNull($servicioLinea->venta_item_id);
        $this->assertSame(15000, $servicioLinea->base_aplicada);
        $this->assertSame('40.00', $servicioLinea->porcentaje_aplicado);
        $this->assertSame(6000, $servicioLinea->comision_calculada);

        $productoLinea = $porTipos[TipoLineaVenta::Producto->value];
        $this->assertSame(12000, $productoLinea->base_aplicada);
        $this->assertSame('10.00', $productoLinea->porcentaje_aplicado);
        $this->assertSame(1200, $productoLinea->comision_calculada);
    }

    #[Test]
    public function el_total_de_la_liquidacion_es_la_suma_de_sus_lineas(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');
        $this->vender($salon, $profesional, $clienta, $servicio);

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertSame((int) $liquidacion->items->sum('comision_calculada'), $liquidacion->total_comision);
        $this->assertSame(6000, $liquidacion->total_comision);
    }

    #[Test]
    public function la_liquidacion_copia_el_porcentaje_para_que_siga_explicable(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');
        $this->vender($salon, $profesional, $clienta, $servicio);

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertSame('40.00', $liquidacion->porcentaje_servicios);
        $this->assertSame('10.00', $liquidacion->porcentaje_productos);

        // Si mañana el salón sube el porcentaje, esta liquidación tiene que
        // seguir diciendo con qué número se calculó.
        $this->dentroDe($salon, static function () use ($salon): void {
            PoliticaComision::query()
                ->where('tenant_id', $salon->id)
                ->update(['porcentaje_servicios' => 55.00]);
        });

        $liquidacion->refresh();

        $this->assertSame('40.00', $liquidacion->porcentaje_servicios);
        $this->assertSame(6000, $liquidacion->total_comision);
    }

    #[Test]
    public function la_base_solo_servicios_deja_el_producto_fuera_del_desglose(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);

        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);
        $producto = $this->crearProducto($salon, 'Shampoo', 12000);

        $this->crearPolitica($salon, BaseCalculo::SoloServicio, '40.00', '10.00');
        $this->vender($salon, $profesional, $clienta, $servicio, $producto);

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertCount(1, $liquidacion->items);
        $this->assertSame(TipoLineaVenta::Servicio, $liquidacion->items->first()->tipo);
        $this->assertSame(0, $liquidacion->base_productos);
    }

    #[Test]
    public function una_venta_anulada_no_entra_a_la_comision(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');

        $this->dentroDe($salon, function () use ($profesional, $clienta, $servicio): void {
            $cita = $this->citaAtendida($profesional, $clienta, $servicio);
            $venta = $this->ventas->abrir($cita);
            $this->ventas->agregarServicio($venta, $servicio);
            $this->ventas->cobrar($venta, 'efectivo');
            $this->ventas->anular($venta);
        });

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertCount(0, $liquidacion->items);
        $this->assertSame(0, $liquidacion->total_comision);
    }

    #[Test]
    public function una_venta_en_borrador_no_entra_a_la_comision(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');

        $this->dentroDe($salon, function () use ($profesional, $clienta, $servicio): void {
            $cita = $this->citaAtendida($profesional, $clienta, $servicio);
            $venta = $this->ventas->abrir($cita);
            $this->ventas->agregarServicio($venta, $servicio);
            // Se deja en borrador: no se cobra.
        });

        $liquidacion = $this->liquidar($salon, $profesional);

        $this->assertCount(0, $liquidacion->items);
    }

    #[Test]
    public function una_venta_cobrada_no_se_edita_mas_tarde(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $clienta = $this->crearClienta($salon);
        $servicio = $this->crearServicio($salon, 'Corte de pelo', 30, 15000, $profesional);

        $this->esperarFallo($salon, function () use ($profesional, $clienta, $servicio): void {
            $cita = $this->citaAtendida($profesional, $clienta, $servicio);
            $venta = $this->ventas->abrir($cita);
            $this->ventas->agregarServicio($venta, $servicio);
            $this->ventas->cobrar($venta, 'efectivo');

            // Ya está cobrada: agregar otra línea no se puede.
            $this->ventas->agregarServicio($venta, $servicio);
        });
    }

    #[Test]
    public function la_politica_propia_manda_sobre_la_general(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '30.00', '5.00');
        $this->crearPolitica($salon, BaseCalculo::Ambos, '50.00', '20.00', $profesional);

        $vigente = $this->dentroDe($salon, fn (): PoliticaComision => PoliticaComision::vigenteEn(
            (string) $salon->id,
            (string) $profesional->id,
            Carbon::parse('2026-03-10'),
        ));

        $this->assertSame('50.00', $vigente->porcentaje_servicios);
        $this->assertSame('20.00', $vigente->porcentaje_productos);
    }

    #[Test]
    public function una_politica_que_ya_no_vige_no_se_usa(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);

        $this->crearPolitica($salon, BaseCalculo::Ambos, '50.00', '20.00', $profesional, '2026-01-01', '2026-01-31');

        $this->esperarFallo($salon, fn (): PoliticaComision => PoliticaComision::vigenteEn(
            (string) $salon->id,
            (string) $profesional->id,
            Carbon::parse('2026-03-10'),
        ));
    }

    #[Test]
    public function una_liquidacion_cerrada_no_se_vuelve_a_cerrar(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $politica = $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');

        $liquidacion = $this->crearLiquidacionVacia($salon, $profesional, $politica, 'cerrada');

        $this->esperarFallo($salon, fn (): LiquidacionComision => $this->registrador->cerrar($liquidacion));
    }

    #[Test]
    public function anular_una_liquidacion_exige_motivo(): void
    {
        $salon = $this->crearSalon();
        [, $profesional] = $this->crearProfesional($salon);
        $politica = $this->crearPolitica($salon, BaseCalculo::Ambos, '40.00', '10.00');

        $liquidacion = $this->crearLiquidacionVacia($salon, $profesional, $politica, 'cerrada');

        $this->esperarFallo(
            $salon,
            fn (): LiquidacionComision => $this->registrador->anular($liquidacion, '   '),
        );
    }

    /* ------------------------------------------------------------------
     | Ayudas
     | ------------------------------------------------------------------ */

    /**
     * Una cita ya atendida, que es lo único que permite abrir una venta.
     */
    private function citaAtendida(
        Profesional $profesional,
        Clienta $clienta,
        Servicio $servicio,
    ): Cita {
        return Cita::query()->create([
            'tenant_id' => $profesional->tenant_id,
            'profesional_id' => $profesional->id,
            'clienta_id' => $clienta->id,
            'servicio_id' => $servicio->id,
            'fecha_inicio' => '2026-03-10 10:00:00',
            'fecha_fin' => '2026-03-10 10:30:00',
            'duracion_minutos' => 30,
            'estado' => EstadoCita::Atendida->value,
        ]);
    }

    /**
     * Abre y cobra una venta con un servicio y, si se pasa, un producto.
     */
    private function vender(
        Salon $salon,
        Profesional $profesional,
        Clienta $clienta,
        Servicio $servicio,
        ?Producto $producto = null,
    ): void {
        $this->dentroDe($salon, function () use ($profesional, $clienta, $servicio, $producto): void {
            $cita = $this->citaAtendida($profesional, $clienta, $servicio);
            $venta = $this->ventas->abrir($cita);

            $this->ventas->agregarServicio($venta, $servicio);

            if ($producto !== null) {
                $this->ventas->agregarProducto($venta, $producto);
            }

            $this->ventas->cobrar($venta, 'efectivo');
        });
    }

    private function liquidar(Salon $salon, Profesional $profesional): LiquidacionComision
    {
        return $this->dentroDe($salon, fn (): LiquidacionComision => $this->registrador->liquidar(
            $profesional,
            $this->marzo(),
            $this->finDeMarzo(),
        ));
    }

    private function crearLiquidacionVacia(
        Salon $salon,
        Profesional $profesional,
        PoliticaComision $politica,
        string $estado,
    ): LiquidacionComision {
        return $this->dentroDe($salon, fn (): LiquidacionComision => LiquidacionComision::query()->create([
            'tenant_id' => $salon->id,
            'profesional_id' => $profesional->id,
            'politica_comision_id' => $politica->id,
            'periodo_desde' => '2026-03-01',
            'periodo_hasta' => '2026-03-31',
            'porcentaje_servicios' => $politica->porcentaje_servicios,
            'porcentaje_productos' => $politica->porcentaje_productos,
            'incluye_productos' => $politica->incluye_productos,
            'estado' => $estado,
        ]));
    }

    private function crearPolitica(
        Salon $salon,
        BaseCalculo $base,
        string $servicios,
        string $productos,
        ?Profesional $profesional = null,
        string $desde = '2026-01-01',
        ?string $hasta = null,
    ): PoliticaComision {
        return $this->dentroDe($salon, fn (): PoliticaComision => PoliticaComision::query()->create([
            'tenant_id' => $salon->id,
            'profesional_id' => $profesional?->id,
            'porcentaje_servicios' => $servicios,
            'porcentaje_productos' => $productos,
            'incluye_productos' => $base !== BaseCalculo::SoloServicio,
            'base_calculo' => $base->value,
            'vigente_desde' => $desde,
            'vigente_hasta' => $hasta,
        ]));
    }

    private function marzo(): Carbon
    {
        return Carbon::parse('2026-03-01')->startOfDay();
    }

    private function finDeMarzo(): Carbon
    {
        return Carbon::parse('2026-03-31')->endOfDay();
    }

    /**
     * @param  \Closure(): mixed  $operacion
     */
    private function esperarFallo(Salon $salon, \Closure $operacion): void
    {
        $fallo = null;

        $this->dentroDe($salon, static function () use ($operacion, &$fallo): void {
            try {
                $operacion();
            } catch (\DomainException $e) {
                $fallo = $e;
            }
        });

        $this->assertNotNull($fallo, 'Se esperaba un DomainException y la operación pasó sin problema.');
    }
}