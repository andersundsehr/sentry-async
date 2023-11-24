<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Transport;

use AUS\SentryAsync\Queue\QueueInterface;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\NullTransport;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

readonly class TransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly QueueInterface $queue)
    {
    }

    public function create(Options $options): TransportInterface
    {
        $dsn = $options->getDsn();

        if (null === $dsn) {
            return new NullTransport();
        }

        return new QueueTransport(
            new PayloadSerializer($options),
            $this->queue,
            $options
        );
    }
}
