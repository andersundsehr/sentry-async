<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace AUS\SentryAsync\Command;

use RuntimeException;
use InvalidArgumentException;
use GuzzleHttp\Client;
use stdClass;
use Throwable;
use AUS\SentryAsync\Entry\EntryInterface;
use AUS\SentryAsync\Queue\FileQueue;
use AUS\SentryAsync\Queue\QueueInterface;
use Http\Discovery\Psr18Client;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Sentry\ClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FlushCommand extends Command
{
    private const GZIP_CACHE_TTL = 86400;

    public function __construct(private readonly QueueInterface $queue, private readonly ?ClientInterface $sentryClient)
    {
        parent::__construct('andersundsehr:sentry-async:flush');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'limit-items',
            null,
            InputOption::VALUE_REQUIRED,
            'How much queue entries should be processed',
            60
        );
        $this->addOption(
            'req-per-sec',
            null,
            InputOption::VALUE_REQUIRED,
            'How many requests per second should be sent',
            5
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = null;
        try {
            if ($this->queue instanceof FileQueue) {
                $lock = @fopen($this->queue->getDirectory() . '.flush.lock', 'c');
                if ($lock === false) {
                    $output->writeln('<error>Queue directory is unavailable</error>');
                    return Command::FAILURE;
                }

                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    $output->writeln('Another flush is already running', OutputInterface::VERBOSITY_VERBOSE);
                    return Command::SUCCESS;
                }
            }

            return $this->flushQueue($input, $output);
        } catch (RuntimeException $runtimeException) {
            $output->writeln(
                '<error>Queue flush failed: ' . $this->safeDiagnostic($runtimeException->getMessage()) . '</error>'
            );
            return Command::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function flushQueue(InputInterface $input, OutputInterface $output): int
    {
        $limitItems = filter_var(
            $input->getOption('limit-items'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $requestsPerSecond = filter_var(
            $input->getOption('req-per-sec'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($limitItems === false || $requestsPerSecond === false) {
            $output->writeln('<error>limit-items and req-per-sec must be positive integers</error>');
            return Command::INVALID;
        }

        if ($this->sentryClient === null) {
            $output->writeln('<error>No Sentry client configured</error>');
            return Command::FAILURE;
        }

        $dsn = $this->sentryClient->getOptions()->getDsn();
        if ($dsn === null) {
            $output->writeln('<error>No DSN configured</error>');
            return Command::FAILURE;
        }

        $client = $this->createHttpClient();
        $exitCode = Command::SUCCESS;
        $count = $this->queue->count();
        if ($count !== null) {
            $output->writeln(sprintf('to do: %d queued entries', $count), OutputInterface::VERBOSITY_VERBOSE);
        }

        $nextRequestAt = 0.0;
        $itemIndex = 0;
        $gzip = null;
        $cacheFile = $this->capabilityCacheFile((string)$dsn);
        while ($itemIndex < $limitItems) {
            $identifier = '';
            try {
                $entry = $this->queue->pop($identifier);
            } catch (JsonException | InvalidArgumentException $exception) {
                $itemIndex++;
                $this->logInvalidEnvelope(OutputFormatter::escape($identifier), $exception, $output);
                $exitCode = Command::FAILURE;
                continue;
            }

            if (!$entry instanceof EntryInterface) {
                break;
            }

            $itemIndex++;
            $label = OutputFormatter::escape($identifier);
            try {
                $payload = $this->replaceDsn($entry->getPayload(), (string)$dsn);
            } catch (JsonException | InvalidArgumentException $exception) {
                $this->logInvalidEnvelope($label, $exception, $output);
                $exitCode = Command::FAILURE;
                continue;
            }

            if ($gzip === null) {
                $gzip = $this->determineGzipSupport(
                    $client,
                    $dsn->getEnvelopeApiEndpointUrl(),
                    (string)$dsn,
                    $cacheFile,
                    $output,
                    $requestsPerSecond,
                    $nextRequestAt
                );
                if ($gzip === null) {
                    // A rate-limited probe must not be followed by an event request.
                    return $exitCode;
                }
            }

            try {
                $spansReduced = false;
                while (true) {
                    $response = $this->sendEnvelope(
                        $client,
                        $dsn->getEnvelopeApiEndpointUrl(),
                        $payload,
                        $label,
                        $output,
                        $requestsPerSecond,
                        $nextRequestAt,
                        $gzip
                    );
                    $code = $response->getStatusCode();
                    if ($gzip && ($code === 400 || $code === 415)) {
                        $this->logResponse($response, $label, 'retrying plain', $output);
                        $gzip = false;
                        $this->writeCapabilityCache($cacheFile, false, $output);
                        continue;
                    }

                    if ($code === 413 && !$spansReduced) {
                        $spansReduced = true;
                        $reducedPayload = $this->reduceTransactionSpans($payload);
                        if ($reducedPayload !== null) {
                            $this->logResponse($response, $label, 'retrying with fewer spans', $output);
                            $payload = $reducedPayload;
                            continue;
                        }
                    }

                    break;
                }
            } catch (JsonException | InvalidArgumentException $exception) {
                $this->logInvalidEnvelope($label, $exception, $output);
                $exitCode = Command::FAILURE;
                continue;
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (ClientExceptionInterface $exception) {
                $output->writeln(sprintf(
                    '<error>%s: transport failure; retained: %s</error>',
                    $label,
                    $this->safeDiagnostic($exception->getMessage())
                ));
                $exitCode = Command::FAILURE;
                continue;
            }

            $code = $response->getStatusCode();
            if ($code > 199 && $code < 300) {
                $output->writeln(
                    sprintf('%s: accepted (HTTP %d)', $label, $code),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                if (!$this->queue->remove($identifier)) {
                    $output->writeln(sprintf('<error>%s: accepted but could not remove queue entry</error>', $label));
                    $exitCode = Command::FAILURE;
                }

                continue;
            }

            if ($code === 429) {
                $this->logResponse($response, $label, 'rate limited; queue retained for next flush', $output);
                break;
            }

            $exitCode = Command::FAILURE;
            if ($code === 400 || $code === 413) {
                $removed = $this->queue->remove($identifier);
                $this->logResponse(
                    $response,
                    $label,
                    $removed ? 'rejected; discarded' : 'rejected; removal failed',
                    $output
                );
            } else {
                $this->logResponse($response, $label, 'rejected; retained', $output);
            }
        }

        $output->writeln(sprintf('Processed %d entries', $itemIndex), OutputInterface::VERBOSITY_VERBOSE);
        return $exitCode;
    }

    protected function createHttpClient(): Psr18Client
    {
        return new Psr18Client(new Client(['connect_timeout' => 5, 'timeout' => 10]));
    }

    protected function waitForRequestSlot(int $requestsPerSecond, float &$nextRequestAt): void
    {
        $delay = (int)ceil(max(0, $nextRequestAt - microtime(true)) * 1_000_000);
        if ($delay > 0) {
            usleep($delay);
        }

        $nextRequestAt = microtime(true) + 1 / $requestsPerSecond;
    }

    protected function supportsGzip(): bool
    {
        return function_exists('gzencode');
    }

    protected function compressPayload(string $payload): string|false
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        return $this->supportsGzip() ? gzencode($payload) : false;
    }

    private function capabilityCacheFile(string $dsn): ?string
    {
        return $this->queue instanceof FileQueue
            ? $this->queue->getDirectory() . '.gzip-' . hash('sha256', $dsn) . '.json'
            : null;
    }

    private function readCapabilityCache(?string $file): ?bool
    {
        if ($file === null || !is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        try {
            $cache = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($cache) || !isset($cache['supported'], $cache['checked_at'])
            || !is_bool($cache['supported']) || !is_int($cache['checked_at'])
        ) {
            return null;
        }

        $age = time() - $cache['checked_at'];
        return $age < 0 || $age > self::GZIP_CACHE_TTL - 1 ? null : $cache['supported'];
    }

    private function writeCapabilityCache(?string $file, bool $supported, OutputInterface $output): void
    {
        if ($file === null) {
            return;
        }

        $contents = json_encode(['supported' => $supported, 'checked_at' => time()], JSON_THROW_ON_ERROR);
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            $output->writeln('Could not write gzip capability cache; delivery will continue');
            return;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            $output->writeln('Could not replace gzip capability cache; delivery will continue');
        }
    }

    private function determineGzipSupport(
        Psr18Client $client,
        string $endpoint,
        string $dsn,
        ?string $cacheFile,
        OutputInterface $output,
        int $requestsPerSecond,
        float &$nextRequestAt
    ): ?bool {
        if (!$this->supportsGzip()) {
            $output->writeln('Gzip unavailable on sender; sending plain', OutputInterface::VERBOSITY_VERBOSE);
            return false;
        }

        $cached = $this->readCapabilityCache($cacheFile);
        if ($cached !== null) {
            $output->writeln(
                'Using cached gzip capability: ' . ($cached ? 'supported' : 'plain'),
                OutputInterface::VERBOSITY_VERBOSE
            );
            return $cached;
        }

        // No event items: this probe does not report an error or transaction.
        $probe = $this->compressPayload(json_encode(['dsn' => $dsn], JSON_THROW_ON_ERROR) . "\n");
        if ($probe === false) {
            $output->writeln('Gzip compression failed; sending plain', OutputInterface::VERBOSITY_VERBOSE);
            return false;
        }

        try {
            $response = $this->postEnvelope($client, $endpoint, $probe, true, $requestsPerSecond, $nextRequestAt);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (ClientExceptionInterface $clientException) {
            $output->writeln('Gzip probe failed; sending plain: ' . $this->safeDiagnostic($clientException->getMessage()));
            return false;
        }

        $code = $response->getStatusCode();
        if ($code > 199 && $code < 300) {
            $this->writeCapabilityCache($cacheFile, true, $output);
            $output->writeln(sprintf('Gzip probe accepted (HTTP %d)', $code), OutputInterface::VERBOSITY_VERBOSE);
            return true;
        }

        $this->logResponse(
            $response,
            'gzip probe',
            $code === 429 ? 'rate limited; queue retained' : 'sending plain',
            $output
        );
        if ($code === 429) {
            return null;
        }

        // Authentication, timeout and transient server failures do not establish a capability result.
        if ($code > 399 && $code < 500 && !in_array($code, [401, 403, 408, 425], true)) {
            $this->writeCapabilityCache($cacheFile, false, $output);
        }

        return false;
    }

    private function sendEnvelope(
        Psr18Client $client,
        string $endpoint,
        string $payload,
        string $label,
        OutputInterface $output,
        int $requestsPerSecond,
        float &$nextRequestAt,
        bool &$gzip
    ): ResponseInterface {
        $body = $gzip ? $this->compressPayload($payload) : $payload;
        if ($body === false) {
            $gzip = false;
            $body = $payload;
            $output->writeln('Gzip compression failed; sending plain', OutputInterface::VERBOSITY_VERBOSE);
        }

        $output->writeln(
            sprintf(
                '%s: envelope=%d bytes, %s=%d bytes',
                $label,
                strlen($payload),
                $gzip ? 'gzip' : 'plain',
                strlen($body)
            ),
            OutputInterface::VERBOSITY_VERBOSE
        );
        return $this->postEnvelope($client, $endpoint, $body, $gzip, $requestsPerSecond, $nextRequestAt);
    }

    private function postEnvelope(
        Psr18Client $client,
        string $endpoint,
        string $body,
        bool $gzip,
        int $requestsPerSecond,
        float &$nextRequestAt
    ): ResponseInterface {
        $request = $client->createRequest('POST', $endpoint)
            ->withHeader('Content-Type', 'application/x-sentry-envelope')
            ->withBody($client->createStream($body));
        if ($gzip) {
            $request = $request->withHeader('Content-Encoding', 'gzip');
        }

        $this->waitForRequestSlot($requestsPerSecond, $nextRequestAt);
        return $client->sendRequest($request);
    }

    private function replaceDsn(string $payload, string $dsn): string
    {
        $newline = strpos($payload, "\n");
        if ($newline === false) {
            throw new InvalidArgumentException('Sentry envelope has no item separator', 1791886002);
        }

        $header = json_decode(substr($payload, 0, $newline), false, 512, JSON_THROW_ON_ERROR);
        if (!$header instanceof stdClass) {
            throw new InvalidArgumentException('Malformed Sentry envelope header', 1791886003);
        }

        $header->dsn = $dsn;
        // Only rewrite the header: item bodies can contain arbitrary attachment bytes.
        return json_encode($header, JSON_THROW_ON_ERROR) . substr($payload, $newline);
    }

    private function reduceTransactionSpans(string $payload): ?string
    {
        $parts = explode("\n", $payload, 3);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed Sentry envelope', 1791886004);
        }

        $itemHeader = json_decode($parts[1], false, 512, JSON_THROW_ON_ERROR);
        if (!$itemHeader instanceof stdClass) {
            throw new InvalidArgumentException('Malformed Sentry item header', 1791886005);
        }

        if (($itemHeader->type ?? null) !== 'transaction') {
            return null;
        }

        // Honor explicit byte lengths and preserve following items, including binary attachments.
        $length = $itemHeader->length ?? strpos($parts[2], "\n");
        if ($length === false) {
            $length = strlen($parts[2]);
        }

        if (!is_int($length) || $length < 1 || $length > strlen($parts[2])) {
            throw new InvalidArgumentException('Malformed transaction item length', 1791886006);
        }

        $transaction = json_decode(substr($parts[2], 0, $length), false, 512, JSON_THROW_ON_ERROR);
        if (!$transaction instanceof stdClass) {
            throw new InvalidArgumentException('Malformed transaction', 1791886009);
        }

        if (!property_exists($transaction, 'spans')) {
            return null;
        }

        if (!is_array($transaction->spans)) {
            throw new InvalidArgumentException('Malformed transaction spans', 1791886007);
        }

        $spans = array_filter($transaction->spans, static function (mixed $span): bool {
            if (!$span instanceof stdClass) {
                throw new InvalidArgumentException('Malformed transaction span', 1791886008);
            }

            return !in_array($span->op ?? null, ['middleware.in', 'middleware.out'], true);
        });
        if (count($spans) === count($transaction->spans)) {
            return null;
        }

        $transaction->spans = array_values($spans);
        $body = json_encode($transaction, JSON_THROW_ON_ERROR);
        if (isset($itemHeader->length)) {
            $itemHeader->length = strlen($body);
        }

        return $parts[0] . "\n" . json_encode($itemHeader, JSON_THROW_ON_ERROR) . "\n"
            . $body . substr($parts[2], $length);
    }

    private function logResponse(
        ResponseInterface $response,
        string $label,
        string $action,
        OutputInterface $output
    ): void {
        $details = [];
        foreach (['X-Sentry-Error', 'X-Sentry-Rate-Limits', 'Retry-After'] as $header) {
            $value = $response->getHeaderLine($header);
            if ($value !== '') {
                $details[] = $header . '=' . $this->safeDiagnostic($value);
            }
        }

        try {
            $body = $response->getBody()->read(2048);
        } catch (RuntimeException) {
            // Diagnostics must not interrupt delivery or the plain-envelope fallback.
            $body = '';
            $details[] = 'body unavailable';
        }

        if ($body !== '') {
            $details[] = 'body=' . $this->safeDiagnostic($body);
        }

        $message = sprintf(
            '%s: HTTP %d; %s%s',
            $label,
            $response->getStatusCode(),
            $action,
            $details === [] ? '' : '; ' . implode('; ', $details)
        );
        $output->writeln($response->getStatusCode() === 429
            ? '<info>' . $message . '</info>'
            : '<error>' . $message . '</error>');
    }

    private function logInvalidEnvelope(
        string $label,
        Throwable $exception,
        OutputInterface $output
    ): void {
        $output->writeln(sprintf(
            '<error>%s: invalid envelope; retained: %s</error>',
            $label,
            $this->safeDiagnostic($exception->getMessage())
        ));
    }

    private function safeDiagnostic(string $message): string
    {
        $dsn = $this->sentryClient?->getOptions()->getDsn();
        if ($dsn !== null) {
            $message = str_replace($dsn->getPublicKey(), '[redacted key]', $message);
        }

        $message = preg_replace('~https?://[^\s"<>]+~', '[redacted URL]', $message) ?? '[redacted]';
        $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', $message) ?? '[redacted]';
        return OutputFormatter::escape(substr($message, 0, 2048));
    }
}
