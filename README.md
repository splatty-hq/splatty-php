# Splatty (PHP)

PHP client for [Splatty](https://github.com/splatty-hq/splatty). Captures
exceptions, PHP errors and logs and ships them over the envelope protocol.
Mirrors [`splatty-ruby`](https://github.com/splatty-hq/splatty-ruby),
[`splatty-js`](https://github.com/splatty-hq/splatty-js) and
[`splatty-go`](https://github.com/splatty-hq/splatty-go).

No runtime dependencies beyond `ext-curl`, `ext-json` and `ext-zlib`. The
Monolog, PSR-3 and PSR-15 integrations are optional and only load if you use
them.

- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Capturing events](#capturing-events)
- [Uncaught exceptions, errors and fatals](#uncaught-exceptions-errors-and-fatals)
- [HTTP requests](#http-requests)
- [Background jobs](#background-jobs)
- [Logs](#logs)
- [Shutting down](#shutting-down)
- [API reference](#api-reference)
- [Wire protocol](#wire-protocol)

## Installation

```sh
composer require splatty-hq/splatty-php
```

Requires PHP 8.1 or newer.

## Quick start

```php
\Splatty\init([
    'dsn' => getenv('SPLATTY_DSN'),
    'environment' => 'production',
    'release' => getenv('SPLATTY_RELEASE'),
]);

try {
    doSomething();
} catch (\Throwable $e) {
    \Splatty\captureException($e);
}
```

A configure-callback form is available too, matching the Ruby client:

```php
\Splatty\init(function (\Splatty\Configuration $config): void {
    $config->dsn = getenv('SPLATTY_DSN');
    $config->environment = 'production';
});
```

`init()` never throws. A missing DSN or an unparseable URL logs one warning and
disables the SDK, so a misconfiguration cannot stop your app from booting.

Sends are **synchronous**, as they are in the Ruby client: a PHP request is
short-lived and there is no background worker to hand an event to. Queued log
entries are flushed automatically on shutdown.

## Configuration

| option | env | default | what it does |
|---|---|---|---|
| `url` | `SPLATTY_URL` | `https://splatty.app` | Server base URL; events go to `<url>/api/envelope` |
| `dsn` | `SPLATTY_DSN` | — (required) | Project key, sent as `Authorization: Bearer <dsn>` |
| `environment` | `SPLATTY_ENVIRONMENT`, `APP_ENV` | `development` | Stamped on every event and log entry |
| `release` | `SPLATTY_RELEASE` | — | Stamped on every event and log entry |
| `serverName` | — | `gethostname()` | Overrides the reported host |
| `enabled` | — | `true` | Set false to turn every capture into a no-op |
| `logs` | — | `true` | Install the batching log appender |
| `captureUnhandled` | — | `false` | Install the uncaught-exception, error and fatal handlers |
| `sendDefaultPii` | — | `false` | Send request headers verbatim instead of filtering them |
| `openTimeoutMs` | — | `5000` | Connection setup timeout |
| `readTimeoutMs` | — | `10000` | Whole-request timeout |
| `projectRoot` | — | detected | Shortens filenames and decides `in_app` |
| `logger` | — | `error_log()` | `callable(string $message)` for the SDK's own warnings |
| `beforeSend` | — | — | `callable(array $event): ?array` — mutate, or return null to drop |
| `logOptions` | — | — | Appender tuning, see [Logs](#logs) |
| `transport` | — | — | A `TransportInterface` to send through instead of curl |

### Filtering sensitive data

By default (`sendDefaultPii => false`) sensitive request headers — `Cookie`,
`Authorization`, CSRF tokens, API keys, session and password headers — are
replaced with `[Filtered]` before an event leaves the process. Matching is
case-insensitive.

Set `sendDefaultPii => true` only if you understand that cookies and auth
tokens will then be transmitted and stored.

## Capturing events

```php
$id = \Splatty\captureException($e);
$id = \Splatty\captureMessage('cache miss storm', ['level' => 'warn']);
```

Both return the event id, or `null` when nothing was sent — disabled SDK, an
already-reported throwable, dropped by `beforeSend`, or a transport failure.

Both take the same scope array:

```php
\Splatty\captureException($e, [
    'level' => 'warn',                            // default: error
    'transaction' => 'POST /checkout',
    'tags' => ['area' => 'billing'],              // indexed, string values
    'extra' => ['order_id' => 4711],              // free-form
    'contexts' => ['app' => ['build' => '1.2.3']],
    'request' => ['url' => $url, 'method' => 'POST', 'headers' => $headers],
]);
```

### Error chains

`getPrevious()` chains are reported root-cause first, and each link keeps its
own stack trace:

```php
throw new \RuntimeException('charging customer', 0, $declined);
// values[0] = the declined error
// values[1] = "charging customer"
```

### Reported once

A throwable is only reported once. A failure that surfaces through both the
PSR-15 middleware and the uncaught-exception handler produces a single event.
Identity is tracked in a `WeakMap`, so remembering a throwable never keeps it
alive.

## Uncaught exceptions, errors and fatals

```php
\Splatty\init(['dsn' => $dsn, 'captureUnhandled' => true]);
```

That installs three handlers:

- **`set_exception_handler`** — uncaught exceptions, reported at `fatal` with a
  `mechanism: uncaught_exception` tag. Any handler that was already registered
  still runs. If there was none, the SDK restores PHP's own behaviour, logging
  `Uncaught ...` and exiting 255 — installing a handler otherwise silently
  turns a crash into exit code 0.
- **`set_error_handler`** — PHP errors, converted to `ErrorException` and mapped
  onto levels. `@`-suppressed diagnostics and anything outside `error_reporting()`
  are ignored, and the handler returns false so your normal error reporting is
  untouched. Notices and deprecations are excluded by default.
- **`register_shutdown_function`** — fatals that no handler can catch, via
  `error_get_last()`.

For finer control:

```php
\Splatty\ErrorHandler::install([
    'exceptions' => true,
    'errors' => true,
    'fatals' => true,
    'errorLevels' => E_ALL & ~E_DEPRECATED & ~E_NOTICE,
    'exitOnUncaught' => true,
]);
```

## HTTP requests

Without a framework, build the scope from the superglobals:

```php
\Splatty\captureException($e, \Splatty\Http\Globals::requestScope());
```

That collects the URL (honouring `X-Forwarded-Proto`), the method, and all
request headers canonicalised the way Rack does — `HTTP_X_REQUEST_ID` becomes
`X-Request-Id` — plus a `request_id` tag from `X-Request-Id`,
`X-Correlation-Id` or `X-Amzn-Trace-Id`.

With a PSR-15 pipeline (requires `psr/http-server-middleware`):

```php
$pipeline->pipe(new \Splatty\Http\Middleware());
```

The middleware reports the exception and rethrows it, so your own error
rendering still runs. No transaction is set by default: only your router knows
the route template, and a raw path would blow up cardinality. Supply one:

```php
new \Splatty\Http\Middleware(
    fn (ServerRequestInterface $r): string => $r->getMethod() . ' ' . $r->getAttribute('route'),
);
```

## Background jobs

PHP has no single dominant queue, so the client stays queue-agnostic: map
whatever your queue gives you onto a job array.

```php
// Laravel
Queue::failing(function (JobFailed $event): void {
    \Splatty\captureJobException($event->exception, [
        'backend' => 'laravel',
        'jobClass' => $event->job->resolveName(),
        'queue' => $event->job->getQueue(),
        'jobId' => $event->job->getJobId(),
        'attempts' => $event->job->attempts(),
        'args' => $event->job->payload()['data'] ?? null,
    ]);
});
```

```php
// Symfony Messenger — subscribe to WorkerMessageFailedEvent
if (!$event->willRetry()) {   // stay quiet while the worker still intends to retry
    \Splatty\captureJobException($event->getThrowable(), [
        'backend' => 'symfony-messenger',
        'jobClass' => $event->getEnvelope()->getMessage()::class,
        'queue' => $event->getReceiverName(),
    ]);
}
```

Events are tagged with `job_backend`, `job_class` and `job_queue`, get a
`transaction` of the job class, and carry `job_id`, `job_attempts` and
`job_args` as extra data. Arguments are JSON-encoded and truncated at 2048
bytes with a `...(truncated)` suffix.

## Logs

`init()` installs a batching appender unless you pass `'logs' => false`. It
buffers entries and ships them as `log` envelope items when the queue reaches
`batchSize`, when `flushInterval` has elapsed since the last flush, or on
shutdown — PHP tears a request down without warning, so the SDK registers a
shutdown flush for you.

Entries about Splatty's own intake paths are dropped, so a dogfooded app can't
feed itself: every shipped batch would otherwise become a new request log,
which becomes another batch. The dropped paths are `/api/envelope`,
`/api/logs` and `/api/metrics`, each also matching an optional numeric project
id (`/api/42/logs`) and a trailing slash.

### Monolog

```php
$logger->pushHandler(new \Splatty\Logs\MonologHandler());
```

Requires `monolog/monolog ^3`. Monolog levels are folded onto the five the
server accepts, and `context` plus `extra` become fields alongside the channel.

### PSR-3

```php
$logger = new \Splatty\Logs\PsrLogger();
```

Requires `psr/log ^3`. Useful where a framework wants a `LoggerInterface` and
you are not running Monolog.

### Anything else

```php
\Splatty\captureLog([
    'level' => 'info',
    'message' => 'checkout completed',
    'time' => new DateTimeImmutable(),
    'fields' => ['request_id' => $id, 'path' => '/checkout', 'status' => 200, 'duration_ms' => 42],
]);
```

Returns `false` when the entry was dropped.

### Entry shape

`request_id`, `method`, `path`, `status`, `duration_ms` (or `duration`),
`controller` and `action` are lifted out of `fields` into top-level columns.
What is left is stringified into a string map. A `sql` field is inlined into
the message: `Load — SELECT 1`.

### Tuning

```php
\Splatty\init([
    'dsn' => $dsn,
    'logOptions' => [
        'level' => 'info',       // drop anything below this
        'batchSize' => 100,      // flush once this many are queued
        'flushInterval' => 15.0, // seconds; only matters in long-running workers
        'queueLimit' => 5000,    // past this the oldest entry is dropped
        'host' => 'web-1',
    ],
]);
```

## Shutting down

```php
\Splatty\flush();  // ship queued logs, keep going
\Splatty\close();  // flush, release the connection, drop the client
```

A normal request needs neither: the shutdown flush handles it. Long-running
workers (RoadRunner, Swoole, a queue daemon) should call `flush()` between
jobs.

## API reference

**Lifecycle** — `\Splatty\init()`, `client()`, `configuration()`, `enabled()`,
`flush()`, `close()`.

**Capture** — `captureException()`, `captureMessage()`, `captureLog()`,
`captureJobException()`.

**Classes** — `Configuration`, `Client`, `Transport`, `TransportInterface`,
`Scrubber`, `EventBuilder`, `Level`, `Jobs`, `ErrorHandler`, `Hub`, `Version`.

**Integrations** — `Http\Globals`, `Http\Middleware`, `Logs\LogAppender`,
`Logs\LogOptions`, `Logs\MonologHandler`, `Logs\PsrLogger`.

**Constants** — `Version::SDK_NAME`, `Version::VERSION`,
`Configuration::DEFAULT_URL`, `Scrubber::FILTERED`,
`Scrubber::SENSITIVE_HEADER_PATTERN`, `LogAppender::INTAKE_PATH_PATTERN`,
`Jobs::MAX_ARGS_LENGTH`, `ErrorHandler::DEFAULT_ERROR_LEVELS`.

## Wire protocol

Everything is POSTed gzipped to `<url>/api/envelope` over a reused curl handle,
with `Content-Type: application/x-splatty-envelope` and
`Authorization: Bearer <dsn>`. The body is three newline-separated lines: an
envelope header, an item header, and the JSON payload.

```
{"event_id":"…","sent_at":"…","dsn":"…","sdk":{"name":"splatty.php","version":"0.1.0"}}
{"type":"event","content_type":"application/json","length":1234}
{"event_id":"…","timestamp":"…","platform":"php","level":"error","exception":{…}}
```

Log batches use the same shape. Their envelope header carries no `event_id`,
and the item header is `{"type":"log","item_count":N,"content_type":
"application/vnd.splatty.items.log+json","length":…}` over a
`{"host":…,"items":[…]}` payload.

Transport failures never throw: they are reported through `logger` and the send
returns false.

## Development

```sh
composer install
composer test
```

## License

MIT.
