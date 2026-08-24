<?php

declare(strict_types=1);

namespace Splatty\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Splatty\Hub;
use Throwable;

/**
 * PSR-15 middleware that reports exceptions escaping the rest of the pipeline
 * and rethrows them, leaving your own error rendering untouched.
 *
 * Requires psr/http-server-middleware.
 */
final class Middleware implements MiddlewareInterface
{
    /**
     * @param (callable(ServerRequestInterface): string)|null $transaction
     *        Derives the transaction name. Without it none is set: only your
     *        router knows the route template, and a raw path would blow up
     *        cardinality.
     */
    public function __construct(private $transaction = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            $client = Hub::getClient();
            if ($client !== null && $client->isEnabled()) {
                $client->captureException($throwable, $this->scope($request));
            }

            throw $throwable;
        }
    }

    /** @return array<string, mixed> */
    private function scope(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $scope = [
            'request' => [
                'url' => (string) $request->getUri(),
                'method' => $request->getMethod(),
                'headers' => $headers,
            ],
        ];

        $id = $request->getHeaderLine('X-Request-Id') ?: $request->getHeaderLine('X-Correlation-Id');
        if ($id !== '') {
            $scope['tags'] = ['request_id' => $id];
        }

        if ($this->transaction !== null) {
            $name = ($this->transaction)($request);
            if (is_string($name) && $name !== '') {
                $scope['transaction'] = $name;
            }
        }

        return $scope;
    }
}
