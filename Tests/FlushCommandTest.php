<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Tests;

use AUS\SentryAsync\Command\FlushCommand;
use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Queue\QueueInterface;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr18Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Sentry\ClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FlushCommandTest extends TestCase
{
    private const DSN = 'https://test-public-key@sentry.example/19';

    #[Test]
    public function gzipPreservesItemAndBinaryAttachmentBytes(): void
    {
        $attachment = "\x00\xffbinary\nhttps:\/\/123@sentry-dummy\/1\x80";
        $items = '{"type":"event"}' . "\n"
            . '{"message":"https:\/\/123@sentry-dummy\/1"}' . "\n"
            . json_encode(
                ['type' => 'attachment', 'length' => strlen($attachment)],
                JSON_THROW_ON_ERROR
            ) . "\n" . $attachment;
        $payload = '{"dsn":"https:\/\/123@sentry-dummy\/1","event_id":"id"}' . "\n" . $items;
        [$tester, $requests] = $this->executeWithResponses($payload, [new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('gzip', $requests[0]->getHeaderLine('Content-Encoding'));
        self::assertSame('application/x-sentry-envelope', $requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('https://sentry.example/api/19/envelope/', (string)$requests[0]->getUri());
        [$header, $actualItems] = explode("\n", gzdecode((string)$requests[0]->getBody()), 2);
        self::assertSame(self::DSN, json_decode($header, true, 512, JSON_THROW_ON_ERROR)['dsn']);
        self::assertSame($items, $actualItems);
        self::assertStringContainsString('accepted (HTTP 200)', $tester->getDisplay());
        self::assertStringContainsString('gzip=', $tester->getDisplay());
        self::assertCount(1, $requests);
        self::assertStringNotContainsString('test-public-key', $tester->getDisplay());
    }

    #[Test]
    public function retries413OnceWithoutMiddlewareSpansAndPreservesAttachments(): void
    {
        $attachment = "\x00\xff\nattachment";
        $body = json_encode([
            'transaction' => 'checkout',
            'contexts' => ['trace' => ['trace_id' => 'trace']],
            'spans' => [
                ['op' => 'middleware.in'], ['op' => 'db', 'description' => 'SELECT 1'], ['op' => 'middleware.out'],
            ],
        ], JSON_THROW_ON_ERROR);
        $suffix = "\n" . json_encode(
            ['type' => 'attachment', 'length' => strlen($attachment)],
            JSON_THROW_ON_ERROR
        ) . "\n" . $attachment;
        $payload = "{}\n" . json_encode(
            ['type' => 'transaction', 'length' => strlen($body)],
            JSON_THROW_ON_ERROR
        ) . "\n" . $body . $suffix;
        [$tester, $requests] = $this->executeWithResponses($payload, [new Response(413), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(2, $requests);
        $parts = explode("\n", gzdecode((string)$requests[1]->getBody()), 3);
        $length = json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR)['length'];
        $transaction = json_decode(substr($parts[2], 0, $length), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['op' => 'db', 'description' => 'SELECT 1']], $transaction['spans']);
        self::assertSame('checkout', $transaction['transaction']);
        self::assertSame(['trace' => ['trace_id' => 'trace']], $transaction['contexts']);
        self::assertSame($suffix, substr($parts[2], $length));
        self::assertStringContainsString('retrying with fewer spans', $tester->getDisplay());
    }

    #[Test]
    public function final413IsDiscardedAfterOneRetry(): void
    {
        [$tester, $requests] = $this->executeWithResponses(
            $this->transactionPayload(),
            [new Response(413), new Response(413)]
        );
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(2, $requests);
        self::assertStringContainsString('rejected; discarded', $tester->getDisplay());
    }

    #[Test]
    public function event413IsDiscardedWithoutChangingTheEvent(): void
    {
        [$tester, $requests] = $this->executeWithResponses(
            "{}\n{\"type\":\"event\"}\n{\"message\":\"error\"}",
            [new Response(413)]
        );
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $requests);
        self::assertStringContainsString('rejected; discarded', $tester->getDisplay());
    }

    #[Test]
    public function transactionWithoutMiddlewareSpansIsNotRetried(): void
    {
        [$tester, $requests] = $this->executeWithResponses(
            "{}\n{\"type\":\"transaction\"}\n{\"spans\":[{\"op\":\"db\"}]}",
            [new Response(413)]
        );
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $requests);
    }

    #[Test]
    #[DataProvider('httpFailures')]
    public function failuresAreExplicitAndRetainedUnless400Or413(int $code, string $action): void
    {
        [$tester] = $this->executeWithResponses($this->transactionPayload(), [new Response($code, [
            'X-Sentry-Error' => 'rejected <error> https://test-public-key@sentry.example/19',
            'X-Sentry-Rate-Limits' => '60:transaction:organization', 'Retry-After' => '60',
        ], str_repeat('body', 1000))]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'test.entry: HTTP ' . $code . '; rejected; ' . $action,
            $display
        );
        self::assertStringContainsString('Retry-After=60', $display);
        self::assertStringContainsString('X-Sentry-Rate-Limits=60:transaction:organization', $display);
        self::assertStringNotContainsString('test-public-key', $display);
        self::assertLessThan(5500, strlen($display));
    }

    public static function httpFailures(): array
    {
        return [
            [400, 'discarded'], [401, 'retained'], [403, 'retained'],
            [500, 'retained'], [302, 'retained'],
        ];
    }

    #[Test]
    public function transportFailuresRetainEntryAndContinue(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('count')->willReturn(2);
        $entries = [new Entry($this->transactionPayload()), new Entry($this->transactionPayload()), null];
        $queue->method('pop')->willReturnCallback(static function (string &$identifier) use (&$entries) {
            $identifier = 'test.entry';
            return array_shift($entries);
        });
        $queue->expects(self::once())->method('remove')->willReturn(true);
        $calls = 0;
        $http = $this->createMock(HttpClientInterface::class);
        $exception = new class extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new \GuzzleHttp\Psr7\Request('POST', 'https://sentry.example');
            }
        };
        $http->expects(self::exactly(3))->method('sendRequest')->willReturnCallback(
            static function () use (&$calls, $exception) {
                if ($calls++ === 1) {
                    throw $exception;
                }
                return new Response(200);
            }
        );
        $tester = new CommandTester($this->command($queue, $http));
        $tester->execute([], ['verbosity' => 64]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('transport failure; retained', $tester->getDisplay());
        self::assertStringContainsString('accepted (HTTP 200)', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('priorFlushErrors')]
    public function rateLimitStopsBeforeSendingNextEntry(bool $priorError): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('count')->willReturn(2);
        $popCount = 0;
        $queue->expects(self::exactly($priorError ? 2 : 1))->method('pop')->willReturnCallback(function (string &$identifier) use ($priorError, &$popCount): Entry {
            $popCount++;
            $identifier = 'test.entry';
            if ($priorError && $popCount === 1) {
                throw new \JsonException('Malformed previous queue entry');
            }
            return new Entry($this->transactionPayload());
        });
        $queue->expects(self::never())->method('remove');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('sendRequest')->willReturnOnConsecutiveCalls(
            new Response(200),
            new Response(429)
        );
        $tester = new CommandTester($this->command($queue, $http));
        $tester->execute([]);
        self::assertSame($priorError ? Command::FAILURE : Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('rate limited; queue retained for next flush', $tester->getDisplay());
    }

    public static function priorFlushErrors(): array
    {
        return [[false], [true]];
    }

    #[Test]
    #[DataProvider('invalidOptions')]
    public function invalidOptionsDoNotConsumeQueue(string $option, string $value): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('pop');
        $tester = new CommandTester(new FlushCommand($queue, null));
        $tester->execute([$option => $value]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public static function invalidOptions(): array
    {
        return [
            ['--req-per-sec', '0'], ['--req-per-sec', '-1'], ['--req-per-sec', '1.5'],
            ['--limit-items', '0'], ['--limit-items', 'invalid'],
        ];
    }

    #[Test]
    public function missingSentryClientFailsWithoutConsumingQueue(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('pop');
        $tester = new CommandTester(new FlushCommand($queue, null));
        $tester->execute([]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function pacingStartsImmediatelyAndSpacesSubsequentRequests(): void
    {
        $command = new FlushCommand($this->createStub(QueueInterface::class), null);
        $method = new \ReflectionMethod($command, 'waitForRequestSlot');
        $next = 0.0;
        $start = microtime(true);
        $method->invokeArgs($command, [5, &$next]);
        self::assertGreaterThan($start, $next);
        // The first slot does not wait. An upcoming slot must wait before proceeding.
        $next = microtime(true) + 0.03;
        $start = microtime(true);
        $method->invokeArgs($command, [5, &$next]);
        self::assertGreaterThan(0.02, microtime(true) - $start);
        self::assertGreaterThan(microtime(true), $next);
    }

    #[Test]
    public function rewritingDsnPreservesHeaderObjects(): void
    {
        [$tester, $requests] = $this->executeWithResponses(
            '{"trace":{"empty":{},"numeric":{"0":"value"}}}' . "\n{\"type\":\"event\"}\n{}",
            [new Response(200)]
        );
        $header = json_decode(explode("\n", gzdecode((string)$requests[0]->getBody()))[0]);
        self::assertInstanceOf(\stdClass::class, $header->trace->empty);
        self::assertInstanceOf(\stdClass::class, $header->trace->numeric);
    }

    #[Test]
    public function reducingSpansPreservesNestedObjects(): void
    {
        $payload = "{}\n{\"type\":\"transaction\"}\n"
            . '{"extra":{"empty":{},"numeric":{"0":"value"}},"spans":[{"op":"middleware.in"},{"op":"db","data":{}}]}';
        [, $requests] = $this->executeWithResponses($payload, [new Response(413), new Response(200)]);
        $body = json_decode(explode("\n", gzdecode((string)$requests[1]->getBody()), 3)[2]);
        self::assertInstanceOf(\stdClass::class, $body->extra->empty);
        self::assertInstanceOf(\stdClass::class, $body->extra->numeric);
        self::assertInstanceOf(\stdClass::class, $body->spans[0]->data);
    }

    #[Test]
    public function oversizedTransactionWithoutSpansDoesNotAbortFlush(): void
    {
        [$tester, $requests] = $this->executeWithResponses(
            "{}\n{\"type\":\"transaction\"}\n{\"transaction\":\"no spans\"}",
            [new Response(413)]
        );
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(1, $requests);
        self::assertStringContainsString('rejected; discarded', $tester->getDisplay());
    }

    #[Test]
    public function unreadableResponseBodyDoesNotPreventPlainRetry(): void
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects(self::once())->method('read')->with(2048)
            ->willThrowException(new \RuntimeException('Read failed'));
        [$tester, $requests] = $this->executeWithResponses(
            $this->transactionPayload(),
            [new Response(415, [], $stream), new Response(200)]
        );
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(2, $requests);
        self::assertStringContainsString('body unavailable', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('malformedEnvelopes')]
    public function malformedEnvelopeIsRetainedAndNextEntryIsSent(string $payload): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $entries = [new Entry($payload), new Entry($this->transactionPayload()), null];
        $queue->method('pop')->willReturnCallback(static function (string &$identifier) use (&$entries) {
            $identifier = count($entries) === 3 ? 'bad.entry' : 'good.entry';
            return array_shift($entries);
        });
        $queue->expects(self::once())->method('remove')->with('good.entry')->willReturn(true);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('sendRequest')->willReturn(new Response(200));
        $tester = new CommandTester($this->command($queue, $http));
        $tester->execute([]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('invalid envelope; retained', $tester->getDisplay());
    }

    public static function malformedEnvelopes(): array
    {
        return [['not an envelope'], ["{invalid}\n{}\n{}"], ["[]\n{}\n{}"]];
    }

    #[Test]
    public function malformedTransactionOn413IsRetainedAndNextEntryIsSent(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $entries = [new Entry("{}\n{\"type\":\"transaction\"}\n{\"spans\":false}"),
            new Entry($this->transactionPayload()), null];
        $queue->method('pop')->willReturnCallback(static function (string &$identifier) use (&$entries) {
            $identifier = count($entries) === 3 ? 'bad.entry' : 'good.entry';
            return array_shift($entries);
        });
        $queue->expects(self::once())->method('remove')->with('good.entry')->willReturn(true);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(3))->method('sendRequest')->willReturnOnConsecutiveCalls(
            new Response(200),
            new Response(413),
            new Response(200)
        );
        $tester = new CommandTester($this->command($queue, $http));
        $tester->execute([]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('bad.entry: invalid envelope; retained', $tester->getDisplay());
    }

    private function transactionPayload(): string
    {
        return "{}\n{\"type\":\"transaction\"}\n{\"spans\":[{\"op\":\"middleware.in\"},{\"op\":\"db\"}]}";
    }

    private function executeWithResponses(string $payload, array $responses): array
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->method('count')->willReturn(1);
        $entries = [new Entry($payload), null];
        $queue->method('pop')->willReturnCallback(static function (string &$identifier) use (&$entries) {
            $identifier = 'test.entry';
            return array_shift($entries);
        });
        $finalCode = $responses[array_key_last($responses)]->getStatusCode();
        $queue->expects($finalCode === 200 || $finalCode === 400 || $finalCode === 413 ? self::once() : self::never())
            ->method('remove')->with('test.entry')->willReturn(true);
        if ($responses[0]->getStatusCode() === 400) {
            array_unshift($responses, new Response(400));
        }
        $requests = new \ArrayObject();
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(count($responses) + 1))->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($requests, &$responses) {
                if ($request->getHeaderLine('Content-Encoding') === 'gzip'
                    && substr_count(gzdecode((string)$request->getBody()), "\n") === 1) {
                    return new Response(200);
                }
                $requests[] = $request;
                return array_shift($responses);
            }
        );
        $tester = new CommandTester($this->command($queue, $http));
        $tester->execute([], ['verbosity' => 64]);
        return [$tester, $requests];
    }

    private function command(QueueInterface $queue, HttpClientInterface $http): FlushCommand
    {
        $client = ClientBuilder::create(['dsn' => self::DSN, 'default_integrations' => false])->getClient();
        return new class($queue, $client, new Psr18Client($http)) extends FlushCommand {
            public function __construct(
                QueueInterface $queue,
                \Sentry\ClientInterface $client,
                private readonly Psr18Client $http
            ) {
                parent::__construct($queue, $client);
            }

            protected function createHttpClient(): Psr18Client
            {
                return $this->http;
            }

            protected function waitForRequestSlot(int $requestsPerSecond, float &$nextRequestAt): void
            {
            }
        };
    }
}
