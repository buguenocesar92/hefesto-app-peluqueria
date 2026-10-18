<script setup lang="ts">
/**
 * Comisiones: la pantalla que hace que la cuenta se pueda rehacer.
 *
 * LAS TRES PREGUNTAS, EN ESTE ORDEN
 * ---------------------------------
 * 1. ¿Cuánto le corresponde a esta profesional en este período?
 * 2. ¿De dónde sale ese número, línea por línea?
 * 3. ¿Quién lo Apaga y cuándo?
 *
 * La 2 es la importante. Un total solo obliga a creerlo; una lista de ventas con
 * su base, su porcentaje y su resultado permite rehacer la cuenta con una
 * calculadora. Por eso el detalle no es una pantalla opcional: es la pantalla.
 *
 * QUIÉN VE QUÉ
 * -----------
 * Cada profesional ve SU cuenta y su desglose. Administración ve todas, cambia
 * porcentajes y produce la liquidación cerrada. El servidor lo comprueba en
 * `LiquidacionComisionPolicy` y en `ComisionController::comprobarAccesoAProfesional`;
 * esconder botones acá no es lo que protege nada.
 *
 * SIN POLÍTICA VIGENTE NO HAY COMISIÓN
 * ------------------------------------
 * Si el salón no ha declarado porcentajes, la API responde con un error que lo
 * dice. Esta pantalla no lo disfraza de cero: un cero es un número que nadie
 * negoció. Ver docs/06.
 */
import type {
    DesgloseComision,
    LiquidacionComision,
    PoliticaComision,
    Profesional,
} from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Comisiones · Peluquería' })

const { usuario, puedeLiquidar } = useSesion()

/* ------------------------------------------------------------------
 | Datos de apoyo
 * ------------------------------------------------------------------ */

const { data: datosProfesionales } = await useAsyncData('comisiones-profesionales', () =>
    $fetch<{ datos: Profesional[] }>('/api/agenda/profesionales'),
)

const profesionales = computed(() => {
    const todas = datosProfesionales.value?.datos ?? []

    // Una profesional solo puede elegir su propia cuenta. El selector no la
    // esconde por seguridad: la API rechazaría con 404 cualquier otra.
    if (!puedeLiquidar.value && usuario.value?.profesional_id !== null) {
        return todas.filter((p) => p.id === usuario.value?.profesional_id)
    }

    return todas
})

const { data: liquidaciones, refresh: recargarLiquidaciones } = await useAsyncData(
    'comisiones-liquidaciones',
    () => $fetch<{ datos: LiquidacionComision[] }>('/api/comisiones'),
)

/** Las políticas son de administración: quien no administra no las pide. */
const { data: politicas, refresh: recargarPoliticas } = await useAsyncData(
    'comisiones-politicas',
    () => $fetch<{ datos: PoliticaComision[] }>('/api/administracion/comisiones/politicas'),
    { immediate: puedeLiquidar.value },
)

/* ------------------------------------------------------------------
 | Período
 * ------------------------------------------------------------------ */

function fechaCorta(diasAtras: number): string {
    const fecha = new Date()

    fecha.setDate(fecha.getDate() - diasAtras)

    return fecha.toISOString().slice(0, 10)
}

const periodo = reactive({
    profesional_id: usuario.value?.profesional_id ?? '',
    desde: fechaCorta(15),
    hasta: new Date().toISOString().slice(0, 10),
})

const previsualizacion = ref<DesgloseComision | null>(null)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

/**
 * La cuenta, calculada y NO guardada.
 *
 * Es lo que el dueño mira antes de decidir cerrar el período, y lo que la
 * profesional revisa antes de firmar. No deja rastro porque no cambia nada.
 */
async function previsualizar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        const respuesta = await $fetch<{ desglose: DesgloseComision }>('/api/comisiones/previsualizar', {
            query: {
                profesional_id: periodo.profesional_id,
                desde: periodo.desde,
                hasta: periodo.hasta,
            },
        })

        previsualizacion.value = respuesta.desglose
    } catch (fallo) {
        previsualizacion.value = null
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/** Encola el cálculo de un período y guarda la liquidación. */
async function liquidar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch('/api/comisiones/liquidar', {
            method: 'POST',
            body: {
                profesional_id: periodo.profesional_id,
                desde: periodo.desde,
                hasta: periodo.hasta,
            },
        })

        mensaje.value = 'El cálculo quedó encolado. Se avisa cuando termine.'

        await recargarLiquidaciones()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/* ------------------------------------------------------------------
 | Liquidaciones
 * ------------------------------------------------------------------ */

const detalle = ref<LiquidacionComision | null>(null)

async function ver(liquidacionId: string): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        detalle.value = await $fetch<LiquidacionComision>(
            `/api/comisiones/liquidaciones/${liquidacionId}`,
        )
    } catch (fallo) {
        detalle.value = null
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

async function cerrar(liquidacionId: string): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/comisiones/liquidaciones/${liquidacionId}/cerrar`, { method: 'POST' })

        mensaje.value = 'Liquidación cerrada. El desglose queda congelado.'

        await recargarLiquidaciones()

        if (detalle.value?.id === liquidacionId) {
            await ver(liquidacionId)
        }
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

const motivoAnulacion = ref('')
const anulando = ref<string | null>(null)

async function anular(liquidacionId: string): Promise<void> {
    error.value = null
    ocupada.value = true
    anulando.value = liquidacionId

    try {
        await $fetch(`/api/comisiones/liquidaciones/${liquidacionId}/anular`, {
            method: 'POST',
            body: { motivo_anulacion: motivoAnulacion.value },
        })

        mensaje.value = 'Liquidación anulada. El motivo queda escrito en el registro.'
        motivoAnulacion.value = ''

        await recargarLiquidaciones()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
        anulando.value = null
    }
}

/* ------------------------------------------------------------------
 | Políticas: los porcentajes los declara el salón
 * ------------------------------------------------------------------ */

const formulario = reactive({
    profesional_id: '',
    porcentaje_servicios: '',
    porcentaje_productos: '',
    incluye_productos: true,
    base_calculo: 'ambos',
    vigente_desde: new Date().toISOString().slice(0, 10),
    vigente_hasta: '',
    notas: '',
})

const guardandoPolitica = ref(false)

async function guardarPolitica(): Promise<void> {
    error.value = null
    mensaje.value = null
    guardandoPolitica.value = true

    try {
        await $fetch('/api/administracion/comisiones/politicas', {
            method: 'POST',
            body: {
                profesional_id: formulario.profesional_id === '' ? null : formulario.profesional_id,
                porcentaje_servicios: formulario.porcentaje_servicios,
                porcentaje_productos: formulario.porcentaje_productos,
                incluye_productos: formulario.incluye_productos,
                base_calculo: formulario.base_calculo,
                vigente_desde: formulario.vigente_desde,
                vigente_hasta: formulario.vigente_hasta === '' ? null : formulario.vigente_hasta,
                notas: formulario.notas === '' ? undefined : formulario.notas,
            },
        })

        mensaje.value = 'Política guardada. Las liquidaciones ya cerradas no cambian.'

        await recargarPoliticas()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        guardandoPolitica.value = false
    }
}

/* ------------------------------------------------------------------
 | Ayudas
 * ------------------------------------------------------------------ */

function pesos(valor: number): string {
    return valor.toLocaleString('es-CL')
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

    return 'No se pudo completar la operación.'
}
</script>

<template>
    <section>
        <h1>Comisiones</h1>

        <p class="ayuda">
            La comisión se calcula línea por línea y se redondea en cada línea, al peso. Así el
            total es la suma exacta de lo que se ve abajo, y cada línea se puede rehacer con
            una calculadora.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <h2>Cuenta de un período</h2>

        <form class="formulario" @submit.prevent="previsualizar">
            <div class="fila">
                <div class="campo">
                    <label for="profesional">Profesional</label>
                    <select id="profesional" v-model="periodo.profesional_id" required>
                        <option value="">Elija una profesional</option>
                        <option v-for="p in profesionales" :key="p.id" :value="p.id">
                            {{ p.nombre }}
                        </option>
                    </select>
                </div>

                <div class="campo">
                    <label for="desde">Desde</label>
                    <input id="desde" v-model="periodo.desde" type="date" required />
                </div>

                <div class="campo">
                    <label for="hasta">Hasta</label>
                    <input id="hasta" v-model="periodo.hasta" type="date" required />
                </div>
            </div>

            <div class="fila">
                <button class="boton" type="submit" :disabled="ocupada">
                    {{ ocupada ? 'Calculando...' : 'Ver la cuenta' }}
                </button>

                <button
                    v-if="puedeLiquidar"
                    class="boton boton--suave"
                    type="button"
                    :disabled="ocupada"
                    @click="liquidar"
                >
                    Liquidar el período
                </button>
            </div>
        </form>

        <template v-if="previsualizacion">
            <div class="rejillas">
                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Total de la comisión</p>
                    <p class="tarjeta__cifra">{{ pesos(previsualizacion.total_comision) }}</p>
                    <p class="tarjeta__nota">
                        Base: {{ previsualizacion.base_calculo_etiqueta }}. Servicios
                        {{ previsualizacion.porcentaje_servicios }} %, productos
                        {{ previsualizacion.porcentaje_productos }} %.
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Base de servicios</p>
                    <p class="tarjeta__cifra">{{ pesos(previsualizacion.total_servicios) }}</p>
                    <p class="tarjeta__nota">
                        {{ previsualizacion.lineas.filter((l) => l.tipo === 'servicio').length }} líneas
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Base de productos</p>
                    <p class="tarjeta__cifra">{{ pesos(previsualizacion.total_productos) }}</p>
                    <p class="tarjeta__nota">
                        {{ previsualizacion.lineas.filter((l) => l.tipo === 'producto').length }} líneas
                    </p>
                </div>
            </div>

            <h3>Desglose</h3>

            <p v-if="previsualizacion.lineas.length === 0" class="vacio">
                En este período no hay ventas cobradas de esta profesional.
            </p>

            <div v-else class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Venta</th>
                            <th>Descripción</th>
                            <th>Tipo</th>
                            <th class="numero">Base</th>
                            <th class="numero">Porcentaje</th>
                            <th class="numero">Comisión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linea in previsualizacion.lineas" :key="linea.venta_item_id">
                            <td>{{ linea.venta_id.slice(0, 8) }}</td>
                            <td>{{ linea.descripcion }}</td>
                            <td>{{ linea.tipo }}</td>
                            <td class="numero">{{ pesos(linea.base_aplicada) }}</td>
                            <td class="numero">{{ linea.porcentaje_aplicado }} %</td>
                            <td class="numero">{{ pesos(linea.comision_calculada) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="ayuda">
                Los identificadores de venta y de línea permiten rastrear cada cifra hasta
                el cobro que la originó.
            </p>
        </template>

        <h2>Liquidaciones</h2>

        <p v-if="(liquidaciones?.datos.length ?? 0) === 0" class="vacio">
            Todavía no hay liquidaciones.
        </p>

        <div v-else class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Profesional</th>
                        <th>Período</th>
                        <th class="numero">Base servicios</th>
                        <th class="numero">Base productos</th>
                        <th class="numero">Total</th>
                        <th>Estado</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="liquidacion in liquidaciones?.datos ?? []" :key="liquidacion.id">
                        <td>{{ liquidacion.profesional?.nombre ?? '—' }}</td>
                        <td>
                            {{ liquidacion.periodo_desde }} a {{ liquidacion.periodo_hasta }}
                        </td>
                        <td class="numero">{{ pesos(liquidacion.base_servicios) }}</td>
                        <td class="numero">{{ pesos(liquidacion.base_productos) }}</td>
                        <td class="numero">{{ pesos(liquidacion.total_comision) }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="
                                    liquidacion.estado === 'cerrada' ? 'etiqueta--exito' : 'etiqueta--alerta'
                                "
                            >
                                {{ liquidacion.estado_etiqueta }}
                            </span>
                        </td>
                        <td>
                            <button
                                class="boton boton--suave"
                                type="button"
                                @click="ver(liquidacion.id)"
                            >
                                Ver desglose
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="detalle">
            <h3>Liquidación de {{ detalle.profesional?.nombre ?? '—' }}</h3>

            <div class="rejillas">
                <div class="tarjeta tarjeta--ok">
                    <p class="tarjeta__titulo">Total</p>
                    <p class="tarjeta__cifra">{{ pesos(detalle.total_comision) }}</p>
                    <p class="tarjeta__nota">
                        Servicios {{ detalle.porcentaje_servicios }} % · productos
                        {{ detalle.porcentaje_productos }} %
                    </p>
                </div>

                <div class="tarjeta">
                    <p class="tarjeta__titulo">Estado</p>
                    <p class="tarjeta__cifra" style="font-size: 18px">
                        {{ detalle.estado_etiqueta }}
                    </p>
                    <p class="tarjeta__nota">
                        La liquidación guarda los porcentajes con que se calculó, aunque la
                        política cambie después.
                    </p>
                </div>
            </div>

            <div v-if="detalle.lineas && detalle.lineas.length > 0" class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Venta</th>
                            <th>Descripción</th>
                            <th class="numero">Base</th>
                            <th class="numero">Porcentaje</th>
                            <th class="numero">Comisión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linea in detalle.lineas" :key="linea.venta_item_id">
                            <td>{{ linea.venta_id.slice(0, 8) }}</td>
                            <td>{{ linea.descripcion }}</td>
                            <td class="numero">{{ pesos(linea.base_aplicada) }}</td>
                            <td class="numero">{{ linea.porcentaje_aplicado }} %</td>
                            <td class="numero">{{ pesos(linea.comision_calculada) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="puedeLiquidar" class="fila" style="margin-top: 16px">
                <button
                    v-if="detalle.estado === 'borrador'"
                    class="boton"
                    type="button"
                    :disabled="ocupada"
                    @click="cerrar(detalle.id)"
                >
                    Cerrar la liquidación
                </button>

                <div class="campo" style="flex: 2">
                    <label for="motivo">Motivo de anulación</label>
                    <input id="motivo" v-model="motivoAnulacion" type="text" maxlength="2000" />
                </div>

                <button
                    v-if="detalle.estado === 'borrador'"
                    class="boton boton--suave"
                    type="button"
                    :disabled="ocupada || motivoAnulacion === ''"
                    @click="anular(detalle.id)"
                >
                    Anular
                </button>
            </div>
        </template>

        <!--
            Las políticas son del negocio, no del software. Esta pantalla existe
            para que el dueño escriba el porcentaje que negoció, no para que
            alguien lo cambie en un archivo de configuración sin dejar rastro.
        -->
        <template v-if="puedeLiquidar">
            <h2>Políticas de comisión</h2>

            <p v-if="(politicas?.datos.length ?? 0) === 0" class="aviso aviso--alerta">
                Este salón no ha declarado ninguna política vigente. Sin política no hay
                comisión: el cálculo responde un error en vez de devolver cero, porque un
                cero es un número que nadie negoció.
            </p>

            <div v-if="politicas && politicas.datos.length > 0" class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Aplica a</th>
                            <th class="numero">Servicios</th>
                            <th class="numero">Productos</th>
                            <th>Base</th>
                            <th>Incluye productos</th>
                            <th>Vigencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="politica in politicas.datos" :key="politica.id">
                            <td>{{ politica.profesional?.nombre ?? 'Todo el salón' }}</td>
                            <td class="numero">{{ politica.porcentaje_servicios }} %</td>
                            <td class="numero">{{ politica.porcentaje_productos }} %</td>
                            <td>{{ politica.base_calculo_etiqueta }}</td>
                            <td>{{ politica.incluye_productos ? 'Sí' : 'No' }}</td>
                            <td>
                                {{ politica.vigente_desde }} a
                                {{ politica.vigente_hasta ?? 'sin término' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Declarar una política</h3>

            <form class="formulario" @submit.prevent="guardarPolitica">
                <div class="fila">
                    <div class="campo">
                        <label for="politica-profesional">Aplica a</label>
                        <select id="politica-profesional" v-model="formulario.profesional_id">
                            <option value="">Todo el salón</option>
                            <option v-for="p in profesionales" :key="p.id" :value="p.id">
                                {{ p.nombre }}
                            </option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="base">Base de cálculo</label>
                        <select id="base" v-model="formulario.base_calculo">
                            <option value="ambos">Servicios y productos</option>
                            <option value="servicio">Solo servicios</option>
                            <option value="producto">Solo productos</option>
                        </select>
                    </div>
                </div>

                <div class="fila">
                    <div class="campo">
                        <label for="porcentaje-servicios">Porcentaje de servicios</label>
                        <input
                            id="porcentaje-servicios"
                            v-model="formulario.porcentaje_servicios"
                            type="text"
                            inputmode="decimal"
                            placeholder="35.50"
                            required
                        />
                    </div>

                    <div class="campo">
                        <label for="porcentaje-productos">Porcentaje de productos</label>
                        <input
                            id="porcentaje-productos"
                            v-model="formulario.porcentaje_productos"
                            type="text"
                            inputmode="decimal"
                            placeholder="10.00"
                            required
                        />
                    </div>

                    <div class="campo">
                        <label for="incluye">¿Participa de los productos?</label>
                        <select id="incluye" v-model="formulario.incluye_productos">
                            <option :value="true">Sí</option>
                            <option :value="false">No</option>
                        </select>
                    </div>
                </div>

                <div class="fila">
                    <div class="campo">
                        <label for="vigente-desde">Vigente desde</label>
                        <input id="vigente-desde" v-model="formulario.vigente_desde" type="date" required />
                    </div>

                    <div class="campo">
                        <label for="vigente-hasta">Vigente hasta</label>
                        <input id="vigente-hasta" v-model="formulario.vigente_hasta" type="date" />
                    </div>
                </div>

                <div class="campo">
                    <label for="notas">Notas</label>
                    <input id="notas" v-model="formulario.notas" type="text" maxlength="2000" />
                </div>

                <p class="ayuda">
                    El porcentaje se escribe con coma decimal: 35,50 o 35.50, según el teclado.
                    La aplicación no trae ningún valor por omisión.
                </p>

                <button class="boton" type="submit" :disabled="guardandoPolitica">
                    {{ guardandoPolitica ? 'Guardando...' : 'Guardar política' }}
                </button>
            </form>
        </template>

        <p class="ayuda">
            No hay un ranking de productividad por profesional, y es a propósito: con comisiones
            encima, ordenar por venta empuja a vender lo que la clienta no necesita. Ver docs/02.
        </p>
    </section>
</template>