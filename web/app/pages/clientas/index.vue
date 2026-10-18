<script setup lang="ts">
/**
 * Listado de clientas y alta rápida.
 *
 * POR QUÉ NO SE VE NADA DE SALUD ACÁ
 * ----------------------------------
 * Este listado es de IDENTIFICACIÓN: nombre, RUT, teléfono. Los antecedentes de
 * salud se abren en la ficha de cada clienta, en otra pantalla, con otro permiso
 * y con auditoría. Si esta tabla dijera "alergia a la amoníaco", dar acceso a la
 * agenda sería dar acceso a la salud. Ver docs/03 y docs/07.
 *
 * POR QUÉ NO SE BORRA UNA CLIENTA
 * -------------------------------
 * Sus ventas ya entraron en liquidaciones y en cortes de caja. Por eso el botón
 * de la ficha desactiva y no elimina. Ver App\Models\Clienta.
 */
import type { Clienta } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Clientas · Peluquería' })

const { puedeAgendar } = useSesion()

const { data, refresh } = await useAsyncData('clientas', () =>
    $fetch<{ datos: Clienta[] }>('/api/clientas'),
)

const clientas = computed(() => data.value?.datos ?? [])

const busqueda = ref('')
const soloActivas = ref(true)

/**
 * El filtro es de la pantalla, no del servidor.
 *
 * El listado trae hasta 500 filas, que es lo que un salón chico maneja. Filtrar
 * acá evita un viaje por cada tecla, y la lista completa igual está en el
 * servidor: nadie ve menos de lo que el servidor permitiría ver.
 */
const filtradas = computed(() => {
    const texto = busqueda.value.trim().toLowerCase()

    return clientas.value.filter((clienta) => {
        if (soloActivas.value && !clienta.activo) {
            return false
        }

        if (texto === '') {
            return true
        }

        return `${clienta.nombre_completo} ${clienta.rut ?? ''} ${clienta.telefono ?? ''}`
            .toLowerCase()
            .includes(texto)
    })
})

const formulario = reactive({
    nombre: '',
    apellido: '',
    rut: '',
    telefono: '',
    correo: '',
})

const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)
const mostrando = ref(false)

async function guardar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch('/api/clientas', {
            method: 'POST',
            body: {
                nombre: formulario.nombre,
                apellido: formulario.apellido === '' ? undefined : formulario.apellido,
                rut: formulario.rut === '' ? undefined : formulario.rut,
                telefono: formulario.telefono === '' ? undefined : formulario.telefono,
                correo: formulario.correo === '' ? undefined : formulario.correo,
            },
        })

        mensaje.value = 'Ficha guardada.'

        formulario.nombre = ''
        formulario.apellido = ''
        formulario.rut = ''
        formulario.telefono = ''
        formulario.correo = ''
        mostrando.value = false

        await refresh()
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

    return 'No se pudo guardar la ficha.'
}
</script>

<template>
    <section>
        <h1>Clientas</h1>

        <div class="fila">
            <div class="campo">
                <label for="busqueda">Buscar</label>
                <input
                    id="busqueda"
                    v-model="busqueda"
                    type="search"
                    placeholder="Nombre, RUT o teléfono"
                />
            </div>

            <div class="campo">
                <label for="activas">Mostrar</label>
                <select id="activas" v-model="soloActivas">
                    <option :value="true">Solo activas</option>
                    <option :value="false">Todas</option>
                </select>
            </div>

            <p class="ayuda" style="flex: 2">{{ filtradas.length }} de {{ clientas.length }}</p>
        </div>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <p v-if="filtradas.length === 0" class="vacio">No hay clientas con ese criterio.</p>

        <div v-else class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>RUT</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Estado</th>
                        <th v-if="puedeAgendar">Ficha</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="clienta in filtradas" :key="clienta.id">
                        <td>{{ clienta.nombre_completo }}</td>
                        <td>{{ clienta.rut ?? '—' }}</td>
                        <td>{{ clienta.telefono ?? '—' }}</td>
                        <td>{{ clienta.correo ?? '—' }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="clienta.activo ? 'etiqueta--exito' : 'etiqueta--alerta'"
                            >
                                {{ clienta.activo ? 'Activa' : 'Desactivada' }}
                            </span>
                        </td>
                        <td>
                            <NuxtLink :to="`/clientas/${clienta.id}`">Abrir</NuxtLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2>Nueva clienta</h2>

        <button class="boton boton--suave" type="button" @click="mostrando = !mostrando">
            {{ mostrando ? 'Ocultar el formulario' : 'Agregar una clienta' }}
        </button>

        <form v-if="mostrando" class="formulario" style="margin-top: 16px" @submit.prevent="guardar">
            <div class="fila">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" v-model="formulario.nombre" type="text" required maxlength="160" />
                </div>

                <div class="campo">
                    <label for="apellido">Apellido</label>
                    <input id="apellido" v-model="formulario.apellido" type="text" maxlength="160" />
                </div>

                <div class="campo">
                    <label for="rut">RUT</label>
                    <input id="rut" v-model="formulario.rut" type="text" placeholder="12345678-9" />
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="telefono">Teléfono</label>
                    <input id="telefono" v-model="formulario.telefono" type="tel" maxlength="32" />
                </div>

                <div class="campo">
                    <label for="correo">Correo</label>
                    <input id="correo" v-model="formulario.correo" type="email" maxlength="160" />
                </div>
            </div>

            <button class="boton" type="submit" :disabled="ocupada">
                {{ ocupada ? 'Guardando...' : 'Guardar ficha' }}
            </button>
        </form>

        <p class="ayuda">
            El RUT y el teléfono son datos personales. Ver la política de resguardo en docs/07.
        </p>
    </section>
</template>