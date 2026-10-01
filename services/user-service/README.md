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
- Redis (Persona and User caching)

## 7. Local Run
```bash
docker compose up -d user-service
# Or directly:
php services/user-service/bin/server.php
```

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

Invalid numbers and multiple users sharing one normalized number stop the
migration; resolve those records explicitly. During a maintenance window, stop
phone/account writes, review the report, and apply the backfill:

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
