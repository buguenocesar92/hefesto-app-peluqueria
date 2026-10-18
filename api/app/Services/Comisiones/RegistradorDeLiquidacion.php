<?php

declare(strict_types=1);

namespace App\Services\Comisiones;

use App\Enums\EstadoLiquidacion;
use App\Enums\EstadoVenta;
use App\Models\LiquidacionComision;
use App\Models\LiquidacionComisionItem;
use App\Models\PoliticaComision;
use App\Models\Profesional;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use DomainException;

/**
 * Liquidación: guarda el resultado del cálculo, línea por línea.
 *
 * QUÉ HACE Y POR QUÉ NO ESTÁ EN LA CALCULADORA
 * ---------------------------------------------
 * La calculadora calcula números y devuelve un desglose. Esta clase lo
 * PERSISTE. La separación es deliberada: calcular es una operación pura y fácil
 * de probar; guardar es la que deja rastro.
 *
 * LA AUDITABILIDAD ESTÁ EN ESTA CLASE
 * ----------------------------------
 * Se guarda, por cada ítem de venta que entró:
 *   - De qué venta y de qué ítem vino.
 *   - Sobre qué base se calculó.
 *   - Con qué porcentaje se calculó.
 *   - Cuánto salió.
 *
 * Con eso, el dueño puede reconstruir la cifra con una calculadora y ver que
 * coincide. Sin eso, una comisión es un número que hay que creer.
 *
 * UNA LIQUIDACIÓN CERRADA NO SE TOCA
 * ---------------------------------
 * Si hay que corregir algo, se anula con motivo y se rehace. Editar en silencio
 * una cifra ya mostrada es peor que tener un error visible.
 */
class RegistradorDeLiquidacion
{
    public function __construct(private readonly CalculadoraDeComision $calculadora) {}

    /**
     * Calcula y guarda la liquidación de una profesional en un período.
     *
     * @throws DomainException si no hay política vigente o si ya existe una
     *                          liquidación cerrada para ese período.
     */
    public function liquidar(
        Profesional $profesional,
        Carbon $desde,
        Carbon $hasta,
    ): LiquidacionComision {
        if ($desde->gt($hasta)) {
            throw new DomainException('El período va al revés: la fecha inicial es posterior a la final.');
        }

        $politica = PoliticaComision::vigenteEn(
            (string) $profesional->tenant_id,
            (string) $profesional->id,
            $desde,
        );

        $items = $this->itemsCobradosDelPeriodo($profesional, $desde, $hasta);

        $desglose = $this->calculadora->calcular($items, $politica);

        return DB::transaction(function () use ($profesional, $politica, $desde, $hasta, $desglose): LiquidacionComision {
            $liquidacion = LiquidacionComision::query()->create([
                'tenant_id' => $profesional->tenant_id,
                'profesional_id' => $profesional->id,
                'politica_comision_id' => $politica->id,
                'periodo_desde' => $desde->toDateString(),
                'periodo_hasta' => $hasta->toDateString(),
                // Se COPIAN de la política: si la política cambia mañana, esta
                // liquidación sigue quedando explicable.
                'porcentaje_servicios' => $desglose->porcentajeServicios,
                'porcentaje_productos' => $desglose->porcentajeProductos,
                'incluye_productos' => $desglose->incluyeProductos,
                'base_servicios' => $desglose->totalServicios,
                'base_productos' => $desglose->totalProductos,
                'total_comision' => $desglose->totalComision,
                'estado' => EstadoLiquidacion::Borrador->value,
            ]);

            // El desglose se guarda ahora, mientras está en borrador. Después
            // queda congelado.
            foreach ($desglose->lineas as $linea) {
                LiquidacionComisionItem::query()->create([
                    'tenant_id' => $profesional->tenant_id,
                    'liquidacion_comision_id' => $liquidacion->id,
                    'venta_id' => $linea->ventaId,
                    'venta_item_id' => $linea->ventaItemId,
                    'tipo' => $linea->tipo->value,
                    'descripcion' => $linea->descripcion,
                    'base_aplicada' => $linea->baseAplicada,
                    'porcentaje_aplicado' => $linea->porcentajeAplicado,
                    'comision_calculada' => $linea->comisionCalculada,
                ]);
            }

            $this->desglosarPorTipo($liquidacion, $desglose);

            return $liquidacion->refresh();
        });
    }

    /**
     * Cierra una liquidación: el desglose queda congelado.
     *
     * @throws DomainException si la liquidación ya está cerrada o anulada.
     */
    public function cerrar(LiquidacionComision $liquidacion): LiquidacionComision
    {
        if ($liquidacion->estado !== EstadoLiquidacion::Borrador) {
            throw new DomainException(
                'La liquidación está en estado ' . $liquidacion->estado->etiqueta()
                . ' y no se puede volver a cerrar.'
            );
        }

        $liquidacion->estado = EstadoLiquidacion::Cerrada;
        $liquidacion->save();

        return $liquidacion;
    }

    /**
     * Anula una liquidación, con motivo obligatorio.
     *
     * @throws DomainException si no hay motivo escrito.
     */
    public function anular(LiquidacionComision $liquidacion, string $motivo): LiquidacionComision
    {
        if (trim($motivo) === '') {
            throw new DomainException('Para anular una liquidación hay que escribir el motivo.');
        }

        $liquidacion->estado = EstadoLiquidacion::Anulada;
        $liquidacion->motivo_anulacion = $motivo;
        $liquidacion->save();

        return $liquidacion;
    }

    /**
     * Las líneas de venta COBRADAS de esa profesional en ese período.
     *
     * Solo `cobrada` cuenta: un borrador no produjo dinero y una venta anulada
     * dejó de producirlo. Ver `EstadoVenta::cuentaParaComision()`.
     *
     * @return Collection<int, VentaItem>
     */
    public function itemsCobradosDelPeriodo(Profesional $profesional, Carbon $desde, Carbon $hasta): Collection
    {
        return VentaItem::query()
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->where('venta_items.tenant_id', $profesional->tenant_id)
            ->where('ventas.profesional_id', $profesional->id)
            ->where('ventas.estado', EstadoVenta::Cobrada->value)
            ->whereBetween('ventas.created_at', [
                $desde->copy()->startOfDay(),
                $hasta->copy()->endOfDay(),
            ])
            ->orderBy('ventas.created_at')
            ->orderBy('venta_items.id')
            ->select('venta_items.*')
            ->get();
    }

    /**
     * Calcula el desglose SIN guardar.
     *
     * Es lo que muestra la pantalla de comisiones antes de que el dueño decida
     * cerrar el período: la cifra y su desglose, sin dejar rastro todavía.
     *
     * @return DesgloseComision
     *
     * @throws DomainException si no hay política vigente.
     */
    public function previsualizar(Profesional $profesional, Carbon $desde, Carbon $hasta): DesgloseComision
    {
        $politica = PoliticaComision::vigenteEn(
            (string) $profesional->tenant_id,
            (string) $profesional->id,
            $desde,
        );

        return $this->calculadora->calcular(
            $this->itemsCobradosDelPeriodo($profesional, $desde, $hasta),
            $politica,
        );
    }

    /**
     * Separa la comisión de servicios y la de productos.
     *
     * El dueño pregunta por separado, y por eso el total se arma sumando las
     * dos partes y no un porcentaje sobre una base unida.
     */
    private function desglosarPorTipo(LiquidacionComision $liquidacion, DesgloseComision $desglose): void
    {
        $comisionServicios = 0;
        $comisionProductos = 0;

        foreach ($desglose->lineas as $linea) {
            if ($linea->tipo->value === 'servicio') {
                $comisionServicios += $linea->comisionCalculada;
            } else {
                $comisionProductos += $linea->comisionCalculada;
            }
        }

        $liquidacion->comision_servicios = $comisionServicios;
        $liquidacion->comision_productos = $comisionProductos;
        $liquidacion->total_comision = $comisionServicios + $comisionProductos;
        $liquidacion->save();
    }

    /** @return Collection<int, Venta> */
    public function ventasDelPeriodo(Profesional $profesional, Carbon $desde, Carbon $hasta): Collection
    {
        return Venta::query()
            ->where('profesional_id', $profesional->id)
            ->where('estado', EstadoVenta::Cobrada->value)
            ->whereBetween('created_at', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->get();
    }
}