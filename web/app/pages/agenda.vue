<script setup lang="ts">
/**
 * La agenda del día.
 *
 * POR QUÉ UNA PANTALLA Y NO UN CALENDARIO MENSUAL
 * ----------------------------------------------
 * En un salón la pregunta del mostrador es siempre "¿a qué hora tengo a esta
 * persona?". Un calendario mensual obliga a abrir el día para responderla, y en
 * la práctica nadie abre el día: se contesta de memoria y se pisan dos citas.
 *
 * POR QUÉ LAS HORAS SE MUESTRAN EN LA DEL SALÓN Y NO EN LA DEL NAVEGADOR
 * ----------------------------------------------------------------------
 * La API devuelve ISO 8601 con su desplazamiento, por ejemplo
 * `2026-10-18T09:00:00-04:00`. Convertirlo a la hora local del navegador haría
 * que el mismo salón viera horas distintas según desde qué computador se mirara.
 * Por eso se toman los dos caracteres de la hora tal como vienen: las 09:00 son
 * las 09:00 del salón, se mire desde donde se mire.
 */
import type {
    BloqueoAgenda,
    Cita,
    Clienta,
    Profesional,
    RespuestaAgenda,
    RespuestaDisponibilidad,
} from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Agenda · Peluquería' })

const { usuario, puedeAgendar } = useSesion()

const hoy = new Date().toISOString().slice(0, 10)
const fecha = ref<string>(hoy)

const { data: agenda, refresh: recargarAgenda } = await useAsyncData(
    'agenda',
    () => $fetch<RespuestaAgenda>('/api/agenda', { query: { fecha: fecha.value } }),
    { watch: [fecha] },
)

const { data: datosProfesionales } = await useAsyncData('agenda-profesionales', () =>
    $fetch<{ datos: Profesional[] }>('/api/agenda/profesionales'),
)

const { data: datosClientas } = await useAsyncData('agenda-clientas', () =>
    $fetch<{ datos: Clienta[] }>('/api/clientas'),
)

const profesionales = computed(() => datosProfesionales.value?.datos ?? [])
const clientas = computed(() => datosClientas.value?.datos ?? [])

/* ------------------------------------------------------------------
 | Agendar
 * ------------------------------------------------------------------ */

const formulario = reactive({
    profesional_id: '',
    servicio_id: '',
    clienta_id: '',
    fecha_inicio: `${hoy}T09:00`,
    notas: '',
})

const ocupada = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

/** Los servicios que hace la profesional elegida. */
const serviciosDisponibles = computed(() => {
    const profesional = profesionales.value.find((p) => p.id === formulario.profesional_id)

    return profesional?.servicios ?? []
})

watch(
    () => formulario.profesional_id,
    () => {
        // Si el servicio elegido no lo hace esta profesional, se suelta. Si no se
        // suelta, el servidor responde con un 404 que no explica nada.
        if (!serviciosDisponibles.value.some((s) => s.id === formulario.servicio_id)) {
            formulario.servicio_id = ''
        }
    },
)

/** La hora de término la calcula el servicio para esa profesional. */
const finEstimada = computed(() => {
    const servicio = serviciosDisponibles.value.find((s) => s.id === formulario.servicio_id)

    if (servicio === undefined || formulario.fecha_inicio === '') {
        return null
    }

    const inicio = new Date(formulario.fecha_inicio)
    const fin = new Date(inicio.getTime() + servicio.duracion_minutos * 60000)

    return `${String(fin.getHours()).padStart(2, '0')}:${String(fin.getMinutes()).padStart(2, '0')}`
})

const disponibilidad = ref<RespuestaDisponibilidad | null>(null)

/**
 * Pregunta por qué se puede o no, ANTES de agendar.
 *
 * La idea es que el motivo del conflicto aparezca mientras se está eligiendo la
 * hora, y no después de apretar el botón. El servidor vuelve a comprobarlo al
 * agendar: esto es una ayuda para la persona, no la garantía.
 */
async function consultarDisponibilidad(): Promise<void> {
    disponibilidad.value = null

    if (formulario.profesional_id === '' || formulario.fecha_inicio === '') {
        return
    }

    try {
        disponibilidad.value = await $fetch<RespuestaDisponibilidad>('/api/agenda/disponibilidad', {
            query: {
                profesional_id: formulario.profesional_id,
                fecha_inicio: formulario.fecha_inicio,
                servicio_id: formulario.servicio_id === '' ? undefined : formulario.servicio_id,
            },
        })
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    }
}

async function agendar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch('/api/citas', {
            method: 'POST',
            body: {
                profesional_id: formulario.profesional_id,
                clienta_id: formulario.clienta_id,
                servicio_id: formulario.servicio_id,
                fecha_inicio: formulario.fecha_inicio,
                notas: formulario.notas === '' ? undefined : formulario.notas,
            },
        })

        mensaje.value = 'Cita agendada.'
        formulario.notas = ''
        disponibilidad.value = null

        await recargarAgenda()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/* ------------------------------------------------------------------
 | Registrar lo que pasó
 * ------------------------------------------------------------------ */

const editando = ref<string | null>(null)
const realidad = reactive({ hora_inicio_real: '', hora_fin_real: '', estado: 'atendida' })

function abrirRealidad(cita: Cita): void {
    editando.value = cita.id
    realidad.hora_inicio_real = horaDe(cita.fecha_inicio)
    realidad.hora_fin_real = horaDe(cita.fecha_fin)
    realidad.estado = cita.estado === 'no_asistio' ? 'no_asistio' : 'atendida'
}

async function registrarRealidad(cita: Cita): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/citas/${cita.id}/realidad`, {
            method: 'POST',
            body: {
                estado: realidad.estado,
                hora_inicio_real: realidad.hora_inicio_real === ''
                    ? undefined
                    : `${fecha.value}T${realidad.hora_inicio_real}`,
                hora_fin_real:
                    realidad.hora_fin_real === '' ? undefined : `${fecha.value}T${realidad.hora_fin_real}`,
            },
        })

        mensaje.value = 'Se registró cómo terminó la cita.'
        editando.value = null

        await recargarAgenda()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

async function anular(cita: Cita): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/citas/${cita.id}`, { method: 'DELETE' })

        mensaje.value = 'Cita anulada. Queda el registro.'

        await recargarAgenda()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/* ------------------------------------------------------------------
 | Ayudas de la pantalla
 * ------------------------------------------------------------------ */

/** Los dos caracteres de la hora, tal como los envió el salón. */
function horaDe(iso: string): string {
    return iso.slice(11, 16)
}

function nombreDeCliente(cita: Cita): string {
    if (cita.clienta === null) {
        return 'Sin clienta'
    }

    return `${cita.clienta.nombre} ${cita.clienta.apellido ?? ''}`.trim()
}

function bloqueosDe(cita: Cita): BloqueoAgenda[] {
    return (agenda.value?.bloqueos ?? []).filter((bloqueo) => {
        if (bloqueo.todo_el_salon) {
            return true
        }

        return bloqueo.profesional?.id === cita.profesional?.id
    })
}

/** El mensaje del servidor, sin inventar uno propio. */
function mensajeDeFallo(fallo: unknown): string {
    if (fallo && typeof fallo === 'object' && 'data' in fallo) {
        const cuerpo = (fallo as { data?: { mensaje?: string } }).data

        if (cuerpo?.mensaje !== undefined) {
            return cuerpo.mensaje
        }
    }

    return 'La operación no se pudo completar.'
}
</script>

<template>
    <section>
        <h1>Agenda</h1>

        <div class="fila">
            <div class="campo">
                <label for="fecha">Día</label>
                <input id="fecha" v-model="fecha" type="date" />
            </div>

            <p class="ayuda" style="flex: 2">
                {{ agenda?.total ?? 0 }} citas · {{ agenda?.con_avisos ?? 0 }} con aviso de salud ·
                {{ (agenda?.bloqueos.length ?? 0) }} bloqueos
            </p>
        </div>

        <p v-if="!agenda?.espera_entre_procesos_comprobada" class="aviso aviso--alerta">
            La espera mínima entre dos procesos químicos sobre el mismo pelo
            <strong>no se comprueba automáticamente</strong>. Ese dato no está verificado y el
            sistema no lo inventa: revise la ficha a mano antes de aplicar un segundo proceso.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <h2>Citas del día</h2>

        <p v-if="(agenda?.citas.length ?? 0) === 0" class="vacio">
            No hay citas agendadas para este día.
        </p>

        <article
            v-for="cita in agenda?.citas ?? []"
            :key="cita.id"
            class="cita"
            :class="{ 'cita--riesgo': cita.requiere_revision }"
        >
            <p class="cita__hora">
                {{ horaDe(cita.fecha_inicio) }} - {{ horaDe(cita.fecha_fin) }}
                <span class="etiqueta">{{ cita.estado_etiqueta }}</span>
                <span v-if="cita.minutos_retraso !== null" class="etiqueta etiqueta--alerta">
                    {{ cita.minutos_retraso }} min de retraso
                </span>
            </p>

            <p class="cita__servicio">
                {{ cita.servicio?.nombre ?? 'Sin servicio' }} · {{ nombreDeCliente(cita) }}
            </p>

            <p class="cita__meta">
                {{ cita.profesional?.nombre ?? 'Sin profesional' }}
                <template v-if="cita.profesional?.silla"> · silla {{ cita.profesional.silla }}</template>
                · {{ cita.duracion_minutos }} min
            </p>

            <div
                v-for="bloqueo in bloqueosDe(cita)"
                :key="bloqueo.id"
                class="bloqueo"
                style="margin-top: 8px"
            >
                Bloqueo: {{ bloqueo.motivo }} ({{ horaDe(bloqueo.fecha_inicio) }} -
                {{ horaDe(bloqueo.fecha_fin) }})
            </div>

            <div v-for="aviso in cita.avisos_salud ?? []" :key="aviso.id" class="aviso aviso--riesgo">
                <strong>{{ aviso.tipo_etiqueta }} · {{ aviso.severidad_etiqueta }}</strong> —
                {{ aviso.descripcion }}
                <span v-if="aviso.bloquea_servicios"> Este antecedente bloquea el servicio.</span>
            </div>

            <div v-if="puedeAgendar && cita.estado !== 'anulada'" class="fila" style="margin-top: 12px">
                <button class="boton boton--suave" type="button" @click="abrirRealidad(cita)">
                    Registrar cómo terminó
                </button>

                <button
                    class="boton boton--suave"
                    type="button"
                    :disabled="ocupada"
                    @click="anular(cita)"
                >
                    Anular
                </button>
            </div>

            <form
                v-if="editando === cita.id"
                class="formulario"
                style="margin-top: 12px"
                @submit.prevent="registrarRealidad(cita)"
            >
                <div class="fila">
                    <div class="campo">
                        <label :for="`inicio-${cita.id}`">Hora real de inicio</label>
                        <input
                            :id="`inicio-${cita.id}`"
                            v-model="realidad.hora_inicio_real"
                            type="time"
                        />
                    </div>

                    <div class="campo">
                        <label :for="`fin-${cita.id}`">Hora real de término</label>
                        <input :id="`fin-${cita.id}`" v-model="realidad.hora_fin_real" type="time" />
                    </div>

                    <div class="campo">
                        <label :for="`estado-${cita.id}`">Cómo terminó</label>
                        <select :id="`estado-${cita.id}`" v-model="realidad.estado">
                            <option value="atendida">Atendida</option>
                            <option value="no_asistio">No asistió</option>
                            <option value="anulada">Anulada</option>
                        </select>
                    </div>
                </div>

                <button class="boton" type="submit" :disabled="ocupada">Guardar</button>
            </form>
        </article>

        <h2 v-if="puedeAgendar">Agendar una cita</h2>

        <form v-if="puedeAgendar" class="formulario" @submit.prevent="agendar">
            <div class="fila">
                <div class="campo">
                    <label for="profesional">Profesional</label>
                    <select id="profesional" v-model="formulario.profesional_id" required>
                        <option value="">Elija una profesional</option>
                        <option v-for="p in profesionales" :key="p.id" :value="p.id">
                            {{ p.nombre }}{{ p.silla ? ` (silla ${p.silla})` : '' }}
                        </option>
                    </select>
                </div>

                <div class="campo">
                    <label for="servicio">Servicio</label>
                    <select id="servicio" v-model="formulario.servicio_id" required>
                        <option value="">Elija un servicio</option>
                        <option v-for="s in serviciosDisponibles" :key="s.id" :value="s.id">
                            {{ s.nombre }} · {{ s.duracion_minutos }} min
                        </option>
                    </select>
                </div>

                <div class="campo">
                    <label for="clienta">Clienta</label>
                    <select id="clienta" v-model="formulario.clienta_id" required>
                        <option value="">Elija una clienta</option>
                        <option v-for="c in clientas" :key="c.id" :value="c.id">
                            {{ c.nombre_completo }}{{ c.rut ? ` · ${c.rut}` : '' }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="inicio">Hora de inicio</label>
                    <input id="inicio" v-model="formulario.fecha_inicio" type="datetime-local" required />
                </div>

                <div class="campo">
                    <label for="notas">Notas</label>
                    <input id="notas" v-model="formulario.notas" type="text" maxlength="240" />
                </div>
            </div>

            <p v-if="finEstimada" class="ayuda">
                Termina a las <strong>{{ finEstimada }}</strong>. Esa hora la define la duración del
                servicio para esa profesional, no un bloque fijo.
            </p>

            <div class="fila">
                <button class="boton boton--suave" type="button" @click="consultarDisponibilidad">
                    Ver si se puede
                </button>

                <button class="boton" type="submit" :disabled="ocupada">
                    {{ ocupada ? 'Guardando...' : 'Agendar' }}
                </button>
            </div>

            <div v-if="disponibilidad" style="margin-top: 12px">
                <p v-if="disponibilidad.puede_agendar" class="aviso aviso--exito">
                    Se puede agendar: termina a las {{ horaDe(disponibilidad.fecha_fin_estimada) }}.
                </p>

                <div v-else class="aviso aviso--riesgo">
                    <p><strong>No se puede agendar en esa hora:</strong></p>
                    <ul>
                        <li v-for="motivo in disponibilidad.conflictos" :key="motivo">{{ motivo }}</li>
                    </ul>
                </div>
            </div>
        </form>

        <p class="ayuda">
            Sesión: {{ usuario?.nombre }} · {{ usuario?.rol_etiqueta }}.
            Si el botón de agendar no aparece, la API lo rechazaría con 404 de todos modos.
        </p>
    </section>
</template>