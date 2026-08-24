<?php

declare(strict_types=1);

namespace Splatty\Tests;

use RuntimeException;
use Splatty\Jobs;

use function Splatty\captureJobException;

final class JobsTest extends TestCase
{
    public function testEncodeArgs(): void
    {
        self::assertNull(Jobs::encodeArgs(null));
        self::assertSame('[1,"two"]', Jobs::encodeArgs([1, 'two']));
        self::assertSame('{"to":"x@example.com"}', Jobs::encodeArgs(['to' => 'x@example.com']));
    }

    public function testEncodeArgsTruncates(): void
    {
        $encoded = Jobs::encodeArgs([str_repeat('x', 4000)]);

        self::assertSame(Jobs::MAX_ARGS_LENGTH + strlen('...(truncated)'), strlen((string) $encoded));
        self::assertStringEndsWith('...(truncated)', (string) $encoded);
    }

    public function testEncodeArgsReturnsNullForUnserializable(): void
    {
        self::assertNull(Jobs::encodeArgs(fopen('php://memory', 'r')));
        self::assertNull(Jobs::encodeArgs(NAN));
    }

    public function testScope(): void
    {
        $scope = Jobs::scope([
            'backend' => 'laravel',
            'jobClass' => 'App\\Jobs\\SendInvoice',
            'queue' => 'billing',
            'jobId' => 'j-1',
            'attempts' => 3,
            'args' => ['invoice' => 42],
            'extra' => ['connection' => 'redis'],
        ]);

        self::assertSame('laravel', $scope['tags']['job_backend']);
        self::assertSame('App\\Jobs\\SendInvoice', $scope['tags']['job_class']);
        self::assertSame('billing', $scope['tags']['job_queue']);
        self::assertSame('App\\Jobs\\SendInvoice', $scope['transaction']);
        self::assertSame('j-1', $scope['extra']['job_id']);
        self::assertSame(3, $scope['extra']['job_attempts']);
        self::assertSame('{"invoice":42}', $scope['extra']['job_args']);
        self::assertSame('redis', $scope['extra']['connection']);
    }

    public function testScopeWithoutJobDetails(): void
    {
        $scope = Jobs::scope(['backend' => 'laravel']);

        self::assertSame(['job_backend' => 'laravel'], $scope['tags']);
        self::assertArrayNotHasKey('transaction', $scope);
        self::assertSame([], $scope['extra']);
    }

    public function testCaptureJobException(): void
    {
        $this->installClient();
        captureJobException(new RuntimeException('smtp down'), [
            'backend' => 'symfony-messenger',
            'jobClass' => 'App\\Message\\SendEmail',
            'queue' => 'async',
            'jobId' => 'm-9',
            'attempts' => 2,
            'args' => [1, 'two'],
        ]);

        $event = $this->firstEvent();
        self::assertSame('smtp down', $event['exception']['values'][0]['value']);
        self::assertSame('symfony-messenger', $event['tags']['job_backend']);
        self::assertSame('App\\Message\\SendEmail', $event['transaction']);
        self::assertSame('[1,"two"]', $event['extra']['job_args']);
        self::assertSame(2, $event['extra']['job_attempts']);
    }

    public function testExtraScopeIsMergedOverTheJobScope(): void
    {
        $this->installClient();
        captureJobException(
            new RuntimeException('boom'),
            ['backend' => 'laravel', 'jobClass' => 'App\\Jobs\\X'],
            ['transaction' => 'custom', 'tags' => ['shard' => 'eu-1']],
        );

        $event = $this->firstEvent();
        self::assertSame('custom', $event['transaction']);
        self::assertSame('eu-1', $event['tags']['shard']);
        self::assertSame('laravel', $event['tags']['job_backend']);
    }

    public function testJobFailureIsReportedOnce(): void
    {
        $this->installClient();
        $error = new RuntimeException('boom');
        captureJobException($error, ['backend' => 'laravel']);
        captureJobException($error, ['backend' => 'laravel']);

        self::assertCount(1, $this->events());
    }
}
