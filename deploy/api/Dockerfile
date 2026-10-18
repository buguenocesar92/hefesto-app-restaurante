# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + php-fpm + nginx interno)
# ---------------------------------------------------------------------------
# Las versiones estan fijadas por tag exacto, no por etiqueta abierta: una
# etiqueta como `8.4` puede cambiar de contenido sin avisar.
#
# PENDIENTE, declarado en docs/10: fijarlas tambien por digest SHA256 y fijar la
# imagen `composer`, que aqui va sin version porque su version exacta no se pudo
# verificar al escribir este archivo. Antes de produccion hay que resolverlo.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Etapa 1: dependencias.
# ---------------------------------------------------------------------------
FROM php:8.4.26-cli-alpine3.23 AS dependencias

RUN apk add --no-cache git unzip libpq-dev icu-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copian primero los manifiestos para que la capa de dependencias se
# reutilice cuando solo cambian archivos del codigo.
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---------------------------------------------------------------------------
# Etapa 2: aplicacion.
# ---------------------------------------------------------------------------
FROM php:8.4.26-fpm-alpine3.23 AS app

# `nginx` es el servidor web interno, para que la API responda por HTTP en el
# puerto 8080. El servidor publico es Caddy. `curl` se instala solo para las
# comprobaciones de salud.
RUN apk add --no-cache nginx supervisor curl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl opcache \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=dependencias /var/www/html /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/restaurante.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]