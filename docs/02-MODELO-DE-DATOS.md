# 02 · Modelo de datos

Dieciocho tablas y una vista. Cada regla importante está en la base o en el
servicio que la usa, y debajo queda escrito **qué quedó sin definir**.

---

## El mapa

```
salones ──┬── profesionales ──┬── citas ──┬── bloqueos_agenda
          │                  │          │
          │                  └── servicios_profesional ── servicios
          │                  │
          ├── clientas ──────┴── antecedentes_salud ── accesos_salud
          │
          ├── ventas ── venta_items ──┬── servicios
          │                          └── productos
          │
          ├── politicas_comision ── liquidaciones_comision ── liquidacion_comision_items
          │
          ├── cortes_caja
          │
          └── usuarios
vw_salones_publicas (sobre salones)
```

Dieciséis de esas tablas tienen `tenant_id` y Row Level Security. Las dos que no lo
tienen son `salones` y `sesiones`, y están justificadas en
[03 · Multitenancy y roles](03-MULTITENANCY-Y-ROLES.md).

---

## Las reglas que están en la base, y no en el código

### Dos citas de una misma profesional no se solapan

```sql
ALTER TABLE citas
ADD CONSTRAINT citas_sin_solapamiento
EXCLUDE USING gist (
    tenant_id WITH =,
    profesional_id WITH =,
    tstzrange(fecha_inicio, fecha_fin, '[)') WITH &&
);
```

No importa si el controlador se equivocó: la base no lo permite. Requiere la
extensión `btree_gist`.

El intervalo es **semiabierto**, `[inicio, fin)`. Una cita que empieza exactamente
cuando termina otra no se pisa, que es como funciona un turno de verdad: la
siguiente clienta se sienta a la hora en que la anterior se levanta.

Exigir `estado NOT IN ('anulada')` dentro de la restricción sería más elegante,
pero el `EXCLUDE` no acepta esa forma de filtrar filas. La anulación la aplica
`AgendaController::destroy()`, que pone el estado en `anulada`; de ahí sale del
solapamiento. La garantía dura está en la base; la de "las anuladas no estorban", en
el controlador. Por eso anular es una ruta con su propio permiso
(`CitaPolicy::delete`) y no un campo editable desde la pantalla de agendar.

### Un corte de caja por profesional y por día

```php
$tabla->unique(['profesional_id', 'fecha']);
```

Es lo que impide cerrar dos veces el mismo día y que la segunda cifra pise la
primera. En un salón, donde cada quien tiene su caja, el corte es por profesional.

### El subtotal de una venta no se inventa

`venta_items` tiene una comprobación que obliga a que `subtotal = cantidad *
precio_unitario`, y una que obliga a que el tipo de la línea sea `servicio` o
`producto`. El total de la venta sale de sumar las líneas.

### Una cita guarda su duración, y una venta su precio

`citas.duracion_minutos` y `venta_items.precio_unitario` son copias del momento en
que se agendó o se cobró. Subir el precio de un corte mañana **no reescribe** lo
que se cobró la semana pasada. Es lo que hace que un corte de caja cierre con
números que resistan una revisión tres meses después.

### `diferencia` se guarda, no se deriva

`cortes_caja.diferencia` se guarda con el valor con que se cerró. Si se derivara al
leer, un corte podría cambiar de número después sin que nadie se entere.

`ventas_incluidas` congela cuántas ventas entraron en ese corte. Si mañana se anula
una venta de hoy, este corte sigue diciendo cuántas contó, y la diferencia se
refleja en el corte siguiente. Un corte que cambia solo no sirve para nada.

---

## La agenda: por qué el tiempo de servicio

La hora de término de una cita **no la manda la clienta ni un bloque fijo**. La
manda el servicio, y el servicio puede tener una duración distinta según quién lo
haga:

```
servicios.duracion_minutos  = la duración del catálogo
servicios_profesional.duracion_minutos = la duración de esa profesional (puede ser NULL)
```

`Profesional::duracionDe()` y `precioDe()` aplican la misma regla: **si la
profesional tiene un valor propio, manda ese; si no, manda el catálogo**. Por eso
una colorista que tarda 60 minutos en un retoque y otra que tarda 45 no pueden
compartir la misma fila de agenda.

### El bloqueo no vive en la misma tabla

Un bloqueo (colación, descanso) y una cita son cosas distintas, y **PostgreSQL no
puede escribir una exclusión entre dos tablas**. Por eso hay dos mecanismos:

| Qué | Dónde | Garantía |
|---|---|---|
| Cita contra cita | Restricción `EXCLUDE` en `citas` | La base no lo permite |
| Cita contra bloqueo | `VerificadorDeDisponibilidad` | El servicio, en una transacción |
| Bloqueo contra bloqueo | `VerificadorDeDisponibilidad` | El servicio, en una transacción |

La primera es una garantía real del motor. Las otras dos las sostiene el código, y
por eso tienen prueba dedicada en
`tests/Feature/Agenda/BloqueosNoSePisanTest.php`.

### El retraso se registra, no se estima

```sql
hora_inicio_real  timestamptz null
hora_fin_real     timestamptz null
minutos_retraso   integer      null
```

`RegistrarRealidadCitaRequest` **no acepta `minutos_retraso`**: lo calcula
`Cita::registrarRealidad()`. Si el cliente lo mandara, podría reportar cero retraso
en un día con veinte minutos de retraso, que es justamente el número que el dueño
necesita ver bien.

---

## La comisión auditable

Es el núcleo del producto y está repartido en cuatro piezas.

### 1. La política: qué paga el salón

```
politicas_comision
  profesional_id      null = política general del salón
  porcentaje_servicios
  porcentaje_productos
  incluye_productos
  base_calculo        servicio | producto | ambos
  vigente_desde / vigente_hasta
```

**No hay porcentaje por omisión en ninguna parte.** Sin política vigente,
`PoliticaComision::vigenteEn()` lanza una excepción y el cálculo falla con un
mensaje. Podría devolver cero, y ese cero sería un número que nadie negoció.

### 2. El cálculo: línea por línea

`CalculadoraDeComision` es una clase pura, sin base de datos, con `bcmath`. Devuelve
un `DesgloseComision` con **una línea por `venta_item`**:

```
venta_id | venta_item_id | tipo | base_aplicada | porcentaje_aplicado | comision_calculada
```

Por qué por línea y no sobre el total: porque una comisión que solo muestra el total
obliga a creerlo. Con la línea, la persona que la recibe puede rehacer la cuenta con
una calculadora y obtener el mismo número.

**El redondeo es por línea**, al peso entero, medio hacia arriba. Si se redondeara al
final, el total no sería la suma de lo que se ve en la tabla, y alguien lo
comprobaría. Redondeando por línea, cada línea se verifica sola y el total cuadra
solo.

### 3. La liquidación: el registro inmutable

```
liquidaciones_comision      -> periodos, bases, totales, estado
liquidacion_comision_items  -> una fila por línea del cálculo
```

La liquidación **copia los porcentajes con que se calculó**. Si la política cambia
mañana, la liquidación de ayer sigue explicando la cifra de ayer. Sin esa copia, un
cambio de porcentaje volvería ilegible el mes anterior.

Además copia `base_calculo`, `incluye_productos`, los totales por tipo y el total.
Liquidar es diferido a un Job; cerrar congela el desglose; anular exige motivo.

### 4. La caja: lo que se declaró y lo que debería haber

```
total_esperado   lo que dicen las ventas cobradas de esa profesional
total_declarado  lo que dice la persona
diferencia       la resta, guardada al cerrar
```

En un salón las dos cosas pueden no coincidir, y esa diferencia es información, no
un error que el sistema deba tapar.

---

## Salud: por qué está en otra tabla

`antecedentes_salud` está separada de `clientas`, y no es PURISMO: es que no es lo
mismo.

- Nombre y teléfono son datos personales: los necesita cualquiera que atienda.
- Una alergia a tintes o una contraindicación es dato **sensible** bajo la Ley
  19.628, y la ley trata esos datos de otra manera.

Mezclarlas obligaría a dar permiso sobre el teléfono para poder ver la alergia.
Separarlas permite que recepción agende sin abrir esto, y que la pantalla que lo
abre quede auditada.

`servicios_afectados` es `jsonb` con identificadores de servicio, **no** una tabla
intermedia. En `null` significa "afecta a todos", que es el caso prudente: una
alergia sin servicio declarado tiene que avisar igual.

`accesos_salud` registra cada apertura, **permitida o denegada**. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Lo que quedó sin definir

Estas tres cosas están declaradas como pendientes, en el código, en la configuración
y en la interfaz. **Ninguna tiene un número inventado.**

### 1. La espera entre procesos químicos

Es verdad que un tinte y un retoque de color no se pueden hacer el mismo día sobre
el mismo pelo, y que hay procesos que exigen espera entre uno y otro.

Lo que **no se pudo verificar** en el entorno donde se escribió esto:

- Cuántos días exige cada combinación de procesos.
- Si el tiempo depende de la técnica, del fabricante o de la concentración del
  producto.
- Si la regla es la misma para decoloración, tinte, matiz y keratinato.

Por eso **no hay columna de días** y **no hay cálculo**.
`RevisorDeAntecedentes::puedeComprobarEsperaEntreProcesos()` devuelve `false`, la
interfaz lo muestra en el panel, en la agenda y en la ficha, y la variable
`PELUQUERIA_DIAS_ENTRE_PROCESOS` nace vacía.

Un número inventado en este lugar puede dañar el pelo de una clienta real.

### 2. Los plazos de conservación

`PELUQUERIA_RETENCION_ANTECEDENTES_DIAS` y `PELUQUERIA_RETENCION_BITACORA_DIAS`
nacen vacías, y **la aplicación no purga nada por su cuenta**. No hay tarea
programada que borre nada. Borrar antecedentes porque pasó un número inventado sería
peor que no borrar nunca. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

### 3. La verificación del registro profesional

`profesionales.registro_profesional` y `registro_verificado` guardan lo que el
salón declara. El sistema **no verifica** ese registro contra ninguna fuente
oficial: no tiene dónde consultarla. Lo que sí hace es dejar registrado quién lo
declara, que es lo que después se puede auditar.

---

## Lo que deliberadamente NO está

- **Un ranking de productividad por profesional.** Con comisiones encima, ordenar por
  venta empuja a vender lo que la clienta no necesita. Hay reportes de ventas,
  cortes, retrasos, comisiones y auditoría. No hay un tablero que ponga a una
  persona contra otra.
- **Una tabla de "favoritos" o preferencias.** Nadie pidió leer lo que una clienta
  pidió la vez pasada, y esa información tiene un costo de privacidad alto para un
  beneficio bajo.
- **Historial de cambios de cada registro.** Un `updated_at` existe, pero no hay
  tabla de auditoría de cambios de negocio. La auditoría que sí existe es la de
  accesos a la salud, que es donde la ley la pide.
- **Facturación electrónica.** Requiere un proveedor y un acuerdo con el
  contribuyente. No se inventó una integración que nadie eligió.

---

© 2026 Hefesto Studio. Software propietario.