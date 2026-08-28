<?php

declare(strict_types=1);

namespace Splatty\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Splatty\LineCache;

final class LineCacheTest extends BaseTestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/splatty-linecache-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->path = $this->dir . '/sample.php';
        $this->write(array_map(static fn (int $i): string => "line {$i}", range(1, 10)));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param list<string> $lines */
    private function write(array $lines): void
    {
        file_put_contents($this->path, implode("\n", $lines) . "\n");
        clearstatcache(true, $this->path);
    }

    public function testReturnsSurroundingLines(): void
    {
        $context = (new LineCache())->context($this->path, 5, 2);

        self::assertNotNull($context);
        self::assertSame(['line 3', 'line 4'], $context['pre_context']);
        self::assertSame('line 5', $context['context_line']);
        self::assertSame(['line 6', 'line 7'], $context['post_context']);
    }

    public function testClampsAtFileBoundaries(): void
    {
        $cache = new LineCache();

        $first = $cache->context($this->path, 1, 3);
        self::assertNotNull($first);
        self::assertSame([], $first['pre_context']);
        self::assertSame('line 1', $first['context_line']);
        self::assertSame(['line 2', 'line 3', 'line 4'], $first['post_context']);

        $last = $cache->context($this->path, 10, 3);
        self::assertNotNull($last);
        self::assertSame(['line 7', 'line 8', 'line 9'], $last['pre_context']);
        self::assertSame('line 10', $last['context_line']);
        self::assertSame([], $last['post_context']);
    }

    public function testGivesUpOnWhatItCannotRead(): void
    {
        $cache = new LineCache();

        self::assertNull($cache->context($this->dir . '/missing.php', 3, 2));
        self::assertNull($cache->context($this->dir, 3, 2));
        self::assertNull($cache->context($this->path, 99, 2));
        self::assertNull($cache->context($this->path, 0, 2));
        self::assertNull($cache->context($this->path, null, 2));
        self::assertNull($cache->context(null, 3, 2));
        self::assertNull($cache->context('', 3, 2));
        self::assertNull($cache->context($this->path, 3, 0));
    }

    public function testRereadsAFileThatChanged(): void
    {
        $cache = new LineCache();
        self::assertSame('line 5', $cache->context($this->path, 5, 1)['context_line']);

        $this->write(array_map(static fn (int $i): string => "changed {$i}", range(1, 10)));
        touch($this->path, time() + 2);
        clearstatcache(true, $this->path);

        self::assertSame('changed 5', $cache->context($this->path, 5, 1)['context_line']);
    }

    public function testTruncatesLongLinesWithoutBreakingUtf8(): void
    {
        $this->write([str_repeat('a', LineCache::MAX_LINE_LENGTH - 1) . 'é']);

        $line = (new LineCache())->context($this->path, 1, 1)['context_line'];

        self::assertSame(LineCache::MAX_LINE_LENGTH - 1, strlen($line));
        self::assertSame(1, preg_match('//u', $line));
    }

    public function testSkipsOversizedFiles(): void
    {
        file_put_contents($this->path, str_repeat("a\n", LineCache::MAX_FILE_BYTES));
        clearstatcache(true, $this->path);

        self::assertNull((new LineCache())->context($this->path, 1, 1));
    }

    public function testHandlesCrlfSources(): void
    {
        file_put_contents($this->path, "one\r\ntwo\r\nthree\r\n");
        clearstatcache(true, $this->path);

        $context = (new LineCache())->context($this->path, 2, 1);

        self::assertSame(['one'], $context['pre_context']);
        self::assertSame('two', $context['context_line']);
        self::assertSame(['three'], $context['post_context']);
    }

    public function testKeepsPayloadEncodable(): void
    {
        file_put_contents($this->path, "\$caf\xc3\x28 = 1;\n");
        clearstatcache(true, $this->path);

        $context = (new LineCache())->context($this->path, 1, 1);

        self::assertNotNull($context);
        self::assertNotFalse(json_encode($context));
    }

    public function testBoundsTheNumberOfCachedFiles(): void
    {
        $cache = new LineCache();
        for ($i = 0; $i <= LineCache::MAX_FILES; ++$i) {
            $path = $this->dir . "/f{$i}.php";
            file_put_contents($path, "line 1\n");
            self::assertNotNull($cache->context($path, 1, 1));
        }

        $files = (new \ReflectionProperty(LineCache::class, 'files'))->getValue($cache);
        self::assertLessThanOrEqual(LineCache::MAX_FILES, count($files));
    }
}
