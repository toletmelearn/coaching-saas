<?php

namespace App\Support\Import;

use App\Models\User;
use InvalidArgumentException;

/**
 * Parses an uploaded CSV's raw bytes into per-row validation results, without creating
 * anything. See docs/specs/phase-9-brief.md, "Bulk import".
 */
class CsvImportPreviewBuilder
{
    /**
     * The brief's default of 100 was measured against this app's production bcrypt cost
     * (BCRYPT_ROUNDS=12, .env.example): 100 hashes took ~44s on the dev machine, well
     * over the brief's ~20s budget. Lowered to 45, which measured ~15-20s for 100
     * hashes' worth of work at that cost — see docs/specs/phase-9-pilot.md.
     */
    public const MAX_ROWS = 45;

    public const PHONE_ALIASES = ['phone', 'mobile', 'mobile number', 'phone number'];

    public const EMAIL_ALIASES = ['email', 'email id', 'e-mail'];

    /**
     * @return array{rows: list<array{name: string, phone: string, email: string, status: string}>, ok: int, problems: int}
     *
     * @throws InvalidArgumentException when the name column is missing, or there are more than MAX_ROWS data rows
     */
    public function build(string $contents): array
    {
        $contents = $this->decode($contents);
        [$headers, $dataLines] = $this->splitIntoRows($contents);

        $nameIndex = array_search('name', $headers, true);

        if ($nameIndex === false) {
            throw new InvalidArgumentException('The file has no "name" column.');
        }

        if (count($dataLines) > self::MAX_ROWS) {
            throw new InvalidArgumentException('The file has more than '.self::MAX_ROWS.' data rows.');
        }

        $phoneIndex = $this->findAliasIndex($headers, self::PHONE_ALIASES);
        $emailIndex = $this->findAliasIndex($headers, self::EMAIL_ALIASES);

        $seenPhones = [];
        $seenEmails = [];
        $rows = [];

        foreach ($dataLines as $line) {
            $name = $this->cleanName($line[$nameIndex] ?? '');
            $rawPhone = $phoneIndex !== false ? ($line[$phoneIndex] ?? '') : '';
            $rawEmail = $emailIndex !== false ? ($line[$emailIndex] ?? '') : '';

            $phone = trim($rawPhone) !== '' ? User::normalizePhone($rawPhone) : '';
            $email = trim($rawEmail) !== '' ? strtolower(trim($rawEmail)) : '';

            $status = $this->statusFor($phone, $email, $seenPhones, $seenEmails);

            if ($phone !== '') {
                $seenPhones[$phone] = true;
            }
            if ($email !== '') {
                $seenEmails[$email] = true;
            }

            $rows[] = compact('name', 'phone', 'email', 'status');
        }

        return [
            'rows' => $rows,
            'ok' => count(array_filter($rows, fn ($r) => $r['status'] === 'ok')),
            'problems' => count(array_filter($rows, fn ($r) => $r['status'] !== 'ok')),
        ];
    }

    /**
     * @param  array<string, true>  $seenPhones
     * @param  array<string, true>  $seenEmails
     */
    private function statusFor(string $phone, string $email, array $seenPhones, array $seenEmails): string
    {
        if ($phone !== '' && ! preg_match('/^[6-9][0-9]{9}$/', $phone)) {
            return 'invalid_phone';
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid_email';
        }

        if ($phone === '' && $email === '') {
            return 'missing_contact';
        }

        if (($phone !== '' && isset($seenPhones[$phone])) || ($email !== '' && isset($seenEmails[$email]))) {
            return 'duplicate_in_file';
        }

        // Tenant isolation here is automatic: User::where(...) runs through
        // BelongsToTenant's global scope, so this can only ever match a row in the
        // current tenant — a phone/email used in a different tenant never matches.
        if (($phone !== '' && User::where('phone', $phone)->exists())
            || ($email !== '' && User::where('email', $email)->exists())) {
            return 'duplicate_in_tenant';
        }

        return 'ok';
    }

    private function decode(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function splitIntoRows(string $contents): array
    {
        $contents = str_replace("\r\n", "\n", $contents);
        $allLines = explode("\n", $contents);
        $headerLine = array_shift($allLines);

        $delimiter = $this->detectDelimiter($headerLine);
        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv($headerLine, $delimiter));

        $dataLines = array_values(array_filter($allLines, fn ($line) => trim($line) !== ''));
        $rows = array_map(fn ($line) => str_getcsv($line, $delimiter), $dataLines);

        return [$headers, $rows];
    }

    private function detectDelimiter(string $headerLine): string
    {
        $counts = [
            ',' => substr_count($headerLine, ','),
            ';' => substr_count($headerLine, ';'),
            "\t" => substr_count($headerLine, "\t"),
        ];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $aliases
     */
    private function findAliasIndex(array $headers, array $aliases): int|false
    {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $headers, true);

            if ($index !== false) {
                return $index;
            }
        }

        return false;
    }

    private function cleanName(string $raw): string
    {
        $name = trim($raw);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return mb_substr($name, 0, 255);
    }
}
