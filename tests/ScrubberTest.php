<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Splatty\Scrubber;

final class ScrubberTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     *
     * @return array<string, string>
     */
    private function scrub(array $headers, array $options = []): array
    {
        $event = ['request' => ['url' => 'http://example.com/y', 'method' => 'GET', 'headers' => $headers]];
        $scrubbed = (new Scrubber($this->makeConfiguration($options)))->scrub($event);

        return $scrubbed['request']['headers'];
    }

    public function testFiltersSensitiveHeadersByDefault(): void
    {
        $headers = $this->scrub([
            'Cookie' => 'session=abc',
            'Authorization' => 'Bearer secret',
            'X-Csrf-Token' => 'tok',
            'X-Api-Key' => 'k',
            'Accept' => 'text/html',
            'User-Agent' => 'curl',
        ]);

        foreach (['Cookie', 'Authorization', 'X-Csrf-Token', 'X-Api-Key'] as $name) {
            self::assertSame(Scrubber::FILTERED, $headers[$name], $name . ' was not filtered');
        }
        self::assertSame('text/html', $headers['Accept']);
        self::assertSame('curl', $headers['User-Agent']);
    }

    public function testFiltersLowercasedHeaderNames(): void
    {
        $headers = $this->scrub(['cookie' => 'a=b', 'authorization' => 'Bearer x']);
        self::assertSame(Scrubber::FILTERED, $headers['cookie']);
        self::assertSame(Scrubber::FILTERED, $headers['authorization']);
    }

    public function testPassesHeadersThroughWithSendDefaultPii(): void
    {
        $headers = $this->scrub(['Cookie' => 'session=abc'], ['sendDefaultPii' => true]);
        self::assertSame('session=abc', $headers['Cookie']);
    }

    public function testToleratesEventWithoutRequest(): void
    {
        $event = ['level' => 'error'];
        self::assertSame($event, (new Scrubber($this->makeConfiguration()))->scrub($event));
    }

    public function testToleratesRequestWithoutHeaders(): void
    {
        $event = ['request' => ['url' => 'http://example.com']];
        $scrubbed = (new Scrubber($this->makeConfiguration()))->scrub($event);
        self::assertSame('http://example.com', $scrubbed['request']['url']);
    }
}
