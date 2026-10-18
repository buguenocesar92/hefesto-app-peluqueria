# 08 · Respaldos

Dos scripts: uno que respalda, uno que restaura. Los dos tienen reglas que no son
opcionales, y las reglas existen por una razón concreta: en un salón, perder la base
es perder la respuesta a "qué se le hizo a esta clienta, con qué producto y con qué
porcentaje se le calculó la comisión".

---

## Las cinco reglas

| # | Regla | Por qué |
|---|---|---|
| 1 | Manifiesto con SHA-256 | Un respaldo sin suma es un archivo: no se sabe si se restauró completo hasta que ya es tarde |
| 2 | La marca de completo se escribe **al final** | Si el respaldo se corta a mitad, el `.completo` no existe y la restauración lo rechaza |
| 3 | Las rutas se validan **antes** de escribir | Un `pg_dump` que escribe donde no debe no se detecta hasta que se necesita |
| 4 | Destino ocupado se rechaza, salvo `--force` | Sobrescribir por error es la forma más común de perder el único respaldo |
| 5 | **Nunca** hay `DROP` | Un `DROP` es irrecuperable |

---

## Respaldo

```bash
deploy/respaldos/respaldo.sh
deploy/respaldos/respaldo.sh --destino /var/backups/peluqueria --retencion 30
```

Variables de entorno obligatorias: `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`.

Por omisión escribe en `/var/backups/peluqueria`, que se puede cambiar con
`DIRECCION_RESPALDOS` en el entorno.

### Qué produce

```
/var/backups/peluqueria/
  peluqueria-20261018-030000.dump              <- los datos
  peluqueria-20261018-030000.manifiesto.txt     <- SHA-256 y metadatos
  peluqueria-20261018-030000.completo          <- la marca de que terminó bien
```

En ese orden, y en ese orden importa. El `.dump` se escribe primero en
`.parcial` y se renombra al final. Si el proceso se corta a mitad, queda un
`.parcial` que ningún restaurador toma por bueno.

Después de escribir, el script **verifica que el archivo se puede leer** con
`pg_restore --list`. Un respaldo que no se puede leer no es un respaldo, y enterarse
en el momento de restaurarlo es la peor forma de enterarse.

### La retención

Borra respaldos **completos** de más de N días. Nunca borra:

- El recién hecho.
- Uno que no tenga su marca de completo. Si algo quedó a medias, esa es justamente
  la copia que alguien va a necesitar.

---

## Restauración

```bash
deploy/respaldos/restauracion.sh ARCHIVO.dump --confirmar
```

**Este script reconstruye la base completa.** Lo que hay en la base de destino
desaparece. Por eso:

- **Nunca hace `DROP`.** Ni de la base, ni de un esquema, ni de una tabla. En
  ninguna versión y con ninguna opción.
- **No usa `pg_restore --clean`**, porque `--clean` implica `DROP`. Por eso el
  destino se limpia con **`TRUNCATE`** antes: vacía las tablas sin borrar su
  definición, así que siguen existiendo con sus índices, sus restricciones y sus
  políticas.
- **No sobrescribe nada** sin `--forzar`.
- **Deja el servicio detenido** al terminar.
- **Rechaza** los respaldos sin marca de completo.
- **Verifica** el SHA-256 contra el manifiesto antes de tocar nada.

### Para una prueba, sobre una base vacía

```bash
DB_DATABASE=peluqueria_restauracion_prueba \
    deploy/respaldos/restauracion.sh \
    /var/backups/peluqueria/peluqueria-20261018-030000.dump \
    --confirmar
```

El script **se niega** a restaurar sobre una base llamada `peluqueria`, que es el
nombre de la de la aplicación. Si lo que se quiere es production, se hace a mano y
con el operador mirando.

### Después de restaurar

El script comprueba que la base restaurada tiene políticas de aislamiento, porque
sin ellas la base es inutilizable aunque tenga todos los datos. Si no las encuentra,
avisa: **no atienda clientas hasta revisar esto**.

Y deja el servicio detenido a propósito. Se levanta a mano, cuando alguien haya
mirado el resultado:

```bash
docker compose -f deploy/docker-compose.prod.yml start api web
docker compose -f deploy/docker-compose.prod.yml exec api \
    php artisan peluqueria:verificar-aislamiento
```

---

## Copia fuera del servidor

**El script no copia nada a otro lado, a propósito.** Adónde va la copia (una NAS, un
servicio de objetos, otro servidor) es una decisión de cada instalación, y no se
puede tomar desde un script genérico.

Lo que sí hace es imprimir el recordatorio al final:

> falta copiarlo fuera y PROBAR una restauración sobre una base vacía.

Un respaldo que vive en el mismo disco que la base no es un respaldo. Es un archivo
que se pierde en el mismo incidente.

---

## Una restauración probada, no una restauración existe

Esto es lo más importante de este documento. **Un respaldo que no se restauró nunca no
está probado.** Es una carpeta con archivos.

El ciclo que hay que hacer, al menos **una vez por trimestre**:

1. Crear una base vacía con un nombre inequívoco.
2. Restaurar el último respaldo completo sobre ella.
3. Correr `php artisan peluqueria:verificar-aislamiento` sobre esa base.
4. Revisar que las liquidaciones cuadran con sus líneas.
5. Borrar la base de prueba.

Una copia de producción que nunca se restauró es una hipótesis, no un respaldo.

---

## Lo que no respalda el script, y hay que copiar aparte

El script hace `pg_dump` de la base. **No copia:**

| Qué | Por qué importa |
|---|---|
| `api/.env` | Sin la `APP_KEY`, las sesiones no se pueden descifrar |
| `deploy/` | Sin el compose no se levanta nada |
| El volumen `storage` | Registros de la aplicación |
| La configuración de Authentik | Es de Hefesto, no de esta instalación |

---

© 2026 Hefesto Studio. Software propietario.