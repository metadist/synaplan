# synaplan-base-php 1.0.0 ships fully-tuned PHP ini files under
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
#   docker buildx imagetools inspect ghcr.io/metadist/synaplan-base-php:<new>
# and replace the @sha256:... below.
#
# Multi-stage layout:
#   - `dev`  : base image + phpredis + baked ENV defaults ONLY. The dev/worker
#              containers bind-mount the application code, vendor and entrypoint
#              scripts at runtime, so they need nothing more from the image than
#              the PHP runtime with ext-redis. Building this stage is independent
#              of backend source, so `docker compose up -d` stays a cache hit
#              after the first build (no composer install / COPY backend churn).
#   - `prod` : full self-contained image (app + composer install + frontend).
#              This is the LAST stage, so a build without an explicit `target`
#              (CI, the published :latest, docker-compose.test.yml) still yields
#              the complete production image.
FROM ghcr.io/metadist/synaplan-base-php:1.0.1@sha256:35e81beba0b8b09f8b43cd3a371df1e91e6b7c34c9c02e37d263f63308c022d9 AS dev

WORKDIR /var/www/backend

# phpredis (ext-redis) — required by Symfony Messenger's Redis Streams transport
# (`symfony/redis-messenger`). Cache, Sessions, and the platform RedisService all
# use Predis (pure PHP) and do NOT need this extension, but the messenger
# CONSUMER must speak the binary protocol via phpredis.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

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

# ---------------------------------------------------------------------------
# `dev` target ends here: base image + phpredis + ENV defaults. Everything
# below builds the self-contained `prod` image and is skipped for dev/worker
# containers (they bind-mount code/vendor/entrypoint via docker-compose).
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
RUN composer install --prefer-dist --no-interaction --no-dev --no-scripts --optimize-autoloader --classmap-authoritative && \
    chown -R www-data:www-data /var/www/backend/vendor

# Copy and setup entrypoint scripts (entrypoint + sourced migrations-bootstrap library)
COPY _docker/backend/docker-entrypoint.sh /usr/local/bin/
COPY _docker/backend/lib/ /usr/local/bin/lib/
COPY _docker/backend/synaplan /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/synaplan

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

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
