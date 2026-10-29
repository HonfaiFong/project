#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Packages\Database\PostgresConnectionFactory;
use Services\MessageService\Infrastructure\Postgres\MessageArchiveImporter;

$options = getopt('', ['messages:', 'sequences:', 'outbox:']);
foreach (['messages', 'sequences', 'outbox'] as $option) {
    if (!is_string($options[$option] ?? null) || !is_file($options[$option])) {
        fwrite(STDERR, "Usage: php scripts/import_message_archive.php --messages=messages.jsonl --sequences=sequences.jsonl --outbox=outbox.jsonl\n");
        exit(2);
    }
}
$pdo = PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'message_db']);
$counts = (new MessageArchiveImporter($pdo))->import($options['messages'], $options['sequences'], $options['outbox']);
fwrite(STDOUT, json_encode($counts, JSON_THROW_ON_ERROR) . "\n");
