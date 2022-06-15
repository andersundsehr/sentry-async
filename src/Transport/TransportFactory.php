<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Transport;

use Sentry\Options;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class TransportFactory implements TransportFactoryInterface  {

    private QueueTransport $queueTransport;

    public function __construct(QueueTransport $queueTransport)
    {
        $this->queueTransport = $queueTransport;
    }

    public function create(Options $options): TransportInterface
    {
        return $this->queueTransport;
    }
}
