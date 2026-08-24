<?php

declare(strict_types=1);

namespace Splatty;

use Throwable;

/**
 * Configures the SDK and installs the process-wide client, replacing and
 * closing any previous one.
 *
 * Accepts an options array or a callable that receives a fresh Configuration:
 *
 *     \Splatty\init(['dsn' => getenv('SPLATTY_DSN')]);
 *     \Splatty\init(fn (Configuration $c) => $c->dsn = getenv('SPLATTY_DSN'));
 *
 * @param array<string, mixed>|callable $options
 */
function init(array|callable $options = []): Client
{
    if (is_callable($options)) {
        $configuration = new Configuration();
        $options($configuration);
    } else {
        $configuration = new Configuration($options);
    }

    Hub::getClient()?->close();

    $client = new Client($configuration);
    Hub::setClient($client);

    if ($configuration->isEnabled()) {
        Hub::registerShutdownFlush();
        if ($configuration->captureUnhandled) {
            ErrorHandler::install();
        }
    }

    return $client;
}

function client(): ?Client
{
    return Hub::getClient();
}

function configuration(): ?Configuration
{
    return Hub::getClient()?->getConfiguration();
}

function enabled(): bool
{
    return Hub::isEnabled();
}

/**
 * Reports a throwable. Returns the event id, or null when nothing was sent.
 * A throwable is only reported once.
 *
 * @param array<string, mixed> $scope
 */
function captureException(Throwable $throwable, array $scope = []): ?string
{
    return Hub::getClient()?->captureException($throwable, $scope);
}

/**
 * @param array<string, mixed> $scope Accepts a 'level' key; defaults to info.
 */
function captureMessage(string $message, array $scope = []): ?string
{
    return Hub::getClient()?->captureMessage($message, $scope);
}

/**
 * Reports a background job failure.
 *
 * @param array<string, mixed> $job   backend, jobClass, queue, jobId, attempts, args, extra
 * @param array<string, mixed> $scope Merged over the job scope.
 */
function captureJobException(Throwable $throwable, array $job, array $scope = []): ?string
{
    return Hub::getClient()?->captureException($throwable, array_replace_recursive(Jobs::scope($job), $scope));
}

/** @param array<string, mixed> $record level, message, fields, time */
function captureLog(array $record): bool
{
    return Hub::getClient()?->captureLog($record) ?? false;
}

/** Ships anything the log appender still has queued. */
function flush(): void
{
    Hub::getClient()?->flush();
}

/** Flushes, releases the connection and drops the client. */
function close(): void
{
    Hub::getClient()?->close();
    Hub::setClient(null);
}
