# Configuration

To enable the asynchronous transport configure sentry to use our transport factory.<br>
The extension is shipped with a default file_queue, which may be configured in *config/packages/sentry.yaml*

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

You can also change the Entry implementation, if you want to carry extradata for your imlpementation.

```yaml
sentry_async:
  entry_factory:
    entry_class: 'AUS\SentryAsync\Entry\Entry'
```
