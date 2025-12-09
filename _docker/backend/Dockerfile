# Synaplan Symfony Backend - FrankenPHP + PHP 8.3
# Using bookworm (Debian 12) because Trixie (Debian 13) removed libc-client-dev needed for IMAP
FROM dunglas/frankenphp:php8.3-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl unzip jq \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    libfreetype6-dev libjpeg62-turbo-dev libwebp-dev \
    libsodium-dev libffi-dev \
    ghostscript imagemagick libmagickwand-dev poppler-utils \
    build-essential cmake pkg-config \
    libopenblas-dev liblapack-dev gfortran \
    protobuf-compiler \
    ffmpeg libavcodec-extra libavformat-dev libavutil-dev \
    libswscale-dev libavfilter-dev libswresample-dev \
    libx264-dev libx265-dev libvpx-dev libmp3lame-dev libopus-dev \
    libc-client-dev libkrb5-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/backend

# Install whisper.cpp
RUN cd /tmp && \
    git clone https://github.com/ggerganov/whisper.cpp.git && \
    cd whisper.cpp && \
    git checkout v1.5.4 && \
    make -j$(nproc) || make -j1 && \
    cp main /usr/local/bin/whisper && \
    cp quantize /usr/local/bin/whisper-quantize && \
    chmod +x /usr/local/bin/whisper* && \
    cd / && rm -rf /tmp/whisper.cpp

# Install PHP extensions
RUN install-php-extensions \
    pdo_mysql mysqli \
    exif pcntl bcmath gd imagick zip sodium ffi \
    grpc intl opcache imap

# PHP Configuration
RUN mkdir -p /var/www/uploads && chmod 777 /var/www/uploads && \
    printf "upload_max_filesize=128M\npost_max_size=128M\nmax_file_uploads=50\nmemory_limit=512M\nupload_tmp_dir=/var/www/uploads\nsys_temp_dir=/var/www/uploads\n" \
    > /usr/local/etc/php/conf.d/uploads.ini && \
    printf "opcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\n" \
    > /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set default environment variables for paths and constants
# These are baked into the image since they reference local filesystem paths
ENV WHISPER_BINARY=/usr/local/bin/whisper \
    WHISPER_MODELS_PATH=/var/www/backend/var/whisper \
    WHISPER_DEFAULT_MODEL=base \
    WHISPER_ENABLED=true \
    FFMPEG_BINARY=/usr/bin/ffmpeg \
    BRAVE_SEARCH_API_URL=https://api.search.brave.com/res/v1 \
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
COPY backend/ .
RUN rm -f .env .env.example .env.local .env.*.local

# Install dependencies with production optimizations
# Flags explained:
# --no-dev: Skip dev dependencies (phpunit, fixtures, etc.) for smaller image
# --no-scripts: Skip post-install scripts (cache:clear requires runtime env vars like APP_URL)
# --optimize-autoloader: Generate optimized PSR-4 class maps
# --classmap-authoritative: Never scan filesystem for classes (all classes are in composer.json)
#   This is safe for Symfony apps where all classes are autoloaded via composer
#   Provides significant performance improvement by eliminating file_exists() calls
# Cache is cleared on container startup anyway via docker-entrypoint.sh
RUN composer install --prefer-dist --no-interaction --no-dev --no-scripts --optimize-autoloader --classmap-authoritative --ignore-platform-req=ext-redis

# Set proper permissions for www-data (uid:gid = 33:33)
RUN mkdir -p /var/www/backend/var/cache /var/www/backend/var/log /var/www/backend/var/uploads /var/www/backend/var/whisper /var/www/backend/public/up && \
    chown -R www-data:www-data /var/www/backend/var /var/www/backend/vendor /var/www/backend/public/up && \
    chmod -R 775 /var/www/backend/var /var/www/backend/public/up

# Copy and setup entrypoint scripts
COPY _docker/backend/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy built frontend (built externally in CI, empty in dev)
COPY frontend/dist /var/www/frontend

# Copy built widgets (built externally in CI, empty in dev)
COPY frontend/dist-widget /var/www/widgets

# Copy Caddyfile for production routing (API vs SPA)
COPY _docker/backend/Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
