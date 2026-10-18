# 05 - Configuración

> **Estado: no se ha ejecutado.** Ver [10](10-PRUEBAS-Y-ESTADO.md).

## 1. `.env.example` y la regla del `.env`

`.env.example` **no tiene ningún valor real** y no debe recibir ninguno. El
`.env` real vive solo en el servidor.

```
.env.example   ->  plantilla, versionada, sin secretos
.env           ->  el real, nunca en el repositorio, nunca en una imagen
```

Hay un verificador estático en `api/tests/Estatico/comprobar_entrega.py` que
falla si `.env.example` trae un valor en una variable sensible.

## 2. Variables

### Aplicación

| Variable | Obligatoria | Notas |
|---|---|---|
| `APP_ENV` | Sí | `produccion` en el servidor. |
| `APP_DEBUG` | Sí | **Siempre `false` en producción.** Con `true` se muestran rutas y excepciones al navegador. |
| `APP_KEY` | Sí | `php artisan key:generate --show`. Cifra las cookies. |
| `APP_URL` | Sí | Debe coincidir con el dominio real. |

### Base de datos

| Variable | Obligatoria | Notas |
|---|---|---|
| `DB_DATABASE` | Sí | |
| `DB_USERNAME` | Sí | **No puede ser superusuario.** Con superusuario, PostgreSQL ignora las políticas de RLS. |
| `DB_PASSWORD` | Sí | |

El rol de la base no puede tener `BYPASSRLS` por la misma razón. Se comprueba en
el despliegue con `restaurante:verificar-aislamiento`.

### Redis

| Variable | Obligatoria | Notas |
|---|---|---|
| `REDIS_PASSWORD` | Sí en producción | Redis sin password en un servidor con red es un problema. |
| `CACHE_STORE` | Sí | `redis`. |
| `QUEUE_CONNECTION` | Sí | `redis`. |
| `SESSION_DRIVER` | Sí | `redis`, **no** `database`. La base tiene que responder rápido a la pantalla de cocina y no conviene mezclarla con sesiones. |

### Pantalla de cocina

| Variable | Por defecto | Notas |
|---|---|---|
| `COCINA_INTERVALO_REFRESCO_MS` | 3000 | Cada cuánto consulta la pantalla. **No bajar de 3000.** |
| `COCINA_REDIS_RETENCION_HORAS` | 72 | Tiempo de diagnóstico de la línea de tiempo. El histórico real está en la base. |

Sobre el intervalo: la pantalla está junto a la cocina y recargar veinte pedidos
una vez por segundo no le aporta nada a quien cocina y carga el servidor. El
tiempo que muestra se calcula en cada respuesta, así que con 3 segundos el
número avanza solo.

### Interfaz

| Variable | Obligatoria | Notas |
|---|---|---|
| `NUXT_SESSION_PASSWORD` | Sí | `openssl rand -base64 32`. Sella la cookie de sesión. **Sin ella la aplicación no arranca.** |
| `NUXT_API_BASE` | Sí (compose) | Interna: `http://api:8080`. |
| `NUXT_PUBLIC_BASE` | Sí | Origen público. |
| `NUXT_INTERVALO_COCINA_MS` | No | Espejo del intervalo, para el navegador. |

`NUXT_SESSION_PASSWORD` es lo único que impide que alguien fabrique su propia
sesión. Se comprueba que tenga al menos 32 caracteres al arrancar.

### Authentik

| Variable | Obligatoria | Notas |
|---|---|---|
| `AUTHENTIK_ISSUER` | Sí | **Sin barra final.** Se compara con el documento de descubrimiento y una barra de más hace que no coincidan. |
| `AUTHENTIK_CLIENT_ID` | Sí | |
| `AUTHENTIK_CLIENT_SECRET` | Sí | |
| `AUTHENTIK_REDIRECT_URI` | Sí | Debe coincidir exactamente con el del proveedor. |
| `AUTHENTIK_GRUPO_ADMIN` | No | Solo para mostrar el nombre. **La API es la autoridad del rol.** |

### Integración con el punto de venta

| Variable | Por defecto | Notas |
|---|---|---|
| `POS_HABILITADA` | `false` | **La integración no está implementada.** Ver [09](09-API.md). |
| `POS_URL`, `POS_TOKEN`, `POS_RUTA_COBROS_PENDIENTE` | vacías | Se dejan vacías hasta que exista el contrato real del POS del cliente. |

Inventar un valor ahí sería peor que dejarlo vacío: un endpoint inventado
produce un error de autenticación en el mejor caso y una sincronización con el
sistema equivocado en el peor.

### Despliegue

| Variable | Por defecto | Notas |
|---|---|---|
| `CORRER_MIGRACIONES` | `false` | En producción se corren a mano, una vez. |

## 3. Lo que NO está en variables de entorno

Los valores de negocio **no** viven en el `.env`:

| Qué | Dónde vive | Por qué |
|---|---|---|
| Tiempo objetivo de cada estación | `estaciones` | Cada local cocina a su ritmo. |
| Horarios de apertura | `turnos` | Cambian por temporada. |
| Precios y carta | `productos` | Cambian; y van en la base para que el POS y el salón los compartan. |
| Quién puede anular | `roles` en `usuarios` | Cambia con la rotación de personal. |

Ponerlos en el `.env` obligaría a un despliegue y un reinicio para cambiar un
tiempo de cocción. La excepción es `RESTAURANTE_ROL_QUE_ANULA`, que existe para
poder cambiar sin desplegar.

**Lo que nunca es configurable:** que una comanda cobrada no se anula desde este
sistema. No es una preferencia de instalación, es una protección del cobro.

## 4. Zona horaria

`deploy/api/docker/php.ini` fija `date.timezone = America/Santiago`, y
`restaurantes.timezone` existe por restaurante.

Sin esto, un pedido de las 21:00 se guardaría con la hora de otro país y el
tiempo en pantalla de cocina sería incorrecto. Para un local que opera de noche,
eso no es un detalle menor: el pico de la jornada cae entre las 20 y las 23.

## 5. Comprobación de la configuración

```bash
# ¿Hay superusuario o BYPASSRLS? ¿Falta alguna política?
php artisan restaurante:verificar-aislamiento

# ¿El reloj de cocina está lleno?
php artisan restaurante:reconstruir-reloj
```

El primero corre solo al arrancar el contenedor y una vez al día. Si falla, hay
que detener la instalación.