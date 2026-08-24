<?php

declare(strict_types=1);

namespace Splatty\Http;

/**
 * Builds request context from PHP's superglobals, so plain PHP, WordPress and
 * any framework get request details without a PSR-7 dependency.
 */
final class Globals
{
    /**
     * @param array<string, mixed>|null $server Defaults to $_SERVER.
     *
     * @return array<string, mixed> A scope with 'request' and, when present, a request_id tag.
     */
    public static function requestScope(?array $server = null): array
    {
        $request = self::requestContext($server);
        if ($request === null) {
            return [];
        }

        $scope = ['request' => $request];
        $id = self::requestId($server ?? $_SERVER);
        if ($id !== null) {
            $scope['tags'] = ['request_id' => $id];
        }

        return $scope;
    }

    /**
     * @param array<string, mixed>|null $server
     *
     * @return array<string, mixed>|null Null outside a request.
     */
    public static function requestContext(?array $server = null): ?array
    {
        $server ??= $_SERVER;
        if (!isset($server['REQUEST_METHOD'])) {
            return null;
        }

        return [
            'url' => self::url($server),
            'method' => (string) $server['REQUEST_METHOD'],
            'headers' => self::headers($server),
        ];
    }

    /** @param array<string, mixed> $server */
    private static function url(array $server): string
    {
        $https = $server['HTTPS'] ?? '';
        $scheme = ($https !== '' && strtolower((string) $https) !== 'off') ? 'https' : 'http';
        if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
            $forwarded = explode(',', (string) $server['HTTP_X_FORWARDED_PROTO']);
            $scheme = trim($forwarded[0]);
        }

        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        $path = (string) ($server['REQUEST_URI'] ?? '');

        return $scheme . '://' . $host . $path;
    }

    /**
     * Turns HTTP_X_REQUEST_ID into X-Request-Id, the same canonicalisation the
     * Ruby client's Rack middleware performs.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || is_array($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[self::headerName(substr($key, 5))] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[self::headerName($key)] = (string) $value;
            }
        }

        return $headers;
    }

    private static function headerName(string $raw): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('_', $raw),
        ));
    }

    /** @param array<string, mixed> $server */
    private static function requestId(array $server): ?string
    {
        foreach (['HTTP_X_REQUEST_ID', 'HTTP_X_CORRELATION_ID', 'HTTP_X_AMZN_TRACE_ID'] as $key) {
            $value = $server[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
