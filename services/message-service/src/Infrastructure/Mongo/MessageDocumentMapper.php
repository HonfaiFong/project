<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

final class MessageDocumentMapper
{
    /** @param array<string,mixed> $message @return array<string,mixed> */
    public function toDocument(array $message): array
    {
        return [
            '_id' => $this->int64((string)$message['message_id'], 'message_id', false),
            'conversation_id' => $this->int64((string)$message['conversation_id'], 'conversation_id', false),
            'seq' => $this->int64((string)$message['seq'], 'seq', false),
            'sender_user_id' => $this->int64((string)$message['sender_user_id'], 'sender_user_id', false),
            'sender_persona_id' => $this->int64((string)$message['sender_persona_id'], 'sender_persona_id', false),
            'sender_identity_mode' => (int)$message['sender_identity_mode'],
            'sender_display_name' => $message['sender_display_name'] ?? null,
            'sender_avatar_key' => $message['sender_avatar_key'] ?? null,
            'sender_anonymous_ref' => $message['sender_anonymous_ref'] ?? null,
            'sender_scope_type' => $message['sender_scope_type'] ?? null,
            'sender_scope_id' => $message['sender_scope_id'] ?? null,
            'client_message_id' => (string)$message['client_message_id'],
            'type' => (int)$message['type'],
            'content' => (string)$message['content'],
            'created_at' => $this->utcDate((string)$message['created_at']),
            'schema_version' => 1,
        ];
    }

    /** @param array<string,mixed>|object $document @return array<string,mixed> */
    public function toDomain(array|object $document): array
    {
        $row = is_array($document) ? $document : get_object_vars($document);
        return [
            'message_id' => $this->decimal($row['_id'] ?? null, 'message_id'),
            'conversation_id' => $this->decimal($row['conversation_id'] ?? null, 'conversation_id'),
            'seq' => $this->decimal($row['seq'] ?? null, 'seq'),
            'sender_user_id' => $this->decimal($row['sender_user_id'] ?? null, 'sender_user_id'),
            'sender_persona_id' => $this->decimal($row['sender_persona_id'] ?? null, 'sender_persona_id'),
            'sender_identity_mode' => (int)($row['sender_identity_mode'] ?? 0),
            'sender_display_name' => $row['sender_display_name'] ?? null,
            'sender_avatar_key' => $row['sender_avatar_key'] ?? null,
            'sender_anonymous_ref' => $row['sender_anonymous_ref'] ?? null,
            'sender_scope_type' => $row['sender_scope_type'] ?? null,
            'sender_scope_id' => $row['sender_scope_id'] ?? null,
            'client_message_id' => (string)($row['client_message_id'] ?? ''),
            'type' => (int)($row['type'] ?? 0),
            'content' => (string)($row['content'] ?? ''),
            'created_at' => $this->dateString($row['created_at'] ?? null),
        ];
    }

    public function int64(string $value, string $field, bool $allowZero = true): Int64
    {
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1 || (!$allowZero && $value === '0')
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new RuntimeException($field . ' is outside the signed 64-bit range');
        }
        return new Int64($value);
    }

    public function decimal(mixed $value, string $field): string
    {
        if ($value instanceof Int64) {
            return (string)$value;
        }
        if (is_int($value) && $value >= 0) {
            return (string)$value;
        }
        throw new RuntimeException('MongoDB ' . $field . ' must be BSON Int64');
    }

    private function utcDate(string $value): UTCDateTime
    {
        $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        return new UTCDateTime($date);
    }

    private function dateString(mixed $value): string
    {
        if (!$value instanceof UTCDateTime) {
            throw new RuntimeException('MongoDB message created_at must be BSON UTCDateTime');
        }
        return $value->toDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
