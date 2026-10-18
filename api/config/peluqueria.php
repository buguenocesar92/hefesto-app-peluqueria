<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reglas de Peluqueria
|------------------------------------------------------------------------------
|
| Lo que la aplicación necesita saber del salón y que no cabe en el código.
| Cada clave está justificada al lado. Ver docs/05.
|
*/

$enteroONulo = static function (?string $valor): ?int {
    if ($valor === null) {
        return null;
    }

    $valor = trim($valor);

    return $valor === '' ? null : (int) $valor;
};

return [

    /*
    |----------------------------------------------------------------------
    | Agenda
    |----------------------------------------------------------------------
    |
    | La duración de una cita NO viene del cliente: sale de la duración del
    | servicio para esa profesional (App\Services\Agenda\ServicioAgenda). Estos
    | dos límites son la red de seguridad para cuando el catálogo tiene un dato
    | raro.
    |
    */
    'agenda' => [
        'duracion_maxima_minutos' => (int) env('PELUQUERIA_DURACION_MAXIMA_MINUTOS', 600),
        'anticipacion_maxima_dias' => (int) env('PELUQUERIA_ANTICIPACION_MAXIMA_DIAS', 180),
    ],

    /*
    |----------------------------------------------------------------------
    | COMISIONES: LO QUE EL SOFTWARE NO PUEDE DECIDIR
    |----------------------------------------------------------------------
    |
    | NO HAY VALOR POR OMISIÓN DE PORCENTAJE.
    |
    | La comisión de una profesional es una decisión del negocio: el salón
    | declara cuánto paga y sobre qué base. Si esta configuración tuviera un
    | porcentaje por omisión, la primera liquidación de una instalación nueva
    | saldría con un número que nadie acordó.
    |
    | La base se DECLARA en `politicas_comision.base_calculo` (servicio,
    | producto o ambos), no se deduce. Ver docs/06.
    |
    */
    'comisiones' => [
        /*
        | Días hacia atrás que se ofrecen como período por omisión en la pantalla
        | de comisiones. Es una ayuda de navegación, no una regla de negocio: el
        | período lo elige quien liquida.
        */
        'periodo_por_omision_dias' => (int) env('PELUQUERIA_PERIODO_COMISION_DIAS', 15),

        /*
        | Si una liquidación de un período ya cerrado se puede volver a calcular.
        |
        | En `false`, que es lo correcto: dos liquidaciones del mismo período
        | acarrean dos cifras, y el dueño no sabe cuál es la buena. Para
        | corregir, se anula con motivo y se rehace.
        */
        'permitir_recalcular_cerradas' => (bool) env('PELUQUERIA_PERMITIR_RECALCULAR', false),
    ],

    /*
    |----------------------------------------------------------------------
    | CONSERVACIÓN: DECISIÓN DEL CLIENTE, NO DEL SOFTWARE
    |----------------------------------------------------------------------
    |
    | ESTA ES LA PARTE MÁS DELICADA DE ESTA CONFIGURACIÓN, LÉELA ANTES DE
    | CAMBIAR NADA.
    |
    | La Ley 19.628 obliga a que la conservación de datos personales tenga una
    | finalidad declarada, pero NO se pudo verificar en el entorno donde se
    | escribió esta aplicación el plazo que corresponde a una ficha de salón con
    | antecedentes de salud. Ver docs/07.
    |
    | Por eso el valor por omisión es `null`, y `null` significa:
    |
    |   "NO HAY PLAZO CONFIGURADO". La aplicación NO purga nada por su cuenta.
    |
    | Borrar antecedentes de salud porque pasó un número inventado sería peor que
    | no borrar nunca. Por eso no hay ninguna tarea programada que purgue.
    |
    | COMPLETAR solo cuando el salón cliente, con su revisión legal, tenga un
    | plazo escrito. El valor va en DÍAS.
    */
    'conservacion' => [
        'antecedentes_salud_dias' => $enteroONulo(env('PELUQUERIA_RETENCION_ANTECEDENTES_DIAS')),
        'bitacora_accesos_dias' => $enteroONulo(env('PELUQUERIA_RETENCION_BITACORA_DIAS')),
        'avisar_antes_de_purgar' => (bool) env('PELUQUERIA_AVISAR_ANTES_DE_PURGAR', false),
    ],

    /*
    |----------------------------------------------------------------------
    | QUÍMICA CAPILAR: PENDIENTE DE VERIFICACIÓN
    |----------------------------------------------------------------------
    |
    | Es verdad que hay procesos que se repelen entre sí: un tinte y un retoque de
    | color no se pueden hacer el mismo día sobre el mismo pelo.
    |
    | Lo que NO se pudo verificar en el entorno donde se escribió esta
    | aplicación es cuántos días exige cada combinación, ni si el tiempo depende
    | de la técnica o del fabricante. Por eso el valor por omisión es `null`.
    |
    | Con `null` la espera NO se comprueba, y la pantalla lo declara. Poner un
    | número acá sería inventar un dato químico que puede dañar el pelo de una
    | clienta real. Ver docs/02 y docs/06.
    |
    */
    'capilar' => [
        'dias_entre_procesos' => $enteroONulo(env('PELUQUERIA_DIAS_ENTRE_PROCESOS')),
    ],

];