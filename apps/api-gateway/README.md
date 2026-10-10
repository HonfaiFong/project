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
- `POST /api/v1/auth/login` (local / development / dev / testing only)
- `POST /api/v1/auth/social/login`
- `POST /api/v1/auth/social/bind` (binds a provider code to the access-token user)
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

`APP_ENV=local`, `development`, `dev`, or `testing` requires both
`ENABLE_DEV_LOGIN=true` and a configured `AUTH_DEV_VERIFICATION_CODE` (the
legacy aliases `AUTH_DEV_LOGIN_CODE`, `DEV_VERIFICATION_CODE`, and
`DEV_LOGIN_CODE` are also accepted). The auth service, rather than the
gateway, consumes this code. Production keeps the flag disabled and requires
a real credential verifier.

Other environments require a real credential verifier to be injected at the
composition root. Without one, login is rejected before UserService can create
or resolve a user.

The gateway uses a 7-second deadline for `SocialLogin` and `BindSocialIdentity`
and keeps ordinary auth RPCs at 300ms. The social HTTP client defaults to 3
seconds per request; QQ performs two upstream requests, so both social deadlines
must remain above that worst-case budget. `GRPC_SOCIAL_LOGIN_TIMEOUT_MS` and
`GRPC_SOCIAL_BIND_TIMEOUT_MS` are each validated at startup to exceed two
`SOCIAL_HTTP_TIMEOUT_MS` budgets. Social login and social bind requests are rate limited
before the OAuth call. Set `SOCIAL_TRUSTED_PROXY_CIDRS` to a
comma-separated list of reverse-proxy IP ranges when the gateway is behind a
proxy. Only an immediate socket peer inside those ranges may supply
`X-Forwarded-For`; the first address outside the trusted proxy chain becomes the
rate-limit bucket key. Untrusted forwarding headers are ignored and direct
connections use the socket peer address. Proxy deployments must set this
variable to the actual proxy ranges; leaving it empty groups users by the
proxy peer, and public catch-all ranges such as `0.0.0.0/0` are rejected.

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

The gateway identifies itself on internal gRPC calls with `SERVICE_NAME=api-gateway`
and a target-specific token (`GRPC_AUTH_TOKEN` or `GRPC_USER_TOKEN`). AuthService
and UserService accept only their configured callers and do not publish ports to
the host; set separate random tokens of at least 32 bytes for Gateway→Auth,
Gateway→User, and Auth→User before starting Compose.

## 7. PHP conventions and configuration

The gateway runs on Webman and uses Laravel 12 components compatible with
PHP 8.2: `illuminate/container` for constructor injection and shared service
bindings, and `illuminate/validation` for social credential rules. Controllers
reuse the existing gRPC clients, actions, and public response presenters; the
gateway continues to have no direct database access.

Runtime environment, trusted proxies, Redis, RPC deadlines, and social rate
limits are resolved in `config/gateway.php` and injected by `config/container.php`.
Configuration changes require a gateway worker restart or reload. Middleware
uses the injected environment and defaults to production when instantiated
without configuration. The Laravel container implements the PSR container
contract consumed by Webman; it does not require a Laravel application kernel.

Install locked dependencies with `composer install` before restarting workers.
Routes, response envelopes, business error codes, and authentication boundaries
are unchanged by this style refactoring.
