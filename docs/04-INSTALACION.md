# 04 · Instalación

De cero a funcionando, y qué comprobar en el camino. Cada comando se copió de las
demás aplicaciones de Hefesto y está pensado para un servidor Linux con Docker.

> **Nada de esto se ejecutó.** En el equipo donde se escribió esta aplicación no hay
> PHP, Composer, Docker ni PostgreSQL. Lo que sigue está escrito con cuidado y
> **no está probado**. Ver [10](10-PRUEBAS-Y-ESTADO.md).

---

## Qué hace falta

| Herramienta | Para qué | Nota |
|---|---|---|
| Docker con Compose v2 | Todo lo demás | `docker compose version` |
| Un dominio con A apuntando al servidor | TLS y cookie de sesión | Sin dominio, el ingreso no funciona |
| Correo del operador | Avisos del certificado | No es el correo del cliente final |

PHP, Composer y Node **no** hacen falta en el servidor: se construyen dentro de las
imágenes.

---

## Los seis pasos

### 1. Copiar la configuración

```bash
cd apps/peluqueria
cp api/.env.example api/.env
cp .env.example .env
```

Son **dos archivos distintos** y la confusión entre ellos es el error más común:

- `api/.env`: base de datos, Redis, Authentik, sesión, reglas del salón.
- `.env` (en la raíz): lo que lee el compose para saber el dominio y el correo del
  certificado.

`DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` tienen que **coincidir en los dos**. Si
no coinciden, PostgreSQL crea una base con un nombre y la API busca otra, y el
síntoma es un contenedor sano que responde "no existe la tabla".

### 2. Generar la clave de la aplicación

```bash
docker compose -f deploy/docker-compose.prod.yml run --rm api php artisan key:generate
```

La clave se guarda en `api/.env` con el prefijo `base64:`. El `entrypoint.sh` la
exige: sin ella, el contenedor **no arranca**, a propósito. Un contenedor sano que
responde mal es peor que uno que no responde, porque el balanceador le manda tráfico
igual.

### 3. Levantar

```bash
docker compose -f deploy/docker-compose.prod.yml up -d --build
```

Las migraciones se ejecutan solas al arrancar. Es deliberado: el paso manual que se
olvida produce una tabla faltante en el momento en que una profesional está
atendiendo.

### 4. Crear el primer salón

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
    php artisan peluqueria:crear-salon \
        --slug=salon-demo \
        --nombre="Salón Demo" \
        --nombre-corto="Demo" \
        --administrador="Cuenta de administración" \
        --correo-admin=admin@ejemplo.cl
```

El `--slug` es lo que aparece en el grupo de Authentik: `salon-<slug>`. Ver
[05](05-CONFIGURACION.md).

### 5. Verificar el aislamiento

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
    php artisan peluqueria:verificar-aislamiento
```

**Este paso no es opcional.** Si falla, la instalación no separa un salón de otro y
no hay nada que hacer más que arreglarlo.

### 6. Entrar

Abrir `https://<DOMINIO>/entrar`. La lista de salones sale de la vista pública, que
expone solo `slug` y `nombre`.

---

## Después, en Authentik

La aplicación no crea cuentas: recibe identidades de Authentik. Para que alguien
pueda entrar hace falta:

1. Un **cliente OIDC** de tipo aplicación, con la misma URL de retorno que
   `AUTHENTIK_REDIRECT_URI`.
2. Un **equipo** por salón. El nombre completo se arma con el prefijo de
   `AUTHENTIK_GRUPO_POR_SALON`: si el prefijo es `salon`, el equipo del salón
   `salon-demo` es `salon-salon-demo`. Las tres personas que el comando de arriba no
   creó, pero que sí se declaran en Authentik, son recepción, profesionales y
   administración.
3. Que el correo de la persona **coincida exactamente** con el `--correo-admin` con
   el que se creó el administrador, y con el `usuarios.correo` del salón.

Si el correo no está en el equipo del salón, el ingreso falla con un mensaje que lo
dice: "La cuenta no está habilitada en este salón". No es un error de contraseña.

---

## Desarrollo local

```bash
cp api/.env.example api/.env      # APP_ENV=local, APP_DEBUG=true
docker compose -f deploy/docker-compose.dev.yml up -d --build
```

| Servicio | Dirección |
|---|---|
| Interfaz, por Caddy | <http://localhost:8080> |
| Interfaz, directo | <http://localhost:3000> |
| API, directo | <http://localhost:8000> |
| PostgreSQL | `127.0.0.1:5432` |

Los puertos están **atados a 127.0.0.1** a propósito: una máquina de desarrollo en
una cafetería con la misma red no es un lugar para una base con fichas de ejemplo.

El compose de desarrollo usa **la misma imagen** que producción. La única diferencia
es que se sobrescribe `opcache.validate_timestamps` para que el código recién
escrito se vea sin reiniciar.

### Pruebas

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan test --testsuite=Aislamiento
```

Hay cinco suites declaradas en `phpunit.xml`: `Unit`, `Feature`, `Aislamiento`,
`Agenda` y `Comisiones`. Ver [10](10-PRUEBAS-Y-ESTADO.md) para saber qué se probó y
qué no.

---

## Para probar sin datos reales

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan db:seed --class=SalonDeEjemploSeeder
```

**Es opt-in a propósito.** `DatabaseSeeder` está vacío: un `migrate --seed` en
producción no tiene que dejar un salón de ejemplo con una comisión de ejemplo.

El seeder marca sus porcentajes como **ejemplo**, no como valor recomendado. La
comisión la declara el salón. Ver [06](06-PRIMERA-CITA.md).

---

## Qué revisar antes de atender clientas de verdad

```bash
# 1. El aislamiento
php artisan peluqueria:verificar-aislamiento

# 2. Que el registro esté en info y no en debug
grep -E '^LOG_LEVEL=' api/.env

# 3. Que la aplicación no esté en modo desarrollo
grep -E '^APP_(ENV|DEBUG)=' api/.env

# 4. Que APP_DEBUG sea false
#    Un error con debug puede imprimir la descripción de una atención
#    con información de salud de la clienta.

# 5. Que los plazos de conservación estén decididos, no inventados
grep -E '^PELUQUERIA_RETENCION' api/.env
```

Y después, leer [07](07-SEGURIDAD-Y-LEY-19628.md), que es el documento que hay que
leer antes que nada.

---

© 2026 Hefesto Studio. Software propietario.