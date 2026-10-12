# Realtime Gateway

> **Status: Skeleton (Phase 1).** What is implemented today: `/live`, `/ready`, `/health`, `/ws`
> acceptance with ticket verification (base64url + HMAC-SHA256, single-use
> nonce backed by Redis), in-memory connection registry, PING/PONG heartbeat,
> and a Redis PubSub fan-out that delivers `MESSAGE_PUSH` to the connections of
> the named recipient users. Everything marked ⏳ below is specified but
> **not implemented yet**.

## 1. Responsibility
- WebSocket connection acceptance (`/ws`).
- HMAC-SHA256 signature verification for short-lived WS Tickets
  (base64url-encoded, single-use nonce enforced across gateway instances).
- Connection registry and multi-device connection management.
- Heartbeat handling: protocol `PING` → `PONG`, plus WS-level ping/pong.
- **Strict rule: No direct business SQL queries.**
- ⏳ Protocol Envelope parsing beyond PING (MESSAGE_* / TYPING / ERROR types).
- Downstream message push: subscribes to the Redis PubSub channel
  `realtime:message.created` and forwards each event's public message to the
  connections of the recipient users only.
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
- `GET /ready` — Redis-backed readiness for the ticket nonce store and the
  PubSub subscriber; returns 503 when Redis is unavailable or the subscription
  is not established.
- `GET /health` — compatibility alias for `/ready`.
- WebSocket Envelopes:
  - `AUTH_OK` (sent on connect), `PING`, `PONG` — **implemented**
  - `MESSAGE_PUSH` — **implemented (outbound)**; see the payload contract below
  - `MESSAGE_SEND`, `MESSAGE_ACK` — ⏳
  - `MESSAGE_DELIVERED`, `MESSAGE_READ` — ⏳
  - `TYPING` — ⏳
  - `ERROR` — ⏳

`MESSAGE_PUSH` contract:

- The message/chat delivery consumer publishes to the Redis PubSub channel
  `realtime:message.created`:
  `{"event_id":"...","recipients":["<user_id>"],"message":{...}}`, where `message`
  is the public message projection (snake_case fields, string ids). Internal
  sender user/persona ids are never carried across this boundary; the event only
  names the conversation members that may receive the push.
- The gateway ignores malformed payloads and invalid or duplicated recipient
  ids, then pushes an envelope to every connection of each recipient user:
  `{"version":1,"request_id":"<event_id>","sequence":0,"timestamp_ms":<ms>,"type":"MESSAGE_PUSH","payload":"<base64(message JSON)>"}`.
  `payload` is `bytes` in the envelope contract, so JSON serializes it as base64.
- If the Redis subscription drops, the gateway backs off and re-subscribes
  without replaying missed events. `MESSAGE_PUSH` is a prompt, not a durable
  stream: clients must recover gaps with the sync API
  (`GET /api/v1/conversations/{id}/messages/sync`) after reconnecting.

## 4. Dependencies
- Build with Go 1.27 in development, CI, and production. The `go 1.24`
  directive in `go.mod` remains the language compatibility baseline.
- WS_TICKET_SECRET shared with auth-service (required, min 32 bytes).
- Redis is required for shared WS ticket replay protection. The gateway fails
  closed if Redis is unavailable.
- Readiness also requires an established `realtime:message.created`
  subscription; instances that cannot deliver pushes are removed from traffic.
- Set `REDIS_USERNAME` and `REDIS_PASSWORD` for authenticated Redis, and
  `REDIS_TLS=true` when the Redis endpoint requires TLS.
- Gorilla's same-origin WebSocket policy is enabled. Serve the web frontend
  and WebSocket endpoint from the same host; native clients without Origin can
  connect. Inbound WebSocket messages are limited to 64 KiB.
- A full outbound queue disconnects the client for resynchronization, except
  for `TYPING` events, which may be dropped.
- Logs use `SERVICE_INSTANCE_ID` when set, otherwise `HOSTNAME` (or `unknown`).

## 5. Local Run
```bash
docker compose up -d realtime-gateway
# Or directly:
WS_TICKET_SECRET=<same-as-auth-service> REDIS_HOST=localhost:6379 go run ./gateways/realtime-gateway/cmd/gateway
```
