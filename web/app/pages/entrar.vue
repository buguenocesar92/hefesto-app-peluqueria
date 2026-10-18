<script setup lang="ts">
/**
 * Pantalla de ingreso.
 *
 * LA LISTA DE SALONES SALE DEL ENDPOINT PÚBLICO
 * ----------------------------------------------
 * Que devuelve SOLO `slug` y `nombre`. No llegan RUT, dirección, correo ni
 * teléfono: antes del ingreso no hay motivo para que el navegador vea nada de
 * eso. La API lee esa lista de la vista `vw_salones_publicas`, que existe para
 * exactamente esto. Ver docs/03.
 *
 * NO HAY FORMULARIO DE USUARIO NI CONTRASEÑA
 * -----------------------------------------
 * No por descuido: la identidad la emite Authentik, que ya resuelve contraseñas,
 * segundo factor, recuperación y bloqueo de cuentas. Reimplementar eso acá sería
 * peor en todos los sentidos y, sin una revisión de seguridad, exactamente el
 * tipo de cosa que no se improvisa. Ver docs/01 y docs/07.
 *
 * Lo único que hay que hacer es apretar un botón.
 */
import type { SalonPublico } from '~/tipos/api'

useHead({ title: 'Ingresar · Peluquería' })

const { usuario } = useSesion()

// Si la sesión ya está abierta, entrar es ruido.
if (usuario.value !== null) {
    await navigateTo('/')
}

const ruta = useRoute()

const error = ref<string | null>(
    typeof ruta.query.error === 'string' ? ruta.query.error : null,
)

const seleccionada = ref<string>('')
const enviando = ref(false)

const { data } = await useFetch<{ salones: SalonPublico[] }>('/api/auth/salones')

const salones = computed(() => data.value?.salones ?? [])

onMounted(() => {
    if (salones.value.length > 0) {
        seleccionada.value = salones.value[0]?.slug ?? ''
    }
})

async function ingresar(): Promise<void> {
    if (!seleccionada.value) {
        return
    }

    enviando.value = true

    // El servidor de Nuxt pide a la API la URL de autorización y redirige. El
    // navegador nunca ve el secreto del cliente.
    await navigateTo(`/api/entrar/${encodeURIComponent(seleccionada.value)}`, { external: true })
}
</script>

<template>
    <div class="entrada">
        <div class="tarjeta-entrada">
            <h1>Peluquería</h1>

            <p class="ayuda">
                Sistema de agenda, comisiones por profesional y corte de caja para salones
                de belleza, peluquerías y centros de estética.
            </p>

            <p v-if="error" class="error">{{ error }}</p>

            <template v-if="salones.length > 0">
                <label class="campo" for="salon">
                    <span>Salón</span>
                    <select id="salon" v-model="seleccionada" class="selector">
                        <option v-for="salon in salones" :key="salon.slug" :value="salon.slug">
                            {{ salon.nombre }}
                        </option>
                    </select>
                </label>

                <button
                    class="boton"
                    type="button"
                    :disabled="!seleccionada || enviando"
                    @click="ingresar"
                >
                    {{ enviando ? 'Redirigiendo...' : 'Ingresar' }}
                </button>
            </template>

            <p v-else class="vacio">
                No hay ningún salón activo en esta instalación.
            </p>

            <p class="ayuda" style="margin-top: 24px;">
                El ingreso lo hace el proveedor de identidad de Hefesto. Esta aplicación
                no guarda contraseñas.
            </p>

            <p class="ayuda">
                Si tiene una cuenta y le rebota el ingreso, suele ser que en el proveedor de
                identidad no está en el equipo del salón. Consulte al administrador.
            </p>
        </div>
    </div>
</template>