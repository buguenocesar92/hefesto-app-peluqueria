<script setup lang="ts">
/**
 * Ficha de una clienta.
 *
 * QUÉ ESTÁ SEPARADO Y POR QUÉ
 * ---------------------------
 * La pantalla tiene tres bloques que NO son lo mismo:
 *
 *   1. Identificación: nombre, RUT, teléfono. Dato personal.
 *   2. Historial: las citas y las ventas que ya están registradas.
 *   3. Antecedentes de salud: dato SENSIBLE bajo la Ley 19.628.
 *
 * El tercero se pide por una ruta aparte, con auditoría. Por eso el bloque se
 * carga por su cuenta: si a esta persona no le corresponde verlo, la pantalla no
 * falla, simplemente lo dice. Y el intento queda anotado igual. Ver docs/07.
 *
 * LO QUE ESTA PANTALLA NO PUEDE HACER
 * ----------------------------------
 * No inventa la regla de espera entre procesos químicos. No hay columna para
 * eso porque el dato no se pudo verificar, y el bloque de salud lo dice en
 * pantalla, no en una nota al pie que nadie lee.
 */
import type { AntecedenteSalud, FichaClienta, RespuestaAntecedentes } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

const ruta = useRoute()
const id = String(ruta.params.id)

useHead({ title: 'Ficha de clienta · Peluquería' })

const { puedeVerAntecedentes } = useSesion()

const { data: ficha, error: errorFicha } = await useAsyncData(`clienta-${id}`, () =>
    $fetch<FichaClienta>(`/api/clientas/${id}`),
)

/*
 * Los antecedentes se piden aparte y solo si corresponde. Un 404 acá significa
 * dos cosas: que no existe o que el permiso lo escondió. La pantalla no puede
 * distinguirlas y no debe intentarlo: en ambos casos la respuesta honesta es la
 * misma, "no se muestran".
 */
const {
    data: antecedentes,
    error: errorAntecedentes,
    refresh: recargarAntecedentes,
} = await useAsyncData(
    `antecedentes-${id}`,
    () => $fetch<RespuestaAntecedentes>(`/api/clientas/${id}/antecedentes`),
    { immediate: puedeVerAntecedentes.value },
)

/* ------------------------------------------------------------------
 | Identificación
 * ------------------------------------------------------------------ */

const editando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

const formulario = reactive({
    nombre: '',
    apellido: '',
    rut: '',
    telefono: '',
    correo: '',
    notas: '',
})

watchEffect(() => {
    if (ficha.value === null || ficha.value === undefined) {
        return
    }

    formulario.nombre = ficha.value.nombre
    formulario.apellido = ficha.value.apellido ?? ''
    formulario.rut = ficha.value.rut ?? ''
    formulario.telefono = ficha.value.telefono ?? ''
    formulario.correo = ficha.value.correo ?? ''
    formulario.notas = ficha.value.notas ?? ''
})

async function guardarFicha(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/clientas/${id}`, {
            method: 'PUT',
            body: {
                nombre: formulario.nombre,
                apellido: formulario.apellido === '' ? undefined : formulario.apellido,
                rut: formulario.rut === '' ? undefined : formulario.rut,
                telefono: formulario.telefono === '' ? undefined : formulario.telefono,
                correo: formulario.correo === '' ? undefined : formulario.correo,
                notas: formulario.notas === '' ? undefined : formulario.notas,
            },
        })

        mensaje.value = 'Ficha guardada.'
        editando.value = false
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

async function desactivar(): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/clientas/${id}`, { method: 'DELETE' })

        mensaje.value = 'Clienta desactivada. Sus ventas y citas se conservan.'

        await navigateTo('/clientas')
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/* ------------------------------------------------------------------
 | Antecedentes de salud
 * ------------------------------------------------------------------ */

const formularioSalud = reactive({
    tipo: 'alergia',
    descripcion: '',
    severidad: 'moderada',
})

async function registrarAntecedente(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/clientas/${id}/antecedentes`, {
            method: 'POST',
            body: {
                tipo: formularioSalud.tipo,
                descripcion: formularioSalud.descripcion,
                severidad: formularioSalud.severidad,
            },
        })

        mensaje.value = 'Antecedente registrado.'
        formularioSalud.descripcion = ''

        await recargarAntecedentes()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

async function cerrarAntecedente(antecedente: AntecedenteSalud): Promise<void> {
    error.value = null
    ocupada.value = true

    try {
        await $fetch(`/api/antecedentes/${antecedente.id}`, { method: 'DELETE' })

        mensaje.value = 'Antecedente cerrado.'

        await recargarAntecedentes()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

/* ------------------------------------------------------------------
 | Ayudas
 * ------------------------------------------------------------------ */

function fechaCorta(iso: string): string {
    return iso.slice(0, 10)
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
        <p class="ayuda">
            <NuxtLink to="/clientas">Clientas</NuxtLink> / ficha
        </p>

        <h1>{{ ficha?.nombre_completo ?? 'Ficha de clienta' }}</h1>

        <div v-if="errorFicha" class="error">
            No se pudo abrir la ficha. Puede que no exista o que esta sesión no la vea.
        </div>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <template v-if="ficha">
            <h2>Identificación</h2>

            <div v-if="!editando" class="tabla-envoltura">
                <table>
                    <tbody>
                        <tr>
                            <th>Nombre</th>
                            <td>{{ ficha.nombre_completo }}</td>
                        </tr>
                        <tr>
                            <th>RUT</th>
                            <td>{{ ficha.rut ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td>{{ ficha.telefono ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Correo</th>
                            <td>{{ ficha.correo ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Nacimiento</th>
                            <td>{{ ficha.fecha_nacimiento ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Notas</th>
                            <td>{{ ficha.notas ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Estado</th>
                            <td>{{ ficha.activo ? 'Activa' : 'Desactivada' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form v-else class="formulario" @submit.prevent="guardarFicha">
                <div class="fila">
                    <div class="campo">
                        <label for="nombre">Nombre</label>
                        <input id="nombre" v-model="formulario.nombre" type="text" required />
                    </div>

                    <div class="campo">
                        <label for="apellido">Apellido</label>
                        <input id="apellido" v-model="formulario.apellido" type="text" />
                    </div>

                    <div class="campo">
                        <label for="rut">RUT</label>
                        <input id="rut" v-model="formulario.rut" type="text" />
                    </div>
                </div>

                <div class="fila">
                    <div class="campo">
                        <label for="telefono">Teléfono</label>
                        <input id="telefono" v-model="formulario.telefono" type="tel" />
                    </div>

                    <div class="campo">
                        <label for="correo">Correo</label>
                        <input id="correo" v-model="formulario.correo" type="email" />
                    </div>
                </div>

                <div class="campo">
                    <label for="notas">Notas</label>
                    <textarea id="notas" v-model="formulario.notas" rows="3"></textarea>
                </div>

                <div class="fila">
                    <button class="boton" type="submit" :disabled="ocupada">Guardar</button>
                    <button class="boton boton--suave" type="button" @click="editando = false">
                        Cancelar
                    </button>
                </div>
            </form>

            <div v-if="!editando" class="fila" style="margin-top: 12px">
                <button class="boton boton--suave" type="button" @click="editando = true">
                    Editar ficha
                </button>

                <button class="boton boton--suave" type="button" :disabled="ocupada" @click="desactivar">
                    Desactivar
                </button>
            </div>

            <h2>Historial</h2>

            <h3>Citas</h3>

            <p v-if="ficha.citas.length === 0" class="vacio">Esta clienta no tiene citas registradas.</p>

            <div v-else class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Servicio</th>
                            <th>Estado</th>
                            <th class="numero">Retraso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cita in ficha.citas" :key="cita.id">
                            <td>{{ fechaCorta(cita.fecha_inicio) }}</td>
                            <td>{{ cita.servicio?.nombre ?? '—' }}</td>
                            <td>{{ cita.estado_etiqueta }}</td>
                            <td class="numero">
                                {{ cita.minutos_retraso === null ? '—' : `${cita.minutos_retraso} min` }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>Ventas</h3>

            <p v-if="ficha.ventas.length === 0" class="vacio">Esta clienta no tiene ventas registradas.</p>

            <div v-else class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Profesional</th>
                            <th>Estado</th>
                            <th class="numero">Servicios</th>
                            <th class="numero">Productos</th>
                            <th class="numero">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="venta in ficha.ventas" :key="venta.id">
                            <td>{{ venta.fecha }}</td>
                            <td>{{ venta.profesional?.nombre ?? '—' }}</td>
                            <td>{{ venta.estado_etiqueta }}</td>
                            <td class="numero">{{ venta.total_servicios }}</td>
                            <td class="numero">{{ venta.total_productos }}</td>
                            <td class="numero">{{ venta.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!--
                El bloque de salud es datos sensibles. Por eso viaja por su propia
                ruta y deja rastro de quién lo abrió, aunque se le niegue.
            -->
            <h2>Antecedentes de salud</h2>

            <div class="aviso aviso--alerta">
                Dato sensible de salud (Ley 19.628). Cada apertura de este bloque queda
                registrada, permitida o denegada.
            </div>

            <p v-if="!puedeVerAntecedentes" class="aviso">
                Esta sesión no puede ver los antecedentes de salud. El intento queda anotado.
            </p>

            <p v-else-if="errorAntecedentes" class="aviso aviso--riesgo">
                No se pudieron mostrar los antecedentes. Puede que la ficha no exista o que
                esta sesión no tenga acceso; en los dos casos el intento quedó registrado.
            </p>

            <template v-else-if="antecedentes">
                <p v-if="!antecedentes.espera_entre_procesos_comprobada" class="aviso aviso--alerta">
                    {{ antecedentes.aviso_espera }}
                </p>

                <p v-if="antecedentes.datos.length === 0" class="vacio">
                    No hay antecedentes registrados.
                </p>

                <article v-for="antecedente in antecedentes.datos" :key="antecedente.id" class="cita">
                    <p class="cita__hora">
                        {{ antecedente.tipo_etiqueta }}
                        <span
                            class="etiqueta"
                            :class="antecedente.severidad === 'grave' ? 'etiqueta--riesgo' : 'etiqueta--alerta'"
                        >
                            {{ antecedente.severidad_etiqueta }}
                        </span>
                    </p>

                    <p class="cita__servicio">{{ antecedente.descripcion }}</p>

                    <p class="cita__meta">
                        Sin servicios declarados: el antecedente avisa en todos. Es el caso
                        prudente, porque una alergia sin servicio asociado igual tiene que
                        aparecer.
                    </p>

                    <button
                        class="boton boton--suave"
                        type="button"
                        :disabled="ocupada"
                        @click="cerrarAntecedente(antecedente)"
                    >
                        Cerrar antecedente
                    </button>
                </article>

                <form class="formulario" style="margin-top: 16px" @submit.prevent="registrarAntecedente">
                    <div class="fila">
                        <div class="campo">
                            <label for="tipo">Tipo</label>
                            <select id="tipo" v-model="formularioSalud.tipo">
                                <option value="alergia">Alergia</option>
                                <option value="contraindicacion">Contraindicación</option>
                                <option value="condicion">Condición de salud</option>
                                <option value="sensibilidad">Sensibilidad</option>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="severidad">Severidad</label>
                            <select id="severidad" v-model="formularioSalud.severidad">
                                <option value="leve">Leve</option>
                                <option value="moderada">Moderada</option>
                                <option value="grave">Grave</option>
                            </select>
                        </div>
                    </div>

                    <div class="campo">
                        <label for="descripcion">Descripción</label>
                        <input
                            id="descripcion"
                            v-model="formularioSalud.descripcion"
                            type="text"
                            required
                            maxlength="480"
                        />
                    </div>

                    <p class="ayuda">
                        La severidad y la marca de bloqueo los define la profesional que
                        atiende o la administración. El sistema no decide si una alergia
                        impide un servicio: quien lo sabe, lo declara.
                    </p>

                    <button class="boton" type="submit" :disabled="ocupada">
                        {{ ocupada ? 'Guardando...' : 'Registrar antecedente' }}
                    </button>
                </form>
            </template>
        </template>
    </section>
</template>