# 01 · Arquitectura

Tres capas, un dominio y una regla que no se negocia: **el navegador nunca habla
directo con la API**.

---

## El dibujo

```
   navegador
       |
       |  cookie de sesion  (el navegador no tiene ningun token)
       v
  +------------------+
  |  interfaz Nuxt   |  web/
  |  (BFF)           |
  +------------------+
       |
       |  reenvia Cookie + X-XSRF-TOKEN
       v
  +------------------+
  |  API Laravel 13  |  api/
  +------------------+
       |
       |  PDO, con el salon fijado por transaccion
       v
  +------------------+
  |  PostgreSQL      |  RLS sobre 16 tablas
  +------------------+
       ^
       |  solo Caddy publica puertos
  +------------------+
  |  Caddy           |  termina TLS y reparte /api/*
  +------------------+

  Redis: cache, sesiones y colas. Lo usa la API, nadie más.
```

---

## Por qué un BFF y no la API expuesta

El navegador nunca ve una llamada a la API. Va a `/api/*` en el servidor de Nuxt, y
ese servidor reenvía a `/api/v1/*` de Laravel arrastrando la cookie de sesión y el
token CSRF.

Eso compra tres cosas concretas:

1. **No hay ningún token en el código del navegador.** La sesión viaja en una
   cookie `HttpOnly` que JavaScript no puede leer. En una aplicación donde la
   sesión abre una ficha de clienta con antecedentes de salud, eso no es un
   detalle de estilo.
2. **La superficie pública es una sola.** Caddy pública el puerto 443 y nada más.
   PostgreSQL, Redis, la API y la interfaz no tienen puertos publicados: en
   producción se alcanzan solo desde la red interna de Compose.
3. **El origen es uno.** Con dos subdominios habría que hacer una cookie de
   dominio compartido, que es más frágil y más difícil de auditar.

El BFF es genérico a propósito: `web/server/api/[...ruta].ts` reenvía todo lo que
llega a `/api/*`. Con un archivo por ruta habría treinta archivos que repiten la
misma llamada, y la oportunidad de que uno se quede sin escribir es la oportunidad
de que una pantalla falle sin aparecer en ninguna búsqueda.

Lo que el BFF **no** hace: no reescribe rutas, no normaliza cuerpos, no transforma
códigos de estado y no "corrige" errores. Un 404 se devuelve como 404. Un 422 con el
mensaje del dominio se devuelve con ese mensaje.

---

## Cómo entra la sesión

No hay usuarios ni contraseñas en esta aplicación. La identidad la emite
**Authentik**, el proveedor de identidad de Hefesto, por OIDC.

```
/entrar  ->  GET /api/v1/auth/salones         (lee vw_salones_publicas)
        ->  GET /api/v1/auth/entrar/{slug}   (devuelve la URL de Authentik)
        ->  Authentik autentica y devuelve
        ->  GET /api/v1/auth/callback        (verifica el id_token y abre la sesion)
```

Tres decisiones en ese camino:

**El selector de salones lee la vista, no la tabla.** `vw_salones_publicas` expone
solo `slug`, `nombre` y `activo`. Antes del ingreso no hay motivo para que el
navegador vea el RUT, la dirección ni el teléfono de un salón. Y como la vista se
consulta de verdad —en `salones()`, en `entrar()` y en `usuarioDesdeClaims()`— si
alguien le agrega una columna, el selector empieza a exponerla y el comando
`peluqueria:verificar-aislamiento` lo detecta. Ver [03](03-MULTITENANCY-Y-ROLES.md).

**Reimplementar contraseñas sería peor.** Authentik ya resuelve contraseñas, segundo
factor, recuperación y bloqueo de cuentas. Escribirlo acá, sin una revisión de
seguridad, es exactamente el tipo de cosa que no se improvisa.

**`usuarios.password` existe y es siempre `NULL`.** La tabla existe porque el resto
del sistema necesita una persona a la que colgar un rol, un salón y una
`profesional_id`. La columna está para que nadie se equivoque: si alguna vez se
llenara, el comando de verificación debería detectarlo.

---

## Cómo se propaga el salón

Cada petición autenticada viaja dentro de una transacción con el salón ya fijado:

```
consulta
  -> Auth::user()
  -> App\Services\Tenancy\ContextoSalon::ejecutarAislado($usuario, fn () => ...)
       -> DB::beginTransaction()
       -> set_config('app.tenant_id', ..., true)   <- alcance de TRANSACCION
       -> la consulta
       -> DB::commit()
```

Dos detalles que importan:

- **`set_config(..., true)`** es de alcance transaccional. Un `SET` de sesión
  sobrevive al commit, y el worker de colas reutiliza la conexión: con alcance de
  sesión, una tarea leería datos de otro salón.
- **`app_tenant_id()` lanza excepción** si no hay contexto. Podría devolver `NULL`
  y filtrar todo en silencio; se eligió que falle ruidoso, para que el registro diga
  exactamente qué operación se saltó el middleware.

---

## Dónde vive cada decisión

| Decisión | Dónde vive | Por qué ahí |
|---|---|---|
| Qué puede ver esta persona | `App\Policies\*` | Cada ruta pregunta, no hay un gate global que se pueda saltar por un lado |
| Qué puede ver esta persona (interfaz) | `CapacidadesDeUsuario` | Solo para esconder botones. Ocultar un botón no es seguridad |
| Qué porcentaje paga el salón | `politicas_comision` | Es del negocio, no del software |
| Cuánto dura un servicio para una profesional | `servicios_profesional` | Porque dos personas no hacen el trabajo en el mismo tiempo |
| Que dos citas no se pisen | Restricción `EXCLUDE` en PostgreSQL | Es una garantía de la base, no del código |
| Cuánto se demora una atención | `citas.hora_inicio_real` y `hora_fin_real` | Es un hecho que alguien registró, no una estimación |

---

## La cola

Liquidar un mes de comisiones es trabajo de minutos: se recorren las ventas del
período y se calcula línea por línea. Por eso `ComisionController::liquidar()`
encola `LiquidarComisionesJob` y devuelve `202` de inmediato, en vez de dejar al
dueño esperando en un botón.

El Job corre dentro de `ContextoSalon::ejecutarAislado()`, con el salón tomado del
propio profesional. Y el worker de colas corre con `stopasgroup` y `killasgroup`
para que muera y vuelva: si arrastrara el `app.tenant_id` de una transacción, una
tarea leería datos de otro salón.

---

## Lo que hay que mirar primero si algo falla

| Síntoma | Dónde mirar |
|---|---|
| La pantalla queda en blanco con un 404 | Una Policy rechazó, y el 404 es intencional. Ver [03](03-MULTITENANCY-Y-ROLES.md) |
| Todos los POST devuelven 419 | Falta reenviar `X-XSRF-TOKEN`. Ver `web/server/utils/api.ts` |
| "Falta el contexto de salon (app.tenant_id)" | Una consulta corrió fuera de `ContextoSalon::ejecutarAislado()` |
| La comisión da cero | No hay política vigente. No debería: el cálculo falla con un mensaje. Ver [06](06-PRIMERA-CITA.md) |
| El isolation check falla | El rol de la aplicación es superusuario o tiene `BYPASSRLS`. Ver [03](03-MULTITENANCY-Y-ROLES.md) |

---

© 2026 Hefesto Studio. Software propietario.