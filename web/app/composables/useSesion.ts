/**
 * Composable de sesión.
 *
 * SOLO LECTURA Y NAVEGACIÓN
 * -------------------------
 * Los permisos vienen de la API. Este archivo esconde botones, no protege datos.
 * Que un botón no aparezca no impide que alguien llame a la API directamente, y
 * por eso cada ruta del servidor vuelve a preguntar al servidor. Ver docs/03.
 */

import type { UsuarioSesion } from '~/tipos/api'

export function useSesion() {
    const usuario = useState<UsuarioSesion | null>('usuario', () => null)

    /**
     * Pide los datos de la persona al servidor.
     *
     * Un 401 o un 404 se tratan igual: sin sesión no hay nada que mostrar, y la
     * pantalla de ingreso es el único lugar donde tiene sentido estar.
     */
    async function cargar(): Promise<UsuarioSesion | null> {
        try {
            usuario.value = await $fetch<UsuarioSesion>('/api/auth/usuario')
        } catch {
            usuario.value = null
        }

        return usuario.value
    }

    async function cerrarSesion(): Promise<void> {
        usuario.value = null

        await navigateTo('/entrar')
    }

    const capacidades = computed(() => usuario.value?.capacidades ?? null)

    /**
     * Cada permiso por separado.
     *
     * Se nombran por lo que hacen y no por el rol, porque el rol es cómo se
     * organiza el salón y la capacidad es lo que la persona puede hacer. Mañana
     * el rol puede cambiar sin que cambie lo que se necesita.
     */
    const puedeVerAgenda = computed(() => capacidades.value?.ver_agenda === true)
    const puedeAgendar = computed(() => capacidades.value?.agendar_citas === true)
    const puedeCobrar = computed(() => capacidades.value?.cobrar_ventas === true)
    const puedeVerAntecedentes = computed(() => capacidades.value?.ver_antecedentes === true)
    const puedeCortarCaja = computed(() => capacidades.value?.cortar_caja === true)
    const puedeVerComisiones = computed(() => capacidades.value?.ver_comisiones === true)
    const puedeLiquidar = computed(() => capacidades.value?.liquidar_comisiones === true)
    const puedeAdministrar = computed(() => capacidades.value?.administra_peluqueria === true)
    const puedeVerAuditoria = computed(() => capacidades.value?.ver_auditoria === true)

    return {
        usuario,
        cargar,
        cerrarSesion,
        puedeVerAgenda,
        puedeAgendar,
        puedeCobrar,
        puedeVerAntecedentes,
        puedeCortarCaja,
        puedeVerComisiones,
        puedeLiquidar,
        puedeAdministrar,
        puedeVerAuditoria,
    }
}