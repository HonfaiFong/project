# syntax=docker/dockerfile:1

FROM php:8.4-cli-bookworm

# apt 缓存放在 BuildKit cache mount,不进镜像层;Debian 源走 https,代理链路下更稳
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources && \
    apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        procps \
        netcat-openbsd

# 固定版本来源,不再请求 GitHub releases/latest
COPY --from=ghcr.io/mlocati/php-extension-installer:2 \
    /usr/bin/install-php-extensions \
    /usr/local/bin/

# 编译型扩展(grpc)只在这一层发生;业务代码变动不会触发本镜像重建
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        mongodb \
        pcntl \
        posix \
        sockets \
        bcmath \
        grpc \
        curl \
        redis \
        zip \
        intl \
        mbstring \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY --from=ghcr.io/roadrunner-server/roadrunner:2025.1.15 /usr/bin/rr /usr/local/bin/rr

WORKDIR /app

RUN echo "opcache.enable_cli=1" > /usr/local/etc/php/conf.d/99-opcache-cli.ini

EXPOSE 8787 50051 50052

CMD ["php", "-v"]
