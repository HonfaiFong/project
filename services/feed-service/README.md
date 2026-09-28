# FeedService

## 1. Responsibility
- User homepage Feed retrieval.
- Timeline aggregation and cursor-based pagination.
- Redis ZSET candidate timeline per user (`feed:user:{user_id}`).
- Merging follow feed, hot feed, and recommendation candidates.

## 2. Owned Data
- Redis timeline caches.

## 3. gRPC API
- `feed.v1.FeedService/GetFeed`

## 4. Consumed Events
- `post.approved.v1`
- `follow.created.v1`

## 5. Produced Events
- None.

## 6. Dependencies
- Redis
- `content-service` (gRPC)
- `social-service` (gRPC)
- `recommend-service` (gRPC)
