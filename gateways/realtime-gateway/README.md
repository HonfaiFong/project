# Realtime Gateway

> **Status: Skeleton (Phase 1).** What is implemented today: `/health`, `/ws`
> acceptance with ticket verification (base64url + HMAC-SHA256, single-use
> nonce backed by Redis), in-memory connection registry, and PING/PONG heartbeat. Everything
> marked ⏳ below is specified but **not implemented yet**.

## 1. Responsibility
- WebSocket connection acceptance (`/ws`).
- HMAC-SHA256 signature verification for short-lived WS Tickets
  (base64url-encoded, single-use nonce enforced across gateway instances).
- Connection registry and multi-device connection management.
- Heartbeat handling: protocol `PING` → `PONG`, plus WS-level ping/pong.
- **Strict rule: No direct business SQL queries.**
- ⏳ Protocol Envelope parsing beyond PING (MESSAGE_* / TYPING / ERROR types).
- ⏳ Downstream message push from RabbitMQ Streams / business services.
- ⏳ Presence state publication.
- ⏳ Connection drain and graceful shutdown (current shutdown just exits).

## 2. Owned Data
- In-memory active connection registry (`user_id -> conn_id -> Client`).
- Redis stores consumed WS ticket nonces. Presence state publication
  (`presence:user:{user_id}`) is ⏳ **not implemented**.

## 3. Endpoints & Protocol
- `GET /ws?ticket=<token>` — ticket is created via
  `POST /api/v1/ws/ticket` on the API Gateway and is single-use.
- `GET /health`
- WebSocket Envelopes:
  - `AUTH_OK` (sent on connect), `PING`, `PONG` — **implemented**
  - `MESSAGE_SEND`, `MESSAGE_ACK`, `MESSAGE_PUSH` — ⏳
  - `MESSAGE_DELIVERED`, `MESSAGE_READ` — ⏳
  - `TYPING` — ⏳
  - `ERROR` — ⏳

## 4. Dependencies
- WS_TICKET_SECRET shared with auth-service (required, min 32 bytes).
- Redis is required for shared WS ticket replay protection. The gateway fails
  closed if Redis is unavailable.

## 5. Local Run
```bash
docker compose up -d realtime-gateway
# Or directly:
WS_TICKET_SECRET=<same-as-auth-service> REDIS_HOST=localhost:6379 go run gateways/realtime-gateway/cmd/gateway/main.go
```
