<?php

declare(strict_types=1);

namespace Splatty;

/**
 * The five levels the server accepts. Arbitrary logger level names are folded
 * onto these by {@see Level::fromName()}.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Fatal = 'fatal';

    public static function fromName(Level|string|null $level, Level $default = self::Info): Level
    {
        if ($level instanceof Level) {
            return $level;
        }
        if ($level === null || $level === '') {
            return $default;
        }

        return match (strtolower(trim($level))) {
            'trace', 'debug', 'verbose' => self::Debug,
            'info', 'notice', 'log', 'http' => self::Info,
            'warn', 'warning' => self::Warn,
            'error', 'err' => self::Error,
            'fatal', 'crit', 'critical', 'alert', 'emerg', 'emergency', 'panic' => self::Fatal,
            default => $default,
        };
    }

    /** Ordering used by the appender's minimum-level filter. */
    public function weight(): int
    {
        return match ($this) {
            self::Debug => 10,
            self::Info => 20,
            self::Warn => 30,
            self::Error => 40,
            self::Fatal => 50,
        };
    }
}
