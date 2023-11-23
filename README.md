# Configuration

To enable the asynchronous transport configure sentry to use our transport factory.<br>
The extension is shipped with a default file_queue, which may be configured in *config/packages/sentry.yaml*

```yaml
sentry:
  transport_factory: AUS\SentryAsync\Transport\TransportFactory

sentry_async:
  file_queue:
    compress: true
    limit: 200
    directory: '%kernel.cache_dir%/sentry_async/'
```

Indeed, you can use another queue functionality and do things on your own *config/services.yaml*

```yaml
  App\Queue\ExampleQueue:
    public: true

  AUS\SentryAsync\Transport\TransportFactory:
    $queue: '@App\Queue\ExampleQueue'
```

Example of an other transport class in *src/Queue/ExampleQueue*
```php
<?php

declare(strict_types=1);

namespace App\Queue;

use AUS\SentryAsync\Queue\Entry;
use AUS\SentryAsync\Queue\QueueInterface;

class ExampleQueue implements QueueInterface
{

    public function pop(): ?Entry
    {
    }

    public function push(Entry $entry): void
    {
    }
}
```
