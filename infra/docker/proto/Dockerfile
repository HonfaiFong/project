FROM golang:1.24-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends protobuf-compiler curl ca-certificates && rm -rf /var/lib/apt/lists/*
RUN go install google.golang.org/protobuf/cmd/protoc-gen-go@v1.35.1 && \
    go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@v1.5.1

ARG TARGETARCH=amd64
RUN curl -fsSL "https://github.com/roadrunner-server/roadrunner/releases/download/v2025.1.15/protoc-gen-php-grpc-2025.1.15-linux-${TARGETARCH}.tar.gz" -o /tmp/php-grpc.tar.gz && \
    tar -xzf /tmp/php-grpc.tar.gz -C /usr/local/bin --strip-components=1 "protoc-gen-php-grpc-2025.1.15-linux-${TARGETARCH}/protoc-gen-php-grpc" && \
    chmod +x /usr/local/bin/protoc-gen-php-grpc && rm /tmp/php-grpc.tar.gz

ENV PATH="/go/bin:${PATH}"
WORKDIR /app
