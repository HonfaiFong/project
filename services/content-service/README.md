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
- Kafka (Transactional Outbox events)
