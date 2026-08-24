<?php

declare(strict_types=1);

namespace Splatty\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Splatty\Client;
use Splatty\Configuration;
use Splatty\Hub;

abstract class TestCase extends BaseTestCase
{
    protected RecordingTransport $transport;

    protected function tearDown(): void
    {
        Hub::setClient(null);
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    protected function makeConfiguration(array $options = []): Configuration
    {
        $this->transport ??= new RecordingTransport();

        return new Configuration(array_replace([
            'url' => 'http://example.com',
            'dsn' => 'abc123',
            'environment' => 'test',
            'release' => '0.0.1',
            'logs' => false,
            'logger' => static function (string $message): void {},
            'transport' => $this->transport,
        ], $options));
    }

    /** @param array<string, mixed> $options */
    protected function makeClient(array $options = []): Client
    {
        $this->transport = new RecordingTransport();

        return new Client($this->makeConfiguration($options));
    }

    /**
     * Installs a client as the process-wide one, for the namespaced functions.
     *
     * @param array<string, mixed> $options
     */
    protected function installClient(array $options = []): Client
    {
        $client = $this->makeClient($options);
        Hub::setClient($client);

        return $client;
    }

    /** @return list<array<string, mixed>> */
    protected function events(): array
    {
        return $this->transport->events;
    }

    /** @return array<string, mixed> */
    protected function firstEvent(): array
    {
        self::assertNotEmpty($this->transport->events, 'no event was sent');

        return $this->transport->events[0];
    }
}
