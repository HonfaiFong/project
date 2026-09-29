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

## 5. Login credential configuration

`APP_ENV=local`, `development`, `dev`, or `testing` requires a configured
`AUTH_DEV_VERIFICATION_CODE` (the legacy aliases `AUTH_DEV_LOGIN_CODE`,
`DEV_VERIFICATION_CODE`, and `DEV_LOGIN_CODE` are also accepted). The gateway
never accepts an empty or hard-coded development code.

Other environments require a real credential verifier to be injected at the
composition root. Without one, login is rejected before UserService can create
or resolve a user.

## 6. Local Run
```bash
docker compose up -d api-gateway
# Or directly on Linux/WSL:
php apps/api-gateway/start.php start
```

The HTTP listener and worker count are configured in `config/process.php` via
`HTTP_PORT` and `WEBMAN_WORKERS`. Routes are declared in `config/route.php`;
request tracing is global middleware, while token verification applies only to
the protected route group. Auth and User remain independent gRPC services.
