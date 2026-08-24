<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Splatty\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testEnvelopeUrlBuiltFromUrl(): void
    {
        $config = $this->makeConfiguration(['url' => 'http://host.example:3001']);
        self::assertSame('abc123', $config->dsnKey());
        self::assertSame('http://host.example:3001/api/envelope', $config->envelopeUrl());
    }

    public function testEnvelopeUrlStripsTrailingSlash(): void
    {
        $config = $this->makeConfiguration(['url' => 'https://example.com/']);
        self::assertSame('https://example.com/api/envelope', $config->envelopeUrl());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badConfigs(): iterable
    {
        yield 'url blank' => [['url' => '   ']];
        yield 'dsn missing' => [['dsn' => null]];
        yield 'dsn blank' => [['dsn' => '  ']];
        yield 'url invalid' => [['url' => 'not-a-url']];
        yield 'url without host' => [['url' => 'https://']];
    }

    /**
     * @dataProvider badConfigs
     *
     * @param array<string, mixed> $options
     */
    public function testValidateDisablesOnBadConfig(array $options): void
    {
        $config = $this->makeConfiguration($options);
        $config->validate();
        self::assertFalse($config->isEnabled());
    }

    public function testValidateDoesNothingWhenAlreadyDisabled(): void
    {
        $config = $this->makeConfiguration(['enabled' => false, 'url' => '', 'dsn' => null]);
        $config->validate();
        self::assertFalse($config->isEnabled());
    }

    public function testWarningGoesToTheConfiguredLogger(): void
    {
        $messages = [];
        $config = $this->makeConfiguration([
            'dsn' => null,
            'logger' => static function (string $message) use (&$messages): void { $messages[] = $message; },
        ]);
        $config->validate();

        self::assertSame(['[Splatty] disabled: config.dsn is required'], $messages);
    }

    public function testDefaults(): void
    {
        $config = new Configuration(['url' => 'https://x.test', 'dsn' => 'k']);

        self::assertSame('https://splatty.app', Configuration::DEFAULT_URL);
        self::assertTrue($config->enabled);
        self::assertTrue($config->logs);
        self::assertFalse($config->sendDefaultPii);
        self::assertFalse($config->captureUnhandled);
        self::assertSame(5000, $config->openTimeoutMs);
        self::assertSame(10000, $config->readTimeoutMs);
        self::assertNotSame('', $config->resolvedServerName());
    }

    public function testReadsEnvironmentVariables(): void
    {
        putenv('SPLATTY_URL=https://env.example');
        putenv('SPLATTY_DSN=env-dsn');
        putenv('SPLATTY_RELEASE=env-release');
        putenv('SPLATTY_ENVIRONMENT=staging');

        try {
            $config = new Configuration();
            self::assertSame('https://env.example', $config->url);
            self::assertSame('env-dsn', $config->dsn);
            self::assertSame('env-release', $config->release);
            self::assertSame('staging', $config->environment);
        } finally {
            putenv('SPLATTY_URL');
            putenv('SPLATTY_DSN');
            putenv('SPLATTY_RELEASE');
            putenv('SPLATTY_ENVIRONMENT');
        }
    }

    public function testExplicitOptionsBeatTheEnvironment(): void
    {
        putenv('SPLATTY_DSN=env-dsn');

        try {
            self::assertSame('explicit', (new Configuration(['dsn' => 'explicit']))->dsn);
        } finally {
            putenv('SPLATTY_DSN');
        }
    }
}
