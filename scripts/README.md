# Isolated CR05 RPC probes

These scripts exercise the real PHP RoadRunner workers against an isolated
MySQL 8.4 container. They use only test tokens supplied below or through
environment variables; no production credential is read or stored. All five
workers share the MySQL container network namespace, so their internal targets
are `127.0.0.1:50052` through `127.0.0.1:50056`.

The stack script creates (or reuses after validating) one dedicated
`mysql:8.4` container and only removes databases beginning with the supplied
`social_test_` prefix and the exact `cr05-rpc-*` worker names. The MySQL
container must carry the ownership, exact-prefix, `role=mysql`, and name
labels written by this script. Each worker carries the same ownership/prefix
labels plus `role=worker` and its exact fixed worker name; a missing, foreign,
or different-prefix label causes `migrate`, `start`, `stop`, and `cleanup` to
fail without removing that container. It never touches `mitm_mcp_traffic.db`
or an existing container with another image.

Worker names are intentionally fixed for the shared network namespace, so one
prefix owns one stack at a time. Do not run two prefixes concurrently in the
same Docker engine; use a different Docker context or wait for `stop` and
`cleanup` to finish. Keep the workers up for the parent/ChatMessage acceptance
run; run `-Action stop` after the probes, then `-Action cleanup` to remove the
labeled temporary MySQL container as well.

From `D:\workplace\project` in PowerShell:

```powershell
$env:RPC_PREFIX = 'social_test_cr05_repro_'
rtk proxy powershell -NoProfile -File scripts/cr05_rpc_stack.ps1 -Action all -Prefix $env:RPC_PREFIX
```

`all` creates and waits for the isolated MySQL container, then performs a fresh prefixed baseline import, all Phinx migrations, MAIN
fixture seeding for users `1001`/`1002` and personas `2001`/`2002`, then starts
the five workers. To inspect or restart workers without dropping databases:

```powershell
rtk proxy powershell -NoProfile -File scripts/cr05_rpc_stack.ps1 -Action status -Prefix $env:RPC_PREFIX
rtk proxy powershell -NoProfile -File scripts/cr05_rpc_stack.ps1 -Action start -Prefix $env:RPC_PREFIX
```

The following block runs the independent token-boundary probe. Valid gateway
tokens are deliberately fake test values and are passed through environment
variables. The probe expects a valid token with an invalid request to return
`INVALID_ARGUMENT` and the test-only wrong token to return `UNAUTHENTICATED`.
It also asserts that `ValidatePersonaOwnership` and `ResolvePersonaMode`
reject a valid service token when trusted `x-user-id` metadata is missing.

```powershell
$tokenEnv = @(
  '--env', 'RPC_GATEWAY_USER_TOKEN=gw-user-cr05-20261011-000000000000',
  '--env', 'RPC_GATEWAY_CONTENT_TOKEN=gw-content-cr05-20261011-000000000000',
  '--env', 'RPC_GATEWAY_SOCIAL_TOKEN=gw-social-cr05-20261011-000000000000',
  '--env', 'RPC_GATEWAY_CHAT_TOKEN=gw-chat-cr05-20261011-000000000000',
  '--env', 'RPC_GATEWAY_MESSAGE_TOKEN=gw-message-cr05-20261011-000000000000'
)
rtk docker run --rm --network container:codex-identity-review-mysql `
  --volume 'D:\workplace\project:/app' --workdir /app --env SERVICE_NAME=api-gateway `
  @tokenEnv project-php-runtime:dev php scripts/cr05_rpc_auth_probe.php
```

Run the Social MAIN/ALIAS/foreign-persona, follow/unfollow, block/unblock,
content authorization, Chat and Message rejection probe with the same gateway
tokens plus the isolated Content→Social token:

```powershell
rtk docker run --rm --network container:codex-identity-review-mysql `
  --volume 'D:\workplace\project:/app' --workdir /app --env SERVICE_NAME=api-gateway `
  --env RPC_GATEWAY_CONTENT_TOKEN=gw-content-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_SOCIAL_TOKEN=gw-social-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_CHAT_TOKEN=gw-chat-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_MESSAGE_TOKEN=gw-message-cr05-20261011-000000000000 `
  --env RPC_CONTENT_SOCIAL_TOKEN=content-social-cr05-20261011-000000000000 `
  project-php-runtime:dev php scripts/social_rpc_probe.php
```

The shared CR02/03 anonymous snapshot and message idempotency probe is run
with all five gateway tokens. Run it after a fresh `all` reset or after the
database is otherwise known to have no leftover block edge:

```powershell
rtk docker run --rm --network container:codex-identity-review-mysql `
  --volume 'D:\workplace\project:/app' --workdir /app --env SERVICE_NAME=api-gateway `
  --env RPC_GATEWAY_USER_TOKEN=gw-user-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_CONTENT_TOKEN=gw-content-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_SOCIAL_TOKEN=gw-social-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_CHAT_TOKEN=gw-chat-cr05-20261011-000000000000 `
  --env RPC_GATEWAY_MESSAGE_TOKEN=gw-message-cr05-20261011-000000000000 `
  project-php-runtime:dev php scripts/chat_message_rpc_probe.php
```

All probes read their targets from `RPC_*_TARGET` when a non-default target is
needed. They fail with exit code 1 on an unexpected success, missing business
error trailer, `INTERNAL`, `UNAVAILABLE`, or deadline status. A successful run
prints JSON with `ok: true` and the asserted steps.

After acceptance, remove only the five temporary workers with:

```powershell
rtk proxy powershell -NoProfile -File scripts/cr05_rpc_stack.ps1 -Action stop -Prefix $env:RPC_PREFIX
```

To remove the temporary MySQL container after stopping the workers:

```powershell
rtk proxy powershell -NoProfile -File scripts/cr05_rpc_stack.ps1 -Action cleanup -Prefix $env:RPC_PREFIX
```
