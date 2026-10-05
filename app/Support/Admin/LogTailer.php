<?php

namespace App\Support\Admin;

/**
 * Tail/search over storage/logs/laravel.log for /admin/logs (Phase 13).
 *
 * Reads backwards from EOF so a multi-hundred-megabyte log is never loaded
 * into memory: tail() walks byte-by-byte until it has collected the requested
 * number of lines, and search() reads at most the last SEARCH_WINDOW_BYTES.
 *
 * The path is constructor-injectable (tests bind a temp file) and defaults to
 * the single-file log the app actually writes to.
 */
class LogTailer
{
    /** How far back search() scans — recent errors are what an operator wants. */
    private const SEARCH_WINDOW_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? storage_path('logs/laravel.log');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * The last $lines lines, oldest → newest.
     *
     * @return list<string>
     */
    public function tail(int $lines): array
    {
        $path = $this->path();

        if ($lines < 1 || ! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            $collected = [];
            $line = '';

            while ($position > 0 && count($collected) < $lines) {
                $position--;
                fseek($handle, $position);
                $char = fread($handle, 1);

                if ($char === "\n") {
                    if ($line !== '') {
                        array_unshift($collected, $line);
                        $line = '';
                    }

                    continue;
                }

                $line = $char.$line;
            }

            // First line of the file (no leading newline) or the final partial line.
            if (count($collected) < $lines && $line !== '') {
                array_unshift($collected, $line);
            }

            return array_map(static fn (string $l): string => rtrim($l, "\r"), $collected);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Lines containing $query (case-insensitive), chronological, capped at $max
     * matches from the tail of the search window. An empty query is treated as
     * "no filter" by the caller, not here.
     *
     * @return list<string>
     */
    public function search(string $query, int $max = 100): array
    {
        $path = $this->path();

        if (! is_file($path) || $query === '') {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $size = fseek($handle, 0, SEEK_END);
            $size = $size === 0 ? (int) ftell($handle) : 0;
            $start = max(0, $size - self::SEARCH_WINDOW_BYTES);
            fseek($handle, $start);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\r\n|\n|\r/', $chunk) ?: [];

        // When we started mid-file, the first element is a truncated line.
        if ($start > 0) {
            array_shift($lines);
        }

        $matches = [];

        foreach ($lines as $line) {
            if ($line !== '' && stripos($line, $query) !== false) {
                $matches[] = $line;
            }
        }

        return array_slice($matches, -$max);
    }

    /**
     * Replaces emails, Indian mobile numbers and long digit runs (card and account numbers) with
     * placeholders, so the log page never shows a student's contact details or payment identifiers.
     */
    public static function redact(string $line): string
    {
        $line = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $line) ?? $line;
        $line = preg_replace('/(?:\+?91[\s-]?)?\b[6-9]\d{4}[\s-]?\d{5}\b/', '[phone]', $line) ?? $line;

        return preg_replace('/\b\d{12,}\b/', '[digits]', $line) ?? $line;
    }
}
