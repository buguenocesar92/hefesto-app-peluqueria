<?php

declare(strict_types=1);

namespace App\Services\Caja;

use App\Enums\EstadoVenta;
use App\Models\CorteCaja;
use App\Models\Profesional;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use DomainException;

/**
 * Corte de caja: el cierre del día, por profesional.
 *
 * LO QUE CIERRA EL DÍA
 * --------------------
 * Cada profesional tiene su caja: es quien cobró y es a quien se le entrega al
 * final del día. Por eso el corte es por profesional, no un cierre general del
 * salón.
 *
 * POR QUÉ LOS TOTALES SE GUARDAN Y NO SE CALCULAN AL LEER
 * ------------------------------------------------------
 * Porque un corte es un ACTO, no una vista. El 14 de octubre a las 19:30 hubo
 * una caja con X pesos. Si la cifra se recalculara cada vez que alguien abre la
 * pantalla, una venta anulada un mes después cambiaría el corte de un día que
 * ya se cerró, y ese día ya no cerraría. Se guarda el valor con el que se cerró,
 * junto con cuántas ventas se contaron.
 *
 * LA DIFERENCIA NO SE DERIVA
 * --------------------------
 * `diferencia` se escribe al cerrar. No es una columna calculada ni una vista:
 * es la resta entre lo declarado y lo esperado, hecha una vez, con el valor
 * que se discutió. Volver a derivarla escondería una discusión ya cerrada.
 *
 * UN CORTE CERRADO NO SE TOCA
 * ---------------------------
 * Si hay una diferencia, se deja anotada con observación y, si hace falta, se
 * deja el corte abierto. La base tiene un único corte por profesional y día, así
 * que un segundo cierre del mismo día es imposible.
 */
class ServicioCortesCaja
{
    /**
     * Abre el corte del día de una profesional.
     *
     * La clave única de la base hace que abrir dos veces el mismo día falle; acá
     * se devuelve el que ya existe, que es más útil que un error para la
     * recepción.
     */
    public function abrir(Profesional $profesional, Carbon $fecha): CorteCaja
    {
        $existente = CorteCaja::query()
            ->where('profesional_id', $profesional->id)
            ->whereDate('fecha', $fecha->toDateString())
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return CorteCaja::query()->create([
            'tenant_id' => $profesional->tenant_id,
            'profesional_id' => $profesional->id,
            'fecha' => $fecha->toDateString(),
            'estado' => 'abierto',
        ]);
    }

    /**
     * Calcula lo esperado para ese día y lo deja anotado en el corte abierto.
     *
     * @return int el total esperado, en pesos
     */
    public function calcularEsperado(CorteCaja $corte): int
    {
        $ventas = $this->ventasCobradasDelDia($corte);

        $esperado = (int) $ventas->sum('total');

        $corte->total_esperado = $esperado;
        $corte->ventas_incluidas = $ventas->count();
        $corte->save();

        return $esperado;
    }

    /**
     * Cierra el corte con lo que la profesional declara.
     *
     * @throws DomainException si el corte ya está cerrado.
     */
    public function cerrar(
        CorteCaja $corte,
        int $totalDeclarado,
        int $totalEfectivo,
        int $totalTarjeta,
        Usuario $quienCierra,
        ?string $observaciones = null,
    ): CorteCaja {
        if ($corte->cerrado()) {
            throw new DomainException(
                'El corte del ' . $corte->fecha->format('d/m/Y') . ' ya estaba cerrado. '
                . 'Un corte no se vuelve a cerrar: queda como se cerró.'
            );
        }

        if ($totalDeclarado < 0 || $totalEfectivo < 0 || $totalTarjeta < 0) {
            throw new DomainException('Los montos no pueden ser negativos.');
        }

        return DB::transaction(function () use ($corte, $totalDeclarado, $totalEfectivo, $totalTarjeta, $quienCierra, $observaciones): CorteCaja {
            $this->calcularEsperado($corte);

            $corte->total_declarado = $totalDeclarado;
            $corte->total_efectivo = $totalEfectivo;
            $corte->total_tarjeta = $totalTarjeta;
            // La diferencia se ESCRIBE, no se deriva en cada lectura.
            $corte->diferencia = $totalDeclarado - $corte->total_esperado;
            $corte->estado = 'cerrado';
            $corte->cerrado_por = $quienCierra->id;
            $corte->cerrado_en = now();
            $corte->observaciones = $observaciones;
            $corte->save();

            return $corte;
        });
    }

    /**
     * Las ventas cobradas de esa profesional en ese día.
     *
     * @return Collection<int, Venta>
     */
    public function ventasCobradasDelDia(CorteCaja $corte): Collection
    {
        return Venta::query()
            ->where('profesional_id', $corte->profesional_id)
            ->where('estado', EstadoVenta::Cobrada->value)
            ->whereDate('created_at', $corte->fecha->toDateString())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Cortes del día para todas las profesionales, que es lo que ve el dueño.
     *
     * @return Collection<int, CorteCaja>
     */
    public function cortesDelDia(string $tenantId, Carbon $fecha): Collection
    {
        return CorteCaja::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('fecha', $fecha->toDateString())
            ->with('profesional')
            ->orderBy('profesional_id')
            ->get();
    }

    /**
     * ¿El corte tiene diferencia?
     *
     * Se muestra aparte porque una diferencia no es un error: es el número que
     * el dueño necesita mirar antes de firmar.
     */
    public function tieneDiferencia(CorteCaja $corte): bool
    {
        return (int) $corte->diferencia !== 0;
    }
}