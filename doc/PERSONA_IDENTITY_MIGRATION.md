# Persona identity migration

The product rules and acceptance scenarios are documented in [真身与匿名分身业务规则](USER_PERSONA_BUSINESS_RULES.md). This file covers implementation and rollout details.

This document describes the replacement for the old client-created
pseudonym model. The current implementation establishes the UserService
invariants, the gateway authorization contract, immutable snapshot helpers,
schema validation, and protobuf shapes. Content, social, chat, and message
business handlers remain skeletons and are not claimed to be implemented.

## Identity model

Each newly registered user receives exactly two personas in the registration
transaction:

| Persona | Ownership | Public behavior |
| --- | --- | --- |
| MAIN | User controlled and long lived | Stable public persona id and profile projection |
| canonical ALIAS | Server controlled and internal | Anonymous projection only, with a write-time display snapshot |

CreatePersona and the legacy alias creation method remain in the wire/source
contract for rolling upgrades but reject every request. Existing noncanonical
alias rows are retained for history and audit. They cannot be used for new
operations, and a soft-deleted canonical alias cannot be silently recreated
or reactivated.

## Operation authorization

The requested mode and operation are parsed as closed enums. Mode and
operation validation happens before alias lookup, row locking, or insertion.
UNSPECIFIED, 0, and unknown values fail closed without database side effects.

| Operation | MAIN | canonical ALIAS |
| --- | --- | --- |
| CREATE_POST | allow | allow |
| CREATE_COMMENT | allow | allow |
| CREATE_CONVERSATION | allow | allow |
| SEND_MESSAGE | allow | allow |
| LIKE / UNLIKE | allow | deny |
| FOLLOW / UNFOLLOW | allow | deny |
| BLOCK / UNBLOCK | allow | deny |
| REPORT | allow | deny |
| CONNECT | allow | deny |
| UPDATE_PROFILE | allow | deny |

WebSocket ticket issuance uses PERSONA_OPERATION_CONNECT and MAIN. The
operation must be a named enum value; numeric literals such as the previous
magic 17 are not used at call sites.

## Snapshot and public projection

An author snapshot copies the display name and avatar at write time. A MAIN
snapshot requires a positive uint64 persona id. An ALIAS snapshot stores its
private persona id only in persistence, uses the system anonymous avatar, and
stores an HMAC reference over JSON containing the persona and
scope_type/scope_id. The HMAC key must be at least 32 bytes.

The projector checks the stored scope against the requested scope before
emitting an anonymous reference. It never derives a reference from a persona
id, never uses nickname/id fallback for an unknown mode, and never exposes an
ALIAS id or user id. Invalid mode values, a MAIN snapshot without a valid
public id, and a missing or wrong-scope reference become an unlinked generic
anonymous author. The snapshot DTO is immutable, so profile edits cannot
rewrite historical content or a fixed conversation/session identity.

The public protobuf boundary uses independent PublicPost, PublicComment,
PublicConversation, PublicConversationMember, and PublicMessage messages.
Their author data is common.v1.PublicAuthor; internal user/persona ownership
columns are absent. Chat targets are either a MAIN persona id or a
scope/type/reference anonymous target. They are never peer_user_id,
peer_persona_id, or an ALIAS id. SendMessageRequest has no per-message
identity selector; fields 8 and 9 are reserved. The conversation member
snapshot supplies the sender identity for every message.
The public message RPC accepts PublicSendMessageRequest, whose only fields
are client_message_id, conversation_id, type, and content. The deprecated
SendMessageRequest remains only as an internal source shape and is not the
RPC input.
FeedItem and CapsuleItem embed PublicPost recursively; CapsuleItem reserves
the former persona_id field so discovery responses cannot leak an internal
author id through a nested post.
DeletePostRequest carries a trusted authenticated user_id for account-level
ownership checks, so deleting a user's anonymous content does not require
the snapshot author persona to be MAIN.

These response and request types are a breaking wire-contract update. A
Public* response replaced an internal Post, Conversation, or Message with
different field meanings and numbers; for example, an old Message field 4
was sender_user_id while PublicMessage field 4 is client_message_id. All
generated clients and consumers must be upgraded together. Do not run old
and new protocol consumers in a mixed rolling deployment. CreatePersona
remains source-compatible only and rejects every request.

## Schema rollout

Do not edit the already released 00000 migration. Apply the migrations in
this order after the legacy schema import and persona constraints:

1. 20261010000100_add_internal_alias_identity.php
2. 20261010000200_add_anonymous_author_snapshots.php

Migration 001 adds the unsigned canonical marker, a generated alias_user_id,
its unique index, and the enforced type check. It queries the actual MySQL
check definition through table_constraints and check_constraints, and accepts
only the canonical expression. An exact partially applied signed TINYINT(1)
marker may be converted when all values are binary; other definitions stop
the migration.

Migration 002 requires every expected legacy table. It validates existing
column type, unsigned mode, nullability, length, and default before issuing
DDL. It stores scope columns for post/comment, conversation member, and
message snapshots. Its down migration refuses to remove snapshot columns from
tables containing rows, because that would destroy identity meaning. Missing
tables are an error during up and are not treated as a successful no-op.

Deployment sequence:

1. Back up the databases and verify the legacy schema/table names.
2. Pause writes and drain old gateway traffic. This is a coordinated
   deployment boundary; old and new protocol consumers must not be mixed.
3. Run migration 001, then migration 002, and verify the generated marker,
   unique key, check constraint, and snapshot columns.
4. Generate the PHP and Go protobuf artifacts from contracts/proto and
   restart workers.
5. Start the upgraded UserService and verify that operation 0/UNSPECIFIED
   and legacy persona creation requests fail closed.
6. Start the upgraded gateway. Let existing ALIAS WebSocket tickets expire;
   clients reconnect to obtain a new MAIN CONNECT ticket.
7. Deploy content/social/chat/message handlers only after they can read and
   write the snapshot columns and public DTOs.

MySQL DDL is auto-committing. A failed rollout must be repaired from the
reported schema mismatch and rerun; it must not be fixed by dropping identity
history.

## HMAC key rotation

The anonymous reference is opaque and scope limited. A key rotation must keep
the persisted reference unchanged. The current projector checks the stored
scope and opaque format; it does not recompute or verify an HMAC on read.
New snapshots use the current key, while old stored references do not need
the old key and are never re-derived from a public persona id. If a future
read-verification design is added, it must persist a key id/version and
define an explicit dual-key window before retiring a key.

## Verification and current limits

Run the following checks from the repository root:

    php -d extension=pdo_sqlite -d extension=sockets vendor/bin/phpunit
    phpstan --no-progress --memory-limit=512M --error-format=json
    buf lint
    go test -mod=mod ./...

Docker/MySQL integration is environment dependent; a local run without those
services must report the integration skips rather than treating them as
successful schema deployment. The current content, social, chat, and message
directories contain protocol/README foundations and snapshot/projector
helpers, not complete post, social, conversation, or message business flows.
