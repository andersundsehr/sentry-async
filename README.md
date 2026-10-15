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

  # Both the transport and the flush command must use the same queue.
  AUS\SentryAsync\Queue\QueueInterface: '@App\Queue\ExampleQueue'
```

You can also change the Entry implementation, if you want to carry extradata for your imlpementation.

```yaml
sentry_async:
  entry_factory:
    entry_class: 'AUS\SentryAsync\Entry\Entry'
```

## Optional gzip delivery

Flush sends gzip only when `gzencode()` is available and the target has accepted a
small gzipped envelope with no event items. Otherwise it sends plain envelopes;
`ext-zlib` is not a package requirement.

With `FileQueue`, the probe result is stored for 24 hours in
`.gzip-<sha256-of-full-dsn>.json` inside the queue directory. Cache files contain
only the support result and check timestamp, and do not count toward the queue
limit. Delete a target's cache file to force a fresh probe. Custom queues reuse
probe results only within the current flush. Targets that reject empty envelopes
receive plain requests, even if they otherwise support gzip.

A gzipped event rejected with HTTP 400 or 415 is retried plain once, and gzip is
disabled for that target in the cache. Transport, authentication and server probe
failures fall back to plain without caching a capability result. HTTP 429 stops
flushing and preserves the queue for the next scheduled run. Rate limiting alone
returns success; an earlier error in the same flush still returns failure.
Oversized transactions still get one retry
without middleware spans; final HTTP 400/413 rejections are logged and discarded.

Envelope rewrites preserve JSON objects and attachment bytes. Transactions without
spans are not reduced. Invalid envelope headers or malformed transaction data
encountered during reduction are logged and retained for inspection; other entries
continue flushing and the command returns failure. Unreadable response bodies do
not interrupt retries or delivery.

## Queue reliability and upgrading

FileQueue publishes complete entries using an atomic rename and serializes producer
capacity checks with `.queue.lock`. Identical events receive unique identifiers.
`push()` returns the same basename identifier accepted by `remove()`; removal cannot
address files outside the queue. Lock and temporary files do not count as entries.
The flush command uses `.flush.lock` to avoid overlapping consumers of a file queue.
Delivery is still at least once: a process crash after acceptance but before removal
can cause a duplicate on the next flush.

Existing plain JSON and raw-DEFLATE queue files remain readable. Text payloads keep
the existing format. Binary payloads are base64 encoded with a `payload_encoding`
marker; update flush workers before enabling producers of binary entries. Keep the
same queue directory during deployment. Directory and file permissions continue to
follow the process umask, so producers and workers need shared access as before.
When zlib is unavailable, new entries are stored uncompressed; reading existing
compressed entries still requires zlib.

Malformed entries are retained and reported while other entries continue flushing.
Storage/serialization failures return a failed Sentry transport result instead of
throwing into the application or reporting successful delivery. Flush HTTP requests
use a five-second connection timeout and ten-second total timeout.

An empty `sentry_async` configuration now supplies all defaults. The default queue
directory remains the system temporary directory for compatibility; the explicit
`%kernel.cache_dir%/sentry_async/` configuration above isolates an application's queue.
Custom queues must iterate without deleting entries until `remove()` acknowledges
successful delivery, and must provide their own concurrency control.

## Tests

Run `composer install` followed by `composer test` in the package checkout. The suite
covers flushing, gzip fallback, persisted queue compatibility, binary payloads,
concurrent producers, transport failures and Symfony container compilation. CI runs
the tests across the supported PHP versions and Symfony 6.4, 7.4 and 8.0.

### Run the GitHub Actions workflow locally

From the package checkout, run `make ci` (or `make`). Docker and GNU Make are
required; PHP, Composer and `act` run inside containers. The Makefile 
runs `.github/workflows/tasks.yml`: PHPUnit covers all supported PHP/Symfony
combinations, and GrumPHP runs once on PHP 8.4/Symfony 7.4. Its development
dependencies currently prevent installation with Symfony 8, so the test matrix
removes GrumPHP from its temporary checkout before resolving dependencies.
The first run downloads the runner image
and actions; subsequent runs reuse the cache in `~/.cache`.

```sh
make ci
make ci ACT_ARGS='--matrix php:8.4 --matrix symfony:7.4'
make ci ACT_ARGS='--list'
make ci ACT_ARGS='--concurrent-jobs 2'
```

`ACT_IMAGE`, `PLATFORM`, `DOCKER_SOCKET` and `ACT_ARGS` can be overridden. An
optional `.secrets` file uses `NAME=value` lines and is ignored by Git. The
command returns failure if any job fails and prints a summary. Runner containers
and volumes are removed after both successful and failed runs.
