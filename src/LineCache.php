<?php

declare(strict_types=1);

namespace Splatty;

/**
 * Serves the source lines around a stack frame.
 *
 * Entries are keyed by modification time and size, so a file edited under a
 * long-running worker is re-read rather than served stale. Unreadable files are
 * remembered too, so a frame in a file we cannot read costs one stat per event
 * instead of one read.
 */
final class LineCache
{
    /** Entries past this are dropped oldest-first. */
    public const MAX_FILES = 100;

    /** Generated or vendored blobs masquerading as source are skipped. */
    public const MAX_FILE_BYTES = 524288;

    /** Keeps one minified line from dominating the payload. */
    public const MAX_LINE_LENGTH = 1000;

    /** @var array<string, array{mtime: int, size: int, lines: list<string>|null}> */
    private array $files = [];

    /**
     * @return array{pre_context: list<string>, context_line: string, post_context: list<string>}|null
     */
    public function context(?string $path, ?int $lineno, int $contextLines): ?array
    {
        if ($path === null || $path === '' || $lineno === null || $lineno < 1 || $contextLines < 1) {
            return null;
        }

        $lines = $this->linesFor($path);
        if ($lines === null) {
            return null;
        }

        $index = $lineno - 1;
        if (!array_key_exists($index, $lines)) {
            return null;
        }

        $start = max($index - $contextLines, 0);

        return [
            'pre_context' => array_values(array_slice($lines, $start, $index - $start)),
            'context_line' => $lines[$index],
            'post_context' => array_values(array_slice($lines, $index + 1, $contextLines)),
        ];
    }

    /** @return list<string>|null */
    private function linesFor(string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = (int) $stat['size'];
        if ($size === 0 || $size > self::MAX_FILE_BYTES) {
            return null;
        }

        $mtime = (int) $stat['mtime'];
        $cached = $this->files[$path] ?? null;
        if ($cached !== null && $cached['mtime'] === $mtime && $cached['size'] === $size) {
            return $cached['lines'];
        }

        while (count($this->files) >= self::MAX_FILES) {
            array_shift($this->files);
        }

        $lines = $this->read($path);
        $this->files[$path] = ['mtime' => $mtime, 'size' => $size, 'lines' => $lines];

        return $lines;
    }

    /** @return list<string>|null */
    private function read(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        // A source file is not guaranteed to be UTF-8, and json_encode drops
        // whatever is not.
        if (preg_match('//u', $contents) !== 1) {
            if (!function_exists('iconv')) {
                return null;
            }
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $contents);
            if ($converted === false) {
                return null;
            }
            $contents = $converted;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values(array_map(self::truncate(...), $lines));
    }

    private static function truncate(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_LENGTH) {
            return $line;
        }

        $cut = substr($line, 0, self::MAX_LINE_LENGTH);
        // A byte-wise cut can leave half of a multi-byte character behind,
        // which json_encode would then drop.
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
