<?php

declare(strict_types=1);

namespace Splatty;

use CurlHandle;

/**
 * Posts gzipped envelopes over a reused curl handle, so a long-running worker
 * keeps the connection alive between sends.
 */
final class Transport implements TransportInterface
{
    private ?CurlHandle $handle = null;

    public function __construct(private Configuration $configuration)
    {
    }

    /** @param array<string, mixed> $event */
    public function sendEnvelope(array $event): bool
    {
        return $this->post($this->serializeEnvelope($event));
    }

    /** @param list<array<string, mixed>> $logs */
    public function sendLogs(string $host, array $logs): bool
    {
        if ($logs === []) {
            return true;
        }

        return $this->post($this->serializeLogEnvelope($host, $logs));
    }

    public function close(): void
    {
        // curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5:
        // CurlHandle is an object, so releasing the reference closes it.
        $this->handle = null;
    }

    /** @param array<string, mixed> $event */
    public function serializeEnvelope(array $event): string
    {
        $payload = $this->encode($event);

        return $this->join(
            [
                'event_id' => $event['event_id'] ?? null,
                'sent_at' => $this->now(),
                'dsn' => $this->configuration->dsn,
                'sdk' => ['name' => Version::SDK_NAME, 'version' => Version::VERSION],
            ],
            [
                'type' => 'event',
                'content_type' => 'application/json',
                'length' => strlen($payload),
            ],
            $payload,
        );
    }

    /** @param list<array<string, mixed>> $logs */
    public function serializeLogEnvelope(string $host, array $logs): string
    {
        $payload = $this->encode(['host' => $host, 'items' => $logs]);

        return $this->join(
            [
                'sent_at' => $this->now(),
                'dsn' => $this->configuration->dsn,
                'sdk' => ['name' => Version::SDK_NAME, 'version' => Version::VERSION],
            ],
            [
                'type' => 'log',
                'item_count' => count($logs),
                'content_type' => 'application/vnd.splatty.items.log+json',
                'length' => strlen($payload),
            ],
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $itemHeader
     */
    private function join(array $header, array $itemHeader, string $payload): string
    {
        return $this->encode($header) . "\n" . $this->encode($itemHeader) . "\n" . $payload;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: '{}';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function post(string $body): bool
    {
        $compressed = gzencode($body);
        if ($compressed === false) {
            $this->configuration->warn('[splatty] could not gzip the envelope');

            return false;
        }

        $handle = $this->handle ??= curl_init();
        if ($handle === false) {
            $this->configuration->warn('[splatty] could not initialise curl');
            $this->handle = null;

            return false;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $this->configuration->envelopeUrl(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $compressed,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->configuration->openTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->configuration->readTimeoutMs,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-splatty-envelope',
                'Authorization: Bearer ' . $this->configuration->dsnKey(),
                'User-Agent: ' . Version::SDK_NAME . '/' . Version::VERSION,
                'Content-Encoding: gzip',
                'Expect:',
            ],
        ]);

        $response = curl_exec($handle);
        if ($response === false) {
            $this->configuration->warn(sprintf(
                '[splatty] transport failure %s: %s',
                $this->configuration->envelopeUrl(),
                curl_error($handle),
            ));
            // The connection may be half-closed by a keep-alive timeout on the
            // server side; drop the handle so the next send starts fresh.
            $this->close();

            return false;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status >= 300) {
            $this->configuration->warn(sprintf(
                '[splatty] unexpected status %d from %s',
                $status,
                $this->configuration->envelopeUrl(),
            ));

            return false;
        }

        return true;
    }
}
