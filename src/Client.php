<?php

declare(strict_types=1);

namespace Splatty;

use Splatty\Logs\LogAppender;
use Throwable;
use WeakMap;

final class Client
{
    private TransportInterface $transport;

    private Scrubber $scrubber;

    private EventBuilder $builder;

    private ?LogAppender $appender = null;

    /**
     * Throwables already reported. A WeakMap keys on object identity without
     * keeping the exception alive, the same role the Ruby client's instance
     * variable and the JS client's WeakSet play.
     *
     * @var WeakMap<Throwable, true>
     */
    private WeakMap $captured;

    public function __construct(private Configuration $configuration)
    {
        $configuration->validate();

        $this->transport = $configuration->transport ?? new Transport($configuration);
        $this->scrubber = new Scrubber($configuration);
        $this->builder = new EventBuilder($configuration);
        $this->captured = new WeakMap();

        if ($configuration->isEnabled() && $configuration->logs) {
            $this->appender = new LogAppender($this, $configuration->logOptions);
        }
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function getAppender(): ?LogAppender
    {
        return $this->appender;
    }

    public function isEnabled(): bool
    {
        return $this->configuration->isEnabled();
    }

    /**
     * Reports a throwable and returns the event id, or null when nothing was
     * sent — disabled client, already-reported throwable, dropped by
     * beforeSend, or a transport failure.
     *
     * @param array<string, mixed> $scope
     */
    public function captureException(Throwable $throwable, array $scope = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (isset($this->captured[$throwable])) {
            return null;
        }
        $this->captured[$throwable] = true;

        return $this->dispatch($this->builder->fromThrowable($throwable, $scope));
    }

    /**
     * @param array<string, mixed> $scope Accepts a 'level' key; defaults to info.
     */
    public function captureMessage(string $message, array $scope = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return $this->dispatch($this->builder->fromMessage($message, $scope));
    }

    /** @param array<string, mixed> $record */
    public function captureLog(array $record): bool
    {
        return $this->appender?->log($record) ?? false;
    }

    public function flush(): void
    {
        $this->appender?->flush();
    }

    public function close(): void
    {
        $this->appender?->close();
        $this->transport->close();
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatch(array $event): ?string
    {
        $event = $this->scrubber->scrub($event);

        $hook = $this->configuration->beforeSend;
        if ($hook !== null) {
            $filtered = ($hook)($event);
            if (!is_array($filtered)) {
                return null;
            }
            $event = $filtered;
        }

        if (!$this->transport->sendEnvelope($event)) {
            return null;
        }

        $id = $event['event_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
