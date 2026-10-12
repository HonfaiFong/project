FROM golang:1.24-bookworm

# 固定协议生成工具链。升级任一版本后必须重新执行 make proto 并提交 contracts/gen，
# 否则 CI 的 "Verify generated Proto contracts" 会判定生成代码过期。
ARG PROTOC_VERSION=21.12
ARG PROTOC_GEN_GO_VERSION=v1.35.1
ARG PROTOC_GEN_GO_GRPC_VERSION=v1.5.1
ARG PROTOC_GEN_PHP_GRPC_VERSION=2025.1.15
# 与上述版本一一对应的下载产物 SHA256。
ARG PROTOC_SHA256_X86_64=3a4c1e5f2516c639d3079b1586e703fc7bcfa2136d58bda24d1d54f949c315e8
ARG PROTOC_SHA256_AARCH64=2dd17f75d66a682640b136e31848da9fb2eefe68d55303baf8b32617374f6711
ARG PROTOC_GEN_PHP_GRPC_SHA256_AMD64=a95a2afa91602ef28041cae65a575a76a2671ad511946bcfe78e6529df719fc8
ARG PROTOC_GEN_PHP_GRPC_SHA256_ARM64=b440f130da766b9d9881b5a277f49c09894f4fb00d766b29c8272cf5394990df

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates unzip && rm -rf /var/lib/apt/lists/*

# protoc 从官方 release 安装并校验 SHA256，避免 apt 包版本漂移改变生成输出。
ARG TARGETARCH=amd64
RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) protoc_arch=x86_64; protoc_sha="${PROTOC_SHA256_X86_64}"; php_grpc_arch=amd64; php_grpc_sha="${PROTOC_GEN_PHP_GRPC_SHA256_AMD64}" ;; \
        arm64) protoc_arch=aarch_64; protoc_sha="${PROTOC_SHA256_AARCH64}"; php_grpc_arch=arm64; php_grpc_sha="${PROTOC_GEN_PHP_GRPC_SHA256_ARM64}" ;; \
        *) echo "unsupported TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl --retry 3 --retry-delay 2 --retry-all-errors -fsSL -o /tmp/protoc.zip "https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOC_VERSION}/protoc-${PROTOC_VERSION}-linux-${protoc_arch}.zip"; \
    echo "${protoc_sha}  /tmp/protoc.zip" | sha256sum -c -; \
    mkdir -p /opt/protoc; \
    unzip -q /tmp/protoc.zip -d /opt/protoc; \
    ln -s /opt/protoc/bin/protoc /usr/local/bin/protoc; \
    curl --retry 3 --retry-delay 2 --retry-all-errors -fsSL -o /tmp/php-grpc.tar.gz "https://github.com/roadrunner-server/roadrunner/releases/download/v${PROTOC_GEN_PHP_GRPC_VERSION}/protoc-gen-php-grpc-${PROTOC_GEN_PHP_GRPC_VERSION}-linux-${php_grpc_arch}.tar.gz"; \
    echo "${php_grpc_sha}  /tmp/php-grpc.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/php-grpc.tar.gz -C /usr/local/bin --strip-components=1 "protoc-gen-php-grpc-${PROTOC_GEN_PHP_GRPC_VERSION}-linux-${php_grpc_arch}/protoc-gen-php-grpc"; \
    chmod +x /usr/local/bin/protoc-gen-php-grpc; \
    rm -f /tmp/protoc.zip /tmp/php-grpc.tar.gz

    # 模块代理/校验和不定期出现瞬时网络失败，重试后仍失败则让构建失败。
RUN set -eux; \
    for attempt in 1 2 3; do \
        if go install google.golang.org/protobuf/cmd/protoc-gen-go@${PROTOC_GEN_GO_VERSION} \
            && go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@${PROTOC_GEN_GO_GRPC_VERSION}; then \
            break; \
        fi; \
        echo "go install failed (attempt ${attempt}); retrying in 5s" >&2; \
        sleep 5; \
    done; \
    protoc-gen-go --version; \
    protoc-gen-go-grpc --version

ENV PATH="/go/bin:${PATH}"
WORKDIR /app
