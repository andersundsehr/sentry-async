<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Tests;

use AUS\SentryAsync\Command\FlushCommand;
use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Factory\EntryFactory;
use AUS\SentryAsync\Queue\FileQueue;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr18Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Sentry\ClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GzipCapabilityTest extends TestCase
{
    private const DSN = 'https://test-public-key@sentry.example/19';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sentry-gzip-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (new \FilesystemIterator($this->directory) as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function missingSenderGzipSkipsProbeAndIgnoresPositiveCache(): void
    {
        $this->cache(true);
        [$tester, $requests] = $this->runFlush([new Response(200)], false);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[0]);
        self::assertStringContainsString('Gzip unavailable on sender', $tester->getDisplay());
    }

    #[Test]
    public function successfulProbeHasNoItemsAndIsCachedForNextFlush(): void
    {
        [$tester, $requests] = $this->runFlush([new Response(200), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $probe = gzdecode((string)$requests[0]->getBody());
        self::assertSame(['dsn' => self::DSN], json_decode(trim($probe), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, substr_count($probe, "\n"));
        self::assertSame('gzip', $requests[1]->getHeaderLine('Content-Encoding'));
        self::assertTrue($this->readCache()['supported']);
        self::assertStringNotContainsString('test-public-key', $this->cacheFile());
        [$next, $nextRequests] = $this->runFlush([new Response(200)]);
        self::assertSame(Command::SUCCESS, $next->getStatusCode());
        self::assertSame('gzip', $nextRequests[0]->getHeaderLine('Content-Encoding'));
        self::assertStringContainsString('Using cached gzip capability', $next->getDisplay());
    }

    #[Test]
    public function rejectedProbeCachesPlainSupportAndReusesIt(): void
    {
        [$tester, $requests] = $this->runFlush([new Response(415), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertFalse($this->readCache()['supported']);
        [, $nextRequests] = $this->runFlush([new Response(200)]);
        $this->assertPlain($nextRequests[0]);
    }

    #[Test]
    #[DataProvider('invalidCache')]
    public function expiredOrMalformedCacheIsProbedAgain(string $contents): void
    {
        file_put_contents($this->cacheFile(), $contents);
        [$tester] = $this->runFlush([new Response(200), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->readCache()['supported']);
    }

    public static function invalidCache(): array
    {
        return [
            [json_encode(['supported' => true, 'checked_at' => time() - 86400], JSON_THROW_ON_ERROR)],
            [json_encode(['supported' => true, 'checked_at' => time() + 86400], JSON_THROW_ON_ERROR)],
            ['not-json'], ['{}'], ['{"supported":"yes","checked_at":1}'],
        ];
    }

    #[Test]
    public function capabilityCacheIsIsolatedByFullDsn(): void
    {
        $this->cache(true);
        $otherDsn = 'https://different-key@sentry.example/19';
        [$tester, $requests] = $this->runFlush([new Response(415), new Response(200)], true, 0, $otherDsn);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertTrue($this->readCache()['supported']);
        self::assertFalse(json_decode(file_get_contents($this->cacheFile($otherDsn)), true)['supported']);
    }

    #[Test]
    #[DataProvider('transientProbeFailures')]
    public function transientProbeFailuresUsePlainWithoutCaching(int $code): void
    {
        [$tester, $requests] = $this->runFlush([new Response($code), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertFileDoesNotExist($this->cacheFile());
    }

    public static function transientProbeFailures(): array
    {
        return [[401], [403], [408], [425], [500], [503], [302]];
    }

    #[Test]
    public function probeTransportFailureUsesPlainWithoutCaching(): void
    {
        $exception = new class('Connection failed') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new Request('POST', 'https://sentry.example');
            }
        };
        [$tester, $requests] = $this->runFlush([$exception, new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function probeRateLimitRetainsEntriesWithoutSendingThem(): void
    {
        [$tester] = $this->runFlush([new Response(429, ['Retry-After' => '60'])]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $this->queue()->count());
        self::assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function probeCompressionFailureSendsPlainWithoutCaching(): void
    {
        [$tester, $requests] = $this->runFlush([new Response(200)], true, 1);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[0]);
        self::assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function eventCompressionFailureUsesPlainAfterSuccessfulProbe(): void
    {
        [$tester, $requests] = $this->runFlush([new Response(200), new Response(200)], true, 2);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertTrue($this->readCache()['supported']);
    }

    #[Test]
    #[DataProvider('gzipRejections')]
    public function gzipRejectionRetriesSamePayloadPlainAndCachesDisabled(int $code): void
    {
        $this->cache(true);
        [$tester, $requests] = $this->runFlush([new Response($code), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        self::assertSame(gzdecode((string)$requests[0]->getBody()), (string)$requests[1]->getBody());
        self::assertFalse($this->readCache()['supported']);
    }

    public static function gzipRejections(): array
    {
        return [[400], [415]];
    }

    #[Test]
    public function plainFallbackCanStillRetryOversizedTransactionWithFewerSpans(): void
    {
        $this->cache(true);
        [$tester, $requests] = $this->runFlush([new Response(415), new Response(413), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertPlain($requests[1]);
        $this->assertPlain($requests[2]);
        $parts = explode("\n", (string)$requests[2]->getBody());
        self::assertSame([['op' => 'db']], json_decode($parts[2], true)['spans']);
    }

    #[Test]
    public function cacheWriteFailureDoesNotPreventDeliveryOrLeaveTemporaryFiles(): void
    {
        // A directory at the destination reliably prevents atomic replacement, even when running as root.
        mkdir($this->cacheFile());
        [$tester, $requests] = $this->runFlush([new Response(200), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('gzip', $requests[1]->getHeaderLine('Content-Encoding'));
        self::assertStringContainsString('Could not replace gzip capability cache', $tester->getDisplay());
        self::assertSame([], glob($this->directory . '*.tmp'));
        self::assertSame([], glob($this->directory . '.*.tmp'));
        self::assertSame(0, $this->queue()->count());
    }

    #[Test]
    public function cacheFilesDoNotAffectQueueCapacityCountOrPop(): void
    {
        $this->cache(true);
        file_put_contents($this->directory . '.gzip-temporary.json.tmp', '{}');
        mkdir($this->directory . 'directory.entry');
        $queue = $this->queue(1);
        self::assertSame($this->directory, $queue->getDirectory());
        self::assertSame(0, $queue->count());
        self::assertNotNull($queue->push(new Entry("{}\n{\"type\":\"event\"}\n{}")));
        self::assertSame(1, $queue->count());
        self::assertNull($queue->push(new Entry('second')));
        $identifier = '';
        self::assertInstanceOf(Entry::class, $queue->pop($identifier));
        self::assertTrue($queue->remove($identifier));
        self::assertNull($queue->pop($identifier));
        self::assertSame(0, $queue->count());
    }

    #[Test]
    #[DataProvider('queueCompression')]
    public function persistedQueueEntriesCanBeFlushedWithEitherStorageCompression(bool $compress): void
    {
        $this->cache(true);
        $queue = new FileQueue(60, $compress, $this->directory, new EntryFactory(Entry::class));
        $payload = "{}\n{\"type\":\"event\"}\n{\"message\":\"stored event\"}";
        self::assertNotNull($queue->push(new Entry($payload)));
        [$tester, $requests] = $this->runFlush([new Response(200), new Response(200)]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->queue()->count());
        $bodies = array_map(static fn (RequestInterface $request): string =>
            gzdecode((string)$request->getBody()), $requests->getArrayCopy());
        self::assertCount(1, array_filter($bodies, static fn (string $body): bool =>
            str_contains($body, 'stored event')));
    }

    public static function queueCompression(): array
    {
        return [[false], [true]];
    }

    #[Test]
    #[DataProvider('corruptEntries')]
    public function corruptStoredEntryDoesNotBlockOtherEntries(string $content): void
    {
        $this->cache(true);
        file_put_contents($this->directory . 'bad.entry', $content);
        [$tester] = $this->runFlush([new Response(200)]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('bad.entry: invalid envelope; retained', $tester->getDisplay());
        self::assertSame(1, $this->queue()->count());
    }

    public static function corruptEntries(): array
    {
        return [[''], ['{invalid'], ['{}'], ['{"payload":false}'],
            ['{"payload":"!","payload_encoding":"base64"}'],
            ['{"payload":"value","payload_encoding":"unknown"}']];
    }

    #[Test]
    public function overlappingFlushDoesNotSendOrRemoveEntries(): void
    {
        $lock = fopen($this->directory . '.flush.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            [$tester] = $this->runFlush([]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            self::assertStringContainsString('Another flush is already running', $tester->getDisplay());
            self::assertSame(1, $this->queue()->count());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cacheFile(string $dsn = self::DSN): string
    {
        return $this->directory . '.gzip-' . hash('sha256', $dsn) . '.json';
    }

    private function cache(bool $supported): void
    {
        file_put_contents($this->cacheFile(), json_encode([
            'supported' => $supported, 'checked_at' => time(),
        ], JSON_THROW_ON_ERROR));
    }

    private function readCache(): array
    {
        return json_decode(file_get_contents($this->cacheFile()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function queue(int $limit = 60): FileQueue
    {
        return new FileQueue($limit, false, $this->directory, new EntryFactory(Entry::class));
    }

    private function assertPlain(RequestInterface $request): void
    {
        self::assertSame('', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('application/x-sentry-envelope', $request->getHeaderLine('Content-Type'));
        self::assertIsArray(json_decode(explode("\n", (string)$request->getBody())[0], true));
    }

    private function runFlush(
        array $responses,
        bool $supportsGzip = true,
        int $failCompressionAt = 0,
        string $dsn = self::DSN
    ): array {
        $queue = $this->queue();
        $queue->push(new Entry(
            "{}\n{\"type\":\"transaction\"}\n"
            . "{\"spans\":[{\"op\":\"middleware.in\"},{\"op\":\"db\"}]}"
        ));
        $requests = new \ArrayObject();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(count($responses)))->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($requests, &$responses) {
                $requests[] = $request;
                $response = array_shift($responses);
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                return $response;
            }
        );
        $client = ClientBuilder::create(['dsn' => $dsn, 'default_integrations' => false])->getClient();
        $command = new class(
            $queue,
            $client,
            new Psr18Client($http),
            $supportsGzip,
            $failCompressionAt
        ) extends FlushCommand {
            private int $compressionCalls = 0;

            public function __construct(
                FileQueue $queue,
                \Sentry\ClientInterface $client,
                private readonly Psr18Client $http,
                private readonly bool $gzipAvailable,
                private readonly int $failCompressionAt
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

            protected function supportsGzip(): bool
            {
                return $this->gzipAvailable;
            }

            protected function compressPayload(string $payload): string|false
            {
                if (++$this->compressionCalls === $this->failCompressionAt) {
                    return false;
                }
                return parent::compressPayload($payload);
            }
        };
        $tester = new CommandTester($command);
        $tester->execute([], ['verbosity' => 64]);
        return [$tester, $requests];
    }
}
