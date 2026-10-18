<script setup lang="ts">
/**
 * Marco de la aplicación: cabecera, navegación y pie.
 *
 * LO QUE LA NAVEGACIÓN MUESTRA Y LO QUE NO
 * ---------------------------------------
 * Los enlaces se filtran por capacidad, para que recepción no vea la pantalla de
 * liquidaciones. Eso NO es seguridad: si alguien escribe la dirección a mano, la
 * API lo rechaza con 404. Ver docs/03.
 */
import type { Capacidades } from '~/tipos/api'

const { usuario } = useSesion()

const ruta = useRoute()

interface Enlace {
    a: string
    texto: string
    /** Se muestra solo si la persona tiene esta capacidad. */
    requiere?: keyof Capacidades
}

const enlaces: Enlace[] = [
    { a: '/', texto: 'Panel' },
    { a: '/agenda', texto: 'Agenda' },
    { a: '/clientas', texto: 'Clientas' },
    { a: '/servicios', texto: 'Servicios', requiere: 'gestionar_servicios' },
    { a: '/productos', texto: 'Productos', requiere: 'gestionar_productos' },
    { a: '/profesionales', texto: 'Profesionales', requiere: 'gestionar_profesionales' },
    { a: '/comisiones', texto: 'Comisiones' },
    { a: '/corte-caja', texto: 'Corte de caja', requiere: 'cortar_caja' },
    { a: '/reportes', texto: 'Reportes', requiere: 'ver_reportes' },
]

const capacidades = computed(() => usuario.value?.capacidades ?? null)

const visibles = computed(() =>
    enlaces.filter((enlace) => !enlace.requiere || capacidades.value?.[enlace.requiere] === true),
)

function estaActivo(destino: string): boolean {
    return destino === '/' ? ruta.path === '/' : ruta.path.startsWith(destino)
}
</script>

<template>
    <div class="aplicacion">
        <header class="cabecera">
            <NuxtLink to="/" class="cabecera__marca">
                <span class="cabecera__nombre">{{ usuario?.salon?.nombre ?? 'Peluquería' }}</span>
                <span class="cabecera__subtitulo">Agenda y comisiones</span>
            </NuxtLink>

            <nav class="navegacion">
                <NuxtLink
                    v-for="enlace in visibles"
                    :key="enlace.a"
                    :to="enlace.a"
                    :class="{ activo: estaActivo(enlace.a) }"
                >
                    {{ enlace.texto }}
                </NuxtLink>
            </nav>

            <div class="cabecera__sesion">
                <span v-if="usuario">
                    {{ usuario.nombre }} · {{ usuario.rol_etiqueta }}
                </span>
                <form method="post" action="/api/salir">
                    <button type="submit" class="boton boton--suave">Salir</button>
                </form>
            </div>
        </header>

        <main class="contenido">
            <slot />
        </main>

        <footer class="pie">
            Peluquería, software propio de Hefesto Studio.
            La espera mínima entre dos procesos químicos sobre el mismo pelo
            <strong>no se comprueba automáticamente</strong>: ese dato no está
            verificado y el sistema no lo inventa.
        </footer>
    </div>
</template>