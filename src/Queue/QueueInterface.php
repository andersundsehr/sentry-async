<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use InvalidArgumentException;
use AUS\SentryAsync\Entry\EntryInterface;
use JsonException;

interface QueueInterface
{
    /**
     * Return the next entry without deleting it, or null when iteration is exhausted.
     * Set $identifier to the entry identifier. Only remove() acknowledges delivery.
     * Advance past malformed entries before throwing so flushing can continue.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function pop(string &$identifier): ?EntryInterface;

    /**
     * Enqueue the entry and return an identifier for the entry or null if an error occured.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function push(EntryInterface $entry): ?string;

    /**
     * Remove the entry identified by the given identifier.
     * Returns true on success or false, if the identifier was not found or could not be removed.
     */
    public function remove(string $identifier): bool;

    public function count(): ?int;
}
