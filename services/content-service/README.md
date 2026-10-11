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
- Public writes/reads: `CreatePost`, `GetPost`, `BatchGetPosts`, `DeletePost`,
  `CreateComment`, `ListComments`, `DeleteComment`.
- Internal authorization: `CanAccessResource` for Social/Chat/Message and
  `ResolveAnonymousTarget` for Chat. `UpdatePostStatus` is restricted to the
  moderation service and is not exposed by the HTTP gateway.

## 4. Consumed Events
- `moderation.completed.v1` (Updates moderation status to PASS/REJECT)

## 5. Produced Events
- `post.created.v1`
- `post.approved.v1`
- `post.deleted.v1`
- `comment.created.v1`

## 6. Dependencies
- MySQL (`content_db`)
- SocialService block authorization RPC (`CheckBidirectionalBlock`)
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

The RoadRunner worker performs every write through authenticated `x-user-id`
metadata and calls UserService with a separate service token to resolve and
validate the requested MAIN or server-owned ALIAS operation. It stores
immutable author snapshots and account-scoped request IDs, and public
responses contain only `PublicPost`/`PublicComment` projections. Anonymous
references are resolved with exact binary scope/ref comparison, active root
visibility checks, and bidirectional block checks before Chat can use them.
Block state is obtained through SocialService's internal RPC; ContentService
does not open `social_db`. SocialService resource callers receive only the
minimal owner IDs needed to enforce that check locally, avoiding a recursive
Social-to-Content-to-Social authorization call.

For an ALIAS write, `alias_display_name` is copied into that post or comment
snapshot after the factory's UTF-8 and 64-character validation. An empty value
uses the factory default `匿名用户`; the persona record is never changed, and
each comment stores its own display snapshot even when it reuses its post's
scope-bound anonymous reference.

The service is deployable through `compose.yaml` on port 50053. It requires
`ANONYMOUS_IDENTITY_KEY`, caller-specific internal RPC tokens, and the
content-to-user token shown in `.env.example`.
