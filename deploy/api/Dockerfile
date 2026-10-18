# =============================================================================
# Hefesto Studio — Peluquería: API
# =============================================================================
# Imagen de producción: PHP con php-fpm, nginx y el worker de colas, bajo un
# único supervisor.
#
# POR QUÉ VARIAS INSTANCIAS EN UNA SOLA IMAGEN
# --------------------------------------------
# En un salón el cuello de botella no es PHP: es esperar a que alguien
# responda. Un solo contenedor con php-fpm, nginx, el worker de colas y el
# planificador es más simple de operar, y en una instalación de este tamaño no
# cuesta nada frente a separar en varios contenedores.
#
# Si el worker de colas no corre, las liquidaciones de comisión quedan
# apiladas. En este producto eso significa que nadie se entera de que la
# comisión del mes ya está calculada hasta que alguien abre la pantalla a ver.
#
# VERSIONES
# ---------
# Las etiquetas se copiaron de `apps/clinica-dental/deploy/api/Dockerfile`, que
# las consultó el 2026-10-17. En el equipo donde se escribió esta aplicación no
# hay Docker, así que NO se volvieron a comprobar contra el registro. Verificar
# antes del primer despliegue. Ver docs/10.
#
#   - php:8.4-fpm-alpine3.24
#   - postgres:18.6            (ver deploy/docker-compose.prod.yml)
#   - redis:8.8.3-alpine
#   - caddy:2.11.7-alpine
#   - node:24-alpine3.24       (ver deploy/web/Dockerfile)
# =============================================================================

FROM php:8.4-fpm-alpine3.24 AS base

# `pcntl` lo necesita el worker de colas; `pdo_pgsql`, la conexión a
# PostgreSQL; `bcmath` e `intl`, el cálculo de la comisión con redondeo por
# línea y el formato de fechas. `nginx` y `supervisor` son los dos procesos que
# corren adentro.
RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        bash \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/*

WORKDIR /var/www/html

# Se copian primero los manifiestos para que la capa de dependencias se
# reutilice mientras no cambien las versiones.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY api/ ./

# `--optimize-autoloader` importa más de lo habitual en esta aplicación:
# `app/Http/Requests/RequestsDePeluqueria.php` declara la clase base de
# validación en un archivo aparte de las clases hijas, y sin el classmap
# optimizado la imagen puede quedarse sin encontrarla. El archivo se llama así
# por convención del proyecto, no porque PSR-4 lo exija.
RUN composer install \
        --no-dev \
        --no-scripts \
        --optimize-autoloader \
        --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/zz-peluqueria.ini
COPY deploy/api/docker/nginx.conf /etc/nginx/http.d/peluqueria.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000

# La salud no depende de la sesión ni del salón: solo comprueba que PHP
# responde. Un chequeo que necesita sesión daría falso negativo durante un
# despliegue, y un chequeo que toca una tabla protegida fallaría cuando la
# petición no tiene `app.tenant_id` fijado.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/v1/salud") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]