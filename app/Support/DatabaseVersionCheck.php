<?php

namespace App\Support;

class DatabaseVersionCheck
{
    private const MYSQL_MINIMUM = '8.0.16';

    private const MARIADB_MINIMUM = '10.2.1';

    /**
     * Below these versions, the users.email/phone CHECK constraint (see SECURITY.md) is
     * silently parsed but never enforced — MySQL 8.0.16 and MariaDB 10.2.1 are where each
     * engine actually started enforcing CHECK constraints.
     *
     * @param  string  $versionString  The raw SELECT VERSION() result, e.g.
     *                                 "8.0.15" or "10.4.32-MariaDB".
     * @return string|null A failure message, or null if the version is supported.
     */
    public static function minimumViolationMessage(string $versionString): ?string
    {
        $isMariaDb = stripos($versionString, 'MariaDB') !== false;
        $numericVersion = preg_replace('/[^0-9.].*$/', '', $versionString) ?? $versionString;

        $minimum = $isMariaDb ? self::MARIADB_MINIMUM : self::MYSQL_MINIMUM;
        $engine = $isMariaDb ? 'MariaDB' : 'MySQL';

        if (version_compare($numericVersion, $minimum, '<')) {
            return "{$engine} {$numericVersion} is below the minimum {$minimum} — the users.email/phone CHECK constraint is silently ignored below this version (see SECURITY.md).";
        }

        return null;
    }
}
