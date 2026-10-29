# syntax=docker/dockerfile:1

FROM php:8.4-cli-bookworm

ARG DEBIAN_MIRROR=https://deb.debian.org

# 保留下载包供 BuildKit cache mount 复用，不写入镜像层。
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean && \
    printf 'Binary::apt::APT::Keep-Downloaded-Packages "true";\n' > /etc/apt/apt.conf.d/keep-cache && \
    sed -i "s|http://deb.debian.org|${DEBIAN_MIRROR}|g; s|https://deb.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list.d/debian.sources && \
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

# 默认四个编译任务；低内存构建机可设置为 1 或 2。
ARG PHP_EXTENSION_JOBS=4

# gRPC 编译单独缓存，增减其他扩展不再使这一层失效。
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    IPE_KEEP_SYSPKG_CACHE=1 IPE_PROCESSOR_COUNT=${PHP_EXTENSION_JOBS} install-php-extensions grpc && \
    rm -rf /tmp/pear

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    IPE_KEEP_SYSPKG_CACHE=1 IPE_PROCESSOR_COUNT=${PHP_EXTENSION_JOBS} install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        pcntl \
        posix \
        sockets \
        bcmath \
        curl \
        redis \
        zip \
        intl \
        mbstring \
        opcache && \
    rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY --from=ghcr.io/roadrunner-server/roadrunner:2025.1.15 /usr/bin/rr /usr/local/bin/rr

WORKDIR /app

RUN echo "opcache.enable_cli=1" > /usr/local/etc/php/conf.d/99-opcache-cli.ini

EXPOSE 8787 50051 50052

CMD ["php", "-v"]
