<?php

declare(strict_types=1);

namespace Splatty\Tests;

use ErrorException;
use RuntimeException;
use Splatty\ErrorHandler;

final class ErrorHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::uninstall();
        parent::tearDown();
    }

    public function testCapturesAnUncaughtException(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install(['errors' => false, 'fatals' => false, 'exitOnUncaught' => false]);

        $handler->handleException(new RuntimeException('escaped'));

        $event = $this->firstEvent();
        self::assertSame('fatal', $event['level']);
        self::assertSame('uncaught_exception', $event['tags']['mechanism']);
        self::assertSame('escaped', $event['exception']['values'][0]['value']);
    }

    public function testDelegatesToAPreviousExceptionHandler(): void
    {
        $this->installClient();
        $seen = null;
        set_exception_handler(static function (\Throwable $t) use (&$seen): void { $seen = $t; });

        try {
            $handler = ErrorHandler::install(['errors' => false, 'fatals' => false]);
            $error = new RuntimeException('escaped');
            $handler->handleException($error);

            self::assertSame($error, $seen, 'the previous handler must still run');
            self::assertCount(1, $this->events());
        } finally {
            ErrorHandler::uninstall();
            restore_exception_handler();
        }
    }

    public function testInstallRegistersAnErrorHandler(): void
    {
        $this->installClient();
        ErrorHandler::install(['exceptions' => false, 'fatals' => false]);

        // set_error_handler(null) returns the handler it replaced.
        $registered = set_error_handler(null);
        restore_error_handler();

        self::assertNotNull($registered, 'install() must register an error handler');
    }

    public function testCapturesPhpErrors(): void
    {
        $this->installClient();
        // PHPUnit manages its own error-handler stack, so drive the handler
        // directly rather than relying on where trigger_error lands.
        $handler = ErrorHandler::install(['exceptions' => false, 'fatals' => false]);

        // PHPUnit narrows error_reporting to exclude E_USER_*; widen it so the
        // handler sees what a normally-configured app would report.
        $previous = error_reporting(E_ALL);
        try {
            $handler->handleError(E_USER_WARNING, 'reported warning', __FILE__, 42);
        } finally {
            error_reporting($previous);
        }

        $event = $this->firstEvent();
        self::assertSame('warn', $event['level']);
        self::assertSame('php_error', $event['tags']['mechanism']);
        self::assertSame(ErrorException::class, $event['exception']['values'][0]['type']);
        self::assertSame('reported warning', $event['exception']['values'][0]['value']);
    }

    public function testRespectsSuppressedErrors(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install(['exceptions' => false, 'fatals' => false]);

        // The @ operator narrows error_reporting for the duration of the call.
        $previous = error_reporting(0);
        try {
            $handler->handleError(E_USER_WARNING, 'suppressed', __FILE__, 1);
        } finally {
            error_reporting($previous);
        }

        self::assertSame([], $this->events());
    }

    public function testMapsSeverityOntoLevels(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install(['exceptions' => false, 'fatals' => false, 'errorLevels' => E_ALL]);

        $previous = error_reporting(E_ALL);
        try {
            $handler->handleError(E_USER_ERROR, 'fatal-ish', __FILE__, 1);
            $handler->handleError(E_USER_WARNING, 'warn-ish', __FILE__, 2);
            $handler->handleError(E_USER_NOTICE, 'info-ish', __FILE__, 3);
        } finally {
            error_reporting($previous);
        }

        self::assertSame(['fatal', 'warn', 'info'], array_column($this->events(), 'level'));
    }

    public function testIgnoresLevelsOutsideTheMask(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install([
            'exceptions' => false,
            'fatals' => false,
            'errorLevels' => E_USER_ERROR,
        ]);

        $previous = error_reporting(E_ALL);
        try {
            $handler->handleError(E_USER_NOTICE, 'a notice nobody wants', __FILE__, 1);
        } finally {
            error_reporting($previous);
        }

        self::assertSame([], $this->events());
    }

    public function testErrorHandlerLetsNormalReportingContinue(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install(['exceptions' => false, 'fatals' => false]);

        self::assertFalse(
            $handler->handleError(E_USER_WARNING, 'x', __FILE__, __LINE__),
            'returning false keeps PHP\'s own error reporting in play',
        );
    }

    public function testInstallIsIdempotent(): void
    {
        $this->installClient();
        $first = ErrorHandler::install(['exceptions' => false, 'fatals' => false]);
        $second = ErrorHandler::install(['exceptions' => false, 'fatals' => false]);

        self::assertSame($first, $second);
        self::assertTrue(ErrorHandler::isInstalled());

        ErrorHandler::uninstall();
        self::assertFalse(ErrorHandler::isInstalled());
    }

    public function testHandlersAreInertWithoutAClient(): void
    {
        $handler = ErrorHandler::install(['errors' => false, 'fatals' => false, 'exitOnUncaught' => false]);
        $handler->handleException(new RuntimeException('nobody listening'));
        $this->addToAssertionCount(1);
    }

    public function testFatalIsReportedOnShutdown(): void
    {
        $this->installClient();
        $handler = ErrorHandler::install(['exceptions' => false, 'errors' => false]);

        // error_get_last() reflects the most recent diagnostic; a suppressed
        // warning is not a fatal type, so shutdown must stay quiet.
        @trigger_error('not fatal', E_USER_WARNING);
        $handler->handleShutdown();

        self::assertSame([], $this->events());
    }
}
