# Use FrankenPHP with PHP 8.4
FROM dunglas/frankenphp:php8.4

# Install system dependencies including FFmpeg and whisper.cpp dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libsodium-dev \
    zip \
    unzip \
    libffi-dev \
    # Build tools for whisper.cpp
    build-essential \
    cmake \
    pkg-config \
    # Additional whisper.cpp dependencies
    libopenblas-dev \
    liblapack-dev \
    #libatlas-base-dev \
    gfortran \
    # FFmpeg and multimedia dependencies
    ffmpeg \
    libavcodec-extra \
    libavformat-dev \
    libavutil-dev \
    libswscale-dev \
    libavfilter-dev \
    libavdevice-dev \
    libpostproc-dev \
    libswresample-dev \
    libx264-dev \
    libx265-dev \
    libvpx-dev \
    libmp3lame-dev \
    libopus-dev \
    libvorbis-dev \
    libass-dev \
    libfontconfig1-dev \
    libfribidi-dev \
    libharfbuzz-dev \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html
RUN git config --global --add safe.directory /var/www/html

# Install whisper.cpp with proper error handling and dependencies
RUN cd /tmp && \
    git clone https://github.com/ggerganov/whisper.cpp.git && \
    cd whisper.cpp && \
    # Checkout a stable version to avoid build issues
    git checkout v1.5.4 && \
    # Try building with different options if the default fails
    (make clean && make -j$(nproc) || \
     (make clean && make -j$(nproc) WHISPER_NO_AVX=1 WHISPER_NO_AVX2=1) || \
     (make clean && make -j$(nproc) WHISPER_NO_AVX=1 WHISPER_NO_AVX2=1 WHISPER_NO_F16C=1) || \
     (make clean && make -j1)) && \
    cp main /usr/local/bin/whisper && \
    cp quantize /usr/local/bin/whisper-quantize && \
    chmod +x /usr/local/bin/whisper /usr/local/bin/whisper-quantize && \
    cd / && rm -rf /tmp/whisper.cpp

# Install additional PHP extensions (some are already included in FrankenPHP)
RUN install-php-extensions \
        pdo_mysql \
        mysqli \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        sodium \
        ffi \
        xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js
COPY --from=node:22 /usr/local /usr/local
RUN node --version && npm --version

COPY . .

# Install dependencies
RUN composer install --prefer-dist --no-interaction --optimize-autoloader
RUN cd public && npm ci --no-audit --no-fund

# Start FrankenPHP
EXPOSE 80
CMD ["frankenphp", "php-server", "--listen", "0.0.0.0:80", "--root", "public/"]
