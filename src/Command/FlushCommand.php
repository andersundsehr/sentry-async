<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Command;

use Jean85\Exception\VersionMissingExceptionInterface;
use Exception;
use Http\Discovery\Psr17FactoryDiscovery;
use Jean85\PrettyVersions;
use AUS\SentryAsync\Queue\Entry;
use AUS\SentryAsync\Queue\QueueInterface;
use Sentry\Client;
use Sentry\Dsn;
use Sentry\HttpClient\HttpClientFactory;
use Http\Client\HttpAsyncClient;
use Sentry\HttpClient\HttpClientFactoryInterface;
use Sentry\Options;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FlushCommand extends Command
{
    private readonly HttpClientFactoryInterface $httpClientFactory;

    /**
     * @var array<HttpAsyncClient>
     */
    private array $httpClientCache = [];

    /**
     * @throws VersionMissingExceptionInterface
     */
    public function __construct(private readonly QueueInterface $queue)
    {
        parent::__construct('andersundsehr:sentry-async:flush');
        $this->httpClientFactory = $this->createHttpClientFactory();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('limit-items', null, InputOption::VALUE_REQUIRED, 'How much queue entries should be processed', 100);
    }

    /**
     * @throws VersionMissingExceptionInterface
     */
    private function createHttpClientFactory(): HttpClientFactory
    {
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        return new HttpClientFactory(
            Psr17FactoryDiscovery::findUriFactory(),
            Psr17FactoryDiscovery::findResponseFactory(),
            $streamFactory,
            null,
            Client::SDK_IDENTIFIER,
            PrettyVersions::getVersion('sentry/sentry')->getPrettyVersion()
        );
    }

    private function getClient(Entry $entry): HttpAsyncClient
    {
        $dsn = $entry->getDsn();
        if (isset($this->httpClientCache[$dsn])) {
            return $this->httpClientCache[$dsn];
        }

        $options = new Options(['dsn' => $dsn]);
        $this->httpClientCache[$dsn] = $this->httpClientFactory->create($options);
        return $this->httpClientCache[$dsn];
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();

        $i = (int)$input->getOption('limit-items');

        while (($entry = $this->queue->pop()) && $i > 0) {
            $dsn = Dsn::createFromString($entry->getDsn());
            if ($entry->isEnvelope()) {
                $request = $requestFactory->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                    ->withHeader('Content-Type', 'application/x-sentry-envelope')
                    ->withBody($streamFactory->createStream($entry->getPayload()));
            } elseif ($entry->isTransaction()) {
                $request = $requestFactory->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                    ->withHeader('Content-Type', 'application/x-sentry-envelope')
                    ->withBody($streamFactory->createStream($entry->getPayload()));
            } else {
                $request = $requestFactory->createRequest('POST', $dsn->getStoreApiEndpointUrl())
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($streamFactory->createStream($entry->getPayload()));
            }

            $client = $this->getClient($entry);
            $client->sendAsyncRequest($request)->wait();
            $i--;
        }

        return Command::SUCCESS;
    }
}
