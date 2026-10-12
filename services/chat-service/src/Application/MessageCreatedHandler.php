<?php

declare(strict_types=1);

namespace Services\ChatService\Application;

use Contracts\Events\EventEnvelope;
use Contracts\Events\EventTypes;
use Contracts\Gen\Message\V1\PublicMessage;
use Google\Protobuf\PrintOptions;
use RuntimeException;
use Services\ChatService\Domain\RealtimeMessagePublisher;
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
            throw new RuntimeException('Unsupported message event');
        }
        $message = $event->payload['message'] ?? null;
        if (!is_array($message) || $event->eventId === '') {
            throw new RuntimeException('Invalid message event');
        }
        foreach (['message_id', 'conversation_id', 'seq'] as $field) {
            if (!is_string($message[$field] ?? null) || preg_match('/\A[1-9][0-9]{0,19}\z/', $message[$field]) !== 1) {
                throw new RuntimeException('Invalid message event ' . $field);
            }
        }
        $conversationId = $message['conversation_id'];
        if ($event->key !== $conversationId || $this->repository->findById($conversationId) === null
            || !is_string($message['created_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\z/', $message['created_at']) !== 1
        ) {
            throw new RuntimeException('Message event conversation or timestamp is invalid');
        }
        // The public Protobuf contract rejects internal or unexpected fields before fan-out.
        $public = new PublicMessage();
        $public->mergeFromJsonString(json_encode($message, JSON_THROW_ON_ERROR));
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
        if ($recipients !== []) {
            $this->realtime->publish($event->eventId, $recipients, $safeMessage);
        }
    }
}
