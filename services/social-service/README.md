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

## 4. Consumed Events
- None.

## 5. Produced Events
- `like.created.v1`
- `follow.created.v1`

## 6. Dependencies
- MySQL (`social_db`)
- Redis (Followers cache, block cache)
