FROM ghcr.io/metadist/synaplan-base-php:0.1.0

WORKDIR /var/www/backend

# PHP Configuration
RUN mkdir -p /var/www/uploads && chmod 777 /var/www/uploads && \
    printf "upload_max_filesize=512M\npost_max_size=512M\nmax_file_uploads=50\nmemory_limit=512M\nupload_tmp_dir=/var/www/uploads\nsys_temp_dir=/var/www/uploads\nmax_execution_time=300\n" \
    > /usr/local/etc/php/conf.d/uploads.ini && \
    printf "opcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\n" \
    > /usr/local/etc/php/conf.d/opcache.ini && \
    printf "display_errors=Off\ndisplay_startup_errors=Off\nlog_errors=On\nerror_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT\n" \
    > /usr/local/etc/php/conf.d/production.ini

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

# Copy application (exclude .env files - use environment variables instead)
COPY --chown=www-data:www-data backend/ .
RUN rm -f .env .env.example .env.local .env.*.local

# Generate gRPC proto files using composer script (single source of truth)
RUN composer proto:generate

# Create directories before composer install to avoid permission layer duplication
RUN mkdir -p /var/www/backend/var/cache /var/www/backend/var/log /var/www/backend/var/uploads /var/www/backend/var/whisper /var/www/backend/public/up && \
    chown -R www-data:www-data /var/www/backend/var /var/www/backend/public/up && \
    chmod -R 775 /var/www/backend/var /var/www/backend/public/up

# Install dependencies with production optimizations
# Flags explained:
# --no-dev: Skip dev dependencies (phpunit, fixtures, etc.) for smaller image
# --no-scripts: Skip post-install scripts (cache:clear requires runtime DB connection)
# --optimize-autoloader: Generate optimized PSR-4 class maps
# --classmap-authoritative: Never scan filesystem for classes (all classes are in composer.json)
#   This is safe for Symfony apps where all classes are autoloaded via composer
#   Provides significant performance improvement by eliminating file_exists() calls
# Cache is cleared on container startup anyway via docker-entrypoint.sh
RUN composer install --prefer-dist --no-interaction --no-dev --no-scripts --optimize-autoloader --classmap-authoritative --ignore-platform-req=ext-redis && \
    chown -R www-data:www-data /var/www/backend/vendor

# Copy and setup entrypoint scripts
COPY _docker/backend/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy built frontend (built externally in CI, empty in dev)
COPY frontend/dist /var/www/frontend

# Copy built widgets (built externally in CI, empty in dev)
COPY frontend/dist-widget /var/www/widgets

# Copy plugins directory (for SortX and other plugins)
COPY plugins/ /plugins/

# Copy Caddyfile for production routing (API vs SPA)
COPY _docker/backend/Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
