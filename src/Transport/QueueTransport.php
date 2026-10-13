<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Transport;

use Throwable;
use AUS\SentryAsync\Factory\EntryFactory;
use AUS\SentryAsync\Queue\QueueInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

readonly class QueueTransport implements TransportInterface
{
    private const HTTP_STATUS_NO_CONTENT = 204;

    private Options $options;

    public function __construct(
        private QueueInterface $queue,
        private EntryFactory $entryFactory
    ) {
        $this->options = new Options(['dsn' => 'https://123@sentry-dummy/1']);
    }

    public function send(Event $event): Result
    {
        try {
            $payloadSerializer = new PayloadSerializer($this->options);
            $entry = $this->entryFactory->createEntry($payloadSerializer->serialize($event));
            $identifier = $this->queue->push($entry);
        } catch (Throwable) {
            // Reporting an error must not replace the application's original failure.
            return new Result(ResultStatus::failed(), $event);
        }

        return new Result($identifier === null ? ResultStatus::failed() : ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        $resultStatus = ResultStatus::createFromHttpStatusCode(self::HTTP_STATUS_NO_CONTENT);
        return new Result($resultStatus);
    }
}
