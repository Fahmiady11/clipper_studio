# syntax=docker/dockerfile:1
# Production image for Clipper Studio. Build from repo root:
#   docker build -f docker/Dockerfile -t clipper-app:<tag> .

# Pinned upstream versions (checked 2026-10-10)
ARG WHISPER_CPP_VERSION=v1.9.5
ARG YTDLP_VERSION=2026.08.19
ARG DENO_VERSION=v2.9.7

# ---- 2. vendor: PHP dependencies (no scripts; package:discover runs in runtime) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
RUN composer dump-autoload --no-dev --no-scripts --optimize --classmap-authoritative

# ---- 1. assets: Vite build -> public/build ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# Tailwind @source references the framework's pagination views
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources ./vendor/laravel/framework/src/Illuminate/Pagination/resources
RUN mkdir -p storage/framework/views && npm run build

# ---- 3. whisper: build whisper-cli ----
FROM debian:bookworm AS whisper
ARG WHISPER_CPP_VERSION
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential cmake curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /src
RUN curl -fsSL "https://github.com/ggml-org/whisper.cpp/archive/refs/tags/${WHISPER_CPP_VERSION}.tar.gz" \
    | tar xz --strip-components=1
# GGML_NATIVE=OFF: do not tune for the build host CPU (portable binary)
RUN cmake -B build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DGGML_NATIVE=OFF \
        -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_EXAMPLES=ON \
    && cmake --build build -j"$(nproc)" --target whisper-cli \
    && install -m 0755 build/bin/whisper-cli /whisper-cli

# ---- 4. runtime ----
FROM php:8.4-fpm-bookworm AS runtime
ARG YTDLP_VERSION
ARG DENO_VERSION

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx supervisor ffmpeg curl ca-certificates unzip postgresql-client libgomp1 \
        libpq-dev libicu-dev libzip-dev libfreetype6-dev libjpeg-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache pcntl gd \
    && apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev libfreetype6-dev libjpeg-dev libwebp-dev \
    && apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 libfreetype6 libjpeg62-turbo libwebp7 libpng16-16 \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    arch="$(uname -m)"; \
    case "$arch" in \
        x86_64)  ytdlp=yt-dlp_linux;         deno_arch=x86_64-unknown-linux-gnu ;; \
        aarch64) ytdlp=yt-dlp_linux_aarch64; deno_arch=aarch64-unknown-linux-gnu ;; \
        *) echo "unsupported arch $arch"; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/${ytdlp}" -o /usr/local/bin/yt-dlp; \
    chmod 0755 /usr/local/bin/yt-dlp; \
    curl -fsSL "https://github.com/denoland/deno/releases/download/${DENO_VERSION}/deno-${deno_arch}.zip" -o /tmp/deno.zip; \
    unzip -q /tmp/deno.zip -d /usr/local/bin; \
    chmod 0755 /usr/local/bin/deno; \
    rm /tmp/deno.zip

COPY --from=whisper /whisper-cli /usr/local/bin/whisper-cli

# PHP / FPM / nginx / supervisor config
# php.ini-production: display_errors Off, log_errors On (error_log unset -> stderr / fpm log).
# Limits follow deploy/php-clipper.ini (memory 512M, expose_php Off); uploads match
# nginx client_max_body_size 2g, max_execution_time matches fastcgi/fpm timeout 300.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'display_errors=Off'; \
        echo 'display_startup_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'expose_php=Off'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'upload_max_filesize=2G'; \
        echo 'post_max_size=2G'; \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/zz-clipper.ini \
    && { \
        echo '[www]'; \
        echo 'clear_env = no'; \
        echo 'request_terminate_timeout = 300'; \
    } > /usr/local/etc/php-fpm.d/zz-clipper.conf \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/clipper.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Bake empty storage skeleton + discover packages (scripts were skipped in the vendor stage)
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
