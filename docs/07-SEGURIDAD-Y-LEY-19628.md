# 07 · Seguridad y Ley 19.628

**Este es el documento que hay que leer antes que nada.**

Esta aplicación maneja datos personales y **datos sensibles de salud**. Lo que sigue
dividido en dos partes: lo que el software hace, y lo que tiene que decidir el salón
con su revisión legal. La segunda parte no se puede responder desde aquí.

---

## Qué se guarda, y de qué tipo es

| Dato | Dónde | Tipo bajo la Ley 19.628 |
|---|---|---|
| Nombre, apellido | `clientas` | Personal |
| RUT | `clientas` | Personal |
| Teléfono, correo | `clientas`, `profesionales`, `usuarios` | Personal |
| Fecha de nacimiento | `clientas` | Personal |
| Alergias, contraindicaciones, condiciones | `antecedentes_salud` | **Sensible** |
| Historial de citas y cobros | `citas`, `ventas` | Personal |
| Quién abrió qué ficha de salud y cuándo | `accesos_salud` | Personal |

El RUT es un identificador que permite asociar datos. No es un dato menor.

---

## Lo que hace el software

### 1. Los datos de salud están en otra tabla

`antecedentes_salud` está separada de `clientas`, y no es PURISMO: es que no es lo
mismo.

Nombre y teléfono los necesita cualquiera que atienda. Una alergia a tintes o una
contraindicación es dato sensible, y la ley lo trata de otra manera. Mezclarlas
obligaría a dar permiso sobre el teléfono para poder ver la alergia.

Separarlas permite que recepción **agende** sin abrir la salud, y que la pantalla que
la abre quede **auditada**.

### 2. Cada apertura queda anotada

`accesos_salud` registra cada intento de abrir los antecedentes de una clienta, con:

- Quién lo hizo.
- Qué hizo: `ver_antecedentes` o `registrar_antecedente`.
- Si se permitió o se denegó.
- A qué clienta, por qué ruta, desde qué dirección.
- Qué detalle tuvo el intento.

**El denegado también se anota**, y ese es el importante: ahí se ve quién intentó
abrir qué.

Para que eso funcione, el middleware `salud.auditado` va **antes** del
controlador. Si anotara después del permiso, un intento denegado no dejaría rastro,
que es justo el intento que hay que poder revisar.

Un detalle técnico que importa: un intento denegado **revierte** la transacción de la
petición, así que el registro se escribe por una **conexión aparte**, la de
`DB_AUDITORIA_*`. Si se escribiera en la misma transacción, la reversa borraría el
registro del intento. Ver `AuditorDeAcceso`.

### 3. Los tres roles pueden verlos, y es una decisión

Si recepción no puede ver una alergia al agendar, el sistema es **peor que una hoja
de papel**: el aviso existe pero nadie lo lee hasta que la clienta ya está sentada.

Lo que se restringe es quién **decide el nivel**. La severidad y la marca de bloqueo
las define la profesional que atiende o la administración. Recepción registra lo que
la clienta informa; no juzga si eso impide un servicio.

### 4. Un rechazo es 404, no 403

Ver la sección completa en [03](03-MULTITENANCY-Y-ROLES.md). La versión corta: un
403 confirmaría que el recurso existe, y en una ficha de salud eso ya es
información.

### 5. No sale nada del salón

No hay integraciones externas. No se mandan correos, no hay analítica, no se sube
nada a ningún servicio. El único tráfico de salida es hacia el proveedor de
identidad, y es el ingreso.

### 6. No hay caché, no hay historial, no hay referencia

- `Cache-Control: no-store` en nginx y en Caddy, en **toda** respuesta.
- `robots: noindex, nofollow` y `Referrer-Policy: same-origin` en la interfaz.
- El equipo se turna en el mismo puesto: el historial del navegador lo ve el
  siguiente. Por eso no-store no es exagerado.
- El registro de acceso de Caddy guarda método, ruta, estado y tiempo. **No guarda
  cuerpos ni cookies.**

---

## Lo que NO hace, y hay que decirlo

- **No verifica el registro profesional** de quien atiende contra ninguna fuente
  oficial. No tiene dónde consultarla. Guarda lo que el salón declara y quién lo
  declaró.
- **No comprueba la espera entre procesos químicos.** Ver abajo.
- **No hay cifrado de la base.** PostgreSQL cifra en tránsito si se le dice
  (`DB_SSLMODE=require`). El cifrado en reposo depende de la infraestructura donde
  vive el volumen, y eso se decide fuera de la aplicación.
- **No hay dosfactor propio.** Viene de Authentik.
- **No hay límites de intento de ingreso.** No hay contraseñas, así que no hay
  ataques de fuerza bruta contra esta aplicación: los maneja el proveedor de
  identidad.
- **No hay auditoría de cambios de negocio.** Hay `updated_at` en cada tabla, pero
  no hay bitácora de quién cambió qué. La auditoría que sí existe es la de accesos a
  la salud, que es donde la ley la pide.
- **No borra.** Clientas, profesionales, servicios y productos se desactivan; los
  antecedentes se cierran. Ver más abajo.

---

## La regla química: pendiente, y declarada

Es verdad que un tinte y un retoque de color no se pueden hacer el mismo día sobre el
mismo pelo, y que hay procesos que exigen espera entre uno y otro.

Lo que **no se pudo verificar**:

- Cuántos días exige cada combinación de procesos.
- Si el tiempo depende de la técnica, del fabricante o de la concentración del
  producto.
- Si la regla es la misma para decoloración, tinte, matiz y keratinato.

Por eso:

- **No hay columna de días** en `antecedentes_salud`.
- `PELUQUERIA_DIAS_ENTRE_PROCESOS` **nace vacía**.
- `RevisorDeAntecedentes::puedeComprobarEsperaEntreProcesos()` devuelve `false`.
- El panel, la agenda y la ficha de clienta lo **dicen en pantalla**.

No es una funcionalidad pendiente escondida en una lista de tareas: es una decisión.
Un número inventado en este lugar puede dañar el pelo de una clienta real, y
`docs/02` lo explica junto al código.

---

## Lo que tiene que decidir el salón

Estas decisiones **no son del software** y por eso no hay un valor por omisión que las
resuelva.

### Los plazos de conservación

```env
PELUQUERIA_RETENCION_ANTECEDENTES_DIAS=
PELUQUERIA_RETENCION_BITACORA_DIAS=
```

Vacías. La aplicación **no purga nada por su cuenta** y no hay ninguna tarea
programada que borre nada.

La ley obliga a que la conservación tenga una finalidad declarada, pero **el plazo que
corresponde a una ficha de salón con antecedentes de salud no se pudo verificar**
desde este entorno, y no se eligió uno al azar. Borrar antecedentes porque pasó un
número inventado sería peor que no borrar nunca.

Cuando el salón, con su revisión legal, tenga un plazo escrito, se configura. Hasta
entonces, la decisión documentada es "conservamos hasta que el salón decida".

### Quién accede a qué

La aplicación define tres roles. Lo que **no** define es si el salón necesita un
cuarto, o si recepción de un turno debería ver menos. Eso depende de cómo se organiza
cada salón.

### El aviso al paciente

No hay ninguna funcionalidad de aviso. Si el salón necesita avisarle a una clienta
que se le guardó un antecedente de salud, eso es una decisión y un canal del salón.

### La entrega de datos a un tercero

Si un salón quiere migrar sus fichas a otro proveedor, esa migración es manual y
queda fuera de esta aplicación. No hay exportación masiva.

---

## Nada se borra: por qué

| Objeto | Qué pasa | Por qué |
|---|---|---|
| Clienta | `activo = false` | Sus ventas ya entraron en liquidaciones y cortes |
| Servicio, producto | `activo = false` | Hay citas y ventas con ese precio |
| Cita | estado `anulada` | El registro de la agenda es histórico |
| Venta | estado `anulada` | El corte de caja la cuenta |
| Antecedente | `activo = false` | El aviso deja de aparecer, el registro queda |
| Liquidación | estado `anulada`, con motivo | Dos liquidaciones del mismo período acarrean dos cifras |
| Corte de caja | **no se corrige** | Un corte corregible es un número que se acomoda |

Borrar una fila que ya entró en una liquidación deja números que no cuadran, y quien
los mire después no va a poder explicarlos.

---

## La lista de control antes de atender

- [ ] `php artisan peluqueria:verificar-aislamiento` pasa.
- [ ] `APP_DEBUG=false`.
- [ ] `LOG_LEVEL=info` o más alto.
- [ ] El rol de la aplicación **no** es superusuario ni tiene `BYPASSRLS`.
- [ ] El dominio tiene TLS válido y la cookie de sesión es `Secure`.
- [ ] Los respaldos corren y se **copian fuera del servidor**. Ver [08](08-RESPALDOS.md).
- [ ] Se probó una restauración sobre una base vacía.
- [ ] Los plazos de conservación están decididos por escrito, no improvisados.
- [ ] La política de comisión está declarada. Ver [06](06-PRIMERA-CITA.md).
- [ ] Alguien del salón sabe que la espera entre procesos químicos **no se
      comprueba**.

---

© 2026 Hefesto Studio. Software propietario.