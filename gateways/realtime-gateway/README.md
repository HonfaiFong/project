# Realtime Gateway

> **Status: Skeleton (Phase 1).** What is implemented today: `/live`, `/ready`, `/health`, `/ws`
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
- Graceful shutdown: readiness becomes NOT_SERVING, HTTP stops accepting,
  WebSocket clients receive CloseGoingAway, and active pumps drain before exit.

## 2. Owned Data
- In-memory active connection registry (`user_id -> conn_id -> Client`).
- Redis stores consumed WS ticket nonces. Presence state publication
  (`presence:user:{user_id}`) is ⏳ **not implemented**.

## 3. Endpoints & Protocol
- `GET /ws?ticket=<token>` — ticket is created via
  `POST /api/v1/ws/ticket` on the API Gateway and is single-use.
- `GET /live` — process liveness.
- `GET /ready` — Redis-backed readiness; returns 503 when Redis is unavailable.
- `GET /health` — compatibility alias for `/ready`.
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
- Set `REDIS_USERNAME` and `REDIS_PASSWORD` for authenticated Redis, and
  `REDIS_TLS=true` when the Redis endpoint requires TLS.
- Gorilla's same-origin WebSocket policy is enabled. Serve the web frontend
  and WebSocket endpoint from the same host; native clients without Origin can
  connect. Inbound WebSocket messages are limited to 64 KiB.
- A full outbound queue disconnects the client for resynchronization, except
  for `TYPING` events, which may be dropped.

## 5. Local Run
```bash
docker compose up -d realtime-gateway
# Or directly:
WS_TICKET_SECRET=<same-as-auth-service> REDIS_HOST=localhost:6379 go run ./gateways/realtime-gateway/cmd/gateway
```
