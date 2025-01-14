<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Transport;

use AUS\SentryAsync\Queue\Entry;
use AUS\SentryAsync\Queue\QueueInterface;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

readonly class QueueTransport implements TransportInterface
{
    private const HTTP_STATUS_ACCEPTED = 201;

    private const HTTP_STATUS_NO_CONTENT = 204;

    private Options $options;

    public function __construct(
        private QueueInterface $queue,
        private string $dsn,
    ) {
        $this->options = new Options(['dsn' => $this->dsn]);
    }

    public function send(Event $event): Result
    {
        $payloadSerializer = new PayloadSerializer($this->options);
        $serializedPayload = $payloadSerializer->serialize($event);

        $eventType = $event->getType();
        $isEnvelope = $this->options->isTracingEnabled() ||
            EventType::transaction() === $eventType ||
            EventType::checkIn() === $eventType;

        $entry = new Entry((string)$this->options->getDsn(), (string)$event->getType(), $isEnvelope, $serializedPayload);
        $this->queue->push($entry);

        $resultStatus = ResultStatus::createFromHttpStatusCode(self::HTTP_STATUS_ACCEPTED);
        return new Result($resultStatus, $event);
    }

    public function close(?int $timeout = null): Result
    {
        $resultStatus = ResultStatus::createFromHttpStatusCode(self::HTTP_STATUS_NO_CONTENT);
        return new Result($resultStatus);
    }
}
