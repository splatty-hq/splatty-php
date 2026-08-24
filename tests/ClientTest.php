<?php

declare(strict_types=1);

namespace Splatty\Tests;

use RuntimeException;
use Splatty\Scrubber;

use function Splatty\captureException;
use function Splatty\captureMessage;
use function Splatty\enabled;

final class ClientTest extends TestCase
{
    public function testReportsAThrowableOnlyOnce(): void
    {
        $client = $this->makeClient();
        $error = new RuntimeException('boom');

        $first = $client->captureException($error);
        $second = $client->captureException($error);

        self::assertIsString($first);
        self::assertNull($second);
        self::assertCount(1, $this->events());
    }

    public function testDistinctThrowablesAreBothReported(): void
    {
        $client = $this->makeClient();
        $client->captureException(new RuntimeException('boom'));
        $client->captureException(new RuntimeException('boom'));

        self::assertCount(2, $this->events());
    }

    public function testCaptureMessageIsNotDeduplicated(): void
    {
        $client = $this->makeClient();
        $client->captureMessage('hello');
        $client->captureMessage('hello');

        self::assertCount(2, $this->events());
        self::assertSame('hello', $this->firstEvent()['message']['formatted']);
        self::assertSame('info', $this->firstEvent()['level']);
    }

    public function testScopeIsApplied(): void
    {
        $client = $this->makeClient();
        $client->captureException(new RuntimeException('boom'), [
            'level' => 'warn',
            'transaction' => 'POST /checkout',
            'tags' => ['area' => 'billing'],
            'extra' => ['order_id' => 4711],
        ]);

        $event = $this->firstEvent();
        self::assertSame('warn', $event['level']);
        self::assertSame('POST /checkout', $event['transaction']);
        self::assertSame('billing', $event['tags']['area']);
        self::assertSame(4711, $event['extra']['order_id']);
    }

    public function testDisabledClientCapturesNothing(): void
    {
        $client = $this->makeClient(['enabled' => false]);

        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureException(new RuntimeException('boom')));
        self::assertNull($client->captureMessage('hi'));
        self::assertSame([], $this->events());
    }

    public function testInvalidConfigDisablesTheClient(): void
    {
        $client = $this->makeClient(['dsn' => null]);

        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureException(new RuntimeException('boom')));
    }

    public function testBeforeSendCanDropAnEvent(): void
    {
        $client = $this->makeClient(['beforeSend' => static fn (array $event): ?array => null]);

        self::assertNull($client->captureException(new RuntimeException('boom')));
        self::assertSame([], $this->events());
    }

    public function testBeforeSendCanMutateAnEvent(): void
    {
        $client = $this->makeClient([
            'beforeSend' => static function (array $event): array {
                $event['tags']['mutated'] = 'yes';

                return $event;
            },
        ]);
        $client->captureException(new RuntimeException('boom'));

        self::assertSame('yes', $this->firstEvent()['tags']['mutated']);
    }

    public function testBeforeSendRunsAfterScrubbing(): void
    {
        $seen = null;
        $client = $this->makeClient([
            'beforeSend' => static function (array $event) use (&$seen): array {
                $seen = $event['request']['headers']['Cookie'];

                return $event;
            },
        ]);
        $client->captureException(new RuntimeException('boom'), [
            'request' => ['headers' => ['Cookie' => 'session=abc']],
        ]);

        self::assertSame(Scrubber::FILTERED, $seen);
    }

    public function testTransportFailureYieldsNoEventId(): void
    {
        $client = $this->makeClient();
        $this->transport->failSends = true;

        self::assertNull($client->captureException(new RuntimeException('boom')));
    }

    public function testCloseClosesTheTransport(): void
    {
        $client = $this->makeClient();
        $client->close();

        self::assertTrue($this->transport->closed);
    }

    public function testNamespacedFunctionsUseTheInstalledClient(): void
    {
        $this->installClient();

        self::assertTrue(enabled());
        self::assertIsString(captureException(new RuntimeException('boom')));
        self::assertIsString(captureMessage('hello', ['level' => 'warn']));
        self::assertCount(2, $this->events());
        self::assertSame('warn', $this->events()[1]['level']);
    }

    public function testNamespacedFunctionsAreInertWithoutAClient(): void
    {
        self::assertFalse(enabled());
        self::assertNull(captureException(new RuntimeException('boom')));
        self::assertNull(captureMessage('hello'));
    }
}
