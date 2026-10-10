# AuthService

## 1. Responsibility
- WeChat / QQ OAuth login with automatic registration, without phone or SMS.
- Authenticated binding of another provider identity; no account transfer or merge.
- Phone credential login is limited to local, development, and testing.
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
  - `social_identities`
  - `social_identity_aliases`
  - `outbox_events`

## 3. gRPC API
- `auth.v1.AuthService/Login` (development only)
- `auth.v1.AuthService/SocialLogin`
- `auth.v1.AuthService/BindSocialIdentity`
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
- Persistence uses Illuminate Eloquent models for devices, refresh tokens, social identities, and aliases. Run `composer install` and restart PHP workers after dependency updates.
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

## 9. User-state policy for issued JWTs

Every `VerifyToken` call validates the JWT signature/expiry and logout revocation,
then reads the current user from UserService via `UserDirectoryInterface::findById`.
Only `STATUS_ACTIVE` (1) is allowed. Banned or otherwise inactive users, soft-deleted
users (excluded by the user repository), and physically missing users return
`valid=false` without identity fields, even for JWTs issued before the state change.
Redis, Auth MySQL, and UserService failures reject access with `SERVICE_UNAVAILABLE`
(HTTP 503); UserService timeouts retain `RPC_TIMEOUT` (HTTP 503). They do not
return `valid=false` or ask clients to discard otherwise valid credentials.

There is no positive user-state cache or event propagation delay. After the user
state change commits, the next verification reading that state rejects access.
Each otherwise valid JWT verification adds one bounded Auth→User gRPC lookup;
UserService availability is therefore required for authenticated requests.
Gateway protected routes already call `VerifyToken`, so they reject these tokens
with `AUTH_TOKEN_INVALID`. Login and refresh retain the same active-user check.

This is a live user-state gate, not permanent session revocation. If a ban is lifted,
a still-unexpired JWT that was not revoked by logout can be used again; an unexpired
refresh token can also be used again. No JWT claim/schema migration is required,
so already-issued tokens receive the same checks after deployment. Requests that
already passed verification and existing WebSocket connections are not terminated
by this check; short-lived WS tickets have their own validation lifecycle.

## 10. Social registration recovery

Before calling UserService, Auth persists status `3` (registering) on the reserved
identity. UserService receives the opaque key `social:<identity-id>` and commits
the User, default Persona, registration key, and outbox events in one transaction.
Repeated calls reuse that account. A timeout leaves the identity resumable on a
new OAuth authorization; manual binding cannot claim an identity already registering.
Status `0` means unbound, `1` active, and `2` disabled; historical numeric values stay intact.

Openid aliases preserve all verified app/openid mappings when resolving a canonical
unionid, including disabled identities. A later authorization without unionid still
resolves the same identity. Reconciliation retains a registering row's ID and rejects
conflicting registration intents or different account owners.

Apply migrations `20261009000000` and `20261009000100` before deploying the updated
UserService, AuthService, and Gateway with their generated contracts. The retired
phone-binding RPCs remain declared for wire compatibility and always reject callers.
