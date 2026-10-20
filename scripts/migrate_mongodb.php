#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use MongoDB\Driver\Exception\CommandException;
use Packages\Database\MongoConnectionFactory;

$databaseName = getenv('MONGODB_DATABASE') ?: 'message_db';
$uri = getenv('MONGODB_MIGRATION_URI') ?: getenv('MONGODB_URI');
if ($uri === false || trim($uri) === '') {
    throw new RuntimeException('MONGODB_MIGRATION_URI or MONGODB_URI is required');
}
$client = MongoConnectionFactory::fromEnvironment(['MONGODB_URI' => $uri]);
$database = $client->selectDatabase($databaseName, [
    'readPreference' => new ReadPreference(ReadPreference::PRIMARY),
    'writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 10000, true),
]);
$token = bin2hex(random_bytes(24));
$lockCollection = $database->selectCollection('schema_migration_locks');
// MongoDB forbids $expr in an upsert predicate. Create the singleton first,
// then acquire it with a conditional, non-upserting update using server time.
try {
    $lockCollection->updateOne(
        ['_id' => 'message-schema-migrations'],
        ['$setOnInsert' => ['lease_until' => new UTCDateTime(0)]],
        ['upsert' => true],
    );
} catch (Throwable $exception) {
    if ((int)$exception->getCode() !== 11000) {
        throw $exception;
    }
    // A competing initializer inserted the singleton; acquisition is still CAS.
}
try {
    $lock = $lockCollection->findOneAndUpdate(
        [
            '_id' => 'message-schema-migrations',
            '$or' => [
                ['lease_until' => ['$exists' => false]],
                ['$expr' => ['$lt' => ['$lease_until', '$$NOW']]],
            ],
        ],
        [[ '$set' => [
            'claim_token' => $token,
            'lease_until' => ['$dateAdd' => ['startDate' => '$$NOW', 'unit' => 'minute', 'amount' => 10]],
        ]]],
        ['returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
    );
} catch (Throwable $exception) {
    throw new RuntimeException('Could not acquire the MongoDB migration lease', 0, $exception);
}
if ($lock === null || (string)($lock['claim_token'] ?? '') !== $token) {
    throw new RuntimeException('Could not acquire the MongoDB migration lease');
}

$migrationId = '20261020000100_message_collections_v1';
$definition = migrationDefinition();
$checksum = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$ledger = $database->selectCollection('schema_migrations');
try {
    $existing = $ledger->findOne(['_id' => $migrationId]);
    if ($existing !== null) {
        if ((string)($existing['checksum'] ?? '') !== $checksum) {
            throw new RuntimeException('MongoDB migration checksum drift detected for ' . $migrationId);
        }
        verifyCollections($database, $definition);
        fwrite(STDOUT, "MongoDB schema is current.\n");
        // Do not exit: finally must release the lease, and databases with v1
        // must also receive the newer migrations below.
    } else {
        installCollections($database, $definition);
        verifyIndexes($database, $definition);
        $ledger->insertOne([
            '_id' => $migrationId,
            'checksum' => $checksum,
            'applied_at' => new UTCDateTime(),
        ]);
        fwrite(STDOUT, "Applied MongoDB migration {$migrationId}.\n");
    }

    // Keep the v1 definition/checksum immutable for databases already migrated.
    $migrationId = '20261020000200_outbox_claimed_at_date';
    $validator = $definition['outbox_events']['validator'];
    $validator['$jsonSchema']['properties']['claimed_at'] = ['bsonType' => ['date', 'null']];
    $checksum = hash('sha256', json_encode($validator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $existing = $ledger->findOne(['_id' => $migrationId]);
    if ($existing !== null && (string)($existing['checksum'] ?? '') !== $checksum) {
        throw new RuntimeException('MongoDB migration checksum drift detected for ' . $migrationId);
    }
    // Idempotent collMod also repairs validator drift on subsequent runs.
    $database->command([
        'collMod' => 'outbox_events', 'validator' => $validator,
        'validationLevel' => 'strict', 'validationAction' => 'error',
    ]);
    if ($existing === null) {
        $ledger->insertOne(['_id' => $migrationId, 'checksum' => $checksum, 'applied_at' => new UTCDateTime()]);
        fwrite(STDOUT, "Applied MongoDB migration {$migrationId}.\n");
    }
} finally {
    $lockCollection->deleteOne(['_id' => 'message-schema-migrations', 'claim_token' => $token]);
}

/** @return array<string,array{validator:array<string,mixed>,indexes:list<array<string,mixed>>}> */
function migrationDefinition(): array
{
    $optionalString = ['bsonType' => ['string', 'null']];
    return [
        'messages' => [
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => [
                    '_id', 'conversation_id', 'seq', 'sender_user_id', 'sender_persona_id', 'sender_identity_mode',
                    'client_message_id', 'type', 'content', 'created_at', 'schema_version',
                ],
                'properties' => [
                    '_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'conversation_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'seq' => ['bsonType' => 'long', 'minimum' => 1],
                    'sender_user_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'sender_persona_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'sender_identity_mode' => ['bsonType' => 'int'],
                    'sender_display_name' => $optionalString,
                    'sender_avatar_key' => $optionalString,
                    'sender_anonymous_ref' => $optionalString,
                    'sender_scope_type' => $optionalString,
                    'sender_scope_id' => $optionalString,
                    'client_message_id' => ['bsonType' => 'string'],
                    'type' => ['bsonType' => 'int'],
                    'content' => ['bsonType' => 'string'],
                    'created_at' => ['bsonType' => 'date'],
                    'schema_version' => ['bsonType' => 'int'],
                ],
            ]],
            'indexes' => [
                ['key' => ['conversation_id' => 1, 'seq' => 1], 'name' => 'uniq_conversation_seq', 'unique' => true],
                [
                    'key' => ['sender_user_id' => 1, 'client_message_id' => 1],
                    'name' => 'uniq_sender_client_message',
                    'unique' => true,
                    'collation' => ['locale' => 'simple'],
                ],
                ['key' => ['conversation_id' => 1, '_id' => -1], 'name' => 'idx_conversation_message_id_desc'],
            ],
        ],
        'conversation_sequences' => [
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['_id', 'last_seq', 'updated_at'],
                'properties' => [
                    '_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'last_seq' => ['bsonType' => 'long', 'minimum' => 0],
                    'updated_at' => ['bsonType' => 'date'],
                ],
            ]],
            'indexes' => [],
        ],
        'outbox_events' => [
            'validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => [
                    '_id', 'conversation_id', 'message_id', 'message_seq', 'aggregate_type', 'aggregate_id', 'event_key',
                    'event_type', 'event_version', 'trace_id', 'payload', 'status', 'available_at', 'created_at',
                    'attempt_count', 'schema_version',
                ],
                'properties' => [
                    '_id' => ['bsonType' => 'string'],
                    'conversation_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'message_id' => ['bsonType' => 'long', 'minimum' => 1],
                    'message_seq' => ['bsonType' => 'long', 'minimum' => 1],
                    'aggregate_type' => ['bsonType' => 'string'],
                    'aggregate_id' => ['bsonType' => 'string'],
                    'event_key' => ['bsonType' => 'string'],
                    'event_type' => ['bsonType' => 'string'],
                    'event_version' => ['bsonType' => 'int'],
                    'trace_id' => ['bsonType' => 'string'],
                    'payload' => ['bsonType' => 'object'],
                    'status' => ['bsonType' => 'int', 'enum' => [0, 1, 2]],
                    'available_at' => ['bsonType' => 'date'],
                    'claimed_at' => $optionalString,
                    'lease_until' => ['bsonType' => ['date', 'null']],
                    'claim_token' => $optionalString,
                    'published_at' => ['bsonType' => ['date', 'null']],
                    'created_at' => ['bsonType' => 'date'],
                    'attempt_count' => ['bsonType' => 'int', 'minimum' => 0],
                    'schema_version' => ['bsonType' => 'int'],
                ],
            ]],
            'indexes' => [
                [
                    'key' => ['conversation_id' => 1, 'message_seq' => 1, 'event_type' => 1],
                    'name' => 'uniq_conversation_message_event', 'unique' => true,
                ],
                ['key' => ['status' => 1, 'available_at' => 1, 'created_at' => 1, '_id' => 1], 'name' => 'idx_pending_scan'],
                ['key' => ['conversation_id' => 1, 'status' => 1, 'message_seq' => 1], 'name' => 'idx_conversation_unpublished_predecessor'],
                ['key' => ['status' => 1, 'lease_until' => 1], 'name' => 'idx_expired_claims'],
            ],
        ],
    ];
}

/** @param array<string,array{validator:array<string,mixed>,indexes:list<array<string,mixed>>}> $definition */
function installCollections(MongoDB\Database $database, array $definition): void
{
    foreach ($definition as $name => $config) {
        $collection = $database->selectCollection($name);
        try {
            $database->createCollection($name, [
                'validator' => $config['validator'],
                'validationLevel' => 'strict',
                'validationAction' => 'error',
            ]);
        } catch (CommandException $exception) {
            if ($exception->getCode() !== 48) {
                throw $exception;
            }
            $database->command([
                'collMod' => $name,
                'validator' => $config['validator'],
                'validationLevel' => 'strict',
                'validationAction' => 'error',
            ]);
        }
        if ($config['indexes'] !== []) {
            $collection->createIndexes($config['indexes']);
        }
    }
}

/** @param array<string,array{validator:array<string,mixed>,indexes:list<array<string,mixed>>}> $definition */
function verifyCollections(MongoDB\Database $database, array $definition): void
{
    foreach ($definition as $name => $config) {
        $collection = $database->selectCollection($name);
        foreach ($config['indexes'] as $index) {
            // createIndexes is idempotent for an identical definition and fails with an index
            // options/key conflict if the same name has drifted.
            $collection->createIndexes([$index]);
        }
    }
}

/** @param array<string,array{validator:array<string,mixed>,indexes:list<array<string,mixed>>}> $definition */
function verifyIndexes(MongoDB\Database $database, array $definition): void
{
    verifyCollections($database, $definition);
}
