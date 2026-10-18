#!/bin/sh
# =============================================================================
# Entrada de la API de Peluquería
# =============================================================================
# Deja la aplicación en estado ejecutable: permisos, caché de configuración y
# migraciones.
#
# POR QUÉ LAS MIGRACIONES CORREN AL ARRANQUE
# ------------------------------------------
# En un salón hay una sola base y el despliegue lo hace el operador o el
# estudio. Correr las migraciones aquí evita el paso manual que siempre se
# olvida, y sobre todo evita el otro problema: que alguien olvide correrlas y
# descubra la tabla faltante cuando una profesional esté atendiendo.
#
# El bloqueo de PostgreSQL hace que dos réplicas arrancando a la vez no corran
# migraciones en paralelo.
#
# POR QUÉ ESTE SCRIPT ABORTA EN VEZ DE AVANZAR
# --------------------------------------------
# Si falta el `.env` o la `APP_KEY`, el contenedor NO arranca con la aplicación
# a medio hacer. Es deliberado: un contenedor sano que responde mal es peor que
# uno que no responde, porque el balanceador le manda tráfico igual.
# =============================================================================

set -eu

cd /var/www/html

if [ ! -f .env ]; then
    echo "Peluquería: falta el archivo .env. Copie api/.env.example y complete los valores." >&2
    exit 1
fi

# El esqueleto de Laravel guarda la APP_KEY con el prefijo `base64:`. Si no está,
# las cookies de sesión no se pueden cifrar ni verificar.
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "Peluquería: APP_KEY vacío. Ejecute 'php artisan key:generate' y guarde el resultado." >&2
    exit 1
fi

# Aviso, no error: la base puede estar todavía levantándose en un despliegue en
# frío. `migrate` es lo que va a fallar con un mensaje útil si no está.
echo "Peluquería: preparando permisos..."
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

echo "Peluquería: limpiando cachés..."
php artisan config:clear --quiet
php artisan route:clear --quiet
php artisan view:clear --quiet

echo "Peluquería: ejecutando migraciones..."
php artisan migrate --force --no-interaction

# El aislamiento entre salones se instala con las migraciones. Si una
# instalación recién actualizada se levanta con esto en falso, hay que decirlo.
echo "Peluquería: comprobando que PostgreSQL acepta las conexiones..."
php -r '
$host = getenv("DB_HOST") ?: "127.0.0.1";
$pdo = new PDO(
    sprintf("pgsql:host=%s;port=%s;dbname=%s", $host, getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "postgres"),
    getenv("DB_USERNAME") ?: "postgres",
    getenv("DB_PASSWORD") ?: "",
    [PDO::ATTR_TIMEOUT => 5]
);
' >/dev/null 2>&1 || echo "Peluquería: no se pudo abrir una conexión propia a PostgreSQL (puede ser normal: la RLS bloquea las consultas sin salón)."

if [ "${APP_ENV:-production}" = "production" ]; then
    echo "Peluquería: compilando cachés..."
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet
fi

exec "$@"