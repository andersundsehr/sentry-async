<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Command;

use AUS\SentryAsync\Queue\QueueInterface;
use Http\Factory\Discovery\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Sentry\Dsn;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Psr18Client;

class FlushCommand extends Command
{
    public function __construct(private readonly QueueInterface $queue)
    {
        parent::__construct('andersundsehr:sentry-async:flush');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit-items', null, InputOption::VALUE_REQUIRED, 'How much queue entries should be processed', 10);
    }

    /**
     * @throws ClientExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = new Psr18Client();
        $requestFactory = HttpFactory::requestFactory();
        $streamFactory = HttpFactory::streamFactory(); // Finds a PSR-17 stream factory
        $i = (int)$input->getOption('limit-items');

        while ($i > 0 && $entry = $this->queue->pop()) {
            $dsn = Dsn::createFromString($entry->getDsn());

            $request = $requestFactory->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                ->withHeader('Content-Type', 'application/x-sentry-envelope')
                ->withBody($streamFactory->createStream($entry->getPayload()));

            $client->sendRequest($request);
            $i--;
        }

        return Command::SUCCESS;
    }
}
