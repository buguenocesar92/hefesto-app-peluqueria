<script setup lang="ts">
/**
 * Panel de inicio: qué hay que mirar hoy.
 *
 * POR QUÉ SON TARJETAS Y NO NÚMEROS SUELTOS
 * ------------------------------------------
 * Un "hay 214 clientas" no dice nada. "Hay 3 avisos de salud con cita hoy" sí.
 * Por eso cada tarjeta trae `requiere_revision` y se pinta sola: lo que hay que
 * mirar queda a la vista sin que nadie tenga que buscarlo.
 *
 * LO QUE NO SE MUESTRA ACÁ
 * -------------------------
 * Ningún dato de salud en detalle. El panel cuenta; la ficha se abre en su
 * pantalla, con su permiso y su auditoría. Ver docs/03.
 */
import type { Panel, TarjetaPanel } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Panel · Peluquería' })

const { usuario } = useSesion()

const { data: panel, error } = await useAsyncData('panel', () => $fetch<Panel>('/api/panel'))

/**
 * Un bloque del panel, con su color.
 *
 * Tres colores y ninguno más: bien, revisar, urgente. Un tablero con seis colores
 * se lee peor que uno con tres.
 */
function clase(bloque: TarjetaPanel | undefined): string {
    if (bloque === undefined) {
        return 'tarjeta tarjeta--ok'
    }

    return bloque.requiere_revision ? 'tarjeta tarjeta--riesgo' : 'tarjeta tarjeta--ok'
}
</script>

<template>
    <section>
        <h1>Panel</h1>

        <div v-if="error" class="error">
            No se pudo cargar el panel. {{ error.statusMessage ?? '' }}
        </div>

        <template v-if="panel">
            <p class="ayuda">
                {{ panel.salon?.nombre }} · {{ panel.fecha }} · {{ usuario?.rol_etiqueta }} ·
                {{ panel.profesionales_activas }} profesionales activas
            </p>

            <div class="rejillas">
                <div :class="clase(panel.tarjetas.citas_hoy)">
                    <p class="tarjeta__titulo">Citas hoy</p>
                    <p class="tarjeta__cifra">{{ panel.tarjetas.citas_hoy.total }}</p>
                    <p class="tarjeta__nota">{{ panel.tarjetas.citas_hoy.mensaje }}</p>
                </div>

                <div :class="clase(panel.tarjetas.citas_con_retraso)">
                    <p class="tarjeta__titulo">Atenciones con retraso</p>
                    <p class="tarjeta__cifra">{{ panel.tarjetas.citas_con_retraso.total }}</p>
                    <p class="tarjeta__nota">{{ panel.tarjetas.citas_con_retraso.mensaje }}</p>
                </div>

                <div :class="clase(panel.tarjetas.avisos_de_salud)">
                    <p class="tarjeta__titulo">Avisos de salud con cita</p>
                    <p class="tarjeta__cifra">{{ panel.tarjetas.avisos_de_salud.total }}</p>
                    <p class="tarjeta__nota">{{ panel.tarjetas.avisos_de_salud.mensaje }}</p>
                </div>

                <div :class="clase(panel.tarjetas.cortes_con_diferencia)">
                    <p class="tarjeta__titulo">Cajas con diferencia</p>
                    <p class="tarjeta__cifra">{{ panel.tarjetas.cortes_con_diferencia.total }}</p>
                    <p class="tarjeta__nota">{{ panel.tarjetas.cortes_con_diferencia.mensaje }}</p>
                </div>

                <div :class="clase(panel.tarjetas.accesos_denegados)">
                    <p class="tarjeta__titulo">Accesos denegados</p>
                    <p class="tarjeta__cifra">{{ panel.tarjetas.accesos_denegados.total }}</p>
                    <p class="tarjeta__nota">{{ panel.tarjetas.accesos_denegados.mensaje }}</p>
                </div>
            </div>

            <!--
                El aviso de que la espera entre procesos NO se comprueba va en la
                pantalla que todos ven, no en una nota al pie de la documentación.
                Un dato que no está verificado tiene que estar donde se decide,
                no donde nadie lo busca.
            -->
            <p v-if="!panel.espera_entre_procesos_comprobada" class="aviso aviso--alerta">
                La espera mínima entre dos procesos químicos sobre el mismo pelo
                <strong>no se comprueba automáticamente</strong>. Ese dato no está
                verificado y el sistema no lo inventa. Revise la ficha a mano antes de
                aplicar un segundo proceso.
            </p>

            <div class="rejillas">
                <NuxtLink to="/agenda" class="tarjeta">
                    <p class="tarjeta__titulo">Agenda</p>
                    <p class="tarjeta__nota">
                        Ver el día, agendar y registrar cómo terminó cada atención.
                    </p>
                </NuxtLink>

                <NuxtLink to="/comisiones" class="tarjeta">
                    <p class="tarjeta__titulo">Comisiones</p>
                    <p class="tarjeta__nota">
                        Ver el desglose de lo que corresponde a cada profesional.
                    </p>
                </NuxtLink>

                <NuxtLink to="/corte-caja" class="tarjeta">
                    <p class="tarjeta__titulo">Corte de caja</p>
                    <p class="tarjeta__nota">Cerrar el día y revisar las diferencias.</p>
                </NuxtLink>
            </div>
        </template>
    </section>
</template>