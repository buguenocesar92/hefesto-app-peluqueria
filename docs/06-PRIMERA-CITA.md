# 06 · Primera cita

Del salón vacío a la primera liquidación pagada. En el orden en que hay que hacer
las cosas, porque el orden importa: **la política de comisión va antes que la
primera venta**, aunque parezca que puede esperar.

---

## Paso 1 · El salón

```bash
php artisan peluqueria:crear-salon \
    --slug=demo \
    --nombre="Salón Demo" \
    --nombre-corto="Demo" \
    --administrador="Cuenta de administración" \
    --correo-admin=admin@ejemplo.cl
```

El comando no crea usuarios con contraseña, porque esta aplicación no tiene
contraseñas. Crea la fila en `usuarios` con `password` en `NULL`, y el ingreso lo
completa Authentik.

---

## Paso 2 · Las profesionales

Administración > Profesionales.

Para cada una:

- **Nombre.** Es lo que ve el resto del salón.
- **Especialidad y silla.** Opcionales, pero la silla ayuda a no preguntar.
- **Color de agenda.** Se usa para distinguirla de un vistazo.
- **Registro profesional.** El salón lo declara. El sistema **no lo verifica**
  contra ninguna fuente oficial: no tiene dónde consultarla. Lo que sí hace es
  dejar registrado quién lo declaró, que es lo que después se puede auditar.
- **Servicios con duración y precio propios.** Si se dejan vacíos, manda el
  catálogo.

Sobre lo último: si dos profesionales hacen el mismo servicio en tiempos distintos,
la agenda se va a llenar mal de todas formas. Se puede deixar en el catálogo una
duración general y ajustar donde haga falta.

---

## Paso 3 · El catálogo de servicios

Administración > Servicios.

Dos campos que no son obvios:

**Duración.** Es la del salón. La que manda en la agenda es la de esa profesional
para ese servicio; si no tiene una propia, manda esta.

**Reposición.** Marca los servicios que se vuelven a cobrar en la próxima visita. Es
una etiqueta que usa el salón, y **nada la calcula**. Es información para la
recepción, no una regla del sistema.

---

## Paso 4 · La política de comisión

**Antes de la primera venta.** Y esto no es opcional.

Administración > Comisiones > Declarar una política.

| Campo | Qué es |
|---|---|
| Aplica a | El salón entero, o una profesional en particular |
| Porcentaje de servicios | Lo que se paga por el trabajo |
| Porcentaje de productos | Lo que se paga por lo que se vende |
| ¿Participa de los productos? | Si la comisión incluye o excluye los productos |
| Base de cálculo | Servicios, productos, o ambos |
| Vigente desde / hasta | Período de validez |

**No hay porcentaje por omisión en ninguna parte de esta aplicación.** Sin una
política vigente, el cálculo **falla con un mensaje**:

> Este salón no tiene una política de comisión vigente para esta profesional.

Podría devolver cero, y ese cero sería un número que nadie negoció. Es la diferencia
entre un sistema que calcula lo que se acordó y uno que inventa lo que parece
razonable.

### La base de cálculo se declara, no se deduce

| Opción | Qué cuenta |
|---|---|
| `ambos` | Servicios y productos |
| `servicio` | Solo servicios |
| `producto` | Solo productos |

Es una decisión del negocio. El sistema no la deduce del porcentaje: se declara.

### Los porcentajes se guardan con decimales

`35.50`, no `0.3550`. Es la convención de negocio y la que se ve al signo. El cálculo
interno usa aritmética exacta con `bcmath`; el porcentaje se guarda como texto con
dos decimales.

---

## Paso 5 · La primera clienta

Clientas > Nueva clienta.

Nombre, apellido, RUT, teléfono. **El RUT es opcional y no se exige**: pedir un
documento para confirmar una reserva no es lo que necesita un salón pequeño, y una
ficha se puede completar después.

La ficha tiene dos lugares distintos y no se mezclan:

- **Identificación**: nombre, RUT, teléfono, notas.
- **Antecedentes de salud**: alergias, contraindicaciones, condiciones.

Los segundos se registran en otra pantalla, con auditoría de cada apertura. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

---

## Paso 6 · La primera cita

Agenda > Agendar.

1. Se elige la profesional. La lista sale de `agenda/profesionales`, que devuelve
   solo lo que la agenda necesita: nombre, silla, color y los servicios con su
   duración efectiva. No devuelve correo ni teléfono, porque agendar no los necesita.
2. Se elige el servicio. La lista se filtra por profesional.
3. Se elige la clienta.
4. Se escribe la hora de inicio.

Al elegir el servicio, la pantalla muestra **a qué hora termina**, calculada con la
duración de ese servicio para esa profesional.

"Ver si se puede" consulta por qué. Y el motivo del conflicto —"ya tiene una cita de
10:00 a 10:30"— aparece **mientras se está eligiendo la hora**, no después de apretar
el botón. Un 500 ahí haría que la recepcionista probara horarios a ciegas, que es
justo lo que hace que un salón se atrase.

El servidor vuelve a comprobarlo al agendar. La pantalla es una ayuda, no la
garantía: la garantía es la restricción `EXCLUDE` de la base.

---

## Paso 7 · Cómo terminó

Cuando la clienta se va, se aprieta **Registrar cómo terminó** y se anotan las horas
reales.

De ahí sale el retraso. `minutos_retraso` **no se envía**: lo calcula la aplicación.
Si el cliente lo mandara, podría reportar cero retraso en un día con veinte minutos
de retraso.

Si nadie aprieta el botón, no hay retraso registrado. El sistema **no lo estima**, y
el reporte de retrasos lo dice en pantalla. Es preferible un número que falta a un
número inventado.

---

## Paso 8 · El cobro

La visita se cobra en **Ventas**, con sus servicios y sus productos.

Dos cosas que pasan a ser historia:

- El **precio unitario se copia** a la línea de venta. Subir el precio mañana no
  reescribe lo que se cobró hoy.
- La línea puede ser de servicio o de producto, y la base lo comprueba. No hay una
  tercera categoría que nadie definió.

---

## Paso 9 · El corte de caja

Corte de caja > Abrir la profesional.

Mientras el corte está abierto, el **esperado se recalcula**: la caja está abierta y
todavía puede entrar una venta.

Al cerrar se anotan:

- El **total declarado**: lo que dice la persona.
- El efectivo y lo cobrado en tarjeta.
- Las observaciones.

El **esperado** lo dice el sistema. Son dos hechos distintos: por eso van por
separado y por eso la **diferencia se muestra y no se corrige**.

Un corte de caja que se pudiera corregir dejaría de ser un acto y pasaría a ser un
número que se acomoda. El sistema registra lo que pasó; revisar qué hacer con la
diferencia es del dueño.

La base tiene `unique(['profesional_id', 'fecha'])`: no se puede cerrar dos veces el
mismo día. En un salón donde cada quien tiene su caja, eso evita que la segunda
cifra pise la primera.

---

## Paso 10 · La primera liquidación

Comisiones > Ver la cuenta.

1. Se elige la profesional y el período.
2. **Ver la cuenta** previsualiza sin guardar nada. Es lo que el dueño mira antes de
   decidir y lo que la profesional revisa antes de firmar.
3. **Liquidar el período** encola el cálculo y devuelve de inmediato: el cálculo de
   un mes es trabajo de minutos y dejar al dueño esperando en un botón no ayuda a
   nadie.

El resultado trae **una línea por venta**, con el identificador de la venta, la
descripción, la base, el porcentaje aplicado y la comisión.

Esa tabla es el producto. Una comisión que solo muestra el total obliga a creerlo;
una que muestra las líneas se puede rehacer con una calculadora.

---

## Lo que hay que mirar el primer día

| Revisar | Por qué |
|---|---|
| La suma de las líneas = el total | El redondeo es por línea, y tiene que cuadrar |
| El porcentaje de cada línea = el de la política | Si no, la política cambió a mitad de período |
| La diferencia de cada corte | Un cero en todos los cortes el primer día es sospechoso |
| Los avisos de salud en la agenda | Si nunca aparecen, puede que no se estén registrando |

---

## Lo que va a fallar y por qué

| Síntoma | Causa habitual |
|---|---|
| "Este salón no tiene una política de comisión vigente" | Paso 4 sin hacer, o la vigencia no cubre hoy |
| La cita no se guarda y no hay mensaje claro | Choque de agenda; el motivo llega en el 422 |
| El 404 al abrir una ficha de clienta | La Policy rechazó, o no existe. Son indistinguibles a propósito |
| Todos los formularios devuelven 419 | Falta la cabecera `X-XSRF-TOKEN` en el BFF |
| La lista de salones en `/entrar` está vacía | Ningún salón activo, o falta la vista pública |

---

© 2026 Hefesto Studio. Software propietario.