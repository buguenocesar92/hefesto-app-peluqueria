<?php

declare(strict_types=1);

namespace Tests\Unit\Comisiones;

use App\Enums\BaseCalculo;
use App\Enums\TipoLineaVenta;
use App\Models\PoliticaComision;
use App\Models\VentaItem;
use App\Services\Comisiones\CalculadoraDeComision;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * El cálculo de la comisión, sin base de datos.
 *
 * POR QUÉ SON PRUEBAS UNITARIAS Y NO DE CARACTERÍSTICA
 * ----------------------------------------------------
 * Porque esta clase es aritmética pura: recibe líneas y una política, y devuelve
 * un desglose. Si tuviera que consultar la base, no se podría probar el
 * redondeo ni el porcentaje sin montar todo un salón alrededor.
 *
 * Lo que se comprueba acá:
 *   - Que cada línea del desglose se pueda rehacer con una calculadora.
 *   - Que la base declarada por el salón se respete.
 *   - Que el redondeo al peso sea el que se declaró, no el que salga por
 *     comodidad.
 *
 * LAS CIFRAS SON DE PRUEBA, NO DE UN SALÓN REAL
 * --------------------------------------------
 * Los precios y porcentajes que aparecen acá son números inventados para poder
 * comprobar el cálculo. No son precios ni porcentajes de referencia: un salón
 * declara los suyos en `politicas_comision`. Ver docs/06.
 */
class CalculadoraDeComisionTest extends TestCase
{
    private CalculadoraDeComision $calculadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = new CalculadoraDeComision();
    }

    #[Test]
    public function el_desglose_tiene_una_linea_por_item_de_venta(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $this->assertCount(2, $desglose->lineas);
        $this->assertSame(2, $desglose->cantidadDeLineas());
    }

    #[Test]
    public function cada_linea_muestra_su_porcentaje_y_no_el_del_resto(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $servicio = $desglose->lineas[0];
        $producto = $desglose->lineas[1];

        $this->assertSame('40.00', $servicio->porcentajeAplicado);
        $this->assertSame('10.00', $producto->porcentajeAplicado);

        // Y la comisión de cada una sale de SU base, no de una base unida.
        $this->assertSame(4000, $servicio->comisionCalculada);
        $this->assertSame(500, $producto->comisionCalculada);
    }

    #[Test]
    public function el_total_es_la_suma_de_las_lineas(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $sumaDeLineas = array_sum(array_map(
            static fn ($linea): int => $linea->comisionCalculada,
            $desglose->lineas,
        ));

        $this->assertSame($sumaDeLineas, $desglose->totalComision);
        $this->assertSame(4500, $desglose->totalComision);
    }

    #[Test]
    public function las_bases_quedan_separadas_por_tipo(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 20000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $this->assertSame(30000, $desglose->totalServicios);
        $this->assertSame(5000, $desglose->totalProductos);
        $this->assertSame(35000, $desglose->baseTotal());
    }

    #[Test]
    public function la_base_solo_servicios_deja_el_producto_fuera(): void
    {
        $politica = $this->politica(BaseCalculo::SoloServicio, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $this->assertCount(1, $desglose->lineas);
        $this->assertSame(10000, $desglose->totalServicios);
        $this->assertSame(0, $desglose->totalProductos);
        $this->assertSame(4000, $desglose->totalComision);
    }

    #[Test]
    public function la_base_solo_producto_deja_el_servicio_fuera(): void
    {
        $politica = $this->politica(BaseCalculo::SoloProducto, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000],
                ['tipo' => TipoLineaVenta::Producto, 'subtotal' => 5000],
            ]),
            $politica,
        );

        $this->assertCount(1, $desglose->lineas);
        $this->assertSame(0, $desglose->totalServicios);
        $this->assertSame(500, $desglose->totalComision);
    }

    #[Test]
    public function un_porcentaje_cero_no_paga_nada_pero_la_linea_se_ve(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '0.00', '0.00');

        $desglose = $this->calculadora->calcular(
            $this->items([['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000]]),
            $politica,
        );

        $this->assertCount(1, $desglose->lineas);
        $this->assertSame(0, $desglose->totalComision);
        $this->assertSame('0.00', $desglose->lineas[0]->porcentajeAplicado);
    }

    #[Test]
    public function una_base_cero_no_paga_nada(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular(
            $this->items([['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 0]]),
            $politica,
        );

        $this->assertSame(0, $desglose->totalComision);
    }

    #[Test]
    public function una_linea_sin_ventas_produce_un_desglose_vacio_no_un_error(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40.00', '10.00');

        $desglose = $this->calculadora->calcular($this->items([]), $politica);

        $this->assertSame([], $desglose->lineas);
        $this->assertSame(0, $desglose->totalComision);
        $this->assertSame(0, $desglose->baseTotal());
    }

    /* ------------------------------------------------------------------
     | Redondeo: la parte que hace auditable la cuenta
     | ------------------------------------------------------------------ */

    #[Test]
    public function el_redondeo_es_al_peso_y_medio_hacia_arriba(): void
    {
        // 10% de 105 son 10,5 pesos. Medio hacia arriba son 11, que es lo que
        // hace la gente al calcular de cabeza.
        $this->assertSame(11, $this->calculadora->aplicarPorcentaje(105, '10.00'));

        // 10% de 104 son 10,4 pesos. Redondea a 10.
        $this->assertSame(10, $this->calculadora->aplicarPorcentaje(104, '10.00'));
    }

    #[Test]
    public function el_redondeo_es_por_linea_y_no_al_final(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '10.00', '10.00');

        // Tres líneas de 105 pesos. Cada una paga 11.
        $desglose = $this->calculadora->calcular(
            $this->items([
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 105],
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 105],
                ['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 105],
            ]),
            $politica,
        );

        // 33 pesos por línea: 3 x 11. Si se redondeara el total (31,5) serían 32,
        // y ninguna línea se podría comprobar por separado.
        $this->assertSame(33, $desglose->totalComision);
    }

    #[Test]
    public function un_porcentaje_con_decimales_se_aplica_como_es(): void
    {
        // 33,5% de 1000 son 335 pesos exactos.
        $this->assertSame(335, $this->calculadora->aplicarPorcentaje(1000, '33.50'));
    }

    #[Test]
    public function el_porcentaje_se_normaliza_a_dos_decimales(): void
    {
        $politica = $this->politica(BaseCalculo::Ambos, '40', '10');

        $desglose = $this->calculadora->calcular(
            $this->items([['tipo' => TipoLineaVenta::Servicio, 'subtotal' => 10000]]),
            $politica,
        );

        $this->assertSame('40.00', $desglose->porcentajeServicios);
        $this->assertSame('10.00', $desglose->porcentajeProductos);
    }

    /**
     * Política de prueba.
     *
     * No usa la base de datos: se instancia en memoria con los mismos atributos
     * que la migración declara, porque esta prueba es del cálculo, no del
     * guardado. Ver PoliticaComisionTest para la parte que sí toca la base.
     */
    private function politica(BaseCalculo $base, string $servicios, string $productos): PoliticaComision
    {
        $politica = new PoliticaComision();
        $politica->base_calculo = $base;
        $politica->porcentaje_servicios = $servicios;
        $politica->porcentaje_productos = $productos;
        $politica->incluye_productos = $base !== BaseCalculo::SoloServicio;

        return $politica;
    }

    /**
     * Líneas de venta de prueba.
     *
     * @param  list<array{tipo: TipoLineaVenta, subtotal: int}>  $lineas
     * @return list<VentaItem>
     */
    private function items(array $lineas): array
    {
        $items = [];
        $i = 0;

        foreach ($lineas as $linea) {
            $i++;

            $item = new VentaItem();
            $item->id = 'item-' . $i;
            $item->venta_id = 'venta-' . $i;
            $item->tipo = $linea['tipo'];
            $item->descripcion = 'Línea de prueba ' . $i;
            $item->cantidad = 1;
            $item->precio_unitario = $linea['subtotal'];
            $item->subtotal = $linea['subtotal'];

            $items[] = $item;
        }

        return $items;
    }
}