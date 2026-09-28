# RecommendService

## 1. Responsibility
- User interest profiling and feature extraction.
- Candidate recall and rule-based ranking for Phase 4.
- Extensible hook for ML model integration in later phases.

## 2. Owned Data
- Redis user feature store.

## 3. gRPC API
- `recommend.v1.RecommendService/RankCandidates` (Phase 4)

## 4. Consumed Events
- `analytics.event.v1`
- `like.created.v1`

## 5. Produced Events
- None.
