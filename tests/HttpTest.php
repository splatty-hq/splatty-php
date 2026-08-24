<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Splatty\Http\Globals;
use Splatty\Http\Middleware;
use Splatty\Scrubber;

final class HttpTest extends TestCase
{
    /** @return array<string, mixed> */
    private function server(array $overrides = []): array
    {
        return array_replace([
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'shop.test',
            'REQUEST_URI' => '/pay?x=1',
            'HTTPS' => 'on',
            'HTTP_COOKIE' => 'session=secret',
            'HTTP_X_REQUEST_ID' => 'req-1',
            'HTTP_ACCEPT' => 'text/html',
            'CONTENT_TYPE' => 'application/json',
        ], $overrides);
    }

    public function testGlobalsBuildRequestContext(): void
    {
        $scope = Globals::requestScope($this->server());

        self::assertSame('https://shop.test/pay?x=1', $scope['request']['url']);
        self::assertSame('POST', $scope['request']['method']);
        self::assertSame('req-1', $scope['tags']['request_id']);
        self::assertSame('session=secret', $scope['request']['headers']['Cookie']);
        self::assertSame('text/html', $scope['request']['headers']['Accept']);
        self::assertSame('application/json', $scope['request']['headers']['Content-Type']);
    }

    public function testGlobalsHonourForwardedProto(): void
    {
        $scope = Globals::requestScope($this->server([
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ]));

        self::assertStringStartsWith('https://', $scope['request']['url']);
    }

    public function testGlobalsFallBackToHttpWithoutTls(): void
    {
        $scope = Globals::requestScope($this->server(['HTTPS' => 'off']));
        self::assertStringStartsWith('http://', $scope['request']['url']);
    }

    public function testGlobalsOmitTheTagWithoutARequestId(): void
    {
        $server = $this->server();
        unset($server['HTTP_X_REQUEST_ID']);

        self::assertArrayNotHasKey('tags', Globals::requestScope($server));
    }

    public function testGlobalsReturnNothingOutsideARequest(): void
    {
        self::assertSame([], Globals::requestScope([]));
        self::assertNull(Globals::requestContext([]));
    }

    public function testGlobalsScopeIsScrubbedWhenCaptured(): void
    {
        $client = $this->installClient();
        $client->captureException(new RuntimeException('boom'), Globals::requestScope($this->server()));

        $event = $this->firstEvent();
        self::assertSame(Scrubber::FILTERED, $event['request']['headers']['Cookie']);
        self::assertSame('text/html', $event['request']['headers']['Accept']);
        self::assertSame('req-1', $event['tags']['request_id']);
    }

    private function handler(?\Throwable $throw): RequestHandlerInterface
    {
        return new class($throw) implements RequestHandlerInterface {
            public function __construct(private ?\Throwable $throw)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new Response(200, [], 'ok');
            }
        };
    }

    public function testMiddlewarePassesSuccessThrough(): void
    {
        $this->installClient();
        $response = (new Middleware())->process(new ServerRequest('GET', 'http://x/y'), $this->handler(null));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->events());
    }

    public function testMiddlewareCapturesAndRethrows(): void
    {
        $this->installClient();
        $request = (new ServerRequest('POST', 'https://shop.test/pay?x=1'))
            ->withHeader('Cookie', 'session=secret')
            ->withHeader('X-Request-Id', 'req-2')
            ->withHeader('Accept', 'text/html');

        $error = new RuntimeException('handler exploded');
        try {
            (new Middleware())->process($request, $this->handler($error));
            self::fail('the exception should have been rethrown');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        $event = $this->firstEvent();
        self::assertSame('handler exploded', $event['exception']['values'][0]['value']);
        self::assertSame('POST', $event['request']['method']);
        self::assertSame('https://shop.test/pay?x=1', $event['request']['url']);
        self::assertSame('req-2', $event['tags']['request_id']);
        self::assertSame(Scrubber::FILTERED, $event['request']['headers']['Cookie']);
        self::assertSame('text/html', $event['request']['headers']['Accept']);
    }

    public function testMiddlewareTransactionCallback(): void
    {
        $this->installClient();
        $middleware = new Middleware(static fn (ServerRequestInterface $r): string => $r->getMethod() . ' /users/{id}');

        try {
            $middleware->process(new ServerRequest('GET', 'http://x/users/7'), $this->handler(new RuntimeException('x')));
        } catch (RuntimeException) {
        }

        self::assertSame('GET /users/{id}', $this->firstEvent()['transaction']);
    }

    public function testMiddlewareSetsNoTransactionByDefault(): void
    {
        $this->installClient();
        try {
            (new Middleware())->process(new ServerRequest('GET', 'http://x/y'), $this->handler(new RuntimeException('x')));
        } catch (RuntimeException) {
        }

        self::assertArrayNotHasKey('transaction', $this->firstEvent());
    }

    public function testMiddlewareStillRethrowsWithoutAClient(): void
    {
        $this->expectException(RuntimeException::class);
        (new Middleware())->process(new ServerRequest('GET', 'http://x/y'), $this->handler(new RuntimeException('x')));
    }
}
