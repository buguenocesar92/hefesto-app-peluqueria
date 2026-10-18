# 05 · Configuración

Cada variable, qué hace y qué pasa si está mal. La fuente de verdad es
`api/.env.example` y `api/config/peluqueria.php`; este documento explica por qué
cada una existe.

---

## Las tres que no se tocan sin pensar

### `APP_DEBUG`

En producción tiene que ser `false`. Un error con debug puede imprimir el cuerpo de
la petición, y el cuerpo de una petición a esta API puede incluir la descripción de
una atención con información de salud de la clienta. No es una excepción bonita con
un número de línea: es una ficha de salud en una pantalla de error.

### `LOG_LEVEL`

`debug` deja el cuerpo de las peticiones en el registro. En producción tiene que ser
`info` o más alto.

### `PELUQUERIA_DIAS_ENTRE_PROCESOS`

**Nace vacía, y tiene que seguir vacía hasta que alguien verifique la regla
química.** Ver más abajo.

---

## Aplicación

| Variable | Por omisión | Qué hace si está mal |
|---|---|---|
| `APP_ENV` | `local` | En `production` se compilan los cachés en el arranque |
| `APP_DEBUG` | `false` | Ver arriba |
| `APP_TIMEZONE` | `America/Santiago` | Las horas de la agenda se muestran corrida |
| `APP_URL` | `http://localhost:8000` | Afecta la URL de retorno que se manda a Authentik |
| `APP_KEY` | vacío | El contenedor **no arranca**. Generar con `key:generate` |
| `LOG_LEVEL` | `info` | Ver arriba |

---

## Base de datos

PostgreSQL, obligatorio. La Row Level Security no existe en otro motor, y el
aislamiento entre salones depende de ella.

| Variable | Por omisión | Nota |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | No cambiar |
| `DB_HOST` | `postgres` | Nombre del servicio en Compose |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | `peluqueria` | Tiene que coincidir con el `.env` de la raíz |
| `DB_USERNAME` | `peluqueria` | **No puede ser superusuario ni tener `BYPASSRLS`** |
| `DB_PASSWORD` | vacío | Sin esto el `compose` se niega a levantar |
| `DB_SSLMODE` | `prefer` | `require` si la base queda fuera de la instalación |

### `DB_AUDITORIA_*`

La conexión de `accesos_salud` es **aparte a propósito**: la petición a la que se le
deniega el acceso termina revertida, y el registro del intento tiene que sobrevivir
a esa reversa. Ver `AuditorDeAcceso`.

En la instalación de una sola máquina apunta a la misma base con el mismo usuario.
Puede apuntar a otra base, incluso a otra máquina, y eso es lo que corresponde si el
cliente tiene una política de separación de registros.

---

## Redis

`REDIS_CLIENT=predis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`. Redis guarda
la caché, las colas y el estado de `queue:work`. **No guarda datos de negocio**: si
se pierde, se pierden tareas encoladas y caché, no ventas ni citas.

`SESSION_DRIVER=database`: la sesión va a PostgreSQL, no a Redis. Es deliberado: una
sesión que se pierde en un reinicio de Redis sería una sesión abierta en un puesto
de atención.

`SESSION_EXPIRACION_MINUTOS=120`: en un salón conviene poco. El equipo se turna en el
mismo puesto, y una sesión abierta mucho rato es una sesión abierta a la ficha de la
clienta que vino antes.

---

## Authentik

| Variable | Qué es |
|---|---|
| `AUTHENTIK_ISSUER` | URL pública del emisor, **sin barra final** |
| `AUTHENTIK_CLIENT_ID` | Cliente OIDC **exclusivo** de esta aplicación |
| `AUTHENTIK_CLIENT_SECRET` | Secreto. NUNCA en un archivo versionado |
| `AUTHENTIK_REDIRECT_URI` | Debe coincidir **exactamente** con la URL de retorno en Authentik |
| `AUTHENTIK_ALGORITMO` | `RS256` |
| `AUTHENTIK_AUDIENCE` | Audiencia esperada. Vacía = el identificador del cliente |
| `AUTHENTIK_LEEWAY` | Tolerancia de reloj, en segundos |
| `AUTHENTIK_CACHE_METADATA` | Caché del descubrimiento, en segundos |
| `AUTHENTIK_CACHE_JWKS` | Caché de las claves públicas, en segundos |

Que el cliente sea exclusivo de esta aplicación es una decisión concreta: así se
puede revocar el acceso a Peluquería sin tocar las demás aplicaciones de Hefesto.

### Los equipos

| Variable | Por omisión |
|---|---|
| `AUTHENTIK_GRUPO_POR_SALON` | `salon` |
| `AUTHENTIK_EQUIPO_RECEPCION` | `Recepcion de Peluqueria` |
| `AUTHENTIK_EQUIPO_PROFESIONAL` | `Profesionales de Peluqueria` |
| `AUTHENTIK_EQUIPO_ADMINISTRADOR` | `Administradores de Peluqueria` |

El grupo completo de un salón es `<prefijo>-<slug>`. Con el prefijo `salon` y el
salón `demo`, es `salon-demo`.

**Las capacidades no salen de Authentik.** Allá solo se declara a qué equipo
pertenece la persona; el rol de esta aplicación vive en `usuarios.rol`. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

---

## Agenda

| Variable | Por omisión | Qué hace |
|---|---|---|
| `PELUQUERIA_DURACION_MAXIMA_MINUTOS` | `600` | Red de seguridad para un dato raro del catálogo |
| `PELUQUERIA_ANTICIPACION_MAXIMA_DIAS` | `180` | Cuánto se puede agendar hacia adelante |

**La duración de una cita NO sale de acá.** Sale del servicio para esa profesional.
Ver [02](02-MODELO-DE-DATOS.md).

---

## Comisiones

| Variable | Por omisión | Qué hace |
|---|---|---|
| `PELUQUERIA_PERIODO_COMISION_DIAS` | `15` | Días hacia atrás que se ofrecen como período en la pantalla |
| `PELUQUERIA_PERMITIR_RECALCULAR` | `false` | Si una liquidación **cerrada** se puede volver a calcular |

`PELUQUERIA_PERIODO_COMISION_DIAS` es una ayuda de navegación, no una regla de
negocio: el período lo elige quien liquida.

`PELUQUERIA_PERMITIR_RECALCULAR` en `false` es lo correcto. En `true`, dos
liquidaciones del mismo período acarrean dos cifras y el dueño no sabe cuál es la
buena. Para corregir, se anula con motivo y se rehace.

**No hay porcentaje de comisión en la configuración, y esa es la decisión.** El
porcentaje lo declara el salón en `politicas_comision`. Si esta configuración
tuviera un valor por omisión, la primera liquidación de una instalación nueva saldría
con un número que nadie acordó. Ver [06](06-PRIMERA-CITA.md).

---

## Conservación: las dos que nacen vacías

```env
PELUQUERIA_RETENCION_ANTECEDENTES_DIAS=
PELUQUERIA_RETENCION_BITACORA_DIAS=
PELUQUERIA_AVISAR_ANTES_DE_PURGAR=false
```

Vacía significa **"no hay plazo configurado"**. La aplicación **no purga nada por su
cuenta** y no hay ninguna tarea programada que borre nada.

La Ley 19.628 obliga a que la conservación tenga una finalidad declarada, pero **no se
pudo verificar** en el entorno donde se escribió esta aplicación el plazo que
corresponde a una ficha de salón con antecedentes de salud. Escribir un número ahí
sería inventar un plazo legal.

Borrar antecedentes de salud porque pasó un número inventado sería peor que no borrar
nunca. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## `PELUQUERIA_DIAS_ENTRE_PROCESOS`: vacía, y por qué

Es verdad que hay procesos que se repelen entre sí: un tinte y un retoque de color no
se pueden hacer el mismo día sobre el mismo pelo.

Lo que **no se pudo verificar**:

- Cuántos días exige cada combinación de procesos.
- Si el tiempo depende de la técnica, del fabricante o de la concentración del
  producto.
- Si la regla es la misma para decoloración, tinte, matiz y keratinato.

Por eso la variable nace vacía, **no hay columna de días en la base**,
`RevisorDeAntecedentes::puedeComprobarEsperaEntreProcesos()` devuelve `false` y la
interfaz lo declara en el panel, en la agenda y en la ficha de clienta.

Un número inventado en este lugar puede dañar el pelo de una clienta real. Si el
salón tiene la regla verificada y documentada, se configura acá y el sistema pasa a
avisar. Hasta entonces, el hueco es la respuesta honesta.

---

## Interfaz

| Variable | Por omisión | Qué hace |
|---|---|---|
| `NUXT_API_BASE` | `http://api:8000` | URL **interna** de la API. Solo la usa el servidor de Nuxt |
| `NUXT_DIAS_COMISION` | `15` | Días hacia atrás en el selector de período |

`NUXT_API_BASE` nunca viaja al navegador. Si se pone una URL pública ahí, la
interfación deja de usar el BFF y el aislamiento de origen se pierde.

---

© 2026 Hefesto Studio. Software propietario.