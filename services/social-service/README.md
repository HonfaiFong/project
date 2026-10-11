# SocialService

## 1. Responsibility
- Likes on posts and comments.
- Follow and unfollow between Personas.
- User-level blocking (prevents circumvention via persona switching).
- Batch interaction state queries (is_liked, is_followed).

## 2. Owned Data
- Logical Database: `social_db`
- Tables:
  - `likes`
  - `follows`
  - `blocks`
  - `outbox_events`

## 3. gRPC API
- `social.v1.SocialService/Like`
- `social.v1.SocialService/Unlike`
- `social.v1.SocialService/Follow`
- `social.v1.SocialService/Unfollow`
- `social.v1.SocialService/Block`
- `social.v1.SocialService/Unblock`
- `social.v1.SocialService/BatchGetInteractionState`
- `social.v1.SocialService/CheckBidirectionalBlock` (internal Chat query)

## 4. Consumed Events
- None.

## 5. Produced Events
- `like.created.v1`
- `follow.created.v1`

## 6. Dependencies
- MySQL (`social_db`)
- Redis (Followers cache, block cache)

## 7. Identity and implementation status

Every social operation resolves MAIN. Likes, follows, blocks, reports, and
their reversals must not accept an ALIAS identity, and an unspecified or
unknown operation fails closed. Public social responses must not use an alias
id, nickname, or user id as a stable identifier.

The Social worker now enforces the write/read boundary described above. The
authenticated account comes from `x-user-id` metadata; request user ids and
identity modes are checked for consistency but never promoted to actor
identity. UserService validates the caller's persona as an active MAIN and
validates follow targets as active MAIN personas. A block query is
service-authenticated and returns true when either account has blocked the
other. Content resource access is delegated through the optional internal
ContentService authorizer; Social does not read content_db.
