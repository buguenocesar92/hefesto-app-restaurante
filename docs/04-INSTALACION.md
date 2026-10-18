# 04 - Instalación

> **Estado: no se ha ejecutado.** En el entorno donde se construyó esta
> aplicación no había Docker, ni PHP, ni Composer. Los comandos de esta guía son
> los correctos según la documentación de cada herramienta, pero **nadie los
> corrió**. Ver [10](10-PRUEBAS-Y-ESTADO.md).

## 1. Requisitos

| Componente | Versión fijada | Por qué esa |
|---|---|---|
| PHP | 8.4.26 | Extensiones `pdo_pgsql`, `pcntl`, `bcmath`, `intl` |
| Node | 24.22.0 | Requisito de Nuxt 4 |
| PostgreSQL | 18.6 | Row Level Security |
| Redis | 8.8.3 | Reloj de cocina |
| Authentik | 2025.10 | **Desde 2025.10 no usa Redis**: son 3 servicios |
| Caddy | 2.11 | TLS y proxy |

Las imágenes están fijadas por tag exacto. **Pendiente declarado:** fijarlas
también por digest SHA256, y fijar la imagen `composer`, que en
`deploy/api/Dockerfile` va sin versión porque su versión exacta no se pudo
verificar al escribir el archivo. Antes de producción hay que resolverlo.

## 2. Desarrollo

```bash
# 1. Configuración
cp .env.example .env

# 2. Generar las claves (las dos son obligatorias)
docker compose -f deploy/docker-compose.dev.yml run --rm api php artisan key:generate --show
#   -> copiar la salida a APP_KEY en el .env

#   NUXT_SESSION_PASSWORD se genera con:
#   openssl rand -base64 32

# 3. Completar en el .env las variables de Authentik

# 4. Levantar
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d --build
```

El `entrypoint` de la API corre las migraciones, siembra el restaurante de
ejemplo, verifica el aislamiento y reconstruye el reloj de cocina.

### Puertos en desarrollo

| Servicio | Puerto | Nota |
|---|---|---|
| Interfaz | 3000 | El único que se usa de verdad |
| API | 8000 | Para depurar; en producción **no se pública** |
| Authentik | 9000 | Solo en la interfaz local del host |
| PostgreSQL | 5432 | Solo `127.0.0.1` |
| Redis | 6379 | Solo `127.0.0.1` |

## 3. Configurar Authentik

En la consola de Authentik, crear un proveedor OIDC de tipo
**OpenID Connect / Provider**:

| Campo | Valor |
|---|---|
| Nombre | `Restaurante` |
| Tipo | OpenID Connect |
| Authorization flow | `authorization code` |
| Client type | `confidential` |
| Redirect URI | `https://<dominio>/api/auth/callback` |
| Scope | `openid profile email` |
| Secret | (generado por Authentik) |

Y una aplicación OAuth2 que apunte a ese proveedor, con el mismo redirect URI.

El dominio de Authentik tiene que ser el mismo que el de la aplicación para que
la redirección de retorno sea válida.

### El primer administrador

El primer usuario debe estar en el grupo que aparece en
`AUTHENTIK_GRUPO_ADMIN`. **La API es la autoridad del rol**, no el grupo: el
grupo sirve para mostrar el nombre. El rol se asigna en la tabla `usuarios`, y
asignarlo es una tarea de administración de Hefesto, no del restaurante.

> **Riesgo conocido:** si Authentik se cae, nadie puede entrar a cobrar. En una
> instalación real hay que dejar un acceso local de emergencia en al menos una
> aplicación crítica, o un procedimiento de recuperación escrito y probado.

## 4. Producción

```bash
# 1. Preparar el .env con valores reales (ver docs/05)

# 2. Construir las imágenes
docker compose -f deploy/docker-compose.prod.yml --env-file .env build

# 3. Correr las migraciones UNA vez, a mano
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  run --rm api php artisan migrate --force

# 4. Sembrar el restaurante de ejemplo (opcional, solo el primero)
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  run --rm api php artisan db:seed --force

# 5. Levantar
docker compose -f deploy/docker-compose.prod.yml --env-file .env up -d
```

En producción `CORRER_MIGRACIONES=false`. Con varias réplicas de la API, todas
correrían `migrate` al arrancar y es mejor no depender del bloqueo de Laravel
para evitarlo.

### Reemplazar los dominios de ejemplo

Dos archivos llevan `ejemplo.cl` y hay que cambiarlos antes de levantar:

1. `deploy/caddy/Caddyfile`: el dominio del bloque principal.
2. `.env`: `APP_URL`, `APP_WEB_URL`, `AUTHENTIK_ISSUER`,
   `AUTHENTIK_REDIRECT_URI`.

### Comprobaciones antes de abrir el local

```bash
# El aislamiento entre restaurantes
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  run --rm api php artisan restaurante:verificar-aislamiento

# El reloj de cocina
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  run --rm api php artisan restaurante:reconstruir-reloj

# La salud de la API
curl -fsS https://<dominio>/api/salud-interno
```

El comando de aislamiento **tiene que salir con éxito**. Si falla, un
restaurante puede estar viendo los datos de otro y hay que detener la
instalación.

## 5. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| La pantalla de cocina sale vacía | Redis no tiene el reloj. Correr `restaurante:reconstruir-reloj`. |
| Todas las consultas fallan con "No hay contexto de tenant" | La petición no pasó por el middleware, o se ejecutó fuera de una transacción. |
| "No hay un turno activo" al operar | Hay que crear los turnos del local y activarlos. Ver [06](06-PRIMER-TURNO.md). |
| Errores 419 en el navegador | El token CSRF no llegó a la API. El BFF lo reenvía; si falla, revisar `NUXT_SESSION_PASSWORD`. |
| La aplicación no arranca con el reloj | `NUXT_SESSION_PASSWORD` vacía o más corta de 32 caracteres. |
| El aislamiento falla la verificación | El rol de la base es superusuario o tiene `BYPASSRLS`. Corregir antes de seguir. |

## 6. Requisitos de servidor

Estimación para un local con hasta 40 mesas:

| Recurso | Mínimo | Cómodo |
|---|---|---|
| CPU | 2 núcleos | 4 núcleos |
| RAM | 2 GB | 4 GB |
| Disco | 20 GB | 40 GB (los respaldos también viven aquí) |

Caddy administra el certificado TLS automáticamente. En un servidor con IP
pública y puertos 80 y 443 abiertos, no hace falta configurar nada más.