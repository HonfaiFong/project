# MessageService

## 1. Responsibility
- Message persistence with `client_message_id` idempotency.
- Strictly monotonic conversation sequence (`seq`) generation.
- Incremental message synchronization (`SyncMessages` via `after_seq`).
- Logical Shard Routing based on `hash(conversation_id) % 256`.
- Reliable event publishing via Transactional Outbox.

## 2. Owned Data
- Logical Database: `message_db`
- Tables:
  - `messages`
  - `outbox_events`

## 3. gRPC API
- `message.v1.MessageService/SendMessage`
- `message.v1.MessageService/SyncMessages`
- `message.v1.MessageService/GetMessages`

## 4. Consumed Events
- None.

## 5. Produced Events
- `message.created.v1`
- `message.delivered.v1`
- `message.read.v1`

## 6. Dependencies
- MySQL (`message_db`)
- Redis (sequence cache)

## 7. Identity and implementation status

SendMessage has no per-message identity selector. The sender identity comes
from the conversation member/session snapshot selected at conversation
creation, so a client cannot switch between MAIN and ALIAS on individual
messages. Public responses must use an independent PublicMessage projection;
internal sender user/persona ids remain authorization and persistence fields.
The public RPC input is PublicSendMessageRequest and contains only the client
message id, conversation id, type, and content. The older SendMessageRequest
shape is source compatibility for internal code only and is not the RPC input.

This service is currently a contract and README skeleton. Message business
handlers, snapshot persistence, and the public projection are not yet
implemented by the identity foundation.
