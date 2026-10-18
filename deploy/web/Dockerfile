# =============================================================================
# Hefesto Studio — Peluquería: imagen de la interfaz
# =============================================================================
# Node 24 sobre Alpine. La versión cumple los motores que Nuxt 4.6.2 declara:
# node ^22.22.3 || ^24.15.0 || >=26.
#
# Compila en la etapa de construcción y se lleva solo el resultado al runtime,
# como en las demás aplicaciones de Hefesto: la imagen final no tiene ni el
# código fuente ni las dependencias de desarrollo.
#
# La etiqueta `node:24-alpine3.24` se copió de
# `apps/clinica-dental/deploy/web/Dockerfile`. En el equipo donde se escribió
# esta aplicación no hay Docker y no se comprobó contra el registro. Ver docs/10.
# =============================================================================

FROM node:24-alpine3.24 AS construccion

WORKDIR /app

# Dependencias primero: la capa se reutiliza mientras el lock no cambie.
COPY web/package.json web/package-lock.json* ./

RUN npm ci --no-audit --no-fund

COPY web/ ./

# `NUXT_API_BASE` se usa en la construcción para resolver el BFF. El valor real
# llega como variable de entorno en ejecución: acá solo hace falta un marcador
# válido para que la compilación no falle.
ENV NUXT_API_BASE=http://api:8000

RUN npm run build

# -----------------------------------------------------------------------------
FROM node:24-alpine3.24 AS runtime

WORKDIR /app

ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000

COPY --from=construccion /app/.output ./.output

EXPOSE 3000

# El chequeo va contra `/entrar` y no contra la API: si la API no está arriba
# todavía, la interfaz sigue sirviendo la pantalla de ingreso y eso es correcto.
# Un chequeo contra la API daría falso negativo durante un despliegue en frío.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/entrar').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", ".output/server/index.mjs"]