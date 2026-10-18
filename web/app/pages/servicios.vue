<script setup lang="ts">
/**
 * Catálogo de servicios.
 *
 * POR QUÉ ESTÁ SEPARADO DE LA AGENDA
 * ----------------------------------
 * La duración del catálogo es la del SALÓN. La duración real la puede cambiar
 * cada profesional, y eso vive en el puente `servicios_profesional`. Por eso la
 * agenda nunca pregunta el precio ni la duración acá: se los pregunta a la
 * profesional que va a atender. Ver `Profesional::duracionDe()`.
 *
 * POR QUÉ CAMBIAR EL CATÁLOGO NO TOCA LO YA AGENDADO
 * --------------------------------------------------
 * La cita guarda su propia duración y la venta guarda su propio precio unitario.
 * Subir el precio de un corte mañana no reescribe lo que se cobró la semana
 * pasada, y eso es lo que hace que un corte de caja cierre con números que
 * resistan una revisión.
 *
 * "Es reposición" marca los servicios que se vuelven a cobrar en la próxima
 * visita. No hay ninguna tabla que lo calcule: es una etiqueta que usa el salón.
 */
import type { Servicio } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Servicios · Peluquería' })

const { data, refresh } = await useAsyncData('servicios', () =>
    $fetch<{ datos: Servicio[] }>('/api/administracion/servicios'),
)

const servicios = computed(() => data.value?.datos ?? [])

const formulario = reactive({
    nombre: '',
    descripcion: '',
    duracion_minutos: 30,
    precio_base: 0,
    categoria: '',
})

const editando = ref<string | null>(null)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

function pesos(valor: number): string {
    return valor.toLocaleString('es-CL')
}

function Limpiar(): void {
    formulario.nombre = ''
    formulario.descripcion = ''
    formulario.duracion_minutos = 30
    formulario.precio_base = 0
    formulario.categoria = ''
    editando.value = null
}

async function guardar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    const cuerpo = {
        nombre: formulario.nombre,
        descripcion: formulario.descripcion === '' ? undefined : formulario.descripcion,
        duracion_minutos: Number(formulario.duracion_minutos),
        precio_base: Number(formulario.precio_base),
        categoria: formulario.categoria === '' ? undefined : formulario.categoria,
    }

    try {
        if (editando.value === null) {
            await $fetch('/api/administracion/servicios', { method: 'POST', body: cuerpo })

            mensaje.value = 'Servicio guardado.'
        } else {
            await $fetch(`/api/administracion/servicios/${editando.value}`, {
                method: 'PUT',
                body: cuerpo,
            })

            mensaje.value = 'Servicio actualizado. Las citas ya agendadas conservan su duración.'
        }

        limpiar()

        await refresh()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

function editar(servicio: Servicio): void {
    formulario.nombre = servicio.nombre
    formulario.descripcion = servicio.descripcion ?? ''
    formulario.duracion_minutos = servicio.duracion_minutos
    formulario.precio_base = servicio.precio_base
    formulario.categoria = servicio.categoria ?? ''
    editando.value = servicio.id
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

    return 'No se pudo guardar el servicio.'
}
</script>

<template>
    <section>
        <h1>Servicios</h1>

        <p class="ayuda">
            Precio y duración en pesos enteros. La duración que manda en la agenda es la que
            tenga esa profesional; si no tiene una propia, manda la de acá.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <div class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th class="numero">Duración</th>
                        <th class="numero">Precio base</th>
                        <th>Reposición</th>
                        <th>Estado</th>
                        <th>Edición</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="servicio in servicios" :key="servicio.id">
                        <td>{{ servicio.nombre }}</td>
                        <td>{{ servicio.categoria ?? '—' }}</td>
                        <td class="numero">{{ servicio.duracion_minutos }} min</td>
                        <td class="numero">{{ pesos(servicio.precio_base) }}</td>
                        <td>{{ servicio.es_reposicion ? 'Sí' : 'No' }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="servicio.activo ? 'etiqueta--exito' : 'etiqueta--alerta'"
                            >
                                {{ servicio.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td>
                            <button class="boton boton--suave" type="button" @click="editar(servicio)">
                                Editar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2>{{ editando === null ? 'Nuevo servicio' : 'Editando servicio' }}</h2>

        <form class="formulario" @submit.prevent="guardar">
            <div class="fila">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" v-model="formulario.nombre" type="text" required maxlength="160" />
                </div>

                <div class="campo">
                    <label for="categoria">Categoría</label>
                    <input id="categoria" v-model="formulario.categoria" type="text" maxlength="80" />
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="duracion">Duración (minutos)</label>
                    <input
                        id="duracion"
                        v-model="formulario.duracion_minutos"
                        type="number"
                        min="5"
                        max="600"
                        step="5"
                        required
                    />
                </div>

                <div class="campo">
                    <label for="precio">Precio base (pesos)</label>
                    <input
                        id="precio"
                        v-model="formulario.precio_base"
                        type="number"
                        min="0"
                        step="500"
                        required
                    />
                </div>
            </div>

            <div class="campo">
                <label for="descripcion">Descripción</label>
                <input id="descripcion" v-model="formulario.descripcion" type="text" maxlength="480" />
            </div>

            <div class="fila">
                <button class="boton" type="submit" :disabled="ocupada">
                    {{ ocupada ? 'Guardando...' : 'Guardar' }}
                </button>

                <button
                    v-if="editando !== null"
                    class="boton boton--suave"
                    type="button"
                    @click="limpiar"
                >
                    Cancelar
                </button>
            </div>
        </form>
    </section>
</template>