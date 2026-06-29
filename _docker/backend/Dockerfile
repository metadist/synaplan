# synaplan-base-php 1.1.0 ships fully-tuned PHP ini files under
# /usr/local/etc/php/conf.d/ (10-runtime, 20-opcache+JIT, 25-realpath,
# 27-grpc, 28-apcu, 30-errors, 40-security) and an entrypoint shim at
# /usr/local/bin/synaplan-php-configure that handles APP_ENV=dev override
# + FrankenPHP worker-mode activation. Anything you'd previously written
# to conf.d/ from this Dockerfile is now baked into the base image.
#
# Sizing overrides are surfaced as PHP_*/FRANKENPHP_* env vars; see the
# base image README at /usr/local/share/synaplan-base-php/README.md.
#
# Pinned to an immutable digest so reproducible builds don't depend on a
# moving floating tag. To bump:
#   docker buildx imagetools inspect ghcr.io/metadist/synaplan-base-php:latest
# and replace the @sha256:... below.
FROM ghcr.io/metadist/synaplan-base-php:1.1.0@sha256:6378ce497415579b9a8ded6395bc497e5589110a1b9bbb7544d1d490b1d3e1da AS dev

WORKDIR /var/www/backend

# iOS HDR "tmap"/gain-map HEIC photos (newer iPhones) require libheif >= 1.18 to
# decode. The base image ships Debian bookworm's libheif 1.15, which fails on them
# with "Failed to read the file", so HEIC->JPEG transcoding of modern iPhone
# uploads breaks. Pull the newer libheif + decoder plugins from bookworm-backports
# so ImageMagick/imagick (which loads libheif.so.1 at runtime) can read them.
# TODO: fold this into the synaplan-base-php base image and drop this layer.
RUN echo "deb http://deb.debian.org/debian bookworm-backports main" > /etc/apt/sources.list.d/backports.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends -t bookworm-backports \
        libheif1 \
        libheif-plugin-libde265 \
        libheif-plugin-aomenc \
        libheif-plugin-x265 \
        libheif-plugin-dav1d \
    && rm -rf /var/lib/apt/lists/*

# ext-redis (phpredis) is provided by the base image (synaplan-base-php >= 1.1.0)
# for Symfony Messenger's Redis Streams transport (`symfony/redis-messenger`),
# which the worker's `messenger:consume` speaks via the binary protocol. Cache,
# Sessions, and the platform RedisService use Predis (pure PHP) and don't need
# it. No downstream build step required — it ships in the base.

# Set default environment variables for paths and constants
# These are baked into the image since they reference local filesystem paths
ENV BRAVE_SEARCH_API_URL=https://api.search.brave.com/res/v1 \
    BRAVE_SEARCH_ENABLED=false \
    BRAVE_SEARCH_COUNT=10 \
    BRAVE_SEARCH_COUNTRY=us \
    BRAVE_SEARCH_SEARCH_LANG=en \
    TIKA_TIMEOUT_MS=30000 \
    TIKA_RETRIES=2 \
    TIKA_RETRY_BACKOFF_MS=1000 \
    TIKA_MIN_LENGTH=10 \
    TIKA_MIN_ENTROPY=3.0 \
    RASTERIZE_DPI=150 \
    RASTERIZE_PAGE_CAP=10 \
    RASTERIZE_TIMEOUT_MS=30000

# Entrypoint scripts (entrypoint + sourced migrations-bootstrap library).
# These MUST live in the `dev` stage: the dev/worker compose services bind-mount
# the host copies for live editing, but the image ENTRYPOINT has to be set so the
# backend container actually runs composer install + migrations on boot. Without
# it the container inherits the base image's default and only launches FrankenPHP,
# leaving vendor/ unpopulated (which then breaks the worker's bin/console).
COPY _docker/backend/docker-entrypoint.sh /usr/local/bin/
COPY _docker/backend/lib/ /usr/local/bin/lib/
COPY _docker/backend/synaplan /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/synaplan

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]

# ---------------------------------------------------------------------------
# `dev` target ends here: base image (phpredis included) + ENV defaults + entrypoint.
# Everything below builds the self-contained `prod` image and is skipped for
# dev/worker containers (they bind-mount code/vendor via docker-compose).
# `prod` inherits EXPOSE and ENTRYPOINT from `dev`.
# ---------------------------------------------------------------------------
FROM dev AS prod

# Copy application (exclude .env files - use environment variables instead)
COPY --chown=www-data:www-data backend/ .
RUN rm -f .env .env.example .env.local .env.*.local

# Generate gRPC proto files using composer script (single source of truth)
RUN composer proto:generate

# Create directories before composer install to avoid permission layer duplication
RUN mkdir -p /var/www/backend/var/cache /var/www/backend/var/log /var/www/backend/var/uploads /var/www/backend/var/whisper /var/www/backend/public/up && \
    chown -R www-data:www-data /var/www/backend/var /var/www/backend/public/up && \
    chmod -R 775 /var/www/backend/var /var/www/backend/public/up

# Copy plugins BEFORE composer install so the autoloader includes plugin classes
# Without this, --classmap-authoritative locks the autoloader to only backend classes
COPY plugins/ /plugins/

# Install dependencies with production optimizations
# Flags explained:
# --no-dev: Skip dev dependencies (phpunit, fixtures, etc.) for smaller image
# --no-scripts: Skip post-install scripts (cache:clear requires runtime DB connection)
# --optimize-autoloader: Generate optimized PSR-4 class maps
# --classmap-authoritative: Never scan filesystem for classes (all classes are in composer.json)
#   This is safe for Symfony apps where all classes are autoloaded via composer
#   Provides significant performance improvement by eliminating file_exists() calls
# Cache is cleared on container startup anyway via docker-entrypoint.sh
# COMPOSER_PROCESS_TIMEOUT=0 disables the 300s per-process limit so large
# packages (e.g. google/apiclient-services) still unzip when this stage is built
# under qemu emulation (e.g. amd64 image on an Apple Silicon host).
RUN COMPOSER_PROCESS_TIMEOUT=0 composer install --prefer-dist --no-interaction --no-dev --no-scripts --optimize-autoloader --classmap-authoritative && \
    chown -R www-data:www-data /var/www/backend/vendor

# Copy built frontend (built externally in CI, empty in dev)
COPY frontend/dist /var/www/frontend

# Copy built widgets (built externally in CI, empty in dev)
COPY frontend/dist-widget /var/www/widgets

# Copy Caddyfile for production routing (API vs SPA)
COPY _docker/backend/Caddyfile /etc/caddy/Caddyfile

# Override the base-image FrankenPHP worker-mode template so APP_RUNTIME points
# at our inlined App\Runtime\FrankenPhpRuntime class. The base image still
# handles the dev/prod toggle via synaplan-php-configure; it just renders OUR
# template into /etc/caddy/synaplan-worker-mode.Caddyfile when APP_ENV=prod.
# Required because runtime/frankenphp-symfony does not yet support Symfony 8.
COPY _docker/backend/synaplan-worker-mode.Caddyfile \
    /usr/local/etc/php/templates/synaplan-worker-mode.Caddyfile

# EXPOSE 80 and ENTRYPOINT ["docker-entrypoint.sh"] are inherited from the `dev` stage.
