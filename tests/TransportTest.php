<?php

declare(strict_types=1);

namespace Splatty\Tests;

use Splatty\Transport;
use Splatty\Version;

/**
 * Exercises the transport end to end against PHP's built-in server, so the
 * gzip, headers and three-line envelope are verified as they actually go over
 * the wire.
 */
final class TransportTest extends TestCase
{
    private static string $dir = '';

    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/splatty-test-' . bin2hex(random_bytes(6));
        mkdir(self::$dir);
        self::$port = self::freePort();

        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/server/router.php'],
            $descriptors,
            $pipes,
            null,
            ['SPLATTY_TEST_DIR' => self::$dir] + $_ENV,
        ) ?: null;

        if (self::$process === null) {
            self::fail('could not start the built-in server');
        }
        self::waitForPort();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (self::$dir !== '' && is_dir(self::$dir)) {
            rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            unlink($file);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($socket, 'could not reserve a port: ' . $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private static function waitForPort(): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $client = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.2);
            if ($client !== false) {
                fclose($client);

                return;
            }
            usleep(50_000);
        }
        self::fail('the built-in server never came up');
    }

    private function transport(array $options = []): Transport
    {
        return new Transport($this->makeConfiguration(array_replace([
            'url' => 'http://127.0.0.1:' . self::$port,
            'dsn' => 'abc',
            'transport' => null,
        ], $options)));
    }

    /** @return list<array<string, mixed>> */
    private function received(): array
    {
        $files = glob(self::$dir . '/*.json') ?: [];
        sort($files);

        return array_map(static function (string $file): array {
            $record = json_decode((string) file_get_contents($file), true);
            $record['raw'] = (string) gzdecode(base64_decode($record['body']));
            $record['lines'] = explode("\n", $record['raw']);

            return $record;
        }, $files);
    }

    public function testSendEnvelopePostsGzippedThreeLineBody(): void
    {
        $transport = $this->transport();
        $event = ['event_id' => str_repeat('deadbeef', 4), 'level' => 'error'];

        self::assertTrue($transport->sendEnvelope($event));
        $transport->close();

        $received = $this->received();
        self::assertCount(1, $received);
        $request = $received[0];

        self::assertSame('/api/envelope', $request['path']);
        self::assertSame('POST', $request['method']);
        self::assertSame('Bearer abc', $request['headers']['HTTP_AUTHORIZATION']);
        self::assertSame('application/x-splatty-envelope', $request['headers']['CONTENT_TYPE']);
        self::assertSame('gzip', $request['headers']['HTTP_CONTENT_ENCODING']);
        self::assertSame(Version::SDK_NAME . '/' . Version::VERSION, $request['headers']['HTTP_USER_AGENT']);

        self::assertCount(3, $request['lines']);
        $header = json_decode($request['lines'][0], true);
        $itemHeader = json_decode($request['lines'][1], true);

        self::assertSame(str_repeat('deadbeef', 4), $header['event_id']);
        self::assertSame('abc', $header['dsn']);
        self::assertSame(Version::SDK_NAME, $header['sdk']['name']);
        self::assertSame('event', $itemHeader['type']);
        self::assertSame('application/json', $itemHeader['content_type']);
        self::assertSame(strlen($request['lines'][2]), $itemHeader['length']);
        self::assertSame($event, json_decode($request['lines'][2], true));
    }

    public function testSendLogsPostsALogItem(): void
    {
        $transport = $this->transport();
        self::assertTrue($transport->sendLogs('test-host', [['level' => 'info', 'message' => 'hello']]));
        $transport->close();

        $request = $this->received()[0];
        $header = json_decode($request['lines'][0], true);
        $itemHeader = json_decode($request['lines'][1], true);
        $payload = json_decode($request['lines'][2], true);

        self::assertArrayNotHasKey('event_id', $header);
        self::assertSame('log', $itemHeader['type']);
        self::assertSame(1, $itemHeader['item_count']);
        self::assertSame('application/vnd.splatty.items.log+json', $itemHeader['content_type']);
        self::assertSame('test-host', $payload['host']);
        self::assertSame('hello', $payload['items'][0]['message']);
    }

    public function testSendLogsSkipsAnEmptyBatch(): void
    {
        $transport = $this->transport();
        self::assertTrue($transport->sendLogs('h', []));
        $transport->close();

        self::assertSame([], $this->received());
    }

    public function testConnectionsAreReusedAcrossSends(): void
    {
        $transport = $this->transport();
        $transport->sendEnvelope(['event_id' => 'a']);
        $transport->sendEnvelope(['event_id' => 'b']);
        $transport->close();

        self::assertCount(2, $this->received());
    }

    public function testTransportFailureIsReportedNotThrown(): void
    {
        $warnings = [];
        $transport = $this->transport([
            'url' => 'http://127.0.0.1:1',
            'openTimeoutMs' => 300,
            'readTimeoutMs' => 300,
            'logger' => static function (string $message) use (&$warnings): void { $warnings[] = $message; },
        ]);

        self::assertFalse($transport->sendEnvelope(['event_id' => 'x']));
        $transport->close();

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('transport failure', $warnings[0]);
    }

    public function testUnexpectedStatusIsReported(): void
    {
        $warnings = [];
        $transport = $this->transport([
            'url' => 'http://127.0.0.1:' . self::$port . '/fail',
            'logger' => static function (string $message) use (&$warnings): void { $warnings[] = $message; },
        ]);

        self::assertFalse($transport->sendEnvelope(['event_id' => 'x']));
        $transport->close();

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('unexpected status 500', $warnings[0]);
    }
}
