<?php

declare(strict_types=1);

namespace Splatty;

use ErrorException;
use Throwable;

/**
 * Reports failures that escape every other integration: uncaught exceptions,
 * PHP errors and fatals. This is the PHP counterpart of the JS client's
 * process handlers.
 */
final class ErrorHandler
{
    /** Warnings and errors, but not the notice/deprecation noise. */
    public const DEFAULT_ERROR_LEVELS = E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED & ~E_USER_NOTICE;

    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR];

    private static ?self $installed = null;

    private static bool $shutdownRegistered = false;

    /** @var callable|null */
    private $previousExceptionHandler = null;

    private bool $handlingFatals = false;

    private function __construct(
        private bool $captureExceptions,
        private bool $captureErrors,
        private bool $captureFatals,
        private int $errorLevels,
        private bool $exitOnUncaught,
    ) {
    }

    /**
     * Installs the handlers. Options: exceptions, errors, fatals (all true),
     * errorLevels (DEFAULT_ERROR_LEVELS), exitOnUncaught (true).
     *
     * @param array<string, mixed> $options
     */
    public static function install(array $options = []): self
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        $handler = new self(
            captureExceptions: (bool) ($options['exceptions'] ?? true),
            captureErrors: (bool) ($options['errors'] ?? true),
            captureFatals: (bool) ($options['fatals'] ?? true),
            errorLevels: (int) ($options['errorLevels'] ?? self::DEFAULT_ERROR_LEVELS),
            exitOnUncaught: (bool) ($options['exitOnUncaught'] ?? true),
        );
        self::$installed = $handler;

        if ($handler->captureExceptions) {
            $handler->previousExceptionHandler = set_exception_handler(
                static fn (Throwable $throwable) => $handler->handleException($throwable),
            );
        }
        if ($handler->captureErrors) {
            set_error_handler(
                static fn (int $severity, string $message, string $file, int $line) => $handler->handleError($severity, $message, $file, $line),
            );
        }
        if ($handler->captureFatals) {
            $handler->handlingFatals = true;
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function(static function (): void {
                    self::$installed?->handleShutdown();
                });
            }
        }

        return $handler;
    }

    public static function uninstall(): void
    {
        $handler = self::$installed;
        if ($handler === null) {
            return;
        }
        if ($handler->captureExceptions) {
            restore_exception_handler();
        }
        if ($handler->captureErrors) {
            restore_error_handler();
        }
        // The shutdown callback cannot be unregistered; clearing the instance
        // makes it a no-op instead.
        $handler->handlingFatals = false;
        self::$installed = null;
    }

    public static function isInstalled(): bool
    {
        return self::$installed !== null;
    }

    public function handleException(Throwable $throwable): void
    {
        Hub::getClient()?->captureException($throwable, [
            'level' => Level::Fatal->value,
            'tags' => ['mechanism' => 'uncaught_exception'],
        ]);
        Hub::getClient()?->flush();

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($throwable);

            return;
        }

        if ($this->exitOnUncaught) {
            // Installing a handler suppresses PHP's own "Uncaught ..." fatal and
            // its 255 exit code; restore both so behaviour is unchanged.
            error_log('PHP Fatal error:  Uncaught ' . $throwable);
            exit(255);
        }
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Respect error_reporting(), which the @ operator narrows.
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        if (($severity & $this->errorLevels) !== 0) {
            Hub::getClient()?->captureException(
                new ErrorException($message, 0, $severity, $file, $line),
                [
                    'level' => self::severityLevel($severity)->value,
                    'tags' => ['mechanism' => 'php_error'],
                ],
            );
        }

        // Let PHP's normal reporting continue.
        return false;
    }

    public function handleShutdown(): void
    {
        if (!$this->handlingFatals) {
            return;
        }
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }

        Hub::getClient()?->captureException(
            new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
            [
                'level' => Level::Fatal->value,
                'tags' => ['mechanism' => 'fatal_error'],
            ],
        );
        Hub::getClient()?->flush();
    }

    private static function severityLevel(int $severity): Level
    {
        return match ($severity) {
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => Level::Fatal,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => Level::Warn,
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => Level::Info,
            default => Level::Error,
        };
    }
}
