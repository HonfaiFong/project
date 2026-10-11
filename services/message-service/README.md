# MessageService

## 1. Responsibility
- Message persistence with `client_message_id` idempotency.
- Strictly monotonic conversation sequence (`seq`) generation.
- Incremental message synchronization (`SyncMessages` via `after_seq`).
- Transactional outbox schema is retained for the event relay integration;
  this worker does not publish events yet.

## 2. Owned Data
- Logical Database: `message_db`
- Tables:
  - `messages`
  - `conversation_sequences`
  - `outbox_events`

## 3. gRPC API
- `message.v1.MessageService/SendMessage`
- `message.v1.MessageService/SyncMessages`
- `message.v1.MessageService/GetMessages`

## 4. Consumed Events
- None.

## 5. Produced Events
- The current worker does not publish message events; the outbox event
  contract remains reserved for the event relay wiring.

## 6. Dependencies
- MySQL (`message_db`)
- ChatService (internal membership, block, and identity snapshot RPC)

## 7. Identity and implementation status

SendMessage has no per-message identity selector. The sender identity comes
from the conversation member/session snapshot selected at conversation
creation, so a client cannot switch between MAIN and ALIAS on individual
messages. Public responses must use an independent PublicMessage projection;
internal sender user/persona ids remain authorization and persistence fields.
The public RPC input is PublicSendMessageRequest and contains only the client
message id, conversation id, type, and content. The older SendMessageRequest
shape is source compatibility for internal code only and is not the RPC input.

The RoadRunner worker persists messages in `message_db` with a per-conversation
sequence row and sender `client_message_id` idempotency. Every send and read
asks ChatService for the authenticated member snapshot, so the request body
cannot select a different persona or identity. Stored display and anonymous
references are projected from the write-time snapshot; current User/Persona
rows are never read by this service. JSON message payloads are compared by
canonical value for idempotency because MySQL JSON columns normalize
whitespace when they are read back. Apply migration `20261011000300` before
starting the worker. The worker intentionally does not run production DDL;
SQLite schema setup is available only to isolated tests.
