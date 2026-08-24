<?php

declare(strict_types=1);

namespace Splatty;

/**
 * Ships envelopes to the server. Swap the implementation to test without a
 * network, or to route through your own HTTP stack.
 */
interface TransportInterface
{
    /**
     * @param array<string, mixed> $event
     */
    public function sendEnvelope(array $event): bool;

    /**
     * @param list<array<string, mixed>> $logs
     */
    public function sendLogs(string $host, array $logs): bool;

    public function close(): void;
}
