<?php

declare(strict_types=1);

namespace Splatty;

/**
 * Strips sensitive request headers from an event before it leaves the process.
 * Disabled wholesale by Configuration::$sendDefaultPii.
 */
final class Scrubber
{
    public const FILTERED = '[Filtered]';

    public const SENSITIVE_HEADER_PATTERN =
        '/authoriz|cookie|csrf|xsrf|secret|token|password|api[-_]?key|session/i';

    public function __construct(private Configuration $configuration)
    {
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    public function scrub(array $event): array
    {
        if ($this->configuration->sendDefaultPii) {
            return $event;
        }
        if (!isset($event['request']) || !is_array($event['request'])) {
            return $event;
        }
        if (!isset($event['request']['headers']) || !is_array($event['request']['headers'])) {
            return $event;
        }

        foreach ($event['request']['headers'] as $name => $_value) {
            if (preg_match(self::SENSITIVE_HEADER_PATTERN, (string) $name) === 1) {
                $event['request']['headers'][$name] = self::FILTERED;
            }
        }

        return $event;
    }
}
