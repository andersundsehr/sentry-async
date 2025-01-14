# Configuration

To enable the asynchronous transport configure sentry to use our transport factory.<br>
The extension is shipped with a default file_queue, which may be configured in *config/packages/sentry.yaml*

The andersundsehr/sentry-async depends on SENTRY_DSN environment variable set.

```yaml
sentry:
  options:
    transport: AUS\SentryAsync\Transport\QueueTransport

sentry_async:
  file_queue:
    compress: true
    limit: 200
    directory: '%kernel.cache_dir%/sentry_async/'
```

Indeed, you can use another queue functionality and do things on your own *config/services.yaml* implementing \AUS\SentryAsync\Queue\QueueInterface

```yaml
  App\Queue\ExampleQueue:
    public: true

  AUS\SentryAsync\Transport\QueueTransport:
    $queue: '@App\Queue\ExampleQueue'
```
