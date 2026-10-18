# ---------------------------------------------------------------------------
# Imagen de la capa web (Nuxt, salida en modo servidor)
# ---------------------------------------------------------------------------
# El navegador NO habla nunca con la API: va a este contenedor y este reenvia
# (BFF). Ver docs/01.
#
# PENDIENTE, declarado en docs/10: fijar la imagen de Node tambien por digest.
# ---------------------------------------------------------------------------

FROM node:24.22.0-alpine3.24 AS construccion

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

COPY . .

# `nuxt build` genera .output/, que es el servidor independiente. Se construye
# en tiempo de construccion, asi que el contenedor final no necesita Node, ni
# el codigo fuente, ni las dependencias.
RUN npm run build

# --- Imagen final -------------------------------------------------------
FROM node:24.22.0-alpine3.24 AS app

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_PORT=3000
ENV NITRO_HOST=0.0.0.0

# `node` es el usuario sin privilegios que trae la imagen oficial.
COPY --from=construccion --chown=node:node /app/.output ./.output

USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]