// Configuración de la interfaz de Peluquería (Nuxt 4 con render en el
// servidor).
//
// Las versiones están fijadas por rango mayor, no exacto, a propósito: una
// corrección de seguridad dentro de la rama compatible no debería exigir
// rehacer la imagen. El digesto exacto queda en deploy/web/Dockerfile.

export default defineNuxtConfig({
    // La interfaz se renderiza en el servidor. Motivo concreto: la agenda y la
    // ficha de clienta no deben quedar en el HTML de una pagina publica, y una
    // pantalla que se dibuja en el servidor no depende de que el JavaScript haya
    // cargado para no filtrar nada.
    ssr: true,

    compatibilityDate: '2026-10-18',

    devtools: { enabled: false },

    runtimeConfig: {
        // URL interna de la API de Laravel. Solo la usa el servidor de Nuxt:
        // nunca viaja al navegador.
        apiBase: process.env.NUXT_API_BASE || 'http://api:8000',

        public: {
            // Dias hacia atras que se ofrecen como periodo por omision en la
            // pantalla de comisiones. Es una ayuda de navegacion.
            diasComision: Number(process.env.NUXT_DIAS_COMISION || 15),
        },
    },

    css: ['~/assets/css/main.css'],

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'Peluquería',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                // La aplicación no se indexa: no es un sitio público.
                { name: 'robots', content: 'noindex, nofollow' },
                { name: 'referrer', content: 'same-origin' },
            ],
        },
    },

    nitro: {
        // El BFF reenvía la cookie de sesión de Laravel. Por eso la aplicación
        // de Nuxt NO necesita ningun token propio.
        compressPublicAssets: true,
    },

    routeRules: {
        // Nada que dependa de una sesión se cachea en un proxy.
        '/**': { headers: { 'cache-control': 'no-store' } },
        '/entrar': { ssr: true },
    },
})