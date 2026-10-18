<script setup lang="ts">
/**
 * Profesionales del salón.
 *
 * POR QUÉ EL COLOR DE LA AGENDA ESTÁ ACÁ
 * -------------------------------------
 * Es lo único que la AgendaController devuelve de una profesional para el resto
 * del salón, y es lectura abierta dentro del salón: recepción tiene que poder
 * ver quién atiende. Lo que NO es lectura abierta es dar de alta a una
 * profesional o desactivarla: eso cambia el trabajo de otras personas y es
 * decisión de la administración. Ver App\Policies\ProfesionalPolicy.
 *
 * POR QUÉ UNA PROFESIONAL PUEDE TENER SU PROPIA DURACIÓN
 * -----------------------------------------------------
 * Una colorista que tarda 60 minutos en un retoque y una que tarda 45 no pueden
 * compartir la misma fila de agenda. Por eso el puente `servicios_profesional`
 * guarda duración y precio propios, y en `null` manda el catálogo. Ver
 * `Profesional::duracionDe()`.
 *
 * POR QUÉ `registro_verificado` ES UN DATO DEL SALÓN
 * --------------------------------------------------
 * El sistema no verifica registros profesionales contra ninguna fuente oficial:
 * no tiene dónde consultarla y no se inventó un enlace. Lo que hace es dejar
 * registrado quién lo declara y cuándo, que es lo que después se puede auditar.
 */
import type { Profesional, Servicio } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Profesionales · Peluquería' })

const { data: datosProfesionales, refresh } = await useAsyncData('profesionales', () =>
    $fetch<{ datos: Profesional[] }>('/api/administracion/profesionales'),
)

const { data: datosServicios } = await useAsyncData('profesionales-servicios', () =>
    $fetch<{ datos: Servicio[] }>('/api/administracion/servicios'),
)

const profesionales = computed(() => datosProfesionales.value?.datos ?? [])
const servicios = computed(() => datosServicios.value?.datos ?? [])

const formulario = reactive({
    nombre: '',
    especialidad: '',
    silla: '',
    correo: '',
    telefono: '',
    registro_profesional: '',
    registro_verificado: false,
    color_agenda: '#2f6f6f',
    activo: true,
})

/** Servicios que se le asignan al dar de alta, con duración y precio propios. */
const asignaciones = ref<{ servicio_id: string; duracion_minutos: number | null; precio: number | null }[]>(
    [],
)

const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

/**
 * Anota la duración propia de un servicio, o la saca si el campo quedó vacío.
 *
 * Solo se envía lo que la profesional fills de verdad: mandar ceros "porque
 * vino vacío" haría que el catálogo quedara anulado sin que nadie lo decidiera.
 */
function anotarDuracion(servicioId: string, evento: Event): void {
    const valor = (evento.target as HTMLInputElement).value

    upsertAsignacion(servicioId, valor === '' ? null : Number(valor), 'duracion_minutos')
}

/** Igual que la duración, para el precio propio. */
function anotarPrecio(servicioId: string, evento: Event): void {
    const valor = (evento.target as HTMLInputElement).value

    upsertAsignacion(servicioId, valor === '' ? null : Number(valor), 'precio')
}

function upsertAsignacion(
    servicioId: string,
    valor: number | null,
    campo: 'duracion_minutos' | 'precio',
): void {
    const existente = asignaciones.value.find((a) => a.servicio_id === servicioId)

    if (existente === undefined) {
        // Un servicio sin nada propio no se manda: el catálogo ya responde.
        if (valor === null) {
            return
        }

        asignaciones.value = [
            ...asignaciones.value,
            { servicio_id: servicioId, duracion_minutos: null, precio: null, [campo]: valor },
        ]

        return
    }

    existente[campo] = valor

    // Si ya no queda nada propio, la línea sobra: mandar un servicio sin
    // duración ni precio solo agregaría ruido al pedido.
    if (existente.duracion_minutos === null && existente.precio === null) {
        asignaciones.value = asignaciones.value.filter((a) => a.servicio_id !== servicioId)
    }
}

async function guardar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    try {
        await $fetch('/api/administracion/profesionales', {
            method: 'POST',
            body: {
                nombre: formulario.nombre,
                especialidad: formulario.especialidad === '' ? undefined : formulario.especialidad,
                silla: formulario.silla === '' ? undefined : formulario.silla,
                correo: formulario.correo === '' ? undefined : formulario.correo,
                telefono: formulario.telefono === '' ? undefined : formulario.telefono,
                registro_profesional:
                    formulario.registro_profesional === '' ? undefined : formulario.registro_profesional,
                registro_verificado: formulario.registro_verificado,
                color_agenda: formulario.color_agenda,
                activo: formulario.activo,
                servicios: asignaciones.value,
            },
        })

        mensaje.value = 'Profesional guardada.'

        formulario.nombre = ''
        formulario.especialidad = ''
        formulario.silla = ''
        formulario.correo = ''
        formulario.telefono = ''
        formulario.registro_profesional = ''
        formulario.registro_verificado = false
        asignaciones.value = []

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

    return 'No se pudo guardar la profesional.'
}
</script>

<template>
    <section>
        <h1>Profesionales</h1>

        <p class="ayuda">
            Al dar de alta a una profesional se le pueden asignar servicios con duración y
            precio propios. Si se dejan vacíos, manda el catálogo del salón.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <div class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Especialidad</th>
                        <th>Silla</th>
                        <th>Contacto</th>
                        <th>Registro</th>
                        <th class="numero">Servicios</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="profesional in profesionales" :key="profesional.id">
                        <td>
                            <span
                                class="etiqueta"
                                :style="`background: ${profesional.color_agenda}; color: #ffffff`"
                            >
                                {{ profesional.nombre }}
                            </span>
                        </td>
                        <td>{{ profesional.especialidad ?? '—' }}</td>
                        <td>{{ profesional.silla ?? '—' }}</td>
                        <td>{{ profesional.telefono ?? profesional.correo ?? '—' }}</td>
                        <td>
                            <template v-if="profesional.registro_profesional">
                                {{ profesional.registro_profesional }}
                                <span
                                    class="etiqueta"
                                    :class="
                                        profesional.registro_verificado
                                            ? 'etiqueta--exito'
                                            : 'etiqueta--alerta'
                                    "
                                >
                                    {{
                                        profesional.registro_verificado
                                            ? 'Declarado verificado'
                                            : 'Sin verificar'
                                    }}
                                </span>
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="numero">{{ profesional.servicios?.length ?? 0 }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="profesional.activo ? 'etiqueta--exito' : 'etiqueta--alerta'"
                            >
                                {{ profesional.activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2>Nueva profesional</h2>

        <form class="formulario" @submit.prevent="guardar">
            <div class="fila">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" v-model="formulario.nombre" type="text" required maxlength="160" />
                </div>

                <div class="campo">
                    <label for="especialidad">Especialidad</label>
                    <input
                        id="especialidad"
                        v-model="formulario.especialidad"
                        type="text"
                        maxlength="120"
                    />
                </div>

                <div class="campo">
                    <label for="silla">Silla</label>
                    <input id="silla" v-model="formulario.silla" type="text" maxlength="24" />
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="correo">Correo</label>
                    <input id="correo" v-model="formulario.correo" type="email" maxlength="160" />
                </div>

                <div class="campo">
                    <label for="telefono">Teléfono</label>
                    <input id="telefono" v-model="formulario.telefono" type="tel" maxlength="32" />
                </div>

                <div class="campo">
                    <label for="color">Color en la agenda</label>
                    <input id="color" v-model="formulario.color_agenda" type="color" />
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="registro">Registro profesional</label>
                    <input
                        id="registro"
                        v-model="formulario.registro_profesional"
                        type="text"
                        maxlength="64"
                    />
                </div>

                <div class="campo">
                    <label for="verificado">Verificación</label>
                    <select id="verificado" v-model="formulario.registro_verificado">
                        <option :value="false">Sin verificar</option>
                        <option :value="true">Declarado verificado por el salón</option>
                    </select>
                </div>

                <div class="campo">
                    <label for="activo">Estado</label>
                    <select id="activo" v-model="formulario.activo">
                        <option :value="true">Activa</option>
                        <option :value="false">Inactiva</option>
                    </select>
                </div>
            </div>

            <h3>Servicios que hace</h3>

            <p v-if="servicios.length === 0" class="vacio">
                Todavía no hay servicios en el catálogo. Créalos primero en la pantalla de
                servicios.
            </p>

            <div v-else class="tabla-envoltura">
                <table>
                    <thead>
                        <tr>
                            <th>Servicio</th>
                            <th class="numero">Duración propia (min)</th>
                            <th class="numero">Precio propio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="servicio in servicios" :key="servicio.id">
                            <td>{{ servicio.nombre }}</td>
                            <td class="numero">
                                <input
                                    type="number"
                                    min="5"
                                    max="600"
                                    step="5"
                                    placeholder="Del catálogo"
                                    @input="anotarDuracion(servicio.id, $event)"
                                />
                            </td>
                            <td class="numero">
                                <input
                                    type="number"
                                    min="0"
                                    step="500"
                                    placeholder="Del catálogo"
                                    @input="anotarPrecio(servicio.id, $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="ayuda">
                Dejar la duración o el precio vacíos hace que mande el catálogo. Las citas ya
                agendadas conservan la duración con la que se agendaron.
            </p>

            <button class="boton" type="submit" :disabled="ocupada">
                {{ ocupada ? 'Guardando...' : 'Guardar profesional' }}
            </button>
        </form>
    </section>
</template>