<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use JsonSerializable;

readonly class Entry implements JsonSerializable
{
    public function __construct(private string $dsn, private string $type, private bool $isEnvelope, private string $payload)
    {
    }

    public function getDsn(): string
    {
        return $this->dsn;
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
            'dsn' => $this->dsn,
            'type' => $this->type,
            'isEnvelope' => $this->isEnvelope,
            'payload' => $this->payload,
        ];
    }
}
