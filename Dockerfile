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
    redis grpc intl opcache

# PHP Configuration
RUN printf "upload_max_filesize=128M\npost_max_size=128M\nmax_file_uploads=50\nmemory_limit=512M\n" \
    > /usr/local/etc/php/conf.d/uploads.ini && \
    printf "opcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\n" \
    > /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application
COPY . .

# Install dependencies
RUN composer install --prefer-dist --no-interaction --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/var

EXPOSE 80

CMD ["frankenphp", "php-server", "--listen", "0.0.0.0:80", "--root", "public/"]
