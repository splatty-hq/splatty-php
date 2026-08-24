<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Splatty\Logs\MonologHandler;
use Splatty\Logs\PsrLogger;

final class LogAdaptersTest extends TestCase
{
    private function client(): \Splatty\Client
    {
        return $this->installClient([
            'logs' => true,
            'logOptions' => ['host' => 'h', 'flushInterval' => 3600.0],
        ]);
    }

    public function testMonologHandlerForwardsRecords(): void
    {
        $client = $this->client();
        $logger = new Logger('api');
        $logger->pushHandler(new MonologHandler());

        $logger->warning('watch out', ['tenant' => 'acme', 'status' => 503]);
        $client->flush();

        $entry = $this->transport->logEntries()[0];
        self::assertSame('warn', $entry['level']);
        self::assertSame('watch out', $entry['message']);
        self::assertSame(503, $entry['status']);
        self::assertSame('acme', $entry['fields']['tenant']);
        self::assertSame('api', $entry['fields']['channel']);
        self::assertGreaterThan(0, $entry['timestamp']);
    }

    public function testMonologHandlerRespectsItsLevel(): void
    {
        $client = $this->client();
        $logger = new Logger('api');
        $logger->pushHandler(new MonologHandler(MonologLevel::Warning));

        $logger->info('quiet');
        $logger->error('loud');
        $client->flush();

        $entries = $this->transport->logEntries();
        self::assertCount(1, $entries);
        self::assertSame('loud', $entries[0]['message']);
        self::assertSame('error', $entries[0]['level']);
    }

    public function testMonologLevelsMapOntoTheServerLevels(): void
    {
        $client = $this->client();
        $logger = new Logger('api');
        $logger->pushHandler(new MonologHandler(MonologLevel::Debug));

        $logger->debug('d');
        $logger->notice('n');
        $logger->critical('c');
        $logger->emergency('e');
        $client->flush();

        self::assertSame(
            ['debug', 'info', 'fatal', 'fatal'],
            array_column($this->transport->logEntries(), 'level'),
        );
    }

    public function testMonologHandlerIsInertWithoutAClient(): void
    {
        $logger = new Logger('api');
        $logger->pushHandler(new MonologHandler());

        $logger->error('nobody listening');
        self::assertSame([], $this->transport->batches ?? []);
    }

    public function testPsrLoggerForwardsRecords(): void
    {
        $client = $this->client();
        $logger = new PsrLogger();

        $logger->warning('psr warning', ['path' => '/x', 'duration_ms' => 12.5]);
        $client->flush();

        $entry = $this->transport->logEntries()[0];
        self::assertSame('warn', $entry['level']);
        self::assertSame('psr warning', $entry['message']);
        self::assertSame('/x', $entry['path']);
        self::assertSame(12.5, $entry['duration_ms']);
    }

    public function testPsrLoggerIsInertWithoutAClient(): void
    {
        (new PsrLogger())->error('nobody listening');
        $this->addToAssertionCount(1);
    }
}
