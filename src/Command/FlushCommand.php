<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Command;

use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Queue\QueueInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18Client;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Sentry\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function assert;
use function max;
use function microtime;
use function sprintf;
use function usleep;

class FlushCommand extends Command
{
    public function __construct(private readonly QueueInterface $queue, private readonly ?ClientInterface $sentryClient)
    {
        parent::__construct('andersundsehr:sentry-async:flush');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit-items', null, InputOption::VALUE_REQUIRED, 'How much queue entries should be processed', 60);
        $this->addOption('req-per-sec', null, InputOption::VALUE_REQUIRED, 'How many requests per second should be sent', 5);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->sentryClient) {
            $output->writeln('<error>No Sentry client configured</error>');
            return Command::FAILURE;
        }

        $client = new Psr18Client();
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $limitItems = $input->getOption('limit-items');
        assert(is_int($limitItems) || is_string($limitItems), 'Expected limit-items to be an int or string');
        $limitItems = (int)$limitItems;

        $requestsPerSecond = $input->getOption('req-per-sec');
        assert(is_int($requestsPerSecond) || is_string($requestsPerSecond), 'Expected req-per-sec to be an int or string');
        $requestsPerSecond = (int)($requestsPerSecond);

        $dsn = $this->sentryClient->getOptions()->getDsn();
        if (null === $dsn) {
            $output->writeln('<error>No DSN configured</error>');
            return Command::FAILURE;
        }

        $exitCode = Command::SUCCESS;

        $output->writeln(sprintf('running with limit-items=%d', $limitItems), OutputInterface::VERBOSITY_VERBOSE);
        $count = $this->queue->count();
        if ($count !== null) {
            $output->writeln(sprintf('to do: %d queued entries', $count), OutputInterface::VERBOSITY_VERBOSE);
        } else {
            $output->writeln(sprintf('<warning>Queue does not support counting entries class: %s</warning>', $this->queue::class), OutputInterface::VERBOSITY_VERY_VERBOSE);
        }

        $lastIdentifier = '';
        $itemIndex = 0;
        $lastTime = microtime(true);
        do {
            $entry = $this->queue->pop($lastIdentifier);
            if (!$entry instanceof Entry) {
                break;
            }

            $output->writeln(sprintf('start with entry %d', $itemIndex), OutputInterface::VERBOSITY_VERBOSE);

            $payload = $entry->getPayload();
            $payload = str_replace('https:\/\/123@sentry-dummy\/1', (string)$dsn, $payload);

            $request = $client
                ->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                ->withHeader('Content-Type', 'application/x-sentry-envelope')
                ->withBody($streamFactory->createStream($payload));

            $response = $client->sendRequest($request);
            $code = $response->getStatusCode();

            // removes the item on a success code, 429 means limit reached, safe to try later.
            if (($code > 199 && $code < 300) || 413 === $code || 400 === $code) {
                $output->writeln(sprintf('done with at %d', $itemIndex), OutputInterface::VERBOSITY_VERBOSE);
                $this->queue->remove($lastIdentifier);
            } elseif ($code === 429) {
                $output->writeln('<error>Rate limit reached, waiting for sentry to recover sleep(5s)</error>');
                sleep(5); // wait for sentry to recover
            } elseif ($code > 399) {
                $output->writeln(sprintf('<error>error at %d</error>', $itemIndex));
                $exitCode = Command::FAILURE;
            }

            if ($itemIndex % $requestsPerSecond === 0) {
                $toSleep = max(0, (int)(1_000_000 - (microtime(true) - $lastTime) * 1_000_000));
                if ($toSleep) {
                    $output->writeln(sprintf('%d req/s (sleep %dms)', $requestsPerSecond, $toSleep / 1_000), OutputInterface::VERBOSITY_VERBOSE);
                    usleep($toSleep);
                }

                $lastTime = microtime(true);
            }

            $itemIndex++;
        } while ($itemIndex < $limitItems);

        $output->writeln('<info>done</info>', OutputInterface::VERBOSITY_VERBOSE);
        if ($itemIndex === $limitItems) {
            $output->writeln(sprintf('<info>Processed %d items, stopping as limit reached</info>', $itemIndex), OutputInterface::VERBOSITY_VERBOSE);
            $output->writeln('<warning>there could be more entries</warning>', OutputInterface::VERBOSITY_VERBOSE);
        }

        return $exitCode;
    }
}
