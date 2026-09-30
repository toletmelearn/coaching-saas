<?php

namespace App\Support\Csv;

/**
 * Neutralises spreadsheet formula injection: a cell beginning with =, +, -, @, a tab, or
 * a carriage return is prefixed with a single quote, matching the standard mitigation
 * (OWASP CSV Injection) so Excel/Sheets never evaluates it as a formula on open.
 */
class CsvFormulaGuard
{
    public static function escape(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
