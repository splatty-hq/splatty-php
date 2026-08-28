<?php

declare(strict_types=1);

namespace Splatty\Tests;

use RuntimeException;
use Splatty\EventBuilder;
use Splatty\Level;

final class EventBuilderTest extends TestCase
{
    private function builder(): EventBuilder
    {
        return new EventBuilder($this->makeConfiguration());
    }

    private function thrower(): RuntimeException
    {
        return new RuntimeException('boom');
    }

    public function testFromThrowableBuildsPayload(): void
    {
        $event = $this->builder()->fromThrowable($this->thrower());

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $event['event_id']);
        self::assertSame('php', $event['platform']);
        self::assertSame('test', $event['environment']);
        self::assertSame('0.0.1', $event['release']);
        self::assertSame('error', $event['level']);
        self::assertSame('splatty.php', $event['sdk']['name']);
        self::assertSame('php', $event['contexts']['runtime']['name']);
        self::assertCount(1, $event['exception']['values']);

        $value = $event['exception']['values'][0];
        self::assertSame(RuntimeException::class, $value['type']);
        self::assertSame('boom', $value['value']);
        self::assertNotEmpty($value['stacktrace']['frames']);
    }

    public function testFramesCarrySourceContext(): void
    {
        $event = $this->builder()->fromThrowable($this->thrower());

        $frames = $event['exception']['values'][0]['stacktrace']['frames'];
        $frame = end($frames);

        self::assertSame(__FILE__, $frame['abs_path']);
        self::assertSame("        return new RuntimeException('boom');", $frame['context_line']);
        self::assertCount(5, $frame['pre_context']);
        self::assertCount(5, $frame['post_context']);
        self::assertSame('    {', end($frame['pre_context']));
    }

    public function testContextLinesZeroLeavesFramesBare(): void
    {
        $builder = new EventBuilder($this->makeConfiguration(['contextLines' => 0]));

        $frames = $builder->fromThrowable($this->thrower())['exception']['values'][0]['stacktrace']['frames'];

        foreach ($frames as $frame) {
            self::assertArrayNotHasKey('context_line', $frame);
            self::assertArrayNotHasKey('pre_context', $frame);
            self::assertArrayNotHasKey('post_context', $frame);
        }
    }

    public function testTimestampCarriesSubSecondPrecision(): void
    {
        $event = $this->builder()->fromMessage('x');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $event['timestamp']);
    }

    public function testFramesAreOldestFirstAndMarkAppCode(): void
    {
        $event = $this->builder()->fromThrowable($this->thrower());
        $frames = $event['exception']['values'][0]['stacktrace']['frames'];

        $last = $frames[count($frames) - 1];
        self::assertStringContainsString('EventBuilderTest.php', (string) $last['filename']);
        self::assertSame('Splatty\Tests\EventBuilderTest->thrower', $last['function']);
        self::assertTrue($last['in_app'], 'the throw site is application code');

        foreach ($frames as $frame) {
            if (str_contains((string) $frame['abs_path'], '/vendor/')) {
                self::assertFalse($frame['in_app'], 'vendor frames are not in_app');
            }
        }
    }

    public function testFilenamesAreRelativeToTheProjectRoot(): void
    {
        $event = $this->builder()->fromThrowable($this->thrower());
        $frames = $event['exception']['values'][0]['stacktrace']['frames'];
        $last = $frames[count($frames) - 1];

        self::assertSame('tests/EventBuilderTest.php', $last['filename']);
        self::assertStringStartsWith('/', (string) $last['abs_path']);
    }

    public function testChainIsRootCauseFirst(): void
    {
        $root = new RuntimeException('root');
        $wrapped = new \LogicException('wrapped', 0, $root);

        $values = $this->builder()->fromThrowable($wrapped)['exception']['values'];

        self::assertCount(2, $values);
        self::assertSame('root', $values[0]['value']);
        self::assertSame('wrapped', $values[1]['value']);
        self::assertSame(\LogicException::class, $values[1]['type']);
    }

    public function testEveryLinkOfTheChainCarriesItsOwnFrames(): void
    {
        $values = $this->builder()->fromThrowable(new \LogicException('outer', 0, new RuntimeException('inner')))['exception']['values'];

        self::assertNotEmpty($values[0]['stacktrace']['frames']);
        self::assertNotEmpty($values[1]['stacktrace']['frames']);
    }

    public function testFromMessage(): void
    {
        $event = $this->builder()->fromMessage('hi there', ['tags' => ['service' => 'api']], Level::Warn);

        self::assertSame('warn', $event['level']);
        self::assertSame('hi there', $event['message']['formatted']);
        self::assertSame(['service' => 'api'], $event['tags']);
        self::assertArrayNotHasKey('exception', $event);
    }

    public function testLevelComesFromTheScopeToo(): void
    {
        self::assertSame('fatal', $this->builder()->fromMessage('x', ['level' => 'fatal'])['level']);
        self::assertSame('warn', $this->builder()->fromThrowable($this->thrower(), ['level' => 'warn'])['level']);
    }

    public function testScopePassesThroughRequestAndTransaction(): void
    {
        $event = $this->builder()->fromMessage('x', [
            'request' => ['url' => '/x', 'method' => 'GET'],
            'transaction' => 'GET /x',
            'contexts' => ['app' => ['build' => '1.2.3']],
        ]);

        self::assertSame('/x', $event['request']['url']);
        self::assertSame('GET /x', $event['transaction']);
        self::assertSame('1.2.3', $event['contexts']['app']['build']);
        self::assertSame('php', $event['contexts']['runtime']['name']);
    }

    public function testUnsetScopeKeysAreOmitted(): void
    {
        $event = $this->builder()->fromMessage('x');

        self::assertArrayNotHasKey('transaction', $event);
        self::assertArrayNotHasKey('request', $event);
        self::assertSame([], $event['tags']);
        self::assertSame([], $event['extra']);
    }

    public function testReleaseIsOmittedWhenUnset(): void
    {
        $builder = new EventBuilder($this->makeConfiguration(['release' => null]));
        self::assertArrayNotHasKey('release', $builder->fromMessage('x'));
    }
}
