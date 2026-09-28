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
