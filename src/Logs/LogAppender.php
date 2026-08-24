<?php

declare(strict_types=1);

namespace Splatty\Logs;

use Splatty\Client;
use Splatty\Level;
use Stringable;

/**
 * Buffers log entries and ships them as log envelope items. PHP has no
 * background thread, so a batch leaves on one of three triggers: the queue
 * reaching batchSize, flushInterval elapsing between calls (which only matters
 * in long-running workers), or an explicit flush — including the one the SDK
 * registers on shutdown.
 */
final class LogAppender
{
    /**
     * Drop entries describing requests to Splatty's own intake endpoints.
     * Without this a dogfooded app feeds itself: every shipped batch becomes a
     * new request log, which becomes another batch.
     */
    public const INTAKE_PATH_PATTERN = '#^/api/(?:\d+/)?(?:logs|metrics|envelope)/?$#';

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private float $lastFlush;

    private string $host;

    private bool $closed = false;

    public function __construct(private Client $client, private LogOptions $options)
    {
        $this->host = $options->host ?? (gethostname() ?: 'unknown');
        $this->lastFlush = microtime(true);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function size(): int
    {
        return count($this->queue);
    }

    /**
     * Enqueue a record. Returns false when the entry was dropped.
     *
     * @param array<string, mixed> $record
     */
    public function log(array $record): bool
    {
        if ($this->closed || !$this->client->isEnabled()) {
            return false;
        }
        if ($this->isIntakeRequest($record)) {
            return false;
        }

        $entry = $this->buildEntry($record);
        if (!$this->passesLevel($entry['level'])) {
            return false;
        }

        if (count($this->queue) >= $this->options->queueLimit) {
            array_shift($this->queue);
        }
        $this->queue[] = $entry;

        if (count($this->queue) >= $this->options->batchSize) {
            $this->flush();
        } elseif (microtime(true) - $this->lastFlush >= $this->options->flushInterval) {
            $this->flush();
        }

        return true;
    }

    /** Ship everything currently queued. */
    public function flush(): void
    {
        $this->lastFlush = microtime(true);
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, $this->options->batchSize * 4);
            if (!$this->client->getTransport()->sendLogs($this->host, $batch)) {
                // The batch is gone either way; retrying in-process would only
                // grow the queue behind a server that is already unhappy.
                return;
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->flush();
        $this->closed = true;
    }

    /** @param array<string, mixed> $record */
    private function isIntakeRequest(array $record): bool
    {
        $path = $record['fields']['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return false;
        }

        return preg_match(self::INTAKE_PATH_PATTERN, $path) === 1;
    }

    private function passesLevel(string $level): bool
    {
        if ($this->options->level === null) {
            return true;
        }

        return Level::fromName($level)->weight() >= $this->options->level->weight();
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function buildEntry(array $record): array
    {
        $configuration = $this->client->getConfiguration();
        $fields = $this->stringifyFields($record['fields'] ?? []);
        $message = (string) ($record['message'] ?? '');

        return [
            'timestamp' => $this->timestamp($record['time'] ?? null),
            'level' => Level::fromName($record['level'] ?? null)->value,
            'message' => $this->buildMessage($message, $fields),
            'request_id' => $this->takeString($fields, 'request_id'),
            'method' => $this->takeString($fields, 'method'),
            'path' => $this->takeString($fields, 'path'),
            'status' => $this->takeInt($fields, 'status'),
            'duration_ms' => $this->takeFloat($fields, 'duration_ms', 'duration'),
            'controller' => $this->takeString($fields, 'controller'),
            'action' => $this->takeString($fields, 'action'),
            'environment' => $configuration->environment,
            'release' => $configuration->release ?? '',
            'host' => $this->host,
            'fields' => $fields,
        ];
    }

    private function timestamp(mixed $time): int
    {
        if ($time instanceof \DateTimeInterface) {
            return (int) round((float) $time->format('U.u') * 1000);
        }
        if (is_int($time) || is_float($time)) {
            // Values already in milliseconds are passed through; seconds are scaled.
            return $time > 1_000_000_000_000 ? (int) $time : (int) round((float) $time * 1000);
        }

        return (int) round(microtime(true) * 1000);
    }

    /** @param array<string, string> $fields */
    private function buildMessage(string $message, array $fields): string
    {
        $sql = trim($fields['sql'] ?? '');
        if ($sql === '') {
            return $message;
        }

        return $message === '' ? $sql : $message . ' — ' . $sql;
    }

    /**
     * @param mixed $source
     *
     * @return array<string, string>
     */
    private function stringifyFields(mixed $source): array
    {
        if (!is_array($source)) {
            return [];
        }

        $out = [];
        foreach ($source as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = $this->stringify($value);
        }

        return $out;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? get_debug_type($value) : $encoded;
    }

    /** @param array<string, string> $fields */
    private function takeString(array &$fields, string $key): string
    {
        $value = $fields[$key] ?? '';
        unset($fields[$key]);

        return $value;
    }

    /** @param array<string, string> $fields */
    private function takeInt(array &$fields, string $key): int
    {
        $value = $fields[$key] ?? null;
        unset($fields[$key]);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, string> $fields */
    private function takeFloat(array &$fields, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            unset($fields[$key]);

            return is_numeric($value) ? (float) $value : null;
        }

        return null;
    }
}
