<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use AUS\SentryAsync\Entry\EntryInterface;
use JsonException;

interface QueueInterface
{
    /**
     * Dequeue the entry or return null if the queue is empty.
     * If a pop occured, $identifier will hol the popped identifier.
     *
     * @throws JsonException
     */
    public function pop(string &$identifier): ?EntryInterface;

    /**
     * Enqueue the entry and return an identifier for the entry or null if an error occured.
     *
     * @throws JsonException
     */
    public function push(EntryInterface $entry): ?string;

    /**
     * Remove the entry identified by the given identifier.
     * Returns true on success or false, if the identifier was not found or could not be removed.
     */
    public function remove(string $identifier): bool;
}
