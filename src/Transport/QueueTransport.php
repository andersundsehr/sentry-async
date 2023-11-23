<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Transport;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use AUS\SentryAsync\Queue\Entry;
use AUS\SentryAsync\Queue\QueueInterface;
use RuntimeException;
use Sentry\Dsn;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Options;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\Serializer\PayloadSerializerInterface;
use Sentry\Transport\TransportInterface;

readonly class QueueTransport implements TransportInterface
{
    public function __construct(private PayloadSerializerInterface $payloadSerializer, private QueueInterface $queue, private Options $options)
    {
    }

    public function send(Event $event): PromiseInterface
    {
        $serializedPayload = $this->payloadSerializer->serialize($event);

        $eventType = $event->getType();
        $isEnvelope = $this->options->isTracingEnabled() ||
            EventType::transaction() === $eventType ||
            EventType::checkIn() === $eventType;

        $entry = new Entry((string)$this->options->getDsn(), (string)$event->getType(), $isEnvelope, $serializedPayload);
        $this->queue->push($entry);

        $sendResponse = new Response(ResponseStatus::createFromHttpStatusCode(200));
        return new FulfilledPromise($sendResponse);
    }

    public function close(?int $timeout = null): PromiseInterface
    {
        return new FulfilledPromise(true);
    }
}
