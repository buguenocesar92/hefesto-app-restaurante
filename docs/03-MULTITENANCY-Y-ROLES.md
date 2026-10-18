# 03 - Multitenancy y roles

Este es el documento más importante del proyecto. El riesgo número uno de esta
aplicación no es que falle una pantalla: es que el mesero de un restaurante vea
las comandas de otro.

## 1. La decisión: columna `tenant_id` + Row Level Security

Se reutiliza el mismo patrón de `apps/portal-autoservicio`
(`docs/03-MULTITENANCY.md`): todas las tablas de negocio llevan `tenant_id`, y
PostgreSQL aplica una política que exige que coincida con el de la petición.

Aquí el tenant es un restaurante. La columna se llama igual `tenant_id` para no
mantener dos patrones paralelos.

### Por qué no un esquema por restaurante

La alternativa clásica es un esquema por cliente (`restaurante_1.comandas`). Se
evaluó y se descartó, por los mismos motivos que en el portal:

1. **Las migraciones se multiplican.** Cada migración tiene que aplicarse en
   todos los esquemas.
2. **El pool de conexiones se dimensiona por combinación (esquema, rol).**
3. **Laravel obliga a duplicar la lógica de esquema**, y ese acoplamiento produce
   fugas.
4. **El aislamiento queda en manos de la aplicación**, con `search_path` como
   parámetro de sesión: si queda mal puesto, la consulta va al esquema equivocado
   y **no falla**.

El análisis completo está en el documento del portal. Aquí se resume lo que
importa: con RLS, la política vive en la base y **no se puede olvidar**.

## 2. El mecanismo

Definido en `api/database/migrations/2026_10_17_000013_activar_row_level_security.php`.

### 2.1 La función de contexto

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid ...
    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION ...
    RETURN valor::uuid;
```

**Lanza excepción si no hay tenant.** Podría devolver `NULL` y filtrar todo en
silencio, pero se eligió que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo `NULL` cuando hace falta y la puerta queda abierta sin
  que nada la delate.
- Si lanza excepción, el error aparece en el log en el momento y se sabe qué
  operación se escapó del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### 2.2 La política

```sql
CREATE POLICY politica_aislamiento_comandas ON public.comandas
    FOR ALL TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con otro `tenant_id`. Sin esto, un
  `INSERT` con el tenant equivocado pasaría la lectura y dejaría una fila
  invisible que nadie podría ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al dueño de
  la tabla**. Sin `FORCE`, en desarrollo (donde el rol de la aplicación suele ser
  el dueño) el aislamiento sería decorativo.

### 2.3 Cómo viaja el tenant

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $restauranteId]);
```

El `true` final es lo más importante: **alcance de transacción**. Al confirmar o
revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión), el valor sobreviviría a la petición, y
el worker de colas —que reutiliza la misma conexión para trabajos de
restaurantes distintos— podría leer datos de otro local. Por eso
`ContextoRestaurante::establecer()` **lanza excepción si no está dentro de una
transacción**.

### 2.4 Quién lo fija

Solo una clase: `App\Services\Tenancy\ContextoRestaurante`, y solo a través del
middleware `App\Http\Middleware\ResolverRestaurante`.

## 3. El punto ciego: `restaurantes`

`restaurantes` **no lleva RLS**, a propósito: se consulta cuando todavía no hay
`app.tenant_id` que poner. Por eso `restaurantes` es la excepción controlada del
patrón.

Es una excepción consciente, con dos compensaciones:

1. **La vista `vw_restaurantes_publicos`** (migración
   `2026_10_17_000014_crear_vista_restaurantes_publicos.php`, que expone `slug`,
   `nombre` y `activo` de los restaurantes activos). **Hoy queda sin uso**: el
   código de ejecución nunca la consulta.

   Hay que decirlo con precisión: esta API **no tiene selector de restaurante**
   antes de iniciar sesión. Todas sus rutas son del grupo `web` con `auth`, así que
   el restaurante se obtiene de `usuario.tenant_id` una vez que la sesión existe. La
   única lectura de `restaurantes` sin contexto es la tarea de mantenimiento
   `restaurante:reconstruir-reloj`, que hace
   `DB::table('restaurantes')->where('activo', true)->pluck('id')` y trae **solo el
   identificador** de cada local activo, para abrir una transacción propia por
   cada uno.

   Sobre lo que se expone: la tabla guarda `correo_contacto` y `zona_geografica`,
   pero ninguno de los dos sale de esa consulta. Lo que se proyecta son columnas de
   identificación (`slug`, `nombre`), nunca datos de contacto ni datos de comandas
   o clientes.

   La vista no se eliminó: se conserva como defensa en profundidad y como respaldo
   para cuando se decida activar la protección.

   **Pendiente, no comprobado:** activar la vista exige revisar qué espera la
   interfaz y si algún flujo necesita más que el nombre. No se ha podido comprobar
   contra una base de datos porque no había una disponible, así que la decisión
   sigue abierta. Una versión anterior de este documento afirmaba que la
   aplicación leía la vista antes de autenticarse; era falso y se corrige aquí.

2. **El rol de la aplicación no es superusuario.** Quien tenga acceso SQL directo
   puede leer `restaurantes` entera.

**Riesgo residual, dicho con todas las letras:** un `slug` es público por diseño
(aparece en la URL de entrada y en los enlaces que el restaurante reparte). Con el
código actual no se expone antes de autenticarse, porque no hay selector. Si más
adelante se agrega uno, hay que decidir con el cliente si eso es aceptable. La
alternativa —dejar el campo en blanco y resolver por correo— queda anotada como
decisión pendiente del cliente, no como algo resuelto.

## 4. Roles

| Rol | Puede | **No puede** |
|---|---|---|
| `administrador` | Todo: catálogo, recetas, mesas, usuarios, turnos | Anular una comanda **cobrada** |
| `cajero` | Cobrar, dividir cuentas, abrir caja | Editar recetas ni precios |
| `mesero` | Abrir comandas, agregar ítems, pedir cuenta, anular ítems no cobrados | Cobrar, editar catálogo, entrar a administración |
| `cocinero` | Marcar estados de sus pedidos | Ver la cuenta, cobrar |
| `jefe_cocina` | Todo lo de cocina, vista completa por estación | Cobrar |

### La separación que el dueño reconoce

**Quien abre caja no es quien edita recetas.**

Con un único permiso de "administrador", el mesero con clave de gerente termina
cambiando precios porque nadie separó los dos poderes. Con roles explícitos,
abrir caja y tocar recetas son permisos distintos, y la bitácora
(`comanda_eventos`) muestra quién hizo cada cosa.

No es segurismo: es que el dato tenga un responsable.

### Doble filtro dentro del tenant

La RLS ya garantiza que nada sale del restaurante. Dentro hay un segundo filtro,
en las Policies:

| Acción | Mesero | Cajero | Cocinero | Administrador |
|---|---|---|---|---|
| Abrir comanda | Sí | Sí | No | Sí |
| Agregar ítems | Sí (si está abierta) | Sí | No | Sí |
| Marcar estado en cocina | No | No | Sí | Sí |
| Anular ítem no cobrado | Sí | Sí | No | Sí |
| Anular ítem **listo** | No | No | No | No (está emplatado) |
| **Anular comanda cobrada** | **No** | **No** | **No** | **No** |
| Pedir la cuenta | Sí | Sí | No | Sí |
| Cobrar | No | Sí | No | Sí |
| Editar recetas y precios | No | No | No | Sí |

Cuando una Policy rechaza por tenant, la respuesta es **404, no 403**: un 403
confirmaría que el recurso existe. Cuando rechaza por rol, es 403 con un mensaje
que explica qué hacer.

## 5. Una regla pendiente, declarada

**No se modela a qué estación está asignado cada cocinero.** En la práctica el
tablet está junto a la estación, pero un cocinero autenticado puede marcar el
estado de un ítem de otra estación si llega al endpoint.

Es una decisión consciente, no un olvido, y está anotado en
`app/Policies/ComandaItemPolicy.php`. Conviene cerrarlo cuando se definan los
turnos de cocina: la asignación por estación necesita una tabla que no existe
todavía.

## 6. Lo que NO se hizo

- No hay revisión de seguridad externa.
- No hay pruebas de penetración.
- **Las pruebas de aislamiento están escritas pero no se ejecutaron** (no había
  PostgreSQL disponible). Ver [10](10-PRUEBAS-Y-ESTADO.md).
- `restaurantes` queda sin RLS, con las compensaciones de la sección 3.

## 7. Cómo se verifica en el despliegue

```bash
php artisan restaurante:verificar-aislamiento
```

Comprueba cinco cosas y falla si alguna no se cumple:

1. El rol de la base **no es superusuario**. Con superusuario, PostgreSQL ignora
   todas las políticas y el aislamiento deja de existir sin dar ningún error.
2. El rol **no tiene `BYPASSRLS`**.
3. Todas las tablas de negocio tienen RLS activado.
4. Todas tienen su política de aislamiento.
5. **La función de contexto falla cerrada**: una consulta a `comandas` sin
   `app.tenant_id` tiene que reventar, no devolver cero filas en silencio.

Corre al arrancar el contenedor y una vez al día por el planificador.

---

## 8. Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre restaurantes funcione en vez
de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `restaurante_admin` | `POSTGRES_USER` | Crear la base `restaurante` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `restaurante_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**. Por eso su contraseña va en `DB_ADMIN_PASSWORD`, separada de
`DB_PASSWORD`.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: un
restaurante habría visto las comandas, los turnos y la caja de los demás, sin
error y sin aviso.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE restaurante OWNER TO restaurante_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan restaurante:verificar-aislamiento
```

El comando consulta `pg_roles` sobre el rol de la conexión y falla con código 1
si ese rol es superusuario o tiene `BYPASSRLS`, y después revisa que la RLS esté
activa y sea efectiva y que la función de contexto falle cerrada.

**Bug corregido en el comando, sin poder comprobarlo contra una base real:** la
consulta que pide los atributos del rol usaba la columna `usesuper`, que no
existe en `pg_roles`; la columna correcta es `rolsuper`. Con la columna
equivocada la consulta reventaba y el comando no verificaba nada. Ahora pide
`rolsuper`, y si la consulta falla por lo que sea, el comando lo reporta como
problema y sale con código 1 en vez de reventar.

Cuando el rol está mal, el mensaje dice qué se detectó, por qué eso anula el
aislamiento (un superusuario, o un rol con `BYPASSRLS`, ignora todas las
políticas, y `FORCE ROW LEVEL SECURITY` sujeta al dueño de la tabla pero no al
superusuario), qué hacer (`POSTGRES_USER` debe ser el rol de arranque
`restaurante_admin`, que la aplicación nunca usa, y el rol de la aplicación se
crea `NOSUPERUSER NOBYPASSRLS` en `deploy/postgres/inicializar-rol.sh`) y dónde
está el patrón de referencia (`apps/almacen/deploy/postgres/inicializar-rol.sh`).

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).