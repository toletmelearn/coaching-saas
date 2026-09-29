<?php

namespace App\Support;

class TemporaryPasswordGenerator
{
    /**
     * Excludes visually ambiguous characters (0, o, 1, l, i) so a teacher can read the
     * password aloud or copy it from a phone screen without mistakes.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Eight random characters from ALPHABET, shown as two groups of four separated by a
     * hyphen, e.g. "hq7m-k3tp".
     */
    public static function generate(): string
    {
        $chars = '';

        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4, 4);
    }
}
