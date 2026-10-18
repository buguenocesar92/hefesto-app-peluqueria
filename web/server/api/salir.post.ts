/**
 * Cierre de sesión.
 *
 * El POST va a la API (que invalida la sesión y regenera el token CSRF), y
 * después el navegador vuelve a la pantalla de ingreso.
 *
 * Va en un archivo aparte y no en el proxy general porque la API responde con una
 * redirección al ingreso, y lo que interesa acá es devolver al navegador a la
 * pantalla correcta, no el cuerpo de esa redirección.
 */
export default defineEventHandler(async (evento) => {
    try {
        await llamarApi(evento, '/auth/logout', { metodo: 'POST' })
    } catch {
        // Si la sesión ya estaba vencida, el objetivo de este archivo se cumple
        // igual: el navegador va al ingreso. No se avisa con un error porque a la
        // persona no le sirve de nada saber que su sesión ya había caducado.
    }

    return sendRedirect(evento, '/entrar')
})