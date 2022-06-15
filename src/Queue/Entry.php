<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use JsonSerializable;
use Sentry\EventType;

class Entry implements JsonSerializable
{
    private string $dsn;
    private string $payload;
    private string $type;

    public function __construct(string $dsn, string $type, string $payload)
    {
        $this->dsn = $dsn;
        $this->type = $type;
        $this->payload = $payload;
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function isTransaction(): bool
    {
        return $this->type === (string)EventType::transaction();
    }

    public function jsonSerialize(): array
    {
        return [
            'dsn' => $this->dsn,
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}