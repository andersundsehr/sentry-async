<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Command;

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

class FlushCommand extends Command
{
    public function __construct(private readonly QueueInterface $queue, private readonly ?ClientInterface $sentryClient)
    {
        parent::__construct('andersundsehr:sentry-async:flush');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit-items', null, InputOption::VALUE_REQUIRED, 'How much queue entries should be processed', 10);
        $this->addOption('microseconds-sleep', null, InputOption::VALUE_REQUIRED, 'Microseconds to sleep between the items pushed to sentry', 100000);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->sentryClient) {
            $output->writeln('No Sentry client configured');
            return Command::FAILURE;
        }

        $client = new Psr18Client();
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        /** @var int|string $limitItems */
        $limitItems = $input->getOption('limit-items');
        $limitItems = (int)$limitItems;

        /** @var int|string $microseconds */
        $microseconds = $input->getOption('microseconds-sleep');
        $microseconds = (int)$microseconds;

        $dsn = $this->sentryClient->getOptions()->getDsn();
        if (null === $dsn) {
            $output->writeln('No DSN configured');
            return Command::FAILURE;
        }

        $success = Command::SUCCESS;

        $lastIdentfier = '';
        while ($limitItems > 0 && $entry = $this->queue->pop($lastIdentfier)) {
            $payload = $entry->getPayload();
            $payload = str_replace('https:\/\/123@sentry-dummy\/1', (string)$dsn, $payload);

            $request = $client->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                ->withHeader('Content-Type', 'application/x-sentry-envelope')
                ->withBody($streamFactory->createStream($payload));

            $response = $client->sendRequest($request);
            $code = $response->getStatusCode();

            // removes the item on a success code, 429 meens limit reached, safe to try later.
            if (($code > 199 && $code < 300) || 413 === $code || 400 === $code) {
                $this->queue->remove($lastIdentfier);
            } elseif ($code !== 429 && $code > 399) {
                $success = Command::FAILURE;
            }

            if ($microseconds) {
                usleep($microseconds);
            }

            $limitItems--;
        }

        return $success;
    }
}
