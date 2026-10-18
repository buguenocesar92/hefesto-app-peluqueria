/**
 * Inicio de sesión: lleva al proveedor de identidad.
 *
 * LA LISTA DE SALONES VIENE DEL ENDPOINT PÚBLICO
 * ----------------------------------------------
 * Que devuelve SOLO `slug` y `nombre`. No se reciben RUT, dirección, correo ni
 * teléfono: antes del ingreso no hay motivo para que el navegador vea esos
 * datos. La API lee esa lista de la vista `vw_salones_publicas`, que existe
 * exactamente para eso. Ver docs/03.
 */
export default defineEventHandler(async (evento) => {
    const slug = getRouterParam(evento, 'slug')

    if (!slug) {
        throw createError({
            statusCode: 404,
            statusMessage: 'No se indicó a qué salón se entra.',
        })
    }

    const respuesta = await llamarApi<{ redirigir_a: string }>(
        evento,
        `/auth/entrar/${encodeURIComponent(slug)}`,
    )

    return sendRedirect(evento, respuesta.redirigir_a)
})