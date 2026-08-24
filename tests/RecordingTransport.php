<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Splatty\TransportInterface;

/** Captures what would have gone over the wire. */
final class RecordingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array{host: string, logs: list<array<string, mixed>>}> */
    public array $batches = [];

    public bool $closed = false;

    public bool $failSends = false;

    public function sendEnvelope(array $event): bool
    {
        if ($this->failSends) {
            return false;
        }
        $this->events[] = $event;

        return true;
    }

    public function sendLogs(string $host, array $logs): bool
    {
        if ($this->failSends) {
            return false;
        }
        $this->batches[] = ['host' => $host, 'logs' => $logs];

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<array<string, mixed>> */
    public function logEntries(): array
    {
        return array_merge(...array_map(static fn (array $b): array => $b['logs'], $this->batches)) ?: [];
    }
}
