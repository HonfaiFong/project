# UserService

## 1. Responsibility
- User account lifecycle and master records.
- Persona (Avatars/Pseudonyms) CRUD and isolation.
- Ensures the core invariant: **User != Persona**.
- Public profiles only expose `persona_id`.

## 2. Owned Data
- Logical Database: `user_db`
- Tables:
  - `users`
  - `personas`
  - `outbox_events`

## 3. gRPC API
- `user.v1.UserService/FindOrCreateByPhone`
- `user.v1.UserService/FindOrCreateBySocialIdentity`
- `user.v1.UserService/GetUser`
- `user.v1.UserService/BatchGetUsers`
- `user.v1.UserService/UpdateUser`
- `user.v1.UserService/CreatePersona`
- `user.v1.UserService/GetPersona`
- `user.v1.UserService/BatchGetPersonas`
- `user.v1.UserService/UpdatePersona`
- `user.v1.UserService/ListPersonas`
- `common.v1.HealthService/Check`

## 4. Consumed Events
- None.

## 5. Produced Events
- `user.updated.v1`
- `persona.updated.v1`

## 6. Dependencies
- MySQL (`user_db`)
- Persistence uses Illuminate Eloquent models. Users, personas, and Outbox events share one connection and transaction; run `composer install` and restart PHP workers after dependency updates.
- Redis (Persona and User caching)
- Internal gRPC callers use separate Gateway→User and Auth→User tokens. AuthService
  is limited to `FindOrCreateByPhone`, `FindOrCreateBySocialIdentity` and `GetUser`; Gateway handles the public
  UserService operations.

## 6.1 Social registration keys

`FindOrCreateBySocialIdentity` receives the caller-owned `registration_key`
(`social:` + `social_identities.id` for social logins) and stores it on the user
row it creates in the same transaction as the default persona and the outbox
events. The key is opaque here: the OAuth subject id is never sent to this
service. A repeat or retried call resolves to the same user; the unique
`users.social_registration_key` index (migration
`20261009000000_add_user_social_registration_key`) is what serializes
concurrent registrations, and a banned or soft-deleted account blocks
re-registration instead of granting a second account.

## 7. Local Run
```bash
docker compose up -d user-service
# Or directly:
php services/user-service/bin/server.php
```

Compose does not publish the UserService gRPC port to the host. Set the three
`INTERNAL_RPC_*_TOKEN` values in `.env`; the service verifies the caller token
before dispatching any RPC method.

## 8. Test
```bash
vendor/bin/phpunit tests/Unit/UserServiceTest.php
```


## 9. Historical phone migration before deployment

All phone writes use E.164 through `PhoneNormalizer` with the same
`PHONE_DEFAULT_REGION` as AuthService (default `CN`). Run the read-only report
against the target database before enabling the new services:

```bash
docker compose run --rm --no-deps user-service php /app/scripts/normalize_user_phones.php
```

The preflight scans in bounded batches through connection-scoped temporary
tables, so it does not load every user into PHP memory. Invalid numbers and
multiple users sharing one normalized number stop the migration; resolve those
records explicitly. The report includes sample user IDs only and never prints
full phone values. During a maintenance window, stop phone/account writes,
review the report, and apply the backfill:

```bash
docker compose run --rm --no-deps user-service php /app/scripts/normalize_user_phones.php --apply
make preflight-phone
```

`make migrate` and the standard `make up` path run `preflight-phone` with
`--require-clean`. This check refuses invalid numbers, collisions, and any
remaining non-E.164 values. It never applies data changes automatically. The
explicit apply command rechecks and locks rows inside a transaction, updates
with the original phone as a condition, and rolls back on conflicts. Direct
service startup must also be preceded by `make preflight-phone`.
