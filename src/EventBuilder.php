<?php

declare(strict_types=1);

namespace Splatty;

use Throwable;

/**
 * Turns throwables and messages into the payloads posted to the server.
 *
 * Events are plain arrays so `beforeSend` hooks can read and rewrite them
 * without learning an object graph, matching the Ruby and JS clients.
 */
final class EventBuilder
{
    private const MAX_CHAIN_DEPTH = 32;

    public function __construct(private Configuration $configuration)
    {
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $throwable, array $scope = []): array
    {
        $event = $this->basePayload($scope);
        $event['level'] = Level::fromName($scope['level'] ?? null, Level::Error)->value;
        $event['exception'] = ['values' => $this->exceptionChain($throwable)];

        return $event;
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function fromMessage(string $message, array $scope = [], Level|string|null $level = null): array
    {
        $event = $this->basePayload($scope);
        $event['level'] = Level::fromName($level ?? ($scope['level'] ?? null), Level::Info)->value;
        $event['message'] = ['formatted' => $message];

        return $event;
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    private function basePayload(array $scope): array
    {
        $contexts = ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]];
        if (isset($scope['contexts']) && is_array($scope['contexts'])) {
            $contexts = array_merge($contexts, $scope['contexts']);
        }

        $event = [
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'platform' => 'php',
            'environment' => $this->configuration->environment,
            'server_name' => $this->configuration->resolvedServerName(),
            'sdk' => ['name' => Version::SDK_NAME, 'version' => Version::VERSION],
            'tags' => $scope['tags'] ?? [],
            'extra' => $scope['extra'] ?? [],
            'contexts' => $contexts,
        ];

        if ($this->configuration->release !== null && $this->configuration->release !== '') {
            $event['release'] = $this->configuration->release;
        }
        if (!empty($scope['transaction'])) {
            $event['transaction'] = (string) $scope['transaction'];
        }
        if (!empty($scope['request']) && is_array($scope['request'])) {
            $event['request'] = $scope['request'];
        }

        return $event;
    }

    /**
     * Flattens the getPrevious() chain root-cause first, matching the other
     * clients.
     *
     * @return list<array<string, mixed>>
     */
    private function exceptionChain(Throwable $throwable): array
    {
        $chain = [];
        $current = $throwable;
        $depth = 0;
        while ($current !== null && $depth < self::MAX_CHAIN_DEPTH) {
            $chain[] = [
                'type' => get_class($current),
                'value' => $current->getMessage(),
                'stacktrace' => ['frames' => $this->frames($current)],
            ];
            $current = $current->getPrevious();
            ++$depth;
        }

        return array_reverse($chain);
    }

    /**
     * PHP's trace records, for each entry, where a call was made *from* and
     * which function it called. A frame therefore pairs one entry's location
     * with the *next* entry's function, and the throw site pairs the
     * exception's own file/line with the innermost called function.
     *
     * @return list<array<string, mixed>>
     */
    private function frames(Throwable $throwable): array
    {
        $trace = $throwable->getTrace();
        $frames = [$this->frame($throwable->getFile(), $throwable->getLine(), $this->qualify($trace[0] ?? null))];

        $count = count($trace);
        for ($i = 0; $i < $count; ++$i) {
            $entry = $trace[$i];
            $frames[] = $this->frame(
                isset($entry['file']) ? (string) $entry['file'] : null,
                isset($entry['line']) ? (int) $entry['line'] : null,
                $this->qualify($trace[$i + 1] ?? null),
            );
        }

        return array_reverse($frames);
    }

    /**
     * @param array<string, mixed>|null $entry
     */
    private function qualify(?array $entry): string
    {
        if ($entry === null || !isset($entry['function'])) {
            return '{main}';
        }
        $class = $entry['class'] ?? null;
        $type = $entry['type'] ?? '';

        return $class === null ? (string) $entry['function'] : $class . $type . $entry['function'];
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(?string $file, ?int $line, string $function): array
    {
        return [
            'filename' => $this->shortFilename($file),
            'abs_path' => $file,
            'function' => $function,
            'lineno' => $line,
            'in_app' => $this->inApp($file),
        ];
    }

    private function shortFilename(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }
        $vendor = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $pos = strpos($file, $vendor);
        if ($pos !== false) {
            return substr($file, $pos + 1);
        }

        $root = $this->configuration->projectRoot;
        if ($root !== '' && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($root) + 1);
        }

        return $file;
    }

    private function inApp(?string $file): bool
    {
        if ($file === null) {
            return false;
        }
        if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            return false;
        }
        $root = $this->configuration->projectRoot;

        return $root !== '' && str_starts_with($file, $root . DIRECTORY_SEPARATOR);
    }
}
