#!/bin/sh
# =============================================================================
# Restauración de un respaldo de Peluquería.
# =============================================================================
# CUÁNDO SE USA
# -------------
# 1. La base se dañó y hay que volver al último estado bueno.
# 2. Se prueba que el respaldo sirve. Una restauración de prueba, al menos una vez
#    por trimestre.
#
# -----------------------------------------------------------------------------
# ADVERTENCIA, EN SERIO
# -----------------------------------------------------------------------------
# Este script RECONSTRUYE LA BASE COMPLETA. Lo que hay en la base de destino
# desaparece. En un salón, eso son ventas, liquidaciones de comisión y
# antecedentes de salud de personas reales, y no hay forma de recuperarlo si se
# pisa la base equivocada.
#
# Por eso este script:
#
#   - NUNCA hace DROP. Nunca. Ni de la base, ni de una tabla, ni de un esquema.
#     Un `DROP DATABASE` es irrecuperable y no hay versión de este script que lo
#     habilite. Para limpiar usa `TRUNCATE`, que vacía sin borrar el objeto.
#   - NO USA `--clean` en `pg_restore`, porque `--clean` implica `DROP`. Por eso
#     el destino se limpia con `TRUNCATE` antes y el respaldo se carga sin
#     limpiar.
#   - NO SOBRESCRIBE NADA sin `--forzar`. Por omisión falla si ya hay datos.
#   - DEJA EL SERVICIO DETENIDO al terminar. Restaurar con el sistema arriba es la
#     forma de que alguien escriba en la base a mitad de la restauración y que el
#     resultado sea un mosaico de dos épocas.
#   - RECHAZA los respaldos sin marca de completo.
#   - VERIFICA el SHA-256 contra el manifiesto antes de tocar nada.
#
# USO
# ---
#   deploy/respaldos/restauracion.sh ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]
#
# Para una prueba, sobre una base vacía de nombre inequívoco:
#   DB_DATABASE=peluqueria_restauracion_prueba \
#     deploy/respaldos/restauracion.sh \
#     /var/backups/peluqueria/peluqueria-20261018-030000.dump --confirmar
#
# Variables de entorno:
#   DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD
#   DB_DATABASE  base DESTINO (en pruebas suele ser una con nombre de prueba)
#   COMPOSE_FILE ruta al compose, para poder detener el servicio. Opcional.
# =============================================================================

set -eu

ARCHIVO=""
CONFIRMADO=0
FORZAR=0

# -----------------------------------------------------------------------------
# Opciones
# -----------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --confirmar)
            CONFIRMADO=1
            shift
            ;;
        --forzar)
            FORZAR=1
            shift
            ;;
        --base)
            DB_DATABASE="${2:-}"
            shift 2
            ;;
        -h|--help)
            echo "Uso: $0 ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]"
            exit 0
            ;;
        *)
            if [ -z "$ARCHIVO" ]; then
                ARCHIVO="$1"
                shift
            else
                echo "[restauracion] ERROR: argumento inesperado '$1'."
                exit 1
            fi
            ;;
    esac
done

if [ -z "$ARCHIVO" ]; then
    echo "Uso: $0 ARCHIVO.dump --confirmar [--forzar] [--base NOMBRE]"
    echo ""
    echo "La restauración RECONSTRUYE la base completa. Nunca hace DROP."
    exit 1
fi

# -----------------------------------------------------------------------------
# Confirmación
# -----------------------------------------------------------------------------
if [ "$CONFIRMADO" -ne 1 ]; then
    echo "ATENCIÓN: esto va a reemplazar TODO el contenido de la base"
    echo "          ${DB_DATABASE:-sin definir}."
    echo ""
    echo "      archivo: $ARCHIVO"
    echo ""
    echo "Si lo que quiere es una PRUEBA, use una base de prueba:"
    echo "  DB_DATABASE=peluqueria_restauracion_prueba $0 $ARCHIVO --confirmar"
    echo ""
    echo "Para confirmar, repita el comando agregando --confirmar."
    exit 1
fi

# -----------------------------------------------------------------------------
# Validaciones ANTES de tocar la base
# -----------------------------------------------------------------------------
if [ ! -f "$ARCHIVO" ]; then
    echo "[restauracion] ERROR: no existe el archivo $ARCHIVO"
    exit 1
fi

if [ -z "${DB_DATABASE:-}" ]; then
    echo "[restauracion] ERROR: falta DB_DATABASE en el entorno."
    exit 1
fi

# Una base cuyo nombre parezca de producción no se restaura desde un script
# genérico. Si es realmente lo que se quiere, hay que hacerlo a mano y con el
# operador mirando.
case "$DB_DATABASE" in
    peluqueria)
        echo "[restauracion] ERROR: '$DB_DATABASE' es el nombre de la base de la aplicación."
        echo "               Este script no restaura sobre ella: use una base de prueba"
        echo "               (por ejemplo peluqueria_restauracion_prueba) o haga la"
        echo "               restauración a mano, con el servicio detenido."
        exit 1
        ;;
esac

# -----------------------------------------------------------------------------
# MARCA DE COMPLETITUD
# -----------------------------------------------------------------------------
# Esta es la comprobación más importante de todas. `respaldo.sh` escribe el
# archivo `.completo` AL FINAL, cuando ya verificó el respaldo. Si no existe, el
# respaldo se hizo a medias y restaurarlo deja una base incompleta que parece
# entera: el peor resultado posible.
BASE_ARCHIVO="$(basename "$ARCHIVO" .dump)"
MARCA="$(dirname "$ARCHIVO")/$BASE_ARCHIVO.completo"

if [ ! -f "$MARCA" ]; then
    echo "[restauracion] ERROR: este respaldo NO tiene marca de completado."
    echo "               No se restaura: pudo cortarse el respaldo a mitad."
    echo "               Busque uno que tenga el archivo junto al .dump:"
    echo "               $MARCA"
    exit 1
fi

echo "[restauracion] respaldo marcado como completo."

# -----------------------------------------------------------------------------
# Verificación de integridad contra el manifiesto
# -----------------------------------------------------------------------------
MANIFIESTO="$(dirname "$ARCHIVO")/$BASE_ARCHIVO.manifiesto.txt"

if [ -f "$MANIFIESTO" ]; then
    echo "[restauracion] verificando el SHA-256 contra el manifiesto..."

    if ! sha256sum -c --ignore-missing "$MANIFIESTO" >/dev/null 2>&1; then
        echo "[restauracion] ERROR: el archivo no coincide con su manifiesto. No se tocó la base."
        exit 1
    fi

    echo "[restauracion] Suma verificada. El archivo está íntegro."
else
    echo "[restauracion] AVISO: no hay manifiesto junto al respaldo."
    echo "               Sin manifiesto no se puede verificar la integridad."
fi

# -----------------------------------------------------------------------------
# La base de destino tiene que estar VACÍA
# -----------------------------------------------------------------------------
# Sin `--forzar`, no se pisa lo que hay. Es la regla que hace que un error de
# dedo no borre la historia de un salón.
TABLAS="$(PGPASSWORD="${DB_PASSWORD:-}" psql \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME}" \
    --dbname="$DB_DATABASE" \
    --tuples-only \
    --no-align \
    --command="SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" \
    2>/dev/null | tr -d '[:space:]')"

if [ -n "$TABLAS" ] && [ "$TABLAS" != "0" ]; then
    if [ "$FORZAR" -ne 1 ]; then
        echo "[restauracion] ERROR: la base '$DB_DATABASE' ya tiene $TABLAS tablas."
        echo "               Este script NO sobrescribe nada por omisión."
        echo "               Para continuar con una base que ya tiene datos:"
        echo "                 $0 $ARCHIVO --confirmar --forzar"
        echo "               Y sepa lo que hace: lo que hay ahí se pierde."
        exit 1
    fi

    echo "[restauracion] AVISO: --forzar. La base '$DB_DATABASE' tiene $TABLAS tablas."
    echo "               Se van a VACIAR sus tablas antes de cargar el respaldo."
fi

# -----------------------------------------------------------------------------
# EL SERVICIO SE DETIENE ANTES DE RESTAURAR
# -----------------------------------------------------------------------------
# Restaurar con el sistema arriba es la forma de que alguien escriba en la base
# a mitad de la restauración. El resultado es un mosaico de dos épocas, que es
# peor que no tener nada: parece restaurado.
COMPOSE="${COMPOSE_FILE:-deploy/docker-compose.prod.yml}"

if command -v docker >/dev/null 2>&1 && [ -f "$COMPOSE" ]; then
    echo "[restauracion] deteniendo el servicio..."

    if ! docker compose -f "$COMPOSE" stop api web 2>/dev/null; then
        echo "[restauracion] ERROR: no se pudo detener el servicio."
        echo "               Restaurar con el sistema arriba deja la base a medias."
        exit 1
    fi

    echo "[restauracion] servicio detenido."
else
    echo "[restauracion] AVISO: no se encontró 'docker' o el archivo $COMPOSE."
    echo "               ASEGÚRESE de que la API esté detenida antes de continuar."
fi

# Si algo falla después de este punto, el servicio se queda detenido. Es lo
# correcto: se levanta a mano, cuando la persona haya mirado el resultado.
# -----------------------------------------------------------------------------
# VACIAR CON TRUNCATE, NO CON DROP
# -----------------------------------------------------------------------------
# `TRUNCATE ... CASCADE` vacía las tablas sin borrar su definición: siguen
# existiendo, con sus índices, sus restricciones y sus políticas. Es lo que se
# necesita para que el respaldo traiga la base tal como estaba.
#
# Lo que NO hace este script, en ninguna versión y con ninguna opción:
# `DROP DATABASE`, `DROP SCHEMA`, `DROP TABLE`, `dropdb`, y `pg_restore --clean`.
# Si lo que se quiere es empezar de cero, se crea una base nueva vacía con un
# nombre distinto y se restaura ahí. Es más tedioso a propósito.
vaciar() {
    echo "[restauracion] vaciando las tablas de $DB_DATABASE con TRUNCATE..."

    if ! PGPASSWORD="${DB_PASSWORD:-}" psql \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="$DB_DATABASE" \
        --quiet \
        --command="DO \$\$ DECLARE bloque text; BEGIN SELECT 'TRUNCATE TABLE ' || string_agg(format('%I.%I', schemaname, tablename), ', ') || ' CASCADE' FROM pg_tables WHERE schemaname = 'public' INTO bloque; IF bloque IS NOT NULL THEN EXECUTE bloque; END IF; END \$\$;"; then

        echo "[restauracion] ERROR: no se pudieron vaciar las tablas. No se tocó nada más."
        return 1
    fi
}

restaurar() {
    echo "[restauracion] restaurando sobre $DB_DATABASE..."

    if ! PGPASSWORD="${DB_PASSWORD:-}" pg_restore \
            --host="${DB_HOST:-127.0.0.1}" \
            --port="${DB_PORT:-5432}" \
            --username="${DB_USERNAME}" \
            --dbname="$DB_DATABASE" \
            --no-owner \
            --single-transaction \
            --exit-on-error \
            "$ARCHIVO"; then

        echo "[restauracion] ERROR: falló la restauración. Revise el estado de la base."
        echo "               El servicio sigue DETENIDO a propósito."
        return 1
    fi

    echo "[restauracion] listo."
}

# -----------------------------------------------------------------------------
# Comprobaciones DESPUÉS de restaurar
# -----------------------------------------------------------------------------
# No basta con que el archivo entre: hay que comprobar que la base restaurada
# puede operar. Y la primera de esas comprobaciones es el aislamiento, porque sin
# él la base restaurada es inutilizable aunque tenga todos los datos.
comprobar() {
    echo ""
    echo "[restauracion] comprobando el aislamiento entre salones..."

    EXISTEN="$(PGPASSWORD="${DB_PASSWORD:-}" psql \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="$DB_DATABASE" \
        --tuples-only \
        --no-align \
        --command="SELECT count(*) FROM pg_policies WHERE policyname = 'politica_aislamiento_salon'" \
        2>/dev/null | tr -d '[:space:]')"

    if [ -n "$EXISTEN" ] && [ "$EXISTEN" != "0" ]; then
        echo "[restauracion] hay $EXISTEN políticas de aislamiento en la base restaurada."
    else
        echo "[restauracion] AVISO: no se encontraron políticas de aislamiento."
        echo "               NO atienda clientas hasta revisar esto."
        echo "               Ver docs/03 y corra: php artisan peluqueria:verificar-aislamiento"
    fi

    echo ""
    echo "[restauracion] recordatorio: las liquidaciones deben cuadrar con las ventas."
    echo "[restauracion] Revise que los totales de comisión coincidan con el desglose línea"
    echo "[restauracion] por línea, y no solo que las tablas tengan filas."
}

vaciar
restaurar
comprobar

echo ""
echo "[restauracion] EL SERVICIO QUEDA DETENIDO."
echo "[restauracion] Revise el resultado antes de levantar nada:"
echo "               docker compose -f $COMPOSE start api web"
echo ""
echo "[restauracion] Y después, antes de recibir la primera clienta:"
echo "               docker compose -f $COMPOSE exec api php artisan peluqueria:verificar-aislamiento"

exit 0