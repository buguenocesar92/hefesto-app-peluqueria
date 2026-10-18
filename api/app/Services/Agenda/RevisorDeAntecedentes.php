<?php

declare(strict_types=1);

namespace App\Services\Agenda;

use App\Models\AntecedenteSalud;
use App\Models\Clienta;
use App\Models\Servicio;
use Illuminate\Support\Collection;

/**
 * Revisión de antecedentes de salud antes de agendar.
 *
 * DISTINGUE AVISO DE BLOQUEO
 * -------------------------
 * Un AVISO es información que hay que mostrar: una alergia registrada que
 * afecta a este servicio. La clienta se puede sentar igual, mirando la ficha.
 *
 * Un BLOQUEO impide agendar: la profesional marcó que ese antecedente no
 * permite hacer ese servicio. Cambiar de horario no lo resuelve.
 *
 * LA REGLA DE ESPERA ENTRE PROCESOS: PENDIENTE DE VERIFICACIÓN
 * ----------------------------------------------------------
 * Es verdad que un tinte y un retoque de color no se pueden hacer el mismo día
 * sobre el mismo pelo, y que hay procesos que exigen espera entre uno y otro.
 *
 * Lo que NO se pudo verificar en el entorno donde se escribió esta aplicación
 * es:
 *
 *   - Cuántos días exige cada combinación de procesos.
 *   - Si el tiempo depende de la técnica, del fabricante o de la concentración
 *     del producto.
 *   - Si la regla es la misma para decoloración, tinte, matiz y keratinato.
 *
 * Por eso NO hay una columna de días y NO hay un cálculo. Escribir un número
 * acá sería inventar un dato químico, y un número inventado en este lugar puede
 * dañar el pelo de una clienta real.
 *
 * Lo que SÍ hace esta clase, y es verificable:
 *   1. Avisa de los antecedentes registrados que afectan a este servicio.
 *   2. Bloquea cuando la profesional marcó el antecedente como bloqueante.
 *   3. Deja constancia de que la comprobación automática de espera NO está
 *      implementada, para que quien configure el salón lo sepa antes de confiar
 *      en el sistema.
 *
 * Ver docs/02 y docs/06.
 */
class RevisorDeAntecedentes
{
    /**
     * Antecedentes que IMPIDEN agendar este servicio.
     *
     * @return Collection<int, AntecedenteSalud>
     */
    public function obstaculosDe(Clienta $clienta, Servicio $servicio): Collection
    {
        return $clienta->antecedentes()
            ->where('activo', true)
            ->where('bloquea_servicios', true)
            ->get()
            ->filter(fn (AntecedenteSalud $antecedente): bool => $antecedente->afectaA($servicio))
            ->values();
    }

    /**
     * Antecedentes que hay que MOSTRAR, sin impedir la cita.
     *
     * @return list<AntecedenteSalud>
     */
    public function avisosDe(Clienta $clienta, Servicio $servicio): array
    {
        return $clienta->antecedentes()
            ->where('activo', true)
            ->get()
            ->filter(fn (AntecedenteSalud $antecedente): bool => $antecedente->afectaA($servicio))
            ->map(fn (AntecedenteSalud $antecedente): array => [
                'id' => $antecedente->id,
                'tipo' => $antecedente->tipo->value,
                'tipo_etiqueta' => $antecedente->tipo->etiqueta(),
                'descripcion' => $antecedente->descripcion,
                'severidad' => $antecedente->severidad->value,
                'severidad_etiqueta' => $antecedente->severidad->etiqueta(),
                'bloquea_servicios' => $antecedente->bloquea_servicios,
            ])
            ->all();
    }

    /**
     * El mensaje que ve quien intenta agendar.
     *
     * @param  Collection<int, AntecedenteSalud>  $obstaculos
     */
    public function mensajeDeBloqueo(Collection $obstaculos): string
    {
        $partes = $obstaclos->map(
            static fn (AntecedenteSalud $a): string => sprintf('%s (%s)', $a->descripcion, $a->severidad->etiqueta()),
        )->all();

        return 'Esta clienta tiene un antecedente registrado que impide el servicio: '
            . implode('; ', $partes)
            . '. La regla la define la profesional que atiende, no el sistema.';
    }

    /**
     * Si el sistema puede comprobar por sí solo la espera entre procesos.
     *
     * Se devuelve como dato para que la pantalla lo muestre. Hoy es `false` y va
     * a seguir en `false` hasta que alguien verifique la regla química. Preferir
     * un hueco a un dato inventado.
     */
    public function puedeComprobarEsperaEntreProcesos(): bool
    {
        return false;
    }

    /**
     * Aviso que la pantalla de agenda muestra sobre la clienta.
     *
     * @return string|null
     */
    public function avisoDeEsperaNoImplementada(): string
    {
        return 'La espera mínima entre dos procesos químicos sobre el mismo pelo NO se comprueba automáticamente: '
            . 'ese dato no está verificado y el sistema no lo inventa. Revise la ficha a mano.';
    }
}