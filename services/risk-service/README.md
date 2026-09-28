# RiskService

## 1. Responsibility
- Synchronous fast decision path (`ALLOW`, `DENY`, `CHALLENGE`, `REVIEW`).
- Evaluates login, registration, posting, commenting, and direct messaging actions.
- In-memory / Redis frequency limits per user, device, and IP hash.

## 2. Owned Data
- Redis rate limiting and risk counters (`rate:user:{user_id}:{action}`).

## 3. gRPC API
- `risk.v1.RiskService/CheckAction`

## 4. Consumed Events
- `report.created.v1`
- `analytics.event.v1`

## 5. Produced Events
- `risk.alert.v1`
