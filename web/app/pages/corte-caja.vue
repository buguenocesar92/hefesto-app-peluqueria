<script setup lang="ts">
/**
 * Cortes de caja.
 *
 * POR QUÉ ES UNA PANTALLA Y NO UN BOTÓN
 * -------------------------------------
 * En un salón cada quien tiene su caja. El corte es por profesional y por día,
 * y la base tiene una clave única que impide cerrar dos veces el mismo día.
 *
 * POR QUÉ LA DIFERENCIA SE MUESTRA Y NO SE CORRIGE
 * -----------------------------------------------
 * Un sistema que deja corregir un corte cerrado deja de ser un acto y pasa a ser
 * un número que se acomoda. Acá la diferencia queda escrita y visible: si sobró o
 * faltó, eso es lo que hay que mirar. Ver App\Services\Caja\ServicioCortesCaja.
 */
import type { CorteCaja } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Corte de caja · Peluquería' })

const fecha = ref<string>(new Date().toISOString().slice(0, 10))

const { data, refresh } = await useAsyncData(
    'cortes',
    () =>
        $fetch<{
            fecha: string
            total_con_diferencia: number
            datos: CorteCaja[]
        }>('/api/cortes', { query: { fecha: fecha.value } }),
    { watch: [fecha] },
)

const cortes = computed(() => data.value?.datos ?? [])

interface DetalleCorte extends CorteCaja {
    fecha: string
    ventas: { id: string; total: number; total_servicios: number; total_productos: number; forma_pago: string | null; hora: string }[]
}

const detalle = ref<DetalleCorte | null>(null)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

const formulario = reactive({
    profesional_id: '',
    total_declarado: 0,
    total_efectivo: 0,
    total_tarjeta: 0,
    observaciones: '',
})

function pesos(valor: number): string {
    return valor.toLocaleString('es-CL')
}

async function ver(profesionalId: string): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        detalle.value = await $fetch<DetalleCorte>(`/api/cortes/${profesionalId}`, {
            query: { fecha: fecha.value },
        })

        formulario.profesional_id = profesionalId
        formulario.total_declarado = detalle.value.total_declarado
        formulario.total_efectivo = detalle.value.total_declarado
        formulario.total_tarjeta = 0
        formulario.observaciones = ''
    } catch (fallo) {
        detalle.value = null
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

async function cerrar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        const respuesta = await $fetch<{
            mensaje: string
            total_esperado: number
            total_declarado: number
            diferencia: number
            con_diferencia: boolean
        }>(`/api/cortes/${formulario.profesional_id}/cerrar`, {
            method: 'POST',
            query: { fecha: fecha.value },
            body: {
                total_declarado: Number(formulario.total_declarado),
                total_efectivo: Number(formulario.total_efectivo),
                total_tarjeta: Number(formulario.total_tarjeta),
                observaciones: formulario.observaciones === '' ? undefined : formulario.observaciones,
            },
        })

        mensaje.value = respuesta.con_diferencia
            ? `Corte cerrado con una diferencia de ${pesos(respuesta.diferencia)} pesos. Queda anotada, no se corrige.`
            : 'Corte cerrado, sin diferencia.'

        await refresh()

        if (detalle.value !== null) {
            await ver(formulario.profesional_id)
        }
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
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

    return 'No se pudo cerrar el corte.'
}
</script>

<template>
    <section>
        <h1>Corte de caja</h1>

        <div class="fila">
            <div class="campo">
                <label for="fecha">Día</label>
                <input id="fecha" v-model="fecha" type="date" />
            </div>

            <p class="ayuda" style="flex: 2">
                {{ cortes.length }} cortes · {{ data?.total_con_diferencia ?? 0 }} con diferencia
            </p>
        </div>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <p v-if="cortes.length === 0" class="vacio">
            No hay cortes para este día. El corte se crea solo al abrirlo.
        </p>

        <div v-else class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Profesional</th>
                        <th class="numero">Esperado</th>
                        <th class="numero">Declarado</th>
                        <th class="numero">Diferencia</th>
                        <th class="numero">Ventas</th>
                        <th>Estado</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="corte in cortes" :key="corte.id">
                        <td>{{ corte.profesional?.nombre ?? '—' }}</td>
                        <td class="numero">{{ pesos(corte.total_esperado) }}</td>
                        <td class="numero">{{ pesos(corte.total_declarado) }}</td>
                        <td
                            class="numero"
                            :style="corte.diferencia === 0 ? '' : 'color: var(--riesgo); font-weight: 700'"
                        >
                            {{ pesos(corte.diferencia) }}
                        </td>
                        <td class="numero">{{ corte.ventas_incluidas }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="corte.estado === 'cerrado' ? 'etiqueta--exito' : 'etiqueta--alerta'"
                            >
                                {{ corte.estado === 'cerrado' ? 'Cerrado' : 'Abierto' }}
                            </span>
                        </td>
                        <td>
                            <button
                                class="boton boton--suave"
                                type="button"
                                @click="ver(corte.profesional?.id ?? '')"
                            >
                                Abrir
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="detalle">
            <h2>Corte de {{ detalle.profesional?.nombre ?? '—' }}</h2>

            <div class="rejillas">
                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Esperado por el sistema</p>
                    <p class="tarjeta__cifra">{{ pesos(detalle.total_esperado) }}</p>
                    <p class="tarjeta__nota">{{ detalle.ventas_incluidas }} ventas cobradas</p>
                </div>

                <div class="tarjeta" :class="detalle.diferencia === 0 ? 'tarjeta--ok' : 'tarjeta--riesgo'">
                    <p class="tarjeta__titulo">Diferencia</p>
                    <p class="tarjeta__cifra">{{ pesos(detalle.diferencia) }}</p>
                    <p class="tarjeta__nota">
                        {{ detalle.estado === 'cerrado' ? 'Corte cerrado' : 'Caja abierta' }}
                    </p>
                </div>
            </div>

            <h3>Ventas contadas</h3>

            <p v-if="detalle.ventas.length === 0" class="vacio">
                No hay ventas cobradas de esta profesional en este día.
            </p>

            <div v-else class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Forma de pago</th>
                            <th class="numero">Servicios</th>
                            <th class="numero">Productos</th>
                            <th class="numero">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="venta in detalle.ventas" :key="venta.id">
                            <td>{{ venta.hora }}</td>
                            <td>{{ venta.forma_pago ?? '—' }}</td>
                            <td class="numero">{{ pesos(venta.total_servicios) }}</td>
                            <td class="numero">{{ pesos(venta.total_productos) }}</td>
                            <td class="numero">{{ pesos(venta.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3 v-if="detalle.estado !== 'cerrado'">Cerrar el día</h3>

            <form v-if="detalle.estado !== 'cerrado'" class="formulario" @submit.prevent="cerrar">
                <div class="fila">
                    <div class="campo">
                        <label for="declarado">Total declarado</label>
                        <input id="declarado" v-model="formulario.total_declarado" type="number" min="0" required />
                    </div>

                    <div class="campo">
                        <label for="efectivo">Efectivo</label>
                        <input id="efectivo" v-model="formulario.total_efectivo" type="number" min="0" required />
                    </div>

                    <div class="campo">
                        <label for="tarjeta">Tarjeta</label>
                        <input id="tarjeta" v-model="formulario.total_tarjeta" type="number" min="0" required />
                    </div>
                </div>

                <div class="campo">
                    <label for="observaciones">Observaciones</label>
                    <textarea id="observaciones" v-model="formulario.observaciones" rows="3"></textarea>
                </div>

                <p class="ayuda">
                    El declarado es lo que cuenta la persona y el esperado es lo que dice el
                    sistema. Son dos hechos distintos: por eso van por separado y por eso la
                    diferencia no se corrige.
                </p>

                <button class="boton" type="submit" :disabled="ocupada">
                    {{ ocupada ? 'Cerrando...' : 'Cerrar el corte' }}
                </button>
            </form>
        </template>

        <p v-else class="ayuda" style="margin-top: 16px">
            Para ver el detalle, elija una profesional en la tabla de arriba. Si no aparece
            ninguna fila, el salón todavía no tiene profesionales activas.
        </p>
    </section>
</template>