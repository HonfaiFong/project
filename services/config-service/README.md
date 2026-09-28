# ConfigService

## 1. Responsibility
- Dynamic operational configuration and feature flag toggles.
- Client RouteProfile distribution (`api_base`, `media_base`, `ws_base`).

## 2. Owned Data
- Redis key: `config:feature:{key}`.
