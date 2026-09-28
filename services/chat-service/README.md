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
