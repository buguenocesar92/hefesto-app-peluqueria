<script setup lang="ts">
/**
 * Reportes.
 *
 * QUÉ HAY Y QUÉ NO
 * ----------------
 * Hay ventas, cortes de caja, retrasos, comisiones y auditoría. Lo que NO hay es
 * un ranking de productividad por profesional.
 *
 * Y no es una falta: con comisiones encima, ordenar por venta empuja a vender
 * lo que la clienta no necesita. Un número que cambia la conducta de quien lo
 * mira tiene que justificarse con evidencia, y acá no la hay. Ver docs/02.
 *
 * QUÉ SON ESTOS NÚMEROS
 * --------------------
 * Son sumas de lo que ya está registrado. No corrigen nada ni estiman nada: si
 * un cobro no está en la base, no aparece acá.
 */
definePageMeta({ middleware: 'auth' })

useHead({ title: 'Reportes · Peluquería' })

type Clave = 'ventas' | 'cortes' | 'retrasos' | 'comisiones' | 'auditoria'

const hoy = new Date().toISOString().slice(0, 10)

function haceDias(dias: number): string {
    const fecha = new Date()

    fecha.setDate(fecha.getDate() - dias)

    return fecha.toISOString().slice(0, 10)
}

const periodo = reactive({ desde: haceDias(30), hasta: hoy })
const elegida = ref<Clave>('ventas')
const soloDenegados = ref(false)

interface RespuestaVentas {
    desde: string
    hasta: string
    ventas: number
    total_servicios: number
    total_productos: number
    total: number
}

interface RespuestaCortes {
    desde: string
    hasta: string
    cortes: number
    con_diferencia: number
    diferencia_total: number
    datos: {
        fecha: string
        profesional: { id: string; nombre: string } | null
        total_esperado: number
        total_declarado: number
        diferencia: number
        ventas_incluidas: number
        observaciones: string | null
    }[]
}

interface RespuestaRetrasos {
    desde: string
    hasta: string
    atenciones: number
    /** Entero redondeado. La API devuelve `0` cuando no hay atenciones con retraso. */
    minutos_retraso_promedio: number
    minutos_retraso_total: number
    datos: {
        fecha_inicio: string
        fecha_fin: string
        servicio: { id: string; nombre: string } | null
        duracion_prevista: number
        minutos_retraso: number | null
    }[]
}

interface RespuestaComisiones {
    desde: string
    hasta: string
    liquidaciones: number
    base_servicios: number
    base_productos: number
    total_comisiones: number
    datos: {
        id: string
        profesional: { id: string; nombre: string } | null
        periodo_desde: string
        periodo_hasta: string
        porcentaje_servicios: string
        porcentaje_productos: string
        base_servicios: number
        base_productos: number
        total_comision: number
    }[]
}

interface RespuestaAuditoria {
    desde: string
    hasta: string
    total: number
    denegados: number
    datos: {
        creado_en: string | null
        resultado: string
        accion: string
        usuario: { id: string; nombre: string; rol: string } | null
        clienta: { id: string; nombre: string; apellido: string | null } | null
        ruta: string | null
        ip: string | null
        detalle: string | null
    }[]
}

type Respuesta =
    | RespuestaVentas
    | RespuestaCortes
    | RespuestaRetrasos
    | RespuestaComisiones
    | RespuestaAuditoria

const error = ref<string | null>(null)
const ocupada = ref(false)
const reporte = ref<Respuesta | null>(null)

async function cargar(): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        reporte.value = await $fetch<Respuesta>(`/api/reportes/${elegida.value}`, {
            query: {
                desde: periodo.desde,
                hasta: periodo.hasta,
                resultado: elegida.value === 'auditoria' && soloDenegados.value ? 'denegado' : undefined,
            },
        })
    } catch (fallo) {
        reporte.value = null
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

await cargar()

function pesos(valor: number): string {
    return valor.toLocaleString('es-CL')
}

function fechaHora(iso: string | null): string {
    return iso === null ? '—' : iso.slice(0, 16).replace('T', ' ')
}

function mensajeDeFallo(fallo: unknown): string {
    if (fallo && typeof fallo === 'object' && 'data' in fallo) {
        const cuerpo = (fallo as { data?: Record<string, unknown> }).data

        if (typeof cuerpo?.mensaje === 'string') {
            return cuerpo.mensaje
        }

        const errores = cuerpo?.errores

        if (errores && typeof errores === 'object') {
            for (const valor of Object.values(errores as Record<string, unknown>)) {
                if (Array.isArray(valor) && typeof valor[0] === 'string') {
                    return valor[0]
                }
            }
        }
    }

    return 'No se pudo cargar el reporte.'
}
</script>

<template>
    <section>
        <h1>Reportes</h1>

        <div class="fila">
            <div class="campo">
                <label for="reporte">Reporte</label>
                <select id="reporte" v-model="elegida" @change="cargar">
                    <option value="ventas">Ventas</option>
                    <option value="cortes">Cortes de caja</option>
                    <option value="retrasos">Retrasos</option>
                    <option value="comisiones">Comisiones</option>
                    <option value="auditoria">Bitácora de salud</option>
                </select>
            </div>

            <div class="campo">
                <label for="desde">Desde</label>
                <input id="desde" v-model="periodo.desde" type="date" />
            </div>

            <div class="campo">
                <label for="hasta">Hasta</label>
                <input id="hasta" v-model="periodo.hasta" type="date" />
            </div>

            <div v-if="elegida === 'auditoria'" class="campo">
                <label for="denegados">Alcance</label>
                <select id="denegados" v-model="soloDenegados" @change="cargar">
                    <option :value="false">Permitidos y denegados</option>
                    <option :value="true">Solo denegados</option>
                </select>
            </div>

            <button class="boton" type="button" :disabled="ocupada" @click="cargar">
                {{ ocupada ? 'Cargando...' : 'Consultar' }}
            </button>
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <p v-else-if="reporte" class="ayuda">
            Del {{ reporte.desde }} al {{ reporte.hasta }}.
        </p>

        <!-- Ventas -->
        <template v-if="reporte && elegida === 'ventas'">
            <div class="rejillas">
                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Total</p>
                    <p class="tarjeta__cifra">{{ pesos((reporte as RespuestaVentas).total) }}</p>
                    <p class="tarjeta__nota">{{ (reporte as RespuestaVentas).ventas }} ventas</p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Servicios</p>
                    <p class="tarjeta__cifra">
                        {{ pesos((reporte as RespuestaVentas).total_servicios) }}
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Productos</p>
                    <p class="tarjeta__cifra">
                        {{ pesos((reporte as RespuestaVentas).total_productos) }}
                    </p>
                </div>
            </div>
        </template>

        <!-- Cortes -->
        <template v-else-if="reporte && elegida === 'cortes'">
            <div class="rejillas">
                <div class="tarjeta">
                    <p class="tarjeta__titulo">Cortes</p>
                    <p class="tarjeta__cifra">{{ (reporte as RespuestaCortes).cortes }}</p>
                </div>

                <div
                    class="tarjeta"
                    :class="
                        (reporte as RespuestaCortes).con_diferencia > 0
                            ? 'tarjeta--riesgo'
                            : 'tarjeta--ok'
                    "
                >
                    <p class="tarjeta__titulo">Con diferencia</p>
                    <p class="tarjeta__cifra">{{ (reporte as RespuestaCortes).con_diferencia }}</p>
                    <p class="tarjeta__nota">
                        Suma de las diferencias: {{ pesos((reporte as RespuestaCortes).diferencia_total) }}
                    </p>
                </div>
            </div>

            <div class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Profesional</th>
                            <th class="numero">Esperado</th>
                            <th class="numero">Declarado</th>
                            <th class="numero">Diferencia</th>
                            <th class="numero">Ventas</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="corte in (reporte as RespuestaCortes).datos"
                            :key="`${corte.fecha}-${corte.profesional?.id ?? ''}`"
                        >
                            <td>{{ corte.fecha }}</td>
                            <td>{{ corte.profesional?.nombre ?? '—' }}</td>
                            <td class="numero">{{ pesos(corte.total_esperado) }}</td>
                            <td class="numero">{{ pesos(corte.total_declarado) }}</td>
                            <td
                                class="numero"
                                :style="
                                    corte.diferencia === 0 ? '' : 'color: var(--riesgo); font-weight: 700'
                                "
                            >
                                {{ pesos(corte.diferencia) }}
                            </td>
                            <td class="numero">{{ corte.ventas_incluidas }}</td>
                            <td>{{ corte.observaciones ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Retrasos -->
        <template v-else-if="reporte && elegida === 'retrasos'">
            <div class="rejillas">
                <div class="tarjeta">
                    <p class="tarjeta__titulo">Atenciones</p>
                    <p class="tarjeta__cifra">{{ (reporte as RespuestaRetrasos).atenciones }}</p>
                </div>

                <div
                    class="tarjeta"
                    :class="
                        (reporte as RespuestaRetrasos).minutos_retraso_promedio > 0
                            ? 'tarjeta--revisar'
                            : 'tarjeta--ok'
                    "
                >
                    <p class="tarjeta__titulo">Retraso promedio</p>
                    <p class="tarjeta__cifra">
                        {{ (reporte as RespuestaRetrasos).minutos_retraso_promedio }} min
                    </p>
                    <p class="tarjeta__nota">
                        Total: {{ (reporte as RespuestaRetrasos).minutos_retraso_total }} min
                    </p>
                </div>
            </div>

            <p class="aviso">
                El retraso se registra cuando alguien aprieta "registrar cómo terminó". Si nadie
                lo aprieta, no hay retraso que mostrar: el sistema no lo estima.
            </p>

            <div class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Inicio</th>
                            <th>Servicio</th>
                            <th class="numero">Duración prevista</th>
                            <th class="numero">Retraso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="atencion in (reporte as RespuestaRetrasos).datos"
                            :key="`${atencion.fecha_inicio}-${atencion.servicio?.id ?? ''}`"
                        >
                            <td>{{ fechaHora(atencion.fecha_inicio) }}</td>
                            <td>{{ atencion.servicio?.nombre ?? '—' }}</td>
                            <td class="numero">{{ atencion.duracion_prevista }} min</td>
                            <td class="numero">
                                {{ atencion.minutos_retraso === null ? '—' : `${atencion.minutos_retraso} min` }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Comisiones -->
        <template v-else-if="reporte && elegida === 'comisiones'">
            <div class="rejillas">
                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Total de comisiones</p>
                    <p class="tarjeta__cifra">
                        {{ pesos((reporte as RespuestaComisiones).total_comisiones) }}
                    </p>
                    <p class="tarjeta__nota">
                        {{ (reporte as RespuestaComisiones).liquidaciones }} liquidaciones
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Base de servicios</p>
                    <p class="tarjeta__cifra">
                        {{ pesos((reporte as RespuestaComisiones).base_servicios) }}
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Base de productos</p>
                    <p class="tarjeta__cifra">
                        {{ pesos((reporte as RespuestaComisiones).base_productos) }}
                    </p>
                </div>
            </div>

            <div class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Profesional</th>
                            <th>Período</th>
                            <th class="numero">% servicios</th>
                            <th class="numero">% productos</th>
                            <th class="numero">Comisión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="liquidacion in (reporte as RespuestaComisiones).datos" :key="liquidacion.id">
                            <td>{{ liquidacion.profesional?.nombre ?? '—' }}</td>
                            <td>
                                {{ liquidacion.periodo_desde }} a {{ liquidacion.periodo_hasta }}
                            </td>
                            <td class="numero">{{ liquidacion.porcentaje_servicios }} %</td>
                            <td class="numero">{{ liquidacion.porcentaje_productos }} %</td>
                            <td class="numero">{{ pesos(liquidacion.total_comision) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Auditoría -->
        <template v-else-if="reporte && elegida === 'auditoria'">
            <div class="rejillas">
                <div class="tarjeta">
                    <p class="tarjeta__titulo">Accesos registrados</p>
                    <p class="tarjeta__cifra">{{ (reporte as RespuestaAuditoria).total }}</p>
                </div>

                <div
                    class="tarjeta"
                    :class="
                        (reporte as RespuestaAuditoria).denegados > 0 ? 'tarjeta--riesgo' : 'tarjeta--ok'
                    "
                >
                    <p class="tarjeta__titulo">Denegados</p>
                    <p class="tarjeta__cifra">{{ (reporte as RespuestaAuditoria).denegados }}</p>
                    <p class="tarjeta__nota">
                        Un acceso denegado también se anota: ahí se ve quién intentó abrir qué.
                    </p>
                </div>
            </div>

            <div class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Cuándo</th>
                            <th>Resultado</th>
                            <th>Acción</th>
                            <th>Quién</th>
                            <th>Clienta</th>
                            <th>Dirección</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(acceso, indice) in (reporte as RespuestaAuditoria).datos" :key="indice">
                            <td>{{ fechaHora(acceso.creado_en) }}</td>
                            <td>
                                <span
                                    class="etiqueta"
                                    :class="acceso.resultado === 'denegado' ? 'etiqueta--riesgo' : 'etiqueta--exito'"
                                >
                                    {{ acceso.resultado }}
                                </span>
                            </td>
                            <td>{{ acceso.accion }}</td>
                            <td>{{ acceso.usuario?.nombre ?? '—' }}</td>
                            <td>
                                {{ acceso.clienta?.nombre ?? '—' }}
                                {{ acceso.clienta?.apellido ?? '' }}
                            </td>
                            <td>{{ acceso.ip ?? '—' }}</td>
                            <td>{{ acceso.detalle ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="ayuda">
            No hay ranking de productividad por profesional, y es a propósito. Ver docs/02.
        </p>
    </section>
</template>