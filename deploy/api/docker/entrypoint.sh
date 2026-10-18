#!/bin/sh
# ---------------------------------------------------------------------------
# Arranque de la API.
# ---------------------------------------------------------------------------
# Las migraciones se ejecutan al arrancar y SOLO si la variable lo pide.
#
# Con dos o mas replicas de la API habilitadas, todas correrian `artisan
# migrate` al mismo tiempo. Laravel tiene un bloqueo para esto, pero es mejor no
# depender de el: en produccion las migraciones se corren una vez, a mano, y el
# despliegue se hace con `CORRER_MIGRACIONES=false`.
#
# La verificacion del aislamiento se corre SIEMPRE. Si el rol de la base quedo
# como superusuario o con BYPASSRLS, la base entrega datos de otros restaurantes
# y nadie se entera. Un comando que falla en el despliegue es una barrera mas.
# ---------------------------------------------------------------------------

set -e

cd /var/www/html

if [ ! -f .env ]; then
    echo "Falta el archivo .env. Revisar deploy/docker-compose.prod.yml."
    exit 1
fi

php artisan config:clear || true
php artisan route:clear || true

if [ "${CORRER_MIGRACIONES}" = "true" ]; then
    echo "Ejecutando migraciones..."
    php artisan migrate --force

    echo "Sembrando el restaurante de ejemplo (solo si la base esta vacia)..."
    php artisan db:seed --force || echo "La semilla fallo o ya habia datos. Revisar."
fi

echo "Verificando el aislamiento entre restaurantes..."
php artisan restaurante:verificar-aislamiento

# El reloj de cocina vive en Redis y es volatil. Si el contenedor arranca con
# Redis recien reiniciado, la pantalla saldria sin tiempos hasta que alguien
# marque algo. Se repuebla al arrancar.
php artisan restaurante:reconstruir-reloj || echo "No se pudo reconstruir el reloj de cocina. Revisar Redis."

echo "API lista."

exec "$@"