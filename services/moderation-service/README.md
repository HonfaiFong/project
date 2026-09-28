# ModerationService

## 1. Responsibility
- Text and media content moderation.
- Status workflow: `PENDING`, `PASS`, `REVIEW`, `REJECT`.
- Sensitive word filters and pluggable remote provider adaptor.

## 2. Owned Data
- Moderation records and manual review queue.

## 3. gRPC API
- `moderation.v1.ModerationService/SubmitContent`
- `moderation.v1.ModerationService/GetModerationResult`

## 4. Consumed Events
- `post.created.v1`
- `comment.created.v1`
- `report.created.v1`

## 5. Produced Events
- `post.approved.v1`
- `moderation.completed.v1`
