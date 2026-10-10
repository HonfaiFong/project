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

## 4. Consumed Events
- None.

## 5. Produced Events
- `conversation.created.v1`

## 6. Dependencies
- MySQL (`chat_db`)
- `social-service` (gRPC for block check)

## 7. Identity and implementation status

Conversation creation may select MAIN or the server-owned ALIAS only after an
explicit CREATE_CONVERSATION authorization. The selected member identity is
captured in a session/conversation snapshot and cannot be changed by a later
message. A private chat target is a MAIN target or a content-scoped anonymous
reference; it is never a peer user id or an alias id in the public contract.
Messages use the existing member snapshot and do not accept a per-message
identity mode or alias display name.

This service is currently a contract and README skeleton. Conversation
business handlers, snapshot persistence, and independent PublicConversation
and PublicMessage projections are not yet implemented by the identity
foundation.
