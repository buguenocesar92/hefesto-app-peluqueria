# 03 · Multitenancy y roles

El aislamiento entre salones lo impone PostgreSQL, no la aplicación. Y un permiso
que se rechaza responde **404**, no 403.

---

## El aislamiento, en cuatro piezas

### 1. La columna

Toda tabla de negocio tiene `tenant_id uuid` con clave foránea a `salones`. La
migración `2026_10_18_000020` verifica, tabla por tabla, que la columna exista
antes de proteger nada:

```php
if ($fila === null || ! $fila->tiene_columna) {
    throw new RuntimeException('La tabla public.' . $tabla . ' no tiene columna tenant_id ...');
}
```

Sin esa comprobación, un error de tipeo en la lista dejaría una tabla de datos de
salud sin protección y la migración pasaría igual.

### 2. La política

```sql
CREATE POLICY politica_aislamiento_salon ON <tabla>
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`WITH CHECK` no es opcional: sin él se podría insertar una fila declarando otro
salón. La lectura la ocultaría y nadie la vería nunca, que es peor que ver un
error.

### 3. `FORCE ROW LEVEL SECURITY`

```sql
ALTER TABLE <tabla> ENABLE ROW LEVEL SECURITY;
ALTER TABLE <tabla> FORCE ROW LEVEL SECURITY;
```

Sin `FORCE`, las políticas no aplican al dueño de la tabla. En desarrollo el rol
de la aplicación suele ser el mismo que creó las tablas, así que sin eso el
aislamiento sería decorativo justo donde se prueba.

### 4. `app_tenant_id()` falla cerrado

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
...
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION 'Falta el contexto de salon (app.tenant_id).'
            USING ERRCODE = 'insufficient_privilege';
    END IF;
```

Podría devolver `NULL` y filtrar todo en silencio. Se eligió que **falle ruidoso**:
un registro vacío en pantalla es un error que se descubre a mitad de mes, y una
excepción en el log dice exactamente qué operación se saltó el middleware.

### Y el contexto se fija con alcance de transacción

`ContextoSalon::ejecutarAislado()` usa `set_config('app.tenant_id', ..., true)`.
El tercer parámetro es lo que la hace de alcance **transaccional**. Un `SET` de
sesión sobrevive al commit, y el worker de colas reutiliza la conexión: con alcance
de sesión, una tarea leería datos de otro salón. Por eso el supervisor corre el
worker con `stopasgroup` y `killasgroup`, para que muera y vuelva.

---

## Las dos tablas sin RLS, y cómo se compensa

### `salones`

Se consulta **antes** de que exista sesión: es la lista del selector de ingreso. No
puede llevar RLS porque la política compararía `tenant_id` contra un contexto que
todavía no existe.

La compensación es la vista:

```sql
CREATE VIEW vw_salones_publicas AS
    SELECT slug, nombre, activo FROM salones WHERE activo = true;
```

**Tres columnas. Ni una más.** Antes del ingreso no hay motivo para que el
navegador vea el RUT, la dirección ni el teléfono de un salón.

Y la vista **se usa de verdad**, en tres lugares:

| Dónde | Para qué |
|---|---|
| `AuthController::salones()` | La lista del selector de ingreso |
| `AuthController::entrar()` | Validar el slug antes de redirigir a Authentik |
| `ProveedorOidcAuthentik::usuarioDesdeClaims()` | Ubicar el salón del grupo OIDC |

Ese último punto es el que importa: si alguien le agrega una columna a la vista, el
ingreso empieza a exponer ese dato, y
`php artisan peluqueria:verificar-aislamiento` lo detecta comprobando que la vista
tenga exactamente tres columnas.

### `sesiones`

La tabla de sesiones es de Laravel, se escribe antes de que exista el salón en la
petición y se lee en cada autenticación. Llevarle RLS la dejaría inutilizable. No
contiene datos de negocio: contiene el identificador de la sesión y una carga
serializada mínima.

---

## El requisito que se olvida

**El rol de la aplicación no puede ser superusuario ni tener `BYPASSRLS`.** Con
superusuario, PostgreSQL ignora las políticas por completo, y todo lo de arriba
sería teatro.

El `compose` crea el usuario de la aplicación con `POSTGRES_USER`, que no es
superusuario. Si alguien cambia la configuración para conectar con `postgres`, el
aislamiento desaparece en silencio. Por eso el comando de verificación empieza por
ahí, antes de mirar ninguna tabla:

```bash
docker compose -f deploy/docker-compose.prod.yml exec api \
    php artisan peluqueria:verificar-aislamiento
```

Qué comprueba, en orden:

1. Que el rol actual **no sea superusuario** y **no tenga `BYPASSRLS`**. Es lo
   primero porque es el modo de falla más silencioso que hay: con superusuario,
   todo lo demás pasaría y el aislamiento no existiría.
2. Que las dieciséis tablas existan, tengan RLS activada **y forzada**, y tengan su
   política `politica_aislamiento_salon`.
3. Que `vw_salones_publicas` tenga **exactamente** `slug`, `nombre` y `activo`.
4. Que el salón A **no** vea clientas del salón B, ni buscándolas por
   identificador.
5. Que no se pueda escribir una fila declarando el salón de otro.
6. Que una consulta **sin contexto** falle, en vez de devolver vacío.

Los puntos 4 a 6 crean dos salones y dos filas temporales, comprueban y las borran
dentro de una transacción que se revierte al final.

---

## Los tres roles

| | Recepción | Profesional | Administración |
|---|:---:|:---:|:---:|
| Ver la agenda | sí | sí | sí |
| Agendar y anular citas | sí | sí | sí |
| Registrar cobros | sí | sí | sí |
| Ficha de identificación | sí | sí | sí |
| Ver antecedentes de salud | sí | sí | sí |
| Registrar antecedentes | sí | sí | sí |
| Marcar severidad o bloqueo de un antecedente | **no** | sí | sí |
| Ver su propia cuenta de comisión | sí | sí | sí |
| Ver la cuenta de otra profesional | **no** | **no** | sí |
| Cambiar porcentajes de comisión | **no** | **no** | sí |
| Liquidar, cerrar o anular liquidaciones | **no** | **no** | sí |
| Catálogos, profesionales, usuarios | **no** | **no** | sí |
| Reportes y bitácora | **no** | **no** | sí |

### Por qué los tres roles ven los antecedentes de salud

Es una decisión consciente, no un descuido. Si recepción no puede ver una alergia al
agendar, el sistema es **peor que una hoja de papel**: el aviso existe pero nadie lo
lee hasta que la clienta ya está sentada. La vista que no se abre es la que no
existe.

Lo que sí se restringe es quién **decide** el nivel: la severidad y la marca de
bloqueo las define la profesional que atiende o la administración. Recepción
registra lo que la clienta informa; no juzga si eso impide un servicio.

Y cada apertura queda anotada en `accesos_salud`, permitida o denegada. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

### Por qué hay capacidades y no solo roles

`CapacidadesDeUsuario` traduce el rol a veinte booleanos. El rol dice cómo se
organiza el salón; la capacidad dice qué necesita hacer la persona. Mañana el rol
puede cambiar sin que cambie lo que se necesita, y esa clase es el único lugar que
hay que tocar.

La interfaz usa esas capacidades **solo para esconder botones**. Ocultar un botón no
es una medida de seguridad: alguien puede llamar a la API directamente. Por eso
**no hay ningún Gate global**: cada ruta vuelve a preguntar a su `Policy`. Un gate
sería un atajo que se puede saltar por un lado.

---

## Por qué un rechazo es 404

Cuando una `Policy` rechaza, la respuesta es `404`, no `403`.

Un 403 confirmaría que ese recurso existe. En una agenda compartida, saber que "la
cita de la 15:00 existe pero no la puedes ver" ya es información. Y en una ficha con
datos de salud importa más: no se le dice a quien preguntó si la clienta existe en
otro salón.

Está en `bootstrap/app.php`:

```php
$exceptions->render(function (AuthorizationException $e, Request $peticion) {
    if ($peticion->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

Y el BFF no lo "corrige": lo devuelve como 404. La interfaz sabe que un 404 puede
significar dos cosas —que no exista o que el permiso lo escondió— y **no debe
intentarlo**. Por eso en la ficha de clienta el bloque de salud, cuando falla,
responde exactamente lo mismo en los dos casos.

### El 422 es para el dominio, no para todo

| Situación | Respuesta |
|---|---|
| Choco de agenda, bloqueo de salud, venta ya cobrada, sin política vigente | `422` con el mensaje que puede leer quien atiende |
| Falta un campo o el RUT no tiene forma | `422` con los errores de validación |
| Sin sesión | `401` |
| La Policy rechazó | `404` |
| Error de infraestructura (no hay contexto de salón, base caída) | `500` |

Solo `DomainException` se traduce a 422. Un `RuntimeException` sigue siendo 500
porque un error de infraestructura tiene que verse como lo que es y no vestirse de
error de negocio: si todo fuera 422, un `app.tenant_id` faltante se vería como "la
hora está ocupada".

---

## El orden de los middleware

```
1. auth            confirma la sesión
2. salud.auditado  anota el intento, permitido o denegado, y corta
3. admin           corta las rutas de administración
4. Policy          permiso fino, con 404 si rechaza
```

`salud.auditado` va **antes** del controlador a propósito: así el intento denegado
también queda anotado. Si anotara después del permiso, un intento denegado no
dejaría rastro, que es justo el intento que hay que poder revisar.

`admin` y la `Policy` son dos capas, no una: el middleware es la puerta y la Policy
es la regla. El middleware corta antes de que la petición llegue al controlador, lo
que además evita resolver relaciones que no se van a mostrar.

---

© 2026 Hefesto Studio. Software propietario.