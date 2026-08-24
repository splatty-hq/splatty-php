<?php

declare(strict_types=1);

namespace Splatty\Logs;

use Psr\Log\AbstractLogger;
use Splatty\Hub;
use Stringable;

/**
 * A PSR-3 logger that ships everything to Splatty. Useful where a framework
 * wants a LoggerInterface and you are not running Monolog.
 *
 * Requires psr/log ^3.
 */
final class PsrLogger extends AbstractLogger
{
    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        Hub::getClient()?->captureLog([
            'level' => is_string($level) ? $level : (string) $level,
            'message' => (string) $message,
            'fields' => $context,
        ]);
    }
}
