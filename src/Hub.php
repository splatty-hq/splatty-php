<?php

declare(strict_types=1);

namespace Splatty;

/**
 * Holds the process-wide client. Kept separate from the namespaced functions
 * so integrations can reach the client without pulling in the function file.
 */
final class Hub
{
    private static ?Client $client = null;

    private static bool $shutdownRegistered = false;

    public static function getClient(): ?Client
    {
        return self::$client;
    }

    public static function setClient(?Client $client): void
    {
        self::$client = $client;
    }

    public static function isEnabled(): bool
    {
        return self::$client !== null && self::$client->isEnabled();
    }

    /**
     * PHP tears a request down without warning, so queued logs have to leave on
     * shutdown. The callback resolves the client lazily and cannot be
     * unregistered, hence the once-per-process guard.
     */
    public static function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::$client?->flush();
        });
    }
}
