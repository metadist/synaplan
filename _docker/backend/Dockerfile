# Synaplan Symfony Backend - FrankenPHP + PHP 8.3
FROM dunglas/frankenphp:php8.3

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl unzip \
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
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

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
    grpc intl opcache

# PHP Configuration
RUN mkdir -p /var/www/uploads && chmod 777 /var/www/uploads && \
    printf "upload_max_filesize=128M\npost_max_size=128M\nmax_file_uploads=50\nmemory_limit=512M\nupload_tmp_dir=/var/www/uploads\nsys_temp_dir=/var/www/uploads\n" \
    > /usr/local/etc/php/conf.d/uploads.ini && \
    printf "opcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\n" \
    > /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application
COPY backend/ .

# Install dependencies including dev dependencies for first build
RUN composer install --prefer-dist --no-interaction --optimize-autoloader --no-scripts --ignore-platform-req=ext-redis

# Set proper permissions for www-data (uid:gid = 33:33)
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/uploads /var/www/html/public/up && \
    chown -R www-data:www-data /var/www/html/var /var/www/html/vendor /var/www/html/public/up && \
    chmod -R 775 /var/www/html/var /var/www/html/public/up

# Copy and setup entrypoint scripts
COPY _docker/backend/docker-entrypoint.sh /usr/local/bin/
COPY _docker/backend/init-env.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/init-env.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
