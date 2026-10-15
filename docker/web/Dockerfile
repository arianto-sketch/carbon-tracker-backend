# syntax=docker/dockerfile:1.7
# Image web: build SPA dari repo carbon-tracker-frontend lalu sajikan lewat nginx, sekaligus
# meneruskan /api dan /up ke php-fpm (service app). Satu origin, jadi tidak perlu CORS.
#
# Konteks utama = folder ini (konfigurasi nginx); repo frontend masuk sebagai konteks tambahan
# bernama "frontend" (lihat compose.yml). Hanya file yang dibutuhkan build yang disalin, jadi
# node_modules/dist lokal tidak ikut terbawa.

FROM node:22-alpine AS build

WORKDIR /app

COPY --from=frontend package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --from=frontend index.html vite.config.ts tsconfig.json tsconfig.app.json tsconfig.node.json ./
COPY --from=frontend public ./public
COPY --from=frontend src ./src

# Path relatif: API dipanggil di origin yang sama dengan SPA
ARG VITE_API_URL=/api/v1
ARG VITE_SENTRY_DSN=
ENV VITE_API_URL=${VITE_API_URL} \
    VITE_SENTRY_DSN=${VITE_SENTRY_DSN}

RUN npm run build

# --- prod: nginx tanpa root, listen 8080 ---
FROM nginxinc/nginx-unprivileged:1.27-alpine AS prod

COPY default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 8080
