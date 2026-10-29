<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use PDO;

/** Repair the summary from committed messages using separate service-scoped connections. */
final readonly class MessageSummaryReconciler
{
    public function __construct(private PDO $messages, private PdoChatRepository $chat)
    {
    }

    public function runOnce(): int
    {
        $cursor = '0';
        $scanned = 0;
        do {
            $statement = $this->messages->prepare(
                "SELECT m.conversation_id::text, m.message_id::text, m.seq::text, "
                . "to_char(m.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS created_at "
                . 'FROM conversation_sequences s JOIN messages m ON m.conversation_id = s.conversation_id AND m.seq = s.last_seq '
                . 'WHERE s.conversation_id > :cursor ORDER BY s.conversation_id LIMIT 500',
            );
            $statement->execute(['cursor' => $cursor]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $this->chat->applyMessageSummary($row['conversation_id'], $row['message_id'], $row['seq'], $row['created_at']);
                $cursor = $row['conversation_id'];
                $scanned++;
            }
        } while (count($rows) === 500);
        return $scanned;
    }
}
