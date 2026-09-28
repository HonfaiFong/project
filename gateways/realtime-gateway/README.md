# Realtime Gateway

## 1. Responsibility
- WebSocket connection acceptance (`/ws`).
- HMAC-SHA256 signature verification for short-lived WS Tickets.
- Connection registry and multi-device connection management.
- Protocol Envelope parsing and serialization.
- Heartbeat handling (PING / PONG).
- Downstream message push to active connections.
- Connection drain and graceful shutdown.
- **Strict rule: No direct business SQL queries.**

## 2. Owned Data
- In-memory active connection registry.
- Presence state published to Redis (`presence:user:{user_id}`).

## 3. Endpoints & Protocol
- `GET /ws?ticket=<token>`
- `GET /health`
- WebSocket Envelopes:
  - `AUTH`, `AUTH_OK`
  - `PING`, `PONG`
  - `MESSAGE_SEND`, `MESSAGE_ACK`, `MESSAGE_PUSH`
  - `MESSAGE_DELIVERED`, `MESSAGE_READ`
  - `TYPING`
  - `ERROR`

## 4. Dependencies
- Redis (Presence registry and push distribution)

## 5. Local Run
```bash
docker compose up -d realtime-gateway
# Or directly:
go run gateways/realtime-gateway/cmd/gateway/main.go
```
