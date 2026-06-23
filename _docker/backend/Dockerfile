# synaplan-base-php 1.0.2 ships fully-tuned PHP ini files under
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
FROM ghcr.io/metadist/synaplan-base-php:1.0.2@sha256:e5d52125b95862bc6f544f09f2ba15b6f7607f870b7149695ab64dbcf8dc4d51 AS dev

WORKDIR /var/www/backend

# phpredis (ext-redis) — required by Symfony Messenger's Redis Streams transport
# (`symfony/redis-messenger`). Cache, Sessions, and the platform RedisService all
# use Predis (pure PHP) and do NOT need this extension, but the messenger
# CONSUMER must speak the binary protocol via phpredis.
#
# ext-redis is now baked into synaplan-base-php (its install-php-extensions list
# includes `redis`). The guard below keeps this build working against an OLDER
# pinned base by compiling phpredis via pecl ONLY when the base doesn't already
# ship it; it becomes a fast no-op once the FROM digest above is bumped to a
# redis-bearing base — at which point this whole RUN can be deleted.
RUN if php -r 'exit(extension_loaded("redis") ? 0 : 1);'; then \
        echo "ext-redis already provided by base image — skipping pecl build"; \
    else \
        apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
        && pecl install redis \
        && docker-php-ext-enable redis \
        && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
        && rm -rf /var/lib/apt/lists/* ; \
    fi

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
# `dev` target ends here: base image + phpredis + ENV defaults + entrypoint.
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
