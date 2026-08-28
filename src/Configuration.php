<?php

declare(strict_types=1);

namespace Splatty;

use Splatty\Logs\LogOptions;

final class Configuration
{
    public const DEFAULT_URL = 'https://splatty.app';

    /** Server base URL. Env: SPLATTY_URL. */
    public string $url;

    /** Project key, sent as a bearer token. Env: SPLATTY_DSN. */
    public ?string $dsn;

    /** Env: SPLATTY_ENVIRONMENT, APP_ENV. */
    public string $environment;

    /** Env: SPLATTY_RELEASE. */
    public ?string $release;

    /** Reported host. Defaults to the machine hostname. */
    public ?string $serverName;

    /** Set false to turn every capture into a no-op. */
    public bool $enabled = true;

    /** Install the batching log appender. */
    public bool $logs = true;

    /** Install handlers for uncaught exceptions, PHP errors and fatals. */
    public bool $captureUnhandled = false;

    /** Send request headers verbatim instead of filtering the sensitive ones. */
    public bool $sendDefaultPii = false;

    /** Source lines sent either side of a stack frame. 0 disables. */
    public int $contextLines = 5;

    /** Connection setup timeout, milliseconds. */
    public int $openTimeoutMs = 5000;

    /** Whole-request timeout, milliseconds. */
    public int $readTimeoutMs = 10000;

    /** Absolute path used to shorten filenames and decide in_app. */
    public string $projectRoot;

    /** @var callable|null Receives the SDK's own warnings as a string. */
    public $logger = null;

    /** @var callable|null fn(array $event): ?array — mutate, or return null to drop. */
    public $beforeSend = null;

    public LogOptions $logOptions;

    /** Swap the transport, mainly for tests. */
    public ?TransportInterface $transport = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->url = (string) ($options['url'] ?? self::env('SPLATTY_URL') ?? self::DEFAULT_URL);
        $this->dsn = $options['dsn'] ?? self::env('SPLATTY_DSN');
        $this->environment = (string) (
            $options['environment']
            ?? self::env('SPLATTY_ENVIRONMENT')
            ?? self::env('APP_ENV')
            ?? 'development'
        );
        $this->release = $options['release'] ?? self::env('SPLATTY_RELEASE');
        $this->serverName = $options['serverName'] ?? null;
        $this->enabled = (bool) ($options['enabled'] ?? true);
        $this->logs = (bool) ($options['logs'] ?? true);
        $this->captureUnhandled = (bool) ($options['captureUnhandled'] ?? false);
        $this->sendDefaultPii = (bool) ($options['sendDefaultPii'] ?? false);
        $this->contextLines = (int) ($options['contextLines'] ?? 5);
        $this->openTimeoutMs = (int) ($options['openTimeoutMs'] ?? 5000);
        $this->readTimeoutMs = (int) ($options['readTimeoutMs'] ?? 10000);
        $this->projectRoot = rtrim((string) ($options['projectRoot'] ?? self::detectProjectRoot()), '/');
        $this->logger = $options['logger'] ?? null;
        $this->beforeSend = $options['beforeSend'] ?? null;
        $this->transport = $options['transport'] ?? null;

        $logOptions = $options['logOptions'] ?? [];
        $this->logOptions = $logOptions instanceof LogOptions
            ? $logOptions
            : LogOptions::fromArray(is_array($logOptions) ? $logOptions : []);
    }

    /**
     * Disables the config rather than throwing: a misconfigured SDK must never
     * stop an application from booting.
     */
    public function validate(): void
    {
        if (!$this->enabled) {
            return;
        }
        if (trim($this->url) === '') {
            $this->disable('config.url is required');

            return;
        }
        if ($this->dsn === null || trim($this->dsn) === '') {
            $this->disable('config.dsn is required');

            return;
        }

        $parsed = parse_url($this->url);
        if ($parsed === false) {
            $this->disable('config.url is invalid');

            return;
        }
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            $this->disable('config.url must include scheme + host');
        }
    }

    public function disable(string $message): void
    {
        $this->enabled = false;
        $this->warn('[Splatty] disabled: ' . $message);
    }

    public function warn(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);

            return;
        }
        error_log($message);
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->dsn !== null && trim($this->dsn) !== ''
            && trim($this->url) !== '';
    }

    public function envelopeUrl(): string
    {
        return rtrim($this->url, '/') . '/api/envelope';
    }

    public function dsnKey(): string
    {
        return $this->dsn ?? '';
    }

    public function resolvedServerName(): string
    {
        return $this->serverName ?? (gethostname() ?: 'unknown');
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }

    /**
     * When installed as a dependency the SDK lives at <root>/vendor/<vendor>/<pkg>/src,
     * so the application root is whatever sits above the vendor directory.
     */
    private static function detectProjectRoot(): string
    {
        $dir = __DIR__;
        $marker = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $pos = strpos($dir, $marker);
        if ($pos !== false) {
            return substr($dir, 0, $pos);
        }

        return dirname(__DIR__);
    }
}
