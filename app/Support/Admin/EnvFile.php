<?php

namespace App\Support\Admin;

use RuntimeException;

/**
 * Programmatic, allowlist-friendly .env access for /admin/settings/system and
 * the health panel's Fix button (Phase 13). The agent still never touches .env
 * by hand — the *application* edits it, at runtime, from the UI.
 *
 * Safety properties:
 *  - set() only ever rewrites a single `KEY=value` line (append if absent):
 *    comments, ordering and every other key survive byte-for-byte.
 *  - Values containing newlines are rejected outright — that is the only way a
 *    value could inject a second KEY= line into the file.
 *  - Writes are atomic (temp file + rename), so a crash mid-write can never
 *    leave a half-written .env.
 *  - Before replacing the real base_path('.env'), a timestamped copy is kept in
 *    storage/app/private/env-backups/ (private disk, itself backed up by
 *    spatie/laravel-backup). Temp files used by tests get no such copy.
 *
 * Which keys callers may write is enforced by the controllers (explicit
 * allowlists), not here — this class is the mechanism, the allowlist is policy.
 */
class EnvFile
{
    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? base_path('.env');
    }

    /** Current value of one key as written in the file, or null when absent. */
    public function get(string $key): ?string
    {
        foreach (preg_split('/\r\n|\n|\r/', $this->read()) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! preg_match('/^(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/', $trimmed, $matches)) {
                continue;
            }

            return $this->unquote(rtrim($matches[1]));
        }

        return null;
    }

    /**
     * Every KEY=value pair in the file (comments skipped) — used by the settings
     * page to show current values.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $this->read()) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $trimmed, $matches)) {
                $values[$matches[1]] = $this->unquote(rtrim($matches[2]));
            }
        }

        return $values;
    }

    /**
     * Set (or append) one key. Throws on anything that could corrupt the file —
     * a throw becomes a 500, which is what we want: loud, not silent.
     */
    public function set(string $key, string $value): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException("Refusing to write malformed env key: {$key}");
        }

        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException("Refusing to write multi-line env value for {$key}");
        }

        $content = $this->read();
        $line = $key.'='.$this->formatValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Splice the line in by offset rather than via preg_replace's
            // replacement string: that string would interpret backslashes and
            // dollar signs in the value, silently corrupting passwords like
            // `pa$$w\ord`.
            $offset = $matches[0][1];
            $length = strlen($matches[0][0]);
            $newContent = substr($content, 0, $offset).$line.substr($content, $offset + $length);
        } else {
            $newContent = rtrim($content).PHP_EOL.$line.PHP_EOL;
        }

        $this->write($newContent);
    }

    private function read(): string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return $content === false ? '' : $content;
    }

    private function write(string $content): void
    {
        $path = $this->path();

        // Safety copy only for the real file; tests point at temp files.
        if ($path === base_path('.env') && is_file($path)) {
            $this->keepBackupCopy();
        }

        $tmp = $path.'-tmp-'.uniqid('', true);

        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Could not write temporary env file: {$tmp}");
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("Could not replace env file: {$path}");
        }
    }

    private function keepBackupCopy(): void
    {
        $dir = storage_path('app/private/env-backups');

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_dir($dir)) {
            @copy($this->path(), $dir.'/.env-'.date('Ymd-His'));

            $copies = glob($dir.'/.env-*') ?: [];
            sort($copies);

            foreach (array_slice($copies, 0, max(0, count($copies) - 20)) as $stale) {
                @unlink($stale);
            }
        }
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Single quotes are literal in Dotenv (no ${VAR} expansion), so they are preferred. Double quotes
     * interpolate "$" and "${NAME}", so they are only used for a value that contains an apostrophe,
     * and then "$" is escaped as well.
     */
    private function formatValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-\.\\/:@#%+,*]+$/', $value)) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
