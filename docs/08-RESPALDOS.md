# 08 - Respaldos

## 1. Qué hay que respaldar

| Qué | Por qué | Método |
|---|---|---|
| **PostgreSQL** | Todo el historial: comandas, cuentas, bitácora | `pg_dump` |
| **`.env`** | Sin él no se puede levantar | Copia cifrada, fuera del servidor |
| **Volumen de Caddy** | Certificados TLS | `docker volume` |
| **Redis** | **Nada** | Ver abajo |

### Redis no se respalda

El reloj de cocina es volátil a propósito. Si se pierde, se reconstruye desde la
base con `php artisan restaurante:reconstruir-reloj`, que lee `enviado_at`,
`listo_at` y `entregado_at`.

Respaldarlo no evita nada: si Redis se perdió, un respaldo viejo no sirve. Y
guardar en disco un dato regenerable solo hace que Redis tarde más en reiniciar.

## 2. Por qué PostgreSQL es el único respaldo crítico

Todo lo que no se puede regenerar está ahí:

- La bitácora de cada comanda: quién pidió qué, quién lo anuló y por qué.
- El historial de la cuenta y los pagos.
- El catálogo con los precios que se cobraron.

Un respaldo sin esa información no sirve para reconstruir nada: sirve para
arrancar con la carta vacía y perder la capacidad de resolver una disputa de
caja.

## 3. Respaldo manual

```bash
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  exec -T postgres pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom \
  > "restaurante-$(date +%Y-%m-%d).dump"
```

`--format=custom` permite restaurar tabla por tabla, que es lo que se necesita
cuando hay que recuperar una sola comanda de una noche mala.

## 4. Restauración

```bash
# 1. Restaurar sobre una base NUEVA primero, nunca sobre la de producción.
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  exec -T postgres pg_restore -U "$DB_USERNAME" -d base_de_prueba --clean --if-exists < respaldo.dump

# 2. Verificar que abre y que los datos están.
# 3. Recién entonces, sobre producción.
```

**Restaurar sobre la base de producción sin probar en otro lado es la forma más
rápida de convertir un respaldo malo en una pérdida de datos.** PostgreSQL avisa
de errores y sigue, así que un `pg_restore` puede "terminar bien" con la mitad
de las tablas vacías.

## 5. Verificación

Un respaldo que nunca se restauró **no es un respaldo**. Es una intención.

Mensualmente:

1. Restaurar el último respaldo sobre una base nueva.
2. Comprobar que las tablas tienen filas:
   ```sql
   SELECT count(*) FROM comandas;
   SELECT count(*) FROM comanda_eventos;
   SELECT count(*) FROM pagos;
   ```
3. Arrancar la API contra esa base y revisar que el corte de caja calza.

## 6. Automatización

Cron en el servidor:

```cron
# A las 03:00, cuando el local está cerrado.
0 3 * * * /opt/restaurante/respaldar.sh >> /var/log/restaurante-respaldo.log 2>&1
```

Contenido de `/opt/restaurante/respaldar.sh`:

```bash
#!/bin/sh
set -e

cd /opt/restaurante
set -a; . ./.env; set +a

DESTINO=/opt/restaurante/respaldos
ARCHIVO="$DESTINO/restaurante-$(date +%Y-%m-%d).dump"

docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  exec -T postgres pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom > "$ARCHIVO"

# Un respaldo que no se comprobó no es un respaldo: el script falla si el
# archivo está vacío o si no se puede leer con pg_restore.
test -s "$ARCHIVO"
docker compose -f deploy/docker-compose.prod.yml --env-file .env \
  exec -T postgres pg_restore --list < "$ARCHIVO" > /dev/null

# Conservar 30 días.
find "$DESTINO" -name 'restaurante-*.dump' -mtime +30 -delete
```

La comprobación `pg_restore --list` es la que distingue un respaldo de un
archivo de texto que dice "OK".

## 7. Copia fuera del servidor

Un respaldo en el mismo disco que los datos **no es un respaldo**: si se cae el
disco, se cae también la copia.

Hefesto usa el kit **"Hefesto Respaldo Gestionado"** (Kopia), con el
almacenamiento remoto del cliente. Encaja con este sistema por dos razones: los
volúmenes del servidor de un local son chicos, y Kopia deduplica, así que la
cadencia diaria durante meses no cuesta casi nada.

## 8. Restauración ante desastre

El escenario real: se cayó el servidor y el local tiene servicio esta noche.

| Paso | Qué hacer |
|---|---|
| 1 | Levantar el servidor nuevo, mismo dominio |
| 2 | Copiar el `.env` del respaldo cifrado |
| 3 | Levantar PostgreSQL y restaurar el último `pg_dump` |
| 4 | Levantar el resto |
| 5 | Reconstruir el reloj de cocina |

El paso 5 no se puede saltar. Sin él, la pantalla de cocina sale sin tiempos y
los pedidos en preparación dejan de ser visibles: exactamente el problema que el
sistema existe para resolver.

## 9. Qué no se respalda y por qué

| Qué | Por qué |
|---|---|
| Contenedores | Se reconstruyen desde el código, que está versionado |
| Certificados de Caddy | Se reemiten; los volúmenes solo aceleran el primer arranque |
| **Redis** | Regenerable desde la base |
| **La aplicación en sí** | El código está en el repositorio |

La regla de oro: **lo que no está en el repositorio y no se puede regenerar,
está en el respaldo.** En este caso, casi todo el respaldo es una base de datos.