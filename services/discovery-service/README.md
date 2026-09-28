# DiscoveryService

## 1. Responsibility
- Core "Drift Bottle / Capsule" discovery gameplay.
- Random stranger discovery pools:
  - `discovery:new`
  - `discovery:hot`
  - `discovery:emotion:{emotion}`
  - `discovery:topic:{topic_id}`
- Self-content filtering, block filtering, and exposure deduplication.

## 2. Owned Data
- Redis candidate sets and exposure sets.

## 3. gRPC API
- `discovery.v1.DiscoveryService/GetRandomCapsule`
- `discovery.v1.DiscoveryService/AcknowledgeExposure`

## 4. Consumed Events
- `post.approved.v1` (Adds candidate post to discovery pools)

## 5. Produced Events
- None.

## 6. Dependencies
- Redis
- `content-service` (gRPC)
- `social-service` (gRPC)
