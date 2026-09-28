FROM golang:1.24-bookworm AS builder

WORKDIR /app
ENV GOFLAGS="-mod=mod"

COPY go.mod go.sum* ./
RUN go mod download || true

COPY . .

RUN CGO_ENABLED=0 GOOS=linux go build -mod=mod -o /app/bin/gateway gateways/realtime-gateway/cmd/gateway/main.go

FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=builder /app/bin/gateway /usr/local/bin/gateway

EXPOSE 8080

CMD ["/usr/local/bin/gateway"]
