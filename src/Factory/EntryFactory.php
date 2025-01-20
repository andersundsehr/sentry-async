<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Factory;

use AUS\SentryAsync\Entry\EntryInterface;

class EntryFactory
{
    public function __construct(private readonly string $entryClass)
    {
    }

    public function createEntry(string $payload): EntryInterface
    {
        $entry = new $this->entryClass($payload);
        assert($entry instanceof EntryInterface);
        return $entry;
    }
}
