# ============================================================================
# Stage 1: Builder - Compile whisper.cpp
# ============================================================================
FROM debian:bookworm-slim AS whisper-builder

# Install only build dependencies needed for whisper.cpp
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates git cmake build-essential pkg-config \
        libopenblas-dev liblapack-dev && \
    rm -rf /var/lib/apt/lists/*

# Build whisper.cpp
# Using v1.7.4 - newer versions have better cross-platform SIMD support
# Note: In 1.6+ the binary is 'whisper-cli' (was 'main') and 'whisper-quantize' (was 'quantize')
# GGML_NATIVE=OFF disables -mcpu=native which fails under QEMU emulation (Apple Silicon -> x86_64)
RUN git clone https://github.com/ggerganov/whisper.cpp.git /tmp/whisper.cpp && \
    cd /tmp/whisper.cpp && \
    git checkout v1.7.4 && \
    cmake -B build -DCMAKE_BUILD_TYPE=Release -DGGML_NATIVE=OFF && \
    cmake --build build --config Release -j$(nproc)

# ============================================================================
# Stage 2: Final Image - FrankenPHP + PHP 8.3
# ============================================================================
FROM dunglas/frankenphp:php8.3-bookworm

# Install runtime and build dependencies, build PHP extensions, then cleanup
# This is done in a single RUN to minimize layer size
RUN set -eux; \
    # Retry logic for network resilience
    for i in 1 2 3; do \
        apt-get update && \
        apt-get install -y --fix-missing --no-install-recommends \
            # Runtime dependencies (kept)
            git curl unzip jq \
            libpng16-16 libonig5 libxml2 libzip4 \
            libfreetype6 libjpeg62-turbo libwebp7 \
            libsodium23 libffi8 \
            ghostscript imagemagick libmagickwand-6.q16-6 poppler-utils \
            libopenblas0-pthread \
            ffmpeg libavcodec-extra59 \
            libc-client2007e libkrb5-3 \
            # Build dependencies (will be removed)
            libpng-dev libonig-dev libxml2-dev libzip-dev \
            libfreetype6-dev libjpeg62-turbo-dev libwebp-dev \
            libsodium-dev libffi-dev \
            libmagickwand-dev \
            libc-client-dev libkrb5-dev && \
        rm -rf /var/lib/apt/lists/* && \
        break || sleep 10; \
    done && \
    # Install PHP extensions (requires -dev packages)
    install-php-extensions \
        pdo_mysql mysqli \
        exif pcntl bcmath gd imagick zip sodium ffi \
        grpc intl opcache imap && \
    # Remove build dependencies to save space (~350-400MB)
    apt-get purge -y --auto-remove \
        libpng-dev libonig-dev libxml2-dev libzip-dev \
        libfreetype6-dev libjpeg62-turbo-dev libwebp-dev \
        libsodium-dev libffi-dev \
        libmagickwand-dev \
        libc-client-dev libkrb5-dev \
        # Also remove compiler tools pulled in as dependencies
        gcc g++ cpp libgcc-*-dev libstdc++-*-dev libc6-dev \
        libssl-dev libicu-dev && \
    rm -rf /var/lib/apt/lists/*

# Install protoc (pinned version for consistent proto generation)
# This version is also used in CI (.github/workflows/ci.yml extracts it via grep)
ENV PROTOC_VERSION=33.2
RUN curl -Lo /tmp/protoc.zip "https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOC_VERSION}/protoc-${PROTOC_VERSION}-linux-x86_64.zip" \
    && unzip -q /tmp/protoc.zip -d /usr/local \
    && chmod +x /usr/local/bin/protoc \
    && rm /tmp/protoc.zip

# Copy whisper.cpp binaries and libraries from builder stage
COPY --from=whisper-builder /tmp/whisper.cpp/build/bin/whisper-cli /usr/local/bin/whisper
COPY --from=whisper-builder /tmp/whisper.cpp/build/bin/quantize /usr/local/bin/whisper-quantize
COPY --from=whisper-builder /tmp/whisper.cpp/build/src/libwhisper.so.1.7.4 /usr/local/lib/
COPY --from=whisper-builder /tmp/whisper.cpp/build/ggml/src/libggml.so /usr/local/lib/
COPY --from=whisper-builder /tmp/whisper.cpp/build/ggml/src/libggml-cpu.so /usr/local/lib/
COPY --from=whisper-builder /tmp/whisper.cpp/build/ggml/src/libggml-base.so /usr/local/lib/

# Set up whisper binaries and libraries
RUN chmod +x /usr/local/bin/whisper* && \
    ln -sf libwhisper.so.1.7.4 /usr/local/lib/libwhisper.so.1 && \
    ln -sf libwhisper.so.1 /usr/local/lib/libwhisper.so && \
    ldconfig

WORKDIR /var/www/backend

# PHP Configuration
RUN mkdir -p /var/www/uploads && chmod 777 /var/www/uploads && \
    printf "upload_max_filesize=512M\npost_max_size=512M\nmax_file_uploads=50\nmemory_limit=512M\nupload_tmp_dir=/var/www/uploads\nsys_temp_dir=/var/www/uploads\nmax_execution_time=300\n" \
    > /usr/local/etc/php/conf.d/uploads.ini && \
    printf "opcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\n" \
    > /usr/local/etc/php/conf.d/opcache.ini && \
    printf "display_errors=Off\ndisplay_startup_errors=Off\nlog_errors=On\nerror_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT\n" \
    > /usr/local/etc/php/conf.d/production.ini

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

# Copy Caddyfile for production routing (API vs SPA)
COPY _docker/backend/Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
