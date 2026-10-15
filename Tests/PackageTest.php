<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Tests;

use SplFileInfo;
use FilesystemIterator;
use RuntimeException;
use InvalidArgumentException;
use stdClass;
use ReflectionClass;
use Composer\Autoload\ClassLoader;
use AUS\SentryAsync\Entry\EntryInterface;
use AUS\SentryAsync\Command\FlushCommand;
use ReflectionProperty;
use AUS\SentryAsync\DependencyInjection\Configuration;
use AUS\SentryAsync\DependencyInjection\SentryAsyncExtension;
use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Factory\EntryFactory;
use AUS\SentryAsync\Queue\FileQueue;
use AUS\SentryAsync\Queue\QueueInterface;
use AUS\SentryAsync\Transport\QueueTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Transport\ResultStatus;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PackageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sentry-package-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->directory) as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    private function queue(bool $compress = false): FileQueue
    {
        return new FileQueue(10, $compress, $this->directory, new EntryFactory(Entry::class));
    }

    #[Test]
    #[DataProvider('compressionModes')]
    public function binaryPayloadSurvivesStorage(bool $compress): void
    {
        $payload = "{}\n{\"type\":\"attachment\",\"length\":4}\n\x00\xff\xfe\n";
        $queue = $this->queue($compress);
        self::assertNotNull($queue->push(new Entry($payload)));
        $identifier = '';
        self::assertSame($payload, $queue->pop($identifier)?->getPayload());
    }

    /** @return list<array{bool}> */
    public static function compressionModes(): array
    {
        return [[false], [true]];
    }

    #[Test]
    public function pushIdentifierCanBeUsedForRemoval(): void
    {
        $queue = $this->queue();
        $identifier = $queue->push(new Entry('payload'));
        self::assertNotNull($identifier);
        self::assertTrue($queue->remove($identifier));
        self::assertSame(0, $queue->count());
    }

    #[Test]
    public function removalCannotEscapeQueueDirectory(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'sentry-outside-');
        try {
            self::assertFalse($this->queue()->remove('../' . basename($outside)));
            self::assertFileExists($outside);
        } finally {
            if (is_file($outside)) {
                unlink($outside);
            }
        }
    }

    #[Test]
    public function repeatedIdenticalEventsHaveUniqueIdentifiers(): void
    {
        $queue = $this->queue();
        $identifiers = [];
        for ($i = 0; $i < 10; $i++) {
            $identifiers[] = $queue->push(new Entry('same payload'));
        }

        self::assertCount(10, array_unique($identifiers));
        self::assertSame(10, $queue->count());
    }

    #[Test]
    public function transportReportsRejectedWrites(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->willReturn(null);
        $transport = new QueueTransport($queue, new EntryFactory(Entry::class));
        self::assertSame(ResultStatus::failed(), $transport->send(Event::createEvent())->getStatus());
    }

    #[Test]
    public function storageFailureDoesNotCrashApplication(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->willThrowException(new RuntimeException('disk failed'));
        $transport = new QueueTransport($queue, new EntryFactory(Entry::class));
        self::assertSame(ResultStatus::failed(), $transport->send(Event::createEvent())->getStatus());
    }

    #[Test]
    public function emptyConfigurationProvidesServiceDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(Entry::class, $config['entry_factory']['entry_class']);
        self::assertTrue($config['file_queue']['compress']);
        self::assertSame(100, $config['file_queue']['limit']);
        $container = new ContainerBuilder();
        (new SentryAsyncExtension())->load([['file_queue' => ['directory' => $this->directory]]], $container);
        $container->compile();
        self::assertInstanceOf(QueueTransport::class, $container->get(QueueTransport::class));
    }

    #[Test]
    public function invalidEntryClassIsRejectedDuringConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EntryFactory(stdClass::class);
    }

    #[Test]
    #[DataProvider('compressionModes')]
    public function legacyEntriesRemainReadable(bool $compress): void
    {
        $content = json_encode(['payload' => 'legacy event'], JSON_THROW_ON_ERROR);
        file_put_contents($this->directory . '/legacy.entry', $compress ? gzdeflate($content) : $content);
        $identifier = '';
        self::assertSame('legacy event', $this->queue()->pop($identifier)?->getPayload());
        self::assertSame('legacy.entry', $identifier);
    }

    #[Test]
    public function queueCanBeReusedAfterReachingEnd(): void
    {
        $queue = $this->queue();
        $identifier = '';
        self::assertNull($queue->pop($identifier));
        $queue->push(new Entry('next batch'));
        self::assertSame('next batch', $queue->pop($identifier)?->getPayload());
    }

    #[Test]
    public function unavailableDirectoryReturnsFailedDelivery(): void
    {
        $path = $this->directory . '/file';
        file_put_contents($path, 'not a directory');
        $queue = new FileQueue(10, true, $path, new EntryFactory(Entry::class));
        $transport = new QueueTransport($queue, new EntryFactory(Entry::class));
        self::assertSame(ResultStatus::failed(), $transport->send(Event::createEvent())->getStatus());
    }

    #[Test]
    public function successfulDeliveryAndCloseReportSuccess(): void
    {
        $queue = $this->queue();
        $transport = new QueueTransport($queue, new EntryFactory(Entry::class));
        self::assertSame(ResultStatus::success(), $transport->send(Event::createEvent())->getStatus());
        self::assertSame(1, $queue->count());
        self::assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    #[Test]
    public function concurrentProducersRespectCapacityAndLeaveCompleteFiles(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrent producer test');
        }

        $classLoaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        self::assertIsString($classLoaderFile);
        $autoload = dirname($classLoaderFile, 2) . '/autoload.php';
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/Fixtures/producer.php', $autoload, $this->directory],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
        }

        $queue = $this->queue();
        self::assertSame(10, $queue->count());
        $identifier = '';
        $count = 0;
        while (($entry = $queue->pop($identifier)) instanceof EntryInterface) {
            self::assertSame('concurrent event', $entry->getPayload());
            $count++;
        }

        self::assertSame(10, $count);
        self::assertSame([], glob($this->directory . '/*.tmp'));
    }

    #[Test]
    public function customQueueAliasIsSharedByTransportAndFlushCommand(): void
    {
        $container = new ContainerBuilder();
        (new SentryAsyncExtension())->load([['file_queue' => ['directory' => $this->directory]]], $container);
        $container->register('custom.queue')->setSynthetic(true)->setPublic(true);
        $container->setAlias(QueueInterface::class, 'custom.queue');
        $container->getDefinition(FlushCommand::class)->setPublic(true);
        $container->compile();
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->willReturn('custom-id');
        $container->set('custom.queue', $queue);
        $transport = $container->get(QueueTransport::class);
        self::assertSame(ResultStatus::success(), $transport->send(Event::createEvent())->getStatus());
        $command = $container->get(FlushCommand::class);
        self::assertSame($queue, (new ReflectionProperty($command, 'queue'))->getValue($command));
    }
}
