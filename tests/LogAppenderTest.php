<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Splatty\Level;

final class LogAppenderTest extends TestCase
{
    /** @param array<string, mixed> $logOptions */
    private function client(array $logOptions = [], array $options = []): \Splatty\Client
    {
        return $this->makeClient(array_replace([
            'logs' => true,
            'logOptions' => array_replace(['host' => 'h-1', 'flushInterval' => 3600.0], $logOptions),
        ], $options));
    }

    public function testEnqueuesAndDispatches(): void
    {
        $client = $this->client(['level' => 'info', 'batchSize' => 10]);
        $when = new \DateTimeImmutable('2026-06-17T12:00:00+00:00');

        self::assertTrue($client->captureLog([
            'time' => $when,
            'level' => 'info',
            'message' => 'hi',
            'fields' => [
                'request_id' => 'rid',
                'method' => 'GET',
                'path' => '/x',
                'status' => 200,
                'duration_ms' => 1.5,
                'user' => 'u',
            ],
        ]));
        $client->flush();

        self::assertCount(1, $this->transport->batches);
        self::assertSame('h-1', $this->transport->batches[0]['host']);

        $entry = $this->transport->logEntries()[0];
        self::assertSame('hi', $entry['message']);
        self::assertSame('info', $entry['level']);
        self::assertSame('rid', $entry['request_id']);
        self::assertSame('GET', $entry['method']);
        self::assertSame('/x', $entry['path']);
        self::assertSame(200, $entry['status']);
        self::assertSame(1.5, $entry['duration_ms']);
        self::assertSame('test', $entry['environment']);
        self::assertSame('0.0.1', $entry['release']);
        self::assertSame('h-1', $entry['host']);
        self::assertSame($when->getTimestamp() * 1000, $entry['timestamp']);
        self::assertSame(['user' => 'u'], $entry['fields']);
    }

    public function testDropsEntriesAboutIntakePaths(): void
    {
        $client = $this->client();

        foreach (['/api/4/logs', '/api/42/metrics', '/api/1/envelope/', '/api/envelope', '/api/logs'] as $path) {
            self::assertFalse(
                $client->captureLog(['level' => 'info', 'message' => 'req', 'fields' => ['path' => $path]]),
                $path . ' should be dropped',
            );
        }
        self::assertTrue($client->captureLog([
            'level' => 'info', 'message' => 'real', 'fields' => ['path' => '/users/42'],
        ]));
        $client->flush();

        $entries = $this->transport->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('/users/42', $entries[0]['path']);
    }

    public function testInlinesSqlIntoTheMessage(): void
    {
        $client = $this->client();
        $client->captureLog(['level' => 'debug', 'message' => 'Load', 'fields' => ['sql' => 'SELECT 1']]);
        $client->captureLog(['level' => 'debug', 'message' => '', 'fields' => ['sql' => 'SELECT 2']]);
        $client->flush();

        $entries = $this->transport->logEntries();
        self::assertSame('Load — SELECT 1', $entries[0]['message']);
        self::assertSame('SELECT 2', $entries[1]['message']);
    }

    public function testHonoursMinimumLevel(): void
    {
        $client = $this->client(['level' => Level::Warn]);

        self::assertFalse($client->captureLog(['level' => 'info', 'message' => 'quiet']));
        self::assertTrue($client->captureLog(['level' => 'error', 'message' => 'loud']));
        $client->flush();

        $entries = $this->transport->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('loud', $entries[0]['message']);
    }

    public function testDropsOldestPastTheQueueLimit(): void
    {
        $client = $this->client(['queueLimit' => 2, 'batchSize' => 1000]);

        foreach (['one', 'two', 'three'] as $message) {
            $client->captureLog(['level' => 'info', 'message' => $message]);
        }
        $client->flush();

        $messages = array_column($this->transport->logEntries(), 'message');
        self::assertSame(['two', 'three'], $messages);
    }

    public function testFlushesOnceTheBatchIsFull(): void
    {
        $client = $this->client(['batchSize' => 3]);

        $client->captureLog(['level' => 'info', 'message' => 'a']);
        $client->captureLog(['level' => 'info', 'message' => 'b']);
        self::assertSame([], $this->transport->batches, 'nothing ships before the batch fills');

        $client->captureLog(['level' => 'info', 'message' => 'c']);
        self::assertCount(1, $this->transport->batches);
        self::assertCount(3, $this->transport->logEntries());
    }

    public function testFlushesOnceTheIntervalElapses(): void
    {
        $client = $this->client(['flushInterval' => 0.0, 'batchSize' => 1000]);
        $client->captureLog(['level' => 'info', 'message' => 'tick']);

        self::assertCount(1, $this->transport->batches);
    }

    public function testCloseShipsTheFinalBatch(): void
    {
        $client = $this->client();
        $client->captureLog(['level' => 'info', 'message' => 'last words']);
        $client->close();

        $entries = $this->transport->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('last words', $entries[0]['message']);
    }

    public function testNoAppenderWhenLogsAreDisabled(): void
    {
        $client = $this->makeClient(['logs' => false]);

        self::assertNull($client->getAppender());
        self::assertFalse($client->captureLog(['level' => 'info', 'message' => 'dropped']));
    }

    public function testAppenderIsInertWhenTheClientIsDisabled(): void
    {
        $client = $this->makeClient(['logs' => true, 'enabled' => false]);
        self::assertNull($client->getAppender());
        self::assertFalse($client->captureLog(['level' => 'info', 'message' => 'x']));
    }

    public function testStringifiesNonScalarFields(): void
    {
        $client = $this->client();
        $client->captureLog(['level' => 'info', 'message' => 'x', 'fields' => [
            'list' => [1, 2],
            'flag' => true,
            'num' => 3.5,
            'nothing' => null,
        ]]);
        $client->flush();

        $fields = $this->transport->logEntries()[0]['fields'];
        self::assertSame('[1,2]', $fields['list']);
        self::assertSame('true', $fields['flag']);
        self::assertSame('3.5', $fields['num']);
        self::assertArrayNotHasKey('nothing', $fields);
    }

    public function testMissingPromotedFieldsGetNeutralDefaults(): void
    {
        $client = $this->client();
        $client->captureLog(['level' => 'info', 'message' => 'bare']);
        $client->flush();

        $entry = $this->transport->logEntries()[0];
        self::assertSame('', $entry['request_id']);
        self::assertSame(0, $entry['status']);
        self::assertNull($entry['duration_ms']);
        self::assertSame([], $entry['fields']);
    }
}
