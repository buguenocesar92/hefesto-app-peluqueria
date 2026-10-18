# 00 · Índice

Peluquería: aplicación para salones de belleza, peluquerías y centros de estética
en Chile. Este es el mapa de la documentación y, sobre todo, el orden en que
conviene leerla.

---

## Antes de nada

Si solo va a leer dos cosas, que sean estas:

1. **[07 · Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md)**. Hay datos
   personales y datos sensibles de salud en esta aplicación. Dice qué se guardó,
   quién puede verlo, qué queda anotado y qué decisiones tiene que tomar el salón
   con su revisión legal.
2. **[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md)**. Esta aplicación **nunca
   se ejecutó**. No hay PHP, Composer, Docker ni PostgreSQL en el equipo donde se
   escribió. Hay cero migraciones aplicadas, cero pruebas corridas y cero imágenes
   construidas. Ese documento lo dice uno por uno, sin adornos.

---

## Los once documentos

| # | Documento | De qué trata | Cuándo leerlo |
|---|---|---|---|
| 00 | Este índice | El mapa y el orden de lectura | Ahora |
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Las tres capas, por qué el navegador nunca habla con la API, cómo entra la sesión | Antes de tocar código |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Las dieciocho tablas, las reglas que las atan y **lo que quedó sin definir** | Antes de tocar el esquema |
| 03 | [Multitenancy y roles](03-MULTITENANCY-Y-ROLES.md) | RLS, la vista pública, los tres roles, por qué un rechazo es 404 | Antes de tocar permisos |
| 04 | [Instalación](04-INSTALACION.md) | De cero a funcionando, y qué comprobar en el camino | Cuando la monte |
| 05 | [Configuración](05-CONFIGURACION.md) | Cada variable, qué hace y qué pasa si está mal | Antes de configurar |
| 06 | [Primera cita](06-PRIMERA-CITA.md) | Del salón vacío a la primera liquidación pagada | La primera vez |
| 07 | [Seguridad y Ley 19.628](07-SEGURIDAD-Y-LEY-19628.md) | Datos personales, salud, auditoría y plazos por decidir | **Antes que nada** |
| 08 | [Respaldos](08-RESPALDOS.md) | Qué se guarda, cómo se verifica y por qué nunca hay `DROP` | Antes de producción |
| 09 | [API](09-API.md) | Las cincuenta y dos rutas con su permiso y su respuesta | Al integrar |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | Qué se probó, qué no, y qué falta verificar | **Siempre** |

---

## Cómo está organizado el código

```
apps/peluqueria/
  api/                      API Laravel 13 sobre PostgreSQL
    app/
      Enums/                Los estados posibles, en PHP y no en comentarios
      Http/                 Middleware, Requests, Controllers y Policies
      Jobs/                 Liquidación diferida de comisiones
      Models/               Eloquent, con Concerns/PerteneceAlSalon
      Policies/             Quién puede hacer qué, tabla por tabla
      Services/
        Agenda/             Agenda, disponibilidad y revisión de salud
        Auth/               OIDC contra Authentik
        Caja/               Cierres por profesional y día
        Comisiones/         El cálculo auditable y su liquidación
        Permisos/           Capacidades por rol
        Tenancy/            Contexto de salón y auditoría de accesos
        Ventas/             Cobro de una visita
    database/migrations/    Veinte migraciones, del 2026-10-18
    routes/web.php          Toda la superficie de la API, versionada en una línea
    tests/                  Unitarias, de agenda, de comisiones y de aislamiento
  web/                      Interfaz Nuxt 4 con render en el servidor
    app/pages/              Once pantallas
    server/api/             El BFF: reenvía la sesión a la API
  deploy/                   Dockerfiles, compose, Caddy y respaldos
  docs/                     Esta documentación
```

---

## Los cuatro temas que aparecen en todos los documentos

**1. El aislamiento lo impone la base.** PostgreSQL con Row Level Security sobre
`tenant_id`, forzada, y una función que lanza excepción cuando no hay contexto de
salón. Un error de programación produce un error ruidoso, no una lista vacía.
Ver [03](03-MULTITENANCY-Y-ROLES.md).

**2. Lo que no se verificó se declara como no verificado.** Tres cosas quedaron
pendientes a propósito: la espera mínima entre procesos químicos sobre el mismo
pelo, los plazos de conservación de datos de salud y la verificación del registro
profesional de quien atiende. Ninguna tiene un número inventado en un valor por
omisión. Ver [02](02-MODELO-DE-DATOS.md) y [07](07-SEGURIDAD-Y-LEY-19628.md).

**3. El software no inventa reglas del negocio.** No hay porcentaje de comisión
por omisión: el salón lo declara. Sin política vigente el cálculo falla con un
mensaje, en vez de devolver cero. Ver [06](06-PRIMERA-CITA.md).

**4. Nada se borra.** Las clientas se desactivan, las citas se anulan, las ventas
se anulan, los antecedentes se cierran y las liquidaciones se anulan con motivo.
El motivo es concreto: las liquidaciones y los cortes de caja se arman con el
historial, y borrarlo deja números que no cuadran. Ver [02](02-MODELO-DE-DATOS.md).

---

## Una nota sobre el material de referencia

Esta aplicación se escribió siguiendo el patrón de aislamiento que usan las demás
aplicaciones de Hefesto Studio. Al revisar ese material aparecieron cuatro errores
fácticos que **no** se copiaron. Están enumerados uno por uno en
[10 · Pruebas y estado](10-PRUEBAS-Y-ESTADO.md#errores-factuales-encontrados-en-el-material-de-referencia),
que es el lugar donde se cuentan las cosas que no se hicieron bien, en vez de
esconderlas en un comentario.

---

© 2026 Hefesto Studio. Software propietario.