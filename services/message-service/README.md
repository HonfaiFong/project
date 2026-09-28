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
