<?php

declare(strict_types=1);

require $argv[1];

$queue = new \AUS\SentryAsync\Queue\FileQueue(
    10,
    false,
    $argv[2],
    new \AUS\SentryAsync\Factory\EntryFactory(\AUS\SentryAsync\Entry\Entry::class)
);
for ($i = 0; $i < 20; $i++) {
    $queue->push(new \AUS\SentryAsync\Entry\Entry('concurrent event'));
}
