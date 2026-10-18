# 09 · API

Cincuenta y dos rutas bajo `/api/v1`, más una de salud. Todas cuelgan del grupo
`web` de Laravel: la sesión viaja en cookie y el token CSRF en la cabecera
`X-XSRF-TOKEN`.

La interfaz no las llama directamente. Pasa por el BFF de Nuxt
(`web/server/api/[...ruta].ts`), que reenvía todo lo que llega a `/api/*`. Ver
[01](01-ARQUITECTURA.md).

---

## Convenciones

| Convención | Regla |
|---|---|
| Campos | `snake_case`, en español y con tildes: `nombre_completo`, `rol_etiqueta` |
| Montos | **Enteros**, en pesos. La API no devuelve decimales |
| Porcentajes | Texto con dos decimales: `"35.50"`, no `0.3550` |
| Fechas | ISO 8601 con desplazamiento: `2026-10-18T09:00:00-04:00` |
| Respuestas del dominio | `{ "mensaje": "..." }` con el texto que la persona puede leer |
| Errores de validación | `{ "mensaje": "...", "errores": { "campo": ["..."] } }` |

### Los cuatro códigos

| Código | Cuándo |
|---|---|
| `200` / `201` / `202` | La operación salió. `202` es el cálculo diferido de una liquidación |
| `401` | No hay sesión |
| `404` | **La Policy rechazó, o no existe.** Son indistinguibles, a propósito |
| `422` | El dominio dijo que no: choque de agenda, bloqueo de salud, venta ya cobrada, sin política vigente |
| `500` | Error de infraestructura. No se disfraza de error de negocio |

---

## Autenticación

Sin sesión. Dos de estas rutas **leen `vw_salones_publicas`**, la vista de tres
columnas.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/salud` | Comprueba que PHP responde. Sin sesión, sin salón, sin base |
| `GET` | `/api/v1/auth/salones` | Lista `slug` y `nombre` de los salones activos |
| `GET` | `/api/v1/auth/entrar/{slug}` | Valida el slug contra la vista y devuelve `redirigir_a` |
| `GET` | `/api/v1/auth/callback` | Verifica el `id_token`, abre la sesión y redirige |
| `POST` | `/api/v1/auth/logout` | Cierra la sesión y vuelve a `/entrar` |
| `POST` | `/api/v1/auth/salir` | Lo mismo, para el BFF: responde JSON |
| `GET` | `/api/v1/auth/usuario` | Quién está entró, con su rol y sus capacidades. **Requiere sesión** |

`usuario` devuelve `capacidades`, que la interfaz usa para esconder botones. Eso **no
es seguridad**: alguien puede llamar a la API directamente.

---

## Panel

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/api/v1/panel` | Sesión. El contenido de cada tarjeta depende del rol |

Siempre trae `espera_entre_procesos_comprobada: false`. Ver [02](02-MODELO-DE-DATOS.md).

---

## Agenda

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/agenda` | `ver_agenda` | El día: citas, bloqueos y avisos de salud pegados a cada cita |
| `GET` | `/api/v1/agenda/profesionales` | `ver_agenda` | Las profesionales que se pueden elegir, con la duración efectiva de sus servicios |
| `GET` | `/api/v1/agenda/disponibilidad` | `ver_agenda` | Por qué se puede o no, **sin agendar** |
| `POST` | `/api/v1/citas` | `agendar_citas` | Agenda. El `fecha_fin` lo calcula el servicio |
| `GET` | `/api/v1/citas/{cita}` | `CitaPolicy::view` | Una cita puntual |
| `PATCH` | `/api/v1/citas/{cita}` | `CitaPolicy::update` | La mueve de hora, conservando su duración |
| `DELETE` | `/api/v1/citas/{cita}` | `CitaPolicy::delete` | La anula. No la borra |
| `POST` | `/api/v1/citas/{cita}/realidad` | `CitaPolicy::registrarRealidad` | Cómo terminó de verdad. El retraso **se calcula** |

### `agenda/profesionales` no es el listado de administración

`administracion/profesionales` cuelga del middleware `admin` y trae correo, teléfono y
registro profesional. Agendar solo necesita saber quién atiende, sobre todo la
duración que le hace falta a cada servicio para **esa** profesional.

Poner el listado de agenda detrás del permiso de administrar haría que recepción no
pudiera agendar, que es justamente lo que hace.

### Parámetros de `agenda/disponibilidad`

| Parámetro | Obligatorio | Qué hace |
|---|---|---|
| `profesional_id` | sí | Quién atiende |
| `fecha_inicio` | sí | Cuándo |
| `servicio_id` | no | Para calcular la duración |
| `cita_id` | no | Para excluir la cita que se está moviendo |

---

## Clientas

Ficha de **identificación**. Los antecedentes de salud están aparte.

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/api/v1/clientas` | `gestionar_clientes` |
| `POST` | `/api/v1/clientas` | `ClientaPolicy::create` |
| `GET` | `/api/v1/clientas/{clienta}` | `ClientaPolicy::view`. Trae historial de citas y ventas |
| `PUT` | `/api/v1/clientas/{clienta}` | `ClientaPolicy::update` |
| `DELETE` | `/api/v1/clientas/{clienta}` | `ClientaPolicy::delete`. **Desactiva**, no borra |

El índice trae hasta 500 filas. Es lo que un salón chico maneja, y filtrar en la
interfaz evita un viaje por cada tecla.

---

## Antecedentes de salud

**Todo este bloque va auditado.** El middleware `salud.auditado` anota cada intento,
permitido o denegado, y corta antes de que la acción llegue al controlador.

| Método | Ruta | Permiso | Anotación |
|---|---|---|---|
| `GET` | `/api/v1/clientas/{clienta}/antecedentes` | `ver_antecedentes` | `salud.auditado:ver_antecedentes` |
| `POST` | `/api/v1/clientas/{clienta}/antecedentes` | `registrar_antecedentes` | `salud.auditado:registrar_antecedente` |
| `PUT` | `/api/v1/antecedentes/{antecedente}` | `registrar_antecedentes` | `salud.auditado:registrar_antecedente` |
| `DELETE` | `/api/v1/antecedentes/{antecedente}` | `registrar_antecedentes` | `salud.auditado:registrar_antecedente` |

La respuesta de lectura trae `espera_entre_procesos_comprobada` y `aviso_espera`, para
que la pantalla pueda decirlo en vez de dejar que alguien lo asuma.

Registrar un antecedente no acepta `bloquea_servicios`: esa marca la pone la
profesional o la administración, según el rol de quien escribe. Recepción registra
lo que la clienta informa; no juzga si eso impide un servicio.

---

## Ventas

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/ventas` | `VentaPolicy::viewAny` | Lista con sus líneas |
| `POST` | `/api/v1/ventas` | `VentaPolicy::create` | Cobra una visita |
| `GET` | `/api/v1/ventas/{venta}` | `VentaPolicy::view` | Una venta con sus líneas |
| `DELETE` | `/api/v1/ventas/{venta}` | `VentaPolicy::delete` | La anula. Queda el registro |

El precio unitario se copia a la línea al cobrar. Cambiar el catálogo después no
altera lo que se cobró.

---

## Cortes de caja

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| `GET` | `/api/v1/cortes` | `CorteCajaPolicy::viewAny` | Los cortes de un día, de todas las profesionales |
| `GET` | `/api/v1/cortes/{profesional}` | `CorteCajaPolicy::view` | El corte de una, con las ventas que cuenta |
| `POST` | `/api/v1/cortes/{profesional}/cerrar` | `CorteCajaPolicy::update` | Cierra con lo declarado |

Mientras el corte está abierto, el esperado se recalcula: la caja está abierta y
todavía puede entrar una venta.

---

## Comisiones

### Ver la cuenta propia

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/api/v1/comisiones/previsualizar` | Cualquiera con sesión, **solo su propia cuenta** |
| `GET` | `/api/v1/comisiones/liquidaciones/{liquidacion}` | `LiquidacionComisionPolicy::view` |

`previsualizar` recibe `profesional_id`, `desde` y `hasta`, y devuelve el desglose
**sin guardar nada**. Es lo que el dueño mira antes de decidir y lo que la
profesional revisa antes de firmar.

El acceso se resuelve en `ComisionController::comprobarAccesoAProfesional`:
administración ve todas, cada profesional ve la suya, nadie más, y la respuesta es
`404` para no confirmar que la profesional existe.

### Administrar comisiones

Todas con middleware `admin`.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/comisiones/politicas` | Las políticas vigentes. Si viene vacío, hay que declarar una |
| `POST` | `/api/v1/comisiones/politicas` | Declara una política |
| `GET` | `/api/v1/comisiones` | Lista de liquidaciones, con filtro por profesional y estado |
| `POST` | `/api/v1/comisiones/liquidar` | Encola el cálculo. Responde `202` |
| `POST` | `/api/v1/comisiones/liquidaciones/{id}/cerrar` | Cierra: el desglose queda congelado |
| `POST` | `/api/v1/comisiones/liquidaciones/{id}/anular` | Anula, con `motivo_anulacion` obligatorio |

---

## Administración del salón

Todas con middleware `admin`, que corta **antes** de llegar a la Policy.

| Método | Ruta |
|---|---|
| `GET` | `/api/v1/administracion/profesionales` |
| `POST` | `/api/v1/administracion/profesionales` |
| `GET` | `/api/v1/administracion/servicios` |
| `POST` | `/api/v1/administracion/servicios` |
| `PUT` | `/api/v1/administracion/servicios/{servicio}` |
| `GET` | `/api/v1/administracion/productos` |
| `POST` | `/api/v1/administracion/productos` |
| `PUT` | `/api/v1/administracion/productos/{producto}` |

Guardar un servicio devuelve un mensaje que conviene leer: **las citas ya agendadas
conservan la duración con la que se agendaron**. Subir el precio no reescribe el
pasado.

---

## Reportes

Todas con middleware `admin`. Todas piden `desde` y `hasta` en formato `Y-m-d`.

| Ruta | Qué devuelve |
|---|---|
| `GET /api/v1/reportes/ventas` | Cantidad de ventas y totales por tipo |
| `GET /api/v1/reportes/cortes` | Cortes del período, diferencias y observaciones |
| `GET /api/v1/reportes/retrasos` | Atenciones con retraso, promedio y detalle |
| `GET /api/v1/reportes/comisiones` | Liquidaciones del período con sus porcentajes |
| `GET /api/v1/reportes/auditoria` | Accesos a la salud. Acepta `resultado=denegado` |

**No hay reporte de productividad por profesional, y es a propósito.** Con comisiones
encima, ordenar por venta empuja a vender lo que la clienta no necesita.

---

## Comandos de Artisan

| Comando | Qué hace |
|---|---|
| `php artisan peluqueria:verificar-aislamiento` | Comprueba el aislamiento de verdad. Ver [03](03-MULTITENANCY-Y-ROLES.md) |
| `php artisan peluqueria:crear-salon` | Crea un salón y su administrador. Ver [06](06-PRIMERA-CITA.md) |

---

© 2026 Hefesto Studio. Software propietario.