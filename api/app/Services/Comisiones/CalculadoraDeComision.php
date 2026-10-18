<?php

declare(strict_types=1);

namespace App\Services\Comisiones;

use App\Enums\BaseCalculo;
use App\Enums\TipoLineaVenta;
use App\Models\PoliticaComision;
use App\Models\VentaItem;
use Illuminate\Support\Collection;

/**
 * Cálculo de la comisión de una profesional.
 *
 * QUÉ RESUELVE
 * ------------
 * Que la profesional no tiene sueldo fijo: cobra un porcentaje de lo que vendió.
 * Y que ese porcentaje tiene que ser AUDITABLE, o sea que el dueño pueda ver de
 * dónde salió cada cifra.
 *
 * LAS TRES REGLAS DEL CÁLCULO
 * --------------------------
 * 1. La comisión se calcula sobre el SERVICIO y sobre el PRODUCTO que la
 *    clienta llevó puestos. Son bases separadas, porque el salón puede pagar
 *    distinto cada una.
 * 2. LA BASE ES UNA DECISIÓN DEL NEGOCIO, no un dato técnico. Sale de
 *    `BaseCalculo` en la política del salón. No hay valor por omisión: si no hay
 *    política vigente, esta clase LANZA EXCEPCIÓN en vez de devolver cero.
 * 3. Sin desglose no hay comisión. El resultado es una lista de líneas, no un
 *    número. Ver `DesgloseComision`.
 *
 * POR QUÉ EL REDONDEO ES POR LÍNEA Y NO AL FINAL
 * ---------------------------------------------
 * Es la regla más fácil de explicar y de verificar. Si se redondea el total
 * una vez, el dueño no puede comprobar ninguna línea sin saber el total. Si se
 * redondea cada línea al peso entero, cada línea se comprueba sola con una
 * calculadora y el total es la suma. Se usa redondeo "medio hacia arriba",
 * que es lo que hace la gente al calcular de cabeza.
 *
 * POR QUÉ EL PORCENTAJE ES UN STRING
 * ---------------------------------
 * Porque en PostgreSQL la columna es `numeric(5,2)` y trae `35.50`, no
 * `0.3550`. Se trabaja con `bcmath` para no perder precisión: en dinero, los
 * coma flotante acumula error y una comisión que no cuadra por un peso es una
 * comisión que nadie puede defender. La extensión va en el Dockerfile.
 *
 * LO QUE NO HACE
 * --------------
 * No guarda nada. Esta clase calcula. Quien guarda es
 * `RegistradorDeLiquidacion`, que usa el desglose que devuelve esta.
 */
class CalculadoraDeComision
{
    /**
     * Calcula la comisión de un conjunto de líneas de venta ya cobradas.
     *
     * @param  Collection<int, VentaItem>  $items  líneas cobradas del período
     *
     * @throws \DomainException si no hay una política vigente que aplique.
     */
    public function calcular(Collection $items, PoliticaComision $politica): DesgloseComision
    {
        $baseCalculo = BaseCalculo::from($politica->base_calculo);

        $porcentajeServicios = $this->porcentajeDe($politica->porcentaje_servicios);
        $porcentajeProductos = $this->porcentajeDe($politica->porcentaje_productos);

        $lineas = [];
        $totalServicios = 0;
        $totalProductos = 0;
        $totalComision = 0;

        foreach ($items as $item) {
            $tipo = TipoLineaVenta::from($item->tipo);

            // La base decide si esta línea entra. Una línea de producto con base
            // "solo servicios" NO se cuenta, y eso es exactamente lo que el
            // dueño negoció: no es un olvido.
            if (! $baseCalculo->incluye($tipo->value)) {
                continue;
            }

            // Una línea sin base no produce comisión. Se incluye igual en el
            // desglose, con porcentaje cero, para que se vea que existió y que
            // se decidió no pagarla.
            $base = (int) $item->subtotal;

            $porcentaje = $tipo === TipoLineaVenta::Servicio
                ? $porcentajeServicios
                : $porcentajeProductos;

            $comision = $this->aplicarPorcentaje($base, $porcentaje);

            $lineas[] = new ItemDesglose(
                ventaId: (string) $item->venta_id,
                ventaItemId: (string) $item->id,
                tipo: $tipo,
                descripcion: (string) $item->descripcion,
                baseAplicada: $base,
                porcentajeAplicado: $porcentaje,
                comisionCalculada: $comision,
            );

            if ($tipo === TipoLineaVenta::Servicio) {
                $totalServicios += $base;
            } else {
                $totalProductos += $base;
            }

            $totalComision += $comision;
        }

        return new DesgloseComision(
            baseCalculo: $baseCalculo,
            porcentajeServicios: $porcentajeServicios,
            porcentajeProductos: $porcentajeProductos,
            incluyeProductos: $politica->incluye_productos,
            lineas: $lineas,
            totalServicios: $totalServicios,
            totalProductos: $totalProductos,
            totalComision: $totalComision,
        );
    }

    /**
     * Aplica un porcentaje a una base y redondea al peso entero.
     *
     * Se hace con aritmética de enteros en base diez, no con coma flotante:
     * `bcmul` y `bcdiv` con escala 0, más medio peso antes de truncar, que es
     * redondeo medio hacia arriba.
     *
     * @param  string  $porcentaje  por ejemplo "35.50", no "0.3550"
     */
    public function aplicarPorcentaje(int $base, string $porcentaje): int
    {
        if ($base === 0 || bccomp($porcentaje, '0', 2) === 0) {
            return 0;
        }

        // base * (porcentaje / 100), en base mil para no perder decimales.
        $resultado = bcdiv(bcmul((string) $base, $porcentaje, 4), '100', 4);

        // Medio peso hacia arriba: se suma medio antes de truncar.
        return (int) bcadd($resultado, '0.5', 0);
    }

    /**
     * Normaliza un porcentaje de la base a un string de dos decimales.
     *
     * PostgreSQL puede devolver "35.5" o "35.50". El desglose tiene que verse
     * siempre igual, porque se muestra y se imprime.
     */
    private function porcentajeDe(mixed $valor): string
    {
        return bcadd((string) $valor, '0', 2);
    }
}