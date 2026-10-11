# ChatService

## 1. Responsibility
- Private conversation creation and member management.
- Conversation settings, mute status, and read sequence pointers.
- Privacy checks and block validation before initiating conversations.

## 2. Owned Data
- Logical Database: `chat_db`
- Tables:
  - `conversations`
  - `conversation_members`
  - `outbox_events`

## 3. gRPC API
- `chat.v1.ChatService/GetOrCreatePrivateConversation`
- `chat.v1.ChatService/GetConversation`
- `chat.v1.ChatService/ListConversations`
- `chat.v1.ChatService/CheckCanMessage`
- `chat.v1.ChatService/MarkRead`
- Internal `chat.v1.ChatService/GetConversationMemberSnapshot` for the
  authenticated MessageService hop.

## 4. Consumed Events
- None.

## 5. Produced Events
- The current worker does not publish a conversation event; the outbox event
  contract remains reserved for the event relay wiring.

## 6. Dependencies
- MySQL (`chat_db`)
- UserService (gRPC persona authorization)
- ContentService (gRPC anonymous target resolution)
- SocialService (gRPC for bidirectional block checks)
- MessageService (internal snapshot consumer)

## 7. Identity and implementation status

Conversation creation may select MAIN or the server-owned ALIAS only after an
explicit CREATE_CONVERSATION authorization. The selected member identity is
captured in a session/conversation snapshot and cannot be changed by a later
message. An optional `alias_display_name` is accepted only for the creator's
ALIAS member, validated as UTF-8 text, and frozen into that snapshot. A private
chat target is a MAIN target or a content-scoped anonymous reference; it is
never a peer user id or an alias id in the public contract. Content's historical
anonymous display snapshot is copied unchanged for the target. Messages use
the existing member snapshot and do not accept a per-message identity mode or
alias display name.

The RoadRunner worker implements private get-or-create conversations with
immutable member snapshots. MAIN targets are validated by UserService;
anonymous targets are resolved by ContentService and checked against the
requesting user's access and SocialService's bidirectional block result.
MessageService receives the snapshot over the internal Chat RPC. Conversation
creation and reads use the per-service PDO repository; the worker does not run
production DDL, and migration `20261011000300` must be applied before startup.
