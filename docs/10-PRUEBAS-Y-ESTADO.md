# 10 - Pruebas y estado

> ## ESTADO GENERAL: **CÓDIGO ESCRITO, SIN EJECUTAR**
>
> En el entorno donde se construyó esta aplicación **no había Docker, ni PHP, ni
> Composer, ni PostgreSQL, ni Redis**. Las pruebas de PHPUnit están escritas
> pero **no se ejecutaron ni una sola vez**.
>
> Este documento separa lo comprobado de lo no comprobado. Nada de lo que está
> sin ejecutar se presenta como probado.

## 1. Lo que SÍ se ejecutó de verdad

### 1.1 Verificador estático de la entrega

```bash
python api/tests/Estatico/comprobar_entrega.py
```

Es un script propio, sin dependencias, que comprueba:

| Comprobación | Qué verifica |
|---|---|
| Estructura | Que existan los 10 documentos, la API, la web, el despliegue y los archivos clave |
| JSON | Que `composer.json` y `package.json` sean JSON válido |
| YAML | Que los dos compose sean YAML válido, declaren los servicios obligatorios y que **en producción solo `proxy` publique puertos** |
| PHP estático | Que namespace y nombre de clase calcen con PSR-4, que el archivo empiece con `<?php` y que llaves, paréntesis y corchetes estén balanceados (ignorando cadenas, comentarios y heredocs) |
| UTF-8 | Que todos los archivos decodifiquen en UTF-8 y sin marcador de orden de bytes |
| Caracteres | Que **no quede ningún carácter CJK ni cirílico** en ningún archivo |
| RLS | Que las 23 tablas de negocio estén en la lista protegida, que estén las cuatro piezas del mecanismo (`ENABLE`, `FORCE`, `USING`, `WITH CHECK`), que `restaurantes` quede **fuera**, y que la función de contexto **falle cerrado** |
| Secretos | Que `.env.example` no traiga valores reales en variables sensibles |

Resultado de la corrida final: **147 archivos revisados, 0 fallas**.

### Prueba de control del verificador

Para comprobar que el verificador no está vacío, se creó a propósito un archivo
temporal con un ideograma, una letra cirílica y una llave sin cerrar, y se
ejecutó:

```
FALLA  api\tests\_control.txt:1:23 caracter CJK no permitido: U+54C1
FALLA  api\tests\_control.txt:1:36 caracter cirilico no permitido: U+0431
FALLA  api\tests\_control.txt tiene marcador de orden de bytes (BOM)
RESULTADO: 3 falla(s)
```

El archivo de control se eliminó después.

**Lo que este script NO hace, y es importante:** no ejecuta las pruebas de
Laravel, no valida el SQL contra PostgreSQL y no comprueba que la aplicación
arranque. Un resultado favorable significa "la entrega está completa y limpia",
**no** "el software funciona".

Además, el análisis estático de PHP **no es `php -l`**: no encuentra errores de
sintaxis reales. Solo comprueba que las piezas que se rompen en silencio estén
bien.

## 1.2 Defectos reales que encontraron las comprobaciones

No son cosméticos. Cada uno era un bug:

| Comprobación | Qué encontró |
|---|---|
| Caracteres | Un ideograma chino en un comentario de `comanda_items` |
| Caracteres | Ideogramas en comentarios de `GeneradorDeFolio` y `RestauranteDemoSeeder` |
| Caracteres | Una letra cirílica (U+043E, la "o" rusa) en `rol:cocinero` dentro de `routes/api.php` |
| Caracteres | Una palabra china y varias palabras en inglés coladas en comentarios: `starting`, `investigasi`, `posibility`, `precision`, `SeYoutuberon` |
| Balance PHP | Heredocs de SQL en las migraciones 13 y 14 escritos como `SQL);`, que es una forma dudosa en PHP. Se reescribieron a `$sql = <<<'SQL' … SQL;` seguido de `DB::unprepared($sql);`, que es inequívocamente válido |
| Secretos | **Un bug en el propio verificador**: el patrón usaba `\s*`, que incluye el salto de línea, así que una variable vacía (`APP_KEY=`) hacía que la coincidencia continuara en la línea siguiente y reportara 9 falsos positivos. Corregido a `[ \t]*` |
| PSR-4 | Un bug en el propio verificador: comparaba el namespace contra la ruta **incluyendo el nombre del archivo**. Corregido para comparar solo los directorios |

Que las últimas dos filas sean bugs del verificador y no del código dice algo
útil: una comprobación que nunca se ejecutó tampoco está probada.

### 1.2 Barrido de caracteres en dos pasadas

El material de esta aplicación se revisó **en dos pasadas** buscando caracteres
CJK (ideogramas, hiragana, katakana, hangul y la puntuación CJK) y cirílicos.

Las dos pasadas encontraron caracteres extraños reales, que se corrigieron:

| Pasada | Hallazgo |
|---|---|
| 1 | Un ideograma chino en un comentario de `comanda_items` |
| 1 | Ideogramas en un comentario de `GeneradorDeFolio` y de `RestauranteDemoSeeder` |
| 1 | Una letra cirílica (U+043E, la "o" rusa) en `rol:cocinero` dentro de `routes/api.php` |
| 1 | Palabras en inglés que se colaron en comentarios: `starting`, `investigasi`, `posibility`, `precision`, `SeYoutuberon` |
| 2 | Sin hallazgos. Barrido limpio en los 147 archivos |

Que aparecieran en la primera pasada y no en la segunda es la razón de hacer dos:
un barrido único no encuentra los que uno mismo acaba de escribir.

## 2. Lo que NO se ejecutó

| Qué | Por qué no | Consecuencia |
|---|---|---|
| **Suite de PHPUnit** | No hay PHP ni Composer | **Ninguna prueba de la aplicación se ha corrido** |
| **Las 14 migraciones contra PostgreSQL** | No hay PostgreSQL | El SQL **no fue validado nunca**. Es el primer riesgo |
| **Row Level Security** | No hay PostgreSQL | **La barrera de aislamiento no está probada** |
| **Pruebas de cocina** | No hay Redis | El reloj en memoria no se ha ejecutado |
| **`docker compose config`** | No hay Docker | Los compose no fueron validados por el orquestador |
| **Construcción de la imagen web** | No hay Docker | Nuxt nunca se compiló |
| **Autenticación OIDC** | No hay Authentik | El flujo de inicio de sesión no se ha probado contra un proveedor real |

### Las dos pruebas prioritarias

**Aislamiento entre tenants** — `tests/Feature/Aislamiento/AislamientoEntreRestaurantesTest.php`

Escribe, para cada caso:

1. Una consulta SQL directa que pide la comanda de otro restaurante. Debe
   devolver **cero filas**.
2. Un endpoint con un identificador de otro restaurante. Debe responder **404**.
3. Una lista de comandas. Debe traer solo las propias.
4. Un `INSERT` en nombre de otro restaurante. Debe **reventar** (por `WITH
   CHECK`).
5. Una anulación de un ítem ajeno. Debe responder **404** y no tocar nada.
6. Una consulta **sin** contexto de restaurante. Debe **reventar**, no devolver
   cero filas en silencio.

**Un mesero no anula una comanda cobrada** — `tests/Feature/Comandas/NoSeAnulaComandaCobradaTest.php`

1. El mesero no puede anular una comanda cobrada → 403, y la comanda sigue cobrada.
2. **El administrador tampoco** → 403.
3. La `Policy` rechaza la anulación con una persona real de **cada uno de los
   cinco roles**.
4. El mesero no puede anular un ítem de una comanda cobrada.
5. **Contraprueba:** una comanda `abierta` **sí** se anula, y una `por_cobrar`
   también. Si la regla fuera "nunca se anula", estas pruebas pasarían por la
   razón equivocada.

## 3. Las otras pruebas escritas

| Suite | Qué cubre | Requiere |
|---|---|---|
| `tests/Unit/MaquinaDeEstadosComandaTest.php` | Todas las transiciones, incluidas las prohibidas | Nada |
| `tests/Unit/TurnoTest.php` | Turno que cruza la medianoche, horas límite | Nada |
| `tests/Feature/Cocina/PantallaDeCocinaTest.php` | Reloj en Redis, orden por antigüedad, atrasado, reconstrucción | PostgreSQL y Redis |
| `tests/Feature/Catalogos/ModificadoresYRecetasTest.php` | Agregados, remociones, recetas, grupos obligatorios, precio copiado | PostgreSQL |
| `tests/Feature/Salon/MesasCombinablesTest.php` | Grupos de mesas, combinación, bloqueo al abrir | PostgreSQL |
| `tests/Feature/Cuentas/DivisionDeCuentaTest.php` | Reparto exacto, propina, cobro, liberación de mesas | PostgreSQL |

Las dos suites de `Unit` no tocan la base. **Se podrían ejecutar hoy mismo** en
cualquier máquina con PHP, y no se ejecutaron.

## 4. Por qué las pruebas no usan SQLite

`phpunit.xml` fija `DB_CONNECTION=pgsql`.

La mitad de lo que hay que comprobar **no existe en SQLite**: la Row Level
Security es una función de PostgreSQL. Una prueba de aislamiento corrida en
SQLite estaría probando que existe un `where`, que no es lo mismo que probar que
la base no entrega filas ajenas.

Una suite que pasa en verde en SQLite da una confianza que no corresponde, y en
seguridad esa confianza es cara.

## 5. Orden sugerido de verificación

Nada de lo siguiente se ha hecho. Es la ruta desde cero hasta poder confiar en
el sistema.

| Paso | Comando | Qué desbloquea |
|---|---|---|
| 1 | `composer install` | Que exista el autoloader |
| 2 | `php -l` en todos los archivos | Que no haya errores de sintaxis |
| 3 | `docker compose -f deploy/docker-compose.dev.yml --env-file .env config` | Que los compose sean válidos |
| 4 | `php artisan migrate --force` | **Que el SQL de las 14 migraciones sea válido** |
| 5 | `php artisan restaurante:verificar-aislamiento` | Que la RLS esté activa y sea efectiva |
| 6 | `php artisan db:seed --force` | Que la semilla funcione |
| 7 | `php artisan test` | Que las pruebas pasen contra PostgreSQL real |
| 8 | `docker compose ... up -d --build` | Que arranque |
| 9 | Un turno real con el mesero | Lo único que demuestra que sirve |

El paso 4 es el más importante y el más probable que falle: **ninguna de las 14
migraciones se ejecutó nunca**.

## 6. Riesgos abiertos

En orden de gravedad.

### 6.1 El SQL nunca se validó

Las 14 migraciones están escritas con la sintaxis de Laravel y de PostgreSQL
consultada, pero ninguna corrió. Un error de sintaxis, un tipo que no existe o
una incompatibilidad de versión se descubren en el paso 4.

### 6.2 La barrera de aislamiento no está probada

La RLS es el argumento de venta de este sistema y **nunca se ejecutó**. Que el
mecanismo esté bien copiado del portal no lo demuestra: el SQL se puede haber
escrito distinto.

Mientras esto no se pruebe, el estado honesto es "el aislamiento está diseñado,
no está verificado".

### 6.3 Sin revisión de seguridad externa

No hay nadie que haya revisado el código buscando vulnerabilidades.

### 6.4 La CSP no protege nada

Está en modo `report-only`. Con la CSP activa, si falta un recurso el local
queda en blanco y el mesero no puede cobrar, así que se dejó observando. Es un
riesgo abierto y declarado.

### 6.5 Sin límite de peticiones

No hay *rate limiting* en la API. Un restaurante tiene IP propia, lo que reduce
el riesgo, pero no lo elimina.

### 6.6 Imágenes sin fijar por digest

Están fijadas por tag exacto, que es mucho mejor que una etiqueta abierta, pero
un tag exacto también puede cambiar. Y `composer:2` va **sin versión**, porque
su versión exacta no se pudo verificar al escribir el Dockerfile.

### 6.7 Un cocinero puede tocar otra estación

No se modela la asignación de cocineros a estaciones. Declarado en
`ComandaItemPolicy` y en [03](03-MULTITENANCY-Y-ROLES.md).

### 6.8 Si Authentik se cae, no se cobra

El acceso es único para toda la organización. Hay que dejar un procedimiento de
recuperación escrito y probado.

### 6.9 Sin gestión de datos personales

Se guardan nombre, correo y la identificación que da el comensal al pagar. Hay
que definir información al cliente y retención según la Ley 19.628. **No está
implementado.**

## 7. Advertencia que no es técnica

**Esto no está listo para abrir un restaurante.**

La rotación de turnos y el manejo de caja tienen implicaciones **fiscales y
laborales** que ninguna aplicación resuelve sola. En Chile, la jornada y la
rotación del personal tienen reglas propias, y el registro de caja es un
documento con valor tributario.

Un sistema de gestión de turnos y comandas **no es** un sistema de nóminas, ni
un libro de caja, ni un cumplimiento de normativa laboral. Entregar esta
aplicación como "sistema para abrir un restaurante" sería vender algo distinto
de lo que es.

Lo que sí hace, bien construido: **coordinar salón y cocina y dejar registro de
lo que pasó.** Eso es un problema real y esta aplicación lo ataca en el punto
correcto. Lo demás hay que resolverlo con las herramientas que existen para eso y
con la asesoría que corresponda.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`restaurante_admin`) y el rol de la
aplicación (`restaurante_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `restaurante_app` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan restaurante:verificar-aislamiento` y confirmar que sale
   con código 0.
4. Probar el escenario que motiva todo el cambio: un restaurante intentando leer
   comandas o caja de otro.

### La columna equivocada en el comando verificador: corregida, sin comprobar

Al revisar `VerificarAislamientoCommand.php` se vio que la consulta que lee los
atributos del rol pedía la columna `usesuper`:

```sql
SELECT current_user AS rol, usesuper AS superusuario, rolbypassrls AS evade_rls
  FROM pg_roles WHERE rolname = current_user
```

La columna que existe en `pg_roles` se llama `rolsuper`, no `usesuper`. Con la
columna equivocada la consulta revienta, así que el comando no verificaba nada:
quien lo corría recibía un error y se quedaba con la impresión de que el
sistema estaba roto, no con la de que el aislamiento estaba caído.

**Se corrigió.** La consulta pide ahora `rolsuper`, el fallo de esa lectura se
reporta como problema en vez de reventar, y el mensaje dice qué se detectó, por
qué anula el aislamiento, qué hacer (`POSTGRES_USER` debe ser el rol de
arranque `restaurante_admin`, que la aplicación nunca usa; el rol de la
aplicación se crea `NOSUPERUSER NOBYPASSRLS` en
`deploy/postgres/inicializar-rol.sh`) y dónde está el patrón de referencia
(`apps/almacen/deploy/postgres/inicializar-rol.sh`).

**Sigue sin comprobarse contra una base real:** en el equipo donde se hizo el
cambio no había PostgreSQL ni Docker. La corrección es estática. Lo verificado es
`bash -n` sobre los scripts, YAML válido y el barrido de `tools/validar_apps.py`.

El mismo error estaba en `estudio-abogados`, y se corrigió allá también.

Lo verificado es estático: `bash -n` pasa sobre el script y el `compose` es YAML
válido, ambas cosas comprobadas por `tools/validar_apps.py`. El razonamiento se
apoya en la documentación oficial de PostgreSQL 18, no en una ejecución.