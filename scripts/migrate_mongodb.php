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

$definition = migrationDefinition();
$ledger = $database->selectCollection('schema_migrations');
try {
    $migrationId = '20261020000100_message_collections_v1';
    $checksum = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $existing = $ledger->findOne(['_id' => $migrationId]);
    if ($existing !== null) {
        if ((string)($existing['checksum'] ?? '') !== $checksum) {
            throw new RuntimeException('MongoDB migration checksum drift detected for ' . $migrationId);
        }
    } else {
        if ($ledger->findOne(['_id' => ['$in' => [
            '20261020000200_outbox_claimed_at_date',
            '20261020000300_drop_message_id_paging_index',
        ]]]) !== null) {
            throw new RuntimeException('MongoDB migration ledger is out of order before ' . $migrationId);
        }
        installCollections($database, $definition);
        verifySchema($database, $definition);
        $ledger->insertOne([
            '_id' => $migrationId,
            'checksum' => $checksum,
            'applied_at' => new UTCDateTime(),
        ]);
        fwrite(STDOUT, "Applied MongoDB migration {$migrationId}.\n");
    }

    $migrationId = '20261020000200_outbox_claimed_at_date';
    $validator = $definition['outbox_events']['validator'];
    $validator['$jsonSchema']['properties']['claimed_at'] = ['bsonType' => ['date', 'null']];
    $checksum = hash('sha256', json_encode($validator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $existing = $ledger->findOne(['_id' => $migrationId]);
    if ($existing !== null && (string)($existing['checksum'] ?? '') !== $checksum) {
        throw new RuntimeException('MongoDB migration checksum drift detected for ' . $migrationId);
    }
    if ($existing === null) {
        if ($ledger->findOne(['_id' => '20261020000300_drop_message_id_paging_index']) !== null) {
            throw new RuntimeException('MongoDB migration ledger is out of order before ' . $migrationId);
        }
        $database->command([
            'collMod' => 'outbox_events', 'validator' => $validator,
            'validationLevel' => 'strict', 'validationAction' => 'error',
        ]);
        $ledger->insertOne(['_id' => $migrationId, 'checksum' => $checksum, 'applied_at' => new UTCDateTime()]);
        fwrite(STDOUT, "Applied MongoDB migration {$migrationId}.\n");
    }

    $migrationId = '20261020000300_drop_message_id_paging_index';
    $migration = ['collection' => 'messages', 'drop_index' => 'idx_conversation_message_id_desc'];
    $checksum = hash('sha256', json_encode($migration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $existing = $ledger->findOne(['_id' => $migrationId]);
    if ($existing !== null && (string)($existing['checksum'] ?? '') !== $checksum) {
        throw new RuntimeException('MongoDB migration checksum drift detected for ' . $migrationId);
    }
    if ($existing === null) {
        dropIndexIfPresent($database->selectCollection('messages'), $migration['drop_index']);
        $ledger->insertOne(['_id' => $migrationId, 'checksum' => $checksum, 'applied_at' => new UTCDateTime()]);
        fwrite(STDOUT, "Applied MongoDB migration {$migrationId}.\n");
    }

    $expected = $definition;
    $expected['outbox_events']['validator'] = $validator;
    $expected['messages']['indexes'] = array_values(array_filter(
        $expected['messages']['indexes'],
        static fn (array $index): bool => $index['name'] !== $migration['drop_index'],
    ));
    verifySchema($database, $expected);
    fwrite(STDOUT, "MongoDB schema is current.\n");
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
function verifySchema(MongoDB\Database $database, array $definition): void
{
    foreach ($definition as $name => $config) {
        $info = null;
        foreach ($database->listCollections(['filter' => ['name' => $name]]) as $candidate) {
            $info = $candidate;
            break;
        }
        if ($info === null || $info->getType() !== 'collection') {
            throw new RuntimeException('MongoDB collection is missing or has the wrong type: ' . $name);
        }

        $options = normalizeBsonDocument($info->getOptions());
        if (canonicalSchemaJson($options['validator'] ?? null) !== canonicalSchemaJson($config['validator'])
            || ($options['validationLevel'] ?? null) !== 'strict'
            || ($options['validationAction'] ?? null) !== 'error') {
            throw new RuntimeException('MongoDB collection validator drift detected for ' . $name);
        }

        $expectedIndexes = ['_id_' => ['key' => ['_id' => 1], 'unique' => true]];
        foreach ($config['indexes'] as $index) {
            $expectedIndexes[$index['name']] = $index;
        }
        $actualIndexes = [];
        foreach ($database->selectCollection($name)->listIndexes() as $index) {
            $actualIndexes[$index->getName()] = $index;
        }
        if (array_diff_key($expectedIndexes, $actualIndexes) !== []
            || array_diff_key($actualIndexes, $expectedIndexes) !== []) {
            throw new RuntimeException('MongoDB index set drift detected for ' . $name);
        }

        foreach ($expectedIndexes as $indexName => $expected) {
            $actual = $actualIndexes[$indexName];
            if ($actual->getKey() !== $expected['key']
                || ($indexName !== '_id_' && $actual->isUnique() !== (bool)($expected['unique'] ?? false))) {
                throw new RuntimeException('MongoDB index definition drift detected for ' . $name . '.' . $indexName);
            }
            foreach (['sparse' => false, 'hidden' => false, 'partialFilterExpression' => null, 'expireAfterSeconds' => null] as $option => $default) {
                if (canonicalSchemaJson($actual[$option] ?? $default) !== canonicalSchemaJson($expected[$option] ?? $default)) {
                    throw new RuntimeException('MongoDB index option drift detected for ' . $name . '.' . $indexName . ': ' . $option);
                }
            }
            $actualCollation = normalizeBsonDocument($actual['collation'] ?? ['locale' => 'simple']);
            $expectedCollation = $expected['collation'] ?? ['locale' => 'simple'];
            // ICU's implementation version is server metadata, not an index option.
            unset($actualCollation['version'], $expectedCollation['version']);
            if (canonicalSchemaJson($actualCollation) !== canonicalSchemaJson($expectedCollation)) {
                throw new RuntimeException('MongoDB index collation drift detected for ' . $name . '.' . $indexName);
            }
        }
    }
}

function dropIndexIfPresent(MongoDB\Collection $collection, string $name): void
{
    foreach ($collection->listIndexes() as $index) {
        if ($index->getName() === $name) {
            $collection->dropIndex($name);
            return;
        }
    }
}

/** @return array<string,mixed> */
function normalizeBsonDocument(mixed $value): array
{
    $normalized = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($normalized)) {
        throw new RuntimeException('MongoDB schema metadata returned an invalid document');
    }
    return $normalized;
}

function canonicalSchemaJson(mixed $value): string
{
    return json_encode(canonicalSchemaValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function canonicalSchemaValue(mixed $value): mixed
{
    if (is_object($value)) {
        $value = normalizeBsonDocument($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalSchemaValue', $value);
    }
    ksort($value);
    foreach ($value as $key => $child) {
        $value[$key] = canonicalSchemaValue($child);
    }
    return $value;
}
