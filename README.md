# Peluquería

Aplicación para salones de belleza, peluquerías y centros de estética en Chile.
Agenda por tiempo de servicio, ficha de clienta con antecedentes de salud, comisión
por profesional auditable línea por línea y corte de caja por profesional y día, con
aislamiento entre salones impuesto por la base de datos.

Software original de **Hefesto Studio**. No es un despliegue de una herramienta de
terceros.

> **Esta aplicación no está lista para atender clientas reales y nunca se ha
> ejecutado.** En el equipo donde se escribió no hay PHP, Composer, Docker ni
> PostgreSQL: las migraciones no se corrieron, las pruebas no se corrieron y los
> contenedores no se construyeron.
>
> Lo que sí se comprobó, y lo que falta, está uno por uno en
> **[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md)**.

---

## Empiece por acá

| Si quiere… | Lea |
|---|---|
| Entender qué es esto y qué no hace | [docs/00-INDICE.md](docs/00-INDICE.md) |
| **Lo que hay que leer antes que nada** | [docs/07-SEGURIDAD-Y-LEY-19628.md](docs/07-SEGURIDAD-Y-LEY-19628.md) |
| Instalarla de cero | [docs/04-INSTALACION.md](docs/04-INSTALACION.md) |
| Saber qué se probó de verdad | [docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md) |

---

## Qué hace

- **Agenda por tiempo de servicio**: la hora de término la calcula el servicio
  para esa profesional, no la clienta ni un bloque fijo. La base impide que dos
  citas de una misma profesional se pisen.
- **Bloqueos de agenda** que no se pisan con las citas, comprobado en el servidor.
- **Ficha de clienta** con identificación e historial, y los antecedentes de salud
  en una tabla aparte, con auditoría de cada apertura.
- **Comisión por profesional auditable**: el cálculo devuelve una línea por
  venta, con la base, el porcentaje aplicado y el resultado, y la liquidación
  guarda los porcentajes con que se calculó.
- **Corte de caja** por profesional y por día. La diferencia se muestra; no se
  corrige.
- **Aislamiento entre salones** con Row Level Security de PostgreSQL, forzada.

### Lo que NO hace

- **No comprueba la espera mínima entre dos procesos químicos sobre el mismo
  pelo.** Es verdad que existe esa regla; lo que no se pudo verificar es cuántos
  días exige cada combinación. Preferimos el hueco al dato inventado. Ver
  [docs/02](docs/02-MODELO-DE-DATOS.md).
- No borra: clientas, profesionales, servicios y productos se desactivan.
- No manda correos ni mensajes: no hay integración con nada externo.
- No tiene ranking de productividad por profesional, y es a propósito.

---

## Las tres decisiones que explican el resto

**1. El aislamiento lo impone la base, no la aplicación.** PostgreSQL con RLS por
`tenant_id`, forzada. Una consulta sin salón fijado falla con una excepción, no
devuelve cero filas. Ver [docs/03](docs/03-MULTITENANCY-Y-ROLES.md).

**2. La comisión se explica o no sirve.** Cada línea del desglose trae de qué
venta salió, sobre qué base y con qué porcentaje, y la suma de las líneas es el
total. Eso permite que la profesional rehaga la cuenta con una calculadora antes de
firmarla. Sin política vigente el cálculo falla: no devuelve cero, porque un cero es
un número que nadie negoció.

**3. Un dato no verificado se declara como no verificado.** La espera entre
procesos químicos, los plazos de conservación y el registro profesional de quien
atiende están marcados como pendientes en pantalla y en la documentación. No hay
números inventados escondido en un valor por omisión.

---

## Estructura

```
apps/peluqueria/
  api/         API Laravel 13 (PHP 8.3+), PostgreSQL
  web/         Interfaz Nuxt 4, render en el servidor
  deploy/      Imágenes Docker, compose, proxy y respaldos
  docs/        Documentación numerada, 00 a 10
```

---

## Puesta en marcha

Está en [docs/04-INSTALACION.md](docs/04-INSTALACION.md). El resumen son cuatro
comandos:

```bash
cp api/.env.example api/.env      # complete los valores
cp .env.example .env              # dominio y correo del certificado
docker compose -f deploy/docker-compose.prod.yml up -d --build
docker compose -f deploy/docker-compose.prod.yml exec api \
    php artisan peluqueria:verificar-aislamiento
```

Ese último comando no es opcional: comprueba que el aislamiento entre salones esté
instalado y que la vista pública no exponga más de lo que debe.

---

## Documentación

| Documento | De qué trata |
|---|---|
| [00-INDICE](docs/00-INDICE.md) | Índice y mapa de lectura |
| [01-ARQUITECTURA](docs/01-ARQUITECTURA.md) | Las tres capas y por qué el navegador no habla con la API |
| [02-MODELO-DE-DATOS](docs/02-MODELO-DE-DATOS.md) | Tablas, reglas de negocio y lo que quedó sin definir |
| [03-MULTITENANCY-Y-ROLES](docs/03-MULTITENANCY-Y-ROLES.md) | RLS, roles, capacidades y por qué un rechazo es 404 |
| [04-INSTALACION](docs/04-INSTALACION.md) | Instalación desde cero |
| [05-CONFIGURACION](docs/05-CONFIGURACION.md) | Cada variable y qué pasa si está mal |
| [06-PRIMERA-CITA](docs/06-PRIMERA-CITA.md) | Del salón vacío a la primera liquidación |
| [07-SEGURIDAD-Y-LEY-19628](docs/07-SEGURIDAD-Y-LEY-19628.md) | Datos personales, salud y lo que hay que decidir |
| [08-RESPALDOS](docs/08-RESPALDOS.md) | Respaldo, restauración y por qué no hay `DROP` |
| [09-API](docs/09-API.md) | Todas las rutas, con sus permisos |
| [10-PRUEBAS-Y-ESTADO](docs/10-PRUEBAS-Y-ESTADO.md) | Qué se probó y qué no |

---

© 2026 Hefesto Studio. Software propietario.