<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Tests;

use AUS\SentryAsync\Command\FlushCommand;
use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Queue\FileQueue;
use AUS\SentryAsync\Queue\QueueInterface;
use AUS\SentryAsync\SentryAsyncBundle;
use AUS\SentryAsync\Tests\Fixtures\SentryAsyncKernel;
use AUS\SentryAsync\Transport\QueueTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Transport\ResultStatus;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class SymfonyKernelTest extends TestCase
{
    private string $directory;
    private SentryAsyncKernel $kernel;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/SentryAsyncKernel.php';

        $this->directory = sys_get_temp_dir() . '/sentry-kernel-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->kernel = new SentryAsyncKernel($this->directory);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->directory);
    }

    #[Test]
    #[DataProvider('queueConfigurations')]
    public function bundleLoadsYamlConfigurationAndWiresServices(?int $limit, ?bool $compress): void
    {
        $queueConfiguration = ['directory' => $this->directory . '/queue'];
        if ($limit !== null) {
            $queueConfiguration['limit'] = $limit;
        }
        if ($compress !== null) {
            $queueConfiguration['compress'] = $compress;
        }
        $this->writeConfiguration($queueConfiguration);

        $this->kernel->boot();

        self::assertInstanceOf(SentryAsyncBundle::class, $this->kernel->getBundle('SentryAsyncBundle'));
        $container = $this->kernel->getContainer();
        $queue = $container->get('test.queue');
        self::assertInstanceOf(FileQueue::class, $queue);
        self::assertSame($this->directory . '/queue/', $queue->getDirectory());
        $transport = $container->get(QueueTransport::class);
        self::assertInstanceOf(QueueTransport::class, $transport);
        self::assertSame(ResultStatus::success(), $transport->send(Event::createEvent())->getStatus());
        self::assertSame(1, $queue->count());

        $identifier = '';
        $entry = $queue->pop($identifier);
        self::assertInstanceOf(Entry::class, $entry);
        $stored = file_get_contents($queue->getDirectory() . $identifier);
        self::assertIsString($stored);
        if (($compress ?? true) && function_exists('gzdeflate')) {
            $stored = gzinflate($stored);
            self::assertIsString($stored);
        }
        $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($entry->getPayload(), $decoded['payload']);

        for ($index = 1; $index < ($limit ?? 100); $index++) {
            self::assertNotNull($queue->push(new Entry('capacity check')));
        }
        self::assertNull($queue->push(new Entry('over capacity')));
        self::assertSame($limit ?? 100, $queue->count());

        $command = $container->get('test.flush_command');
        self::assertInstanceOf(FlushCommand::class, $command);
        self::assertSame($queue, (new \ReflectionProperty($command, 'queue'))->getValue($command));
    }

    /**
     * @return array<string, array{?int, ?bool}>
     */
    public static function queueConfigurations(): array
    {
        return [
            'defaults' => [null, null],
            'custom limits and uncompressed storage' => [2, false],
        ];
    }

    /**
     * @param array<string, int|string|bool> $queueConfiguration
     */
    #[Test]
    #[DataProvider('invalidQueueConfigurations')]
    public function invalidYamlConfigurationPreventsKernelBoot(array $queueConfiguration): void
    {
        $this->writeConfiguration($queueConfiguration);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('sentry_async.file_queue');

        $this->kernel->boot();
    }

    /**
     * @return array<string, array{array<string, int|string|bool>}>
     */
    public static function invalidQueueConfigurations(): array
    {
        return [
            'negative capacity' => [['limit' => -1]],
            'empty directory' => [['directory' => '']],
        ];
    }

    /**
     * @param array<string, int|string|bool> $queueConfiguration
     */
    private function writeConfiguration(array $queueConfiguration): void
    {
        $configuration = [
            'sentry_async' => ['file_queue' => $queueConfiguration],
            'services' => [
                'test.queue' => ['alias' => QueueInterface::class, 'public' => true],
                'test.flush_command' => ['alias' => FlushCommand::class, 'public' => true],
            ],
        ];
        file_put_contents($this->directory . '/config.yaml', Yaml::dump($configuration, 5));
    }
}
