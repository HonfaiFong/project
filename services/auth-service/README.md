# AuthService

## 1. Responsibility
- User authentication via Phone / SMS / Password / OAuth.
- JWT Access Token generation and validation.
- Refresh Token management, rotation, and revocation.
- Device tracking and registration.
- Short-lived WebSocket Ticket generation.
- Session termination and logout.

## 2. Owned Data
- Logical Database: `auth_db`
- Tables:
  - `devices`
  - `refresh_tokens`
  - `outbox_events`

## 3. gRPC API
- `auth.v1.AuthService/Login`
- `auth.v1.AuthService/RefreshToken`
- `auth.v1.AuthService/Logout`
- `auth.v1.AuthService/VerifyToken`
- `auth.v1.AuthService/CreateWsTicket`
- `common.v1.HealthService/Check`

## 4. Consumed Events
- None (Auth is upstream authority for credentials and tokens).

## 5. Produced Events
- `user.registered.v1`
- `user.logged_in.v1`

## 6. Dependencies
- MySQL (`auth_db`)
- Redis (session cache and token blacklist)
- UserService over internal gRPC (`GRPC_USER_SERVICE_TOKEN` authenticates Auth→User)

## 7. Local Run
```bash
docker compose up -d auth-service
# Or directly:
php services/auth-service/bin/server.php
```

Compose does not publish the AuthService gRPC port to the host. Incoming calls
must carry the Gateway→Auth token and `SERVICE_NAME=api-gateway`; the service
also uses a separate Auth→User token for its UserService calls.

## 8. Test
```bash
vendor/bin/phpunit tests/Unit/AuthServiceTest.php
```
