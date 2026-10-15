<?php

declare(strict_types=1);

use AUS\SentryAsync\Queue\FileQueue;
use AUS\SentryAsync\Factory\EntryFactory;
use AUS\SentryAsync\Entry\Entry;

require $argv[1];

$queue = new FileQueue(
    10,
    false,
    $argv[2],
    new EntryFactory(Entry::class)
);
for ($i = 0; $i < 20; $i++) {
    $queue->push(new Entry('concurrent event'));
}
