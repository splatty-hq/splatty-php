<?php

declare(strict_types=1);

namespace Splatty\Logs;

use Splatty\Level;

/** Tuning for the batching log appender. */
final class LogOptions
{
    public const DEFAULT_BATCH_SIZE = 100;
    public const DEFAULT_FLUSH_INTERVAL = 15.0;
    public const DEFAULT_QUEUE_LIMIT = 5000;

    public function __construct(
        /** Minimum level to ship; null ships everything. */
        public ?Level $level = null,
        /** Flush once this many entries are queued. */
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
        /**
         * Seconds between time-based flushes. Only meaningful in long-running
         * workers: a normal request flushes on shutdown regardless.
         */
        public float $flushInterval = self::DEFAULT_FLUSH_INTERVAL,
        /** Past this many queued entries the oldest is dropped. */
        public int $queueLimit = self::DEFAULT_QUEUE_LIMIT,
        /** Host reported with each batch. Defaults to the machine hostname. */
        public ?string $host = null,
    ) {
    }

    /** @param array<string, mixed> $options */
    public static function fromArray(array $options): self
    {
        return new self(
            level: isset($options['level']) ? Level::fromName($options['level']) : null,
            batchSize: (int) ($options['batchSize'] ?? self::DEFAULT_BATCH_SIZE),
            flushInterval: (float) ($options['flushInterval'] ?? self::DEFAULT_FLUSH_INTERVAL),
            queueLimit: (int) ($options['queueLimit'] ?? self::DEFAULT_QUEUE_LIMIT),
            host: isset($options['host']) ? (string) $options['host'] : null,
        );
    }
}
