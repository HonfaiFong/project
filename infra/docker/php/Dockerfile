FROM php:8.4-cli-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    procps \
    netcat-openbsd \
    protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*

# Install docker-php-extension-installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_mysql \
    pcntl \
    posix \
    sockets \
    bcmath \
    zip \
    intl \
    opcache

# Build phpredis from source for PHP 8.4 compatibility
RUN git clone --depth 1 -b develop https://github.com/phpredis/phpredis.git /tmp/phpredis && \
    cd /tmp/phpredis && \
    phpize && \
    ./configure && \
    make -j$(nproc) && \
    make install && \
    docker-php-ext-enable redis && \
    rm -rf /tmp/phpredis

# Copy Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Copy RoadRunner binary
COPY --from=ghcr.io/roadrunner-server/roadrunner:2024.2.0 /usr/bin/rr /usr/local/bin/rr

WORKDIR /app

# Enable opcache for CLI
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

EXPOSE 8787 50051 50052

CMD ["php", "-v"]
