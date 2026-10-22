# syntax=docker/dockerfile:1

FROM golang:1.27-bookworm AS builder

WORKDIR /src

ARG GOPROXY=https://proxy.golang.org,direct

ENV CGO_ENABLED=0 \
    GOOS=linux \
    GOFLAGS=-mod=mod

COPY go.mod go.sum ./

# 模块写入构建阶段镜像层，GHA mode=max 可跨 runner 恢复。
# 仅使用 cache mount 时，新的 CI runner 不会恢复其中的模块文件。
RUN GOPROXY=${GOPROXY} go mod download

# 只复制 gateway 真正链接的代码;PHP/contracts 变更不会使本层缓存失效
COPY gateways/realtime-gateway ./gateways/realtime-gateway
COPY packages/go ./packages/go

RUN --mount=type=cache,target=/root/.cache/go-build \
    GOPROXY=${GOPROXY} go build -trimpath -ldflags="-s -w" -o /out/gateway ./gateways/realtime-gateway/cmd/gateway

FROM debian:bookworm-slim

# CGO=0 的网关只需要 CA bundle，复用同为 Bookworm 的构建阶段证书。
# 最终镜像无需再次联网 apt update/install。
COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt

WORKDIR /app

COPY --from=builder /out/gateway /usr/local/bin/gateway

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/gateway"]
