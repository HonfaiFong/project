# ContentService

## 1. Responsibility
- Post creation, visibility, and deletion.
- PostMedia metadata indexing and attachment.
- First-level comments and replies.
- Topic and Emotion categorization.
- Content lifecycle and status transitions.

## 2. Owned Data
- Logical Database: `content_db`
- Tables:
  - `posts`
  - `post_media`
  - `comments`
  - `outbox_events`

## 3. gRPC API
- `content.v1.ContentService/CreatePost`
- `content.v1.ContentService/GetPost`
- `content.v1.ContentService/BatchGetPosts`
- `content.v1.ContentService/DeletePost`
- `content.v1.ContentService/CreateComment`
- `content.v1.ContentService/ListComments`

## 4. Consumed Events
- `moderation.completed.v1` (Updates moderation status to PASS/REJECT)

## 5. Produced Events
- `post.created.v1`
- `post.approved.v1`
- `post.deleted.v1`
- `comment.created.v1`

## 6. Dependencies
- MySQL (`content_db`)
- Redis (`counter:post:{post_id}`)
- RabbitMQ Streams (Transactional Outbox events)

## 7. Identity and implementation status

Content creation is the only public area, together with comments, where a
caller may request the server-owned ALIAS mode. The request is resolved by
UserService with an explicit operation; it cannot create or select a
client-owned fixed pseudonym. The write path must store an immutable author
snapshot (mode, display name, system avatar, and a scope-bound anonymous
reference) and public reads must project an independent PublicPost or
PublicComment shape. Internal persona_id and user_id are persistence and
authorization fields and are not public author identity fields.
DeletePost uses a trusted authenticated user_id for account ownership checks,
so removing anonymous content does not require the snapshot persona to be MAIN.

This service is currently a contract and README skeleton. Post/comment
business handlers, snapshot persistence, and public projections are not yet
implemented here. Do not treat the existing internal Post/Comment models
as the finished public API.
