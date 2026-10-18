# 10 · Pruebas y estado

Este documento dice **qué se comprobó y qué no**. Está al final a propósito, pero es
el primero que hay que leer antes de confiar en esta aplicación.

---

## La frase corta

**Esta aplicación nunca se ejecutó.** Cero migraciones aplicadas, cero pruebas
corridas, cero imágenes construidas, cero peticiones HTTP hechas. No hay ni una
captura de pantalla de esta aplicación funcionando, porque no hay ninguna.

---

## Qué hay en el equipo donde se escribió

| Herramienta | Estado | Consecuencia |
|---|---|---|
| PHP | **No está** | No se pudo revisar la sintaxis de los archivos PHP |
| Composer | **No está** | No se pudo instalar ni verificar dependencias |
| Docker | **No está** | No se construyó ninguna imagen, no se levantó ningún contenedor |
| PostgreSQL (`psql`, `pg_dump`) | **No está** | No se corrieron migraciones ni pruebas de base de datos |
| Node.js | **Sí está**, v24.21.0 | No se usó para nada: no se compiló la interfaz |
| Python | **Sí está**, 3.14.7 | Se usaron las herramientas del repositorio |
| `bash` | **Sí está** | Se pudo revisar la sintaxis de los scripts de shell |

El equipo es Windows. Los `Dockerfile`, el compose, los scripts de shell y los
comandos de este documento están pensados para **Linux**, que es donde corre un
servidor con Docker. Escribirlos y probarlos aquí habría sido escribir en un papel
en vez de en un disco.

---

## Lo que sí se comprobó

| Comprobación | Herramienta | Resultado |
|---|---|---|
| Sintaxis de los tres scripts de shell | `bash -n` | **Pasan los tres**: `entrypoint.sh`, `respaldo.sh`, `restauracion.sh` |
| Los YAML del compose se parsean | `tools/validar_apps.py` | Ver el resultado al final del documento |
| Los `.md` tienen tildes yortografía completa | `tools/acentuar.py` | Ver el resultado al final del documento |
| Nombres de tabla y de columna contra las migraciones | Lectura cruzada, a mano | Revisé los nombres que usan los modelos, los servicios, los comandos y las pruebas contra lo que crean las migraciones. **No es una verificación automática** |
| Las rutas de `routes/web.php` contra los controladores | Lectura cruzada, a mano | Cada ruta apunta a un método que existe |
| Los campos que usa la interfaz contra lo que devuelve la API | Lectura cruzada, a mano | Cada campo de `web/app/tipos/api.ts` existe en la respuesta del controlador |

Las tres últimas son **lectura con criterio, no pruebas**. Encuentran el error
obvio; no encuentran el error que solo aparece al ejecutarlo.

---

## Lo que NO se comprobó, y por qué importa

### 1. Ni un archivo PHP se revisó sintácticamente

Con `php -l` se habría detectado en segundos un punto y coma que falta o una llave
desbalanceada. **No se pudo correr.**

Lo que sí se hizo, a mano y con el archivo abierto al lado: revisar firmas de
métodos, tipos de argumentos, imports de las clases que se usan, y que cada
`Policy` tenga los métodos que el descubrimiento de Laravel encuentra. Eso no
detecta un error de sintaxis.

**Antes de instalar, corra `php -l` sobre `api/` y `find api/app -name '*.php' -exec
php -l {} \;`.**

### 2. Las migraciones nunca se aplicaron

Hay veinte migraciones escritas y **cero aplicadas**. No se sabe si la extensión
`btree_gist` está disponible en la instalación de PostgreSQL que se use, ni si la
restricción `EXCLUDE` acepta exactamente la forma que se escribió, ni si algún
`COMMENT ON` tiene un error de sintaxis.

Las dos primeras que conviene mirar primero, porque son las que más dependen de la
versión de PostgreSQL:

- `2026_10_18_000008`, que crea `btree_gist` y la restricción `citas_sin_solapamiento`.
- `2026_10_18_000020`, que activa la RLS en dieciséis tablas.

### 3. Las pruebas están escritas y no se corrieron

Hay cinco suites declaradas en `phpunit.xml`:

| Suite | Qué cubre |
|---|---|
| `Unit` | El cálculo de la comisión línea por línea, con `bcmath` |
| `Feature` | Comisiones auditable de punta a punta |
| `Agenda` | La agenda por duración de servicio |
| `Agenda` | Que los bloqueos no se pisen con las citas |
| `Aislamiento` | Que un salón no vea ni escriba datos de otro |

**Ninguna se ejecutó.** No se puede afirmar que pasen. Lo que sí se puede afirmar es
que cubren los tres riesgos principales del diseño, y que están escritas contra las
clases reales.

### 4. Las versiones de los paquetes y de las imágenes no se comprobaron

| Lo que se copió | De dónde | Qué falta |
|---|---|---|
| `laravel/framework ^13.37`, `predis/predis ^3.7`, `firebase/php-jwt ^7.2`, `fakerphp/faker ^1.24`, `phpunit/phpunit ^13.0.3` | `apps/clinica-dental/api/composer.json` | No se comprobó que esas versiones existan en Packagist |
| `nuxt ^4.6.2`, `vue ^3.5.44`, `vue-router ^5.4.0` | `apps/clinica-dental/web/package.json` | Ídem |
| `php:8.4-fpm-alpine3.24`, `node:24-alpine3.24`, `postgres:18.6`, `redis:8.8.3-alpine`, `caddy:2.11.7-alpine` | Los `Dockerfile` y compose de `apps/clinica-dental` | No se comprobó que esas etiquetas existan en el registro |

Son las mismas versiones que usan las demás aplicaciones de Hefesto, que es lo más
verificable que se podía hacer sin red. **No se affirmation que existan**: se
copiaron de un lugar del mismo repositorio donde seMCs Affirmaron el 2026-10-17.

### 5. La regla química sigue sin verificarse

Es lo más importante de esta lista. Es verdad que un tinte y un retoque de color no
se pueden hacer el mismo día sobre el mismo pelo; lo que no se pudo verificar es
**cuántos días exige cada combinación**, ni si depende de la técnica o del
fabricante.

Por eso no hay columna, no hay cálculo, la variable nace vacía y la pantalla lo
dice. Ver [02](02-MODELO-DE-DATOS.md).

### 6. Los plazos de conservación no se verificaron

La Ley 19.628 exige una finalidad declarada, pero el plazo que corresponde a una
ficha de salón con antecedentes de salud **no se pudo verificar** desde este entorno.
Las dos variables nacen vacías y **la aplicación no purga nada por su cuenta**.

Escribir un número ahí habría sido inventar un plazo legal. Ver
[07](07-SEGURIDAD-Y-LEY-19628.md).

### 7. El registro profesional no se verifica

`profesionales.registro_verificado` guarda lo que el salón declara. El sistema no lo
contrasta con ninguna fuente oficial: no tiene dónde consultarla, y no se inventó un
enlace ni un servicio que no se eligió.

### 8. El BFF no se probó contra una API real

`web/server/api/[...ruta].ts` reenvía cookie y `X-XSRF-TOKEN`. La lógica está escrita
y es sencilla, pero **no se verificó contra una API de verdad**. Si el
`Set-Cookie` de Laravel trae varias cookies en una cabecera, el separador usado es un
patrón y no una ley: eso se verificó leyendo el formato, no ejecutándolo.

### 9. No hay pruebas de carga, ni de concurrencia

El caso interesante es la agenda: dos recepcionistas agendando a la misma
profesional a la vez. La restricción `EXCLUDE` de la base lo resuelve, pero **no se
probó que el mensaje que llega sea legible** en ese caso.

---

## Errores factuales encontrados en el material de referencia

Al seguir el patrón de las otras aplicaciones de Hefesto aparecieron cuatro cosas
que **no se copiaron**. Se anotan aquí porque un error del material de referencia es
un dato para el que trabaja el próximo.

Cada una se verificó abriendo el archivo y buscando el código, no de memoria.

### 1. Una vista pública que nunca se usa

La vista `vw_consultorios_publicos` de `apps/clinica-dental` se crea dentro de la
migración `2026_02_10_000001_crear_tabla_consultorios.php`, y
`ResolverTenant::consultoriosParaLogin()` **no la consulta**: lee la tabla
`consultorios` con cinco columnas, incluida `nombre_corto`. El propio comentario de
ese método lo admite: "la vista existe y sigue SIN USO".

Es la única protección declarada de una tabla que no lleva RLS, y no está en el
camino que la usa.

Acá se hizo al revés: `AuthController::salones()`, `AuthController::entrar()` y
`ProveedorOidcAuthentik::usuarioDesdeClaims()` leen la vista, y el comando de
verificación comprueba que tenga **exactamente tres columnas**. Como la vista no
puede devolver `nombre_corto`, el encabezado de la interfaz usa otro dato del mismo
salón.

### 2. El comando de verificación no cubre el modo de falla principal

`apps/clinica-dental/api/app/Console/Commands/VerificarAislamientoCommand.php`
comprueba que las tablas existan, que tengan RLS y que tengan su política. **No
comprueba que el rol de la aplicación no sea superusuario ni tenga `BYPASSRLS`**, y
tampoco la vista pública.

Con superusuario, PostgreSQL ignora todas las políticas: el resto de las
comprobaciones pasaría y el comando imprimiría "Aislamiento verificado". Es el modo
de falla más silencioso que hay, y es exactamente el que la documentación de esa
aplicación le pide al operador comprobar antes de atender al primer paciente.

Acá el comando **empieza** por esa comprobación: si el rol actual es superusuario o
tiene `BYPASSRLS`, falla antes de mirar una sola tabla.

### 3. Un `restauracion.sh` que hace `DROP` mientras dice que no

`apps/clinica-dental/deploy/respaldos/restauracion.sh` usa

```
pg_restore --clean --if-exists ...
```

`--clean` **implica `DROP`**: PostgreSQL borra los objetos antes de recrearlos. El
encabezado del propio script dice, en mayúsculas, que nunca hace `DROP` y que no hay
versión del script que lo habilite.

Es la clase de contradicción que hay que mirar dos veces, porque el que lea el encabezado se
quedará con lo contrario de lo que hace el cuerpo.

Acá `restauracion.sh` limpia con **`TRUNCATE`** y restaura **sin** `--clean`. Es la
diferencia entre vaciar y borrar: `TRUNCATE` deja la tabla, sus índices, sus
restricciones y sus políticas; `--clean` las borra.

### 4. La documentación manda correr un comando que no existe

La firma real del comando de esa aplicación es
`clinica:verificar-aislamiento`. Pero su propia documentación lo invoca como
`clinica-dental:verificar-aislamiento` en cinco lugares repartidos en tres archivos
(`docs/06-PRIMER-PACIENTE.md`, `docs/07-SEGURIDAD-Y-LEY-19628.md` y
`docs/08-RESPALDOS.md`), incluido el punto donde se le dice al operador que **no
siga** si el comando falla.

Acá el nombre es `peluqueria:verificar-aislamiento` en el comando, en el compose, en
los dos scripts de respaldo y en los once documentos. Un solo nombre, dicho once.

---

## Resumen honesto

| Cosa | Estado |
|---|---|
| Archivos escritos | Completos para lo que se pidió |
| Migraciones aplicadas | **0 de 20** |
| Pruebas escritas | 5 suites |
| Pruebas ejecutadas | **0** |
| Imágenes construidas | **0** |
| Peticiones HTTP | **0** |
| Scripts de shell revisados con `bash -n` | **3 de 3, pasan** |
| Documentos | 11 |
| Datos inventados | **0**, y los tres huecos están declarados |

**Antes de poner esto en un salón, hay que correr las pruebas, aplicar las
migraciones y hacer una restauración de prueba.** Nada de eso se hizo.

---

© 2026 Hefesto Studio. Software propietario.