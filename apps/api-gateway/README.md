# API Gateway (BFF)

## 1. Responsibility
- External HTTP REST API endpoint (`/api/v1`).
- Request tracing, UUIDv7 generation, traceparent propagation.
- Authentication verification and token decoding.
- Parameter validation and rate limiting.
- Thin aggregation layer dispatching to internal gRPC microservices.
- Uniform JSON response formatting and error handling.
- **Strict rule: No direct database access.**

## 2. Owned Data
- None. (Stateless BFF).

## 3. Endpoints
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `POST /api/v1/ws/ticket`
- `GET  /api/v1/me`
- `GET  /api/v1/personas`
- `POST /api/v1/personas`
- `GET  /api/v1/personas/{id}`
- `PATCH /api/v1/personas/{id}`
- `GET  /health`

## 4. Dependencies
- `auth-service` (gRPC :50051)
- `user-service` (gRPC :50052)
- Redis (Rate limiting and cache)

## 5. Local Run
```bash
docker compose up -d api-gateway
# Or directly:
php apps/api-gateway/start.php
```
