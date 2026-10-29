<?php

declare(strict_types=1);

namespace Services\ChatService\Application;

use Contracts\Events\EventEnvelope;
use Contracts\Events\EventTypes;
use Contracts\Gen\Message\V1\PublicMessage;
use Google\Protobuf\PrintOptions;
use RuntimeException;
use Services\ChatService\Domain\RealtimeMessagePublisher;
use Services\ChatService\Domain\Exception\InvalidMessageEvent;
use Services\ChatService\Infrastructure\PdoChatRepository;

final readonly class MessageCreatedHandler
{
    public function __construct(
        private PdoChatRepository $repository,
        private ChatApplicationService $chat,
        private RealtimeMessagePublisher $realtime,
    ) {
    }

    public function handle(EventEnvelope $event): void
    {
        if ($event->eventType !== EventTypes::MESSAGE_CREATED || $event->eventVersion !== 1) {
            throw new InvalidMessageEvent('Unsupported message event');
        }
        $message = $event->payload['message'] ?? null;
        if (!is_array($message) || blank($event->eventId)) {
            throw new InvalidMessageEvent('Invalid message event');
        }
        foreach (['message_id', 'conversation_id', 'seq'] as $field) {
            if (!is_string($message[$field] ?? null) || preg_match('/\A[1-9][0-9]{0,18}\z/', $message[$field]) !== 1
                || (strlen($message[$field]) === 19 && strcmp($message[$field], '9223372036854775807') > 0)) {
                throw new InvalidMessageEvent('Invalid message event ' . $field);
            }
        }
        $conversationId = $message['conversation_id'];
        if ($event->key !== $conversationId
            || !is_string($message['created_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\z/', $message['created_at']) !== 1
        ) {
            throw new InvalidMessageEvent('Message event conversation or timestamp is invalid');
        }
        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $message['created_at'], new \DateTimeZone('UTC'));
        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s.v') !== $message['created_at']) {
            throw new InvalidMessageEvent('Message event timestamp is invalid');
        }
        if (empty($this->repository->findById($conversationId))) {
            throw new RuntimeException('Message event conversation is unavailable');
        }
        // The public Protobuf contract rejects internal or unexpected fields before fan-out.
        $public = new PublicMessage();
        try {
            $public->mergeFromJsonString(json_encode($message, JSON_THROW_ON_ERROR));
        } catch (\Exception $exception) {
            throw new InvalidMessageEvent('Message event public payload is invalid', previous: $exception);
        }
        $safeMessage = json_decode($public->serializeToJsonString(
            PrintOptions::PRESERVE_PROTO_FIELD_NAMES | PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS,
        ), true, 512, JSON_THROW_ON_ERROR);
        $this->repository->applyMessageSummary(
            $conversationId, $message['message_id'], $message['seq'], $message['created_at'],
        );
        $conversation = $this->repository->findById($conversationId);
        $recipients = [];
        foreach ($conversation['members'] ?? [] as $member) {
            $userId = (string)$member['user_id'];
            // Recheck current identity and bilateral block state, rather than trusting old event recipients.
            if ($this->chat->getConversationMemberSnapshot($userId, $conversationId)['allowed']) {
                $recipients[] = $userId;
            }
        }
        if (!empty($recipients)) {
            $this->realtime->publish($event->eventId, $recipients, $safeMessage);
        }
    }
}
