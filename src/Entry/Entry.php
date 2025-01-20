<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Entry;

readonly class Entry implements EntryInterface
{
    public function __construct(private string $payload)
    {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * @return array<string, string|bool>
     */
    public function jsonSerialize(): array
    {
        return [
            'payload' => $this->payload,
        ];
    }
}
