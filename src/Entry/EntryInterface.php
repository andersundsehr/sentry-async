<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Entry;

use JsonSerializable;

/**
 * Interface for queue entries.
 * Designed to support more metadata on your implementation.
 */
interface EntryInterface extends JsonSerializable
{
    public function getPayload(): string;
}
