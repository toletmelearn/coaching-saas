<?php

namespace App\Support\Devices;

class DeviceLabelParser
{
    private const FALLBACK = 'Unknown device';

    /**
     * Browser patterns checked in order (Edge/Samsung/Opera before Chrome/Safari, since
     * their user agents also contain "Chrome"/"Safari" tokens for compatibility).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const BROWSERS = [
        ['Edg/', 'Edge'],
        ['SamsungBrowser/', 'Samsung Internet'],
        ['OPR/', 'Opera'],
        ['Firefox/', 'Firefox'],
        ['Chrome/', 'Chrome'],
        ['Version/', 'Safari'], // Safari's own UA has no "Safari/x.y" version token, only "Version/x.y"
        ['Safari/', 'Safari'],
    ];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const OPERATING_SYSTEMS = [
        ['Android', 'Android'],
        ['iPhone', 'iOS'],
        ['iPad', 'iOS'],
        ['iOS', 'iOS'],
        ['Windows', 'Windows'],
        ['Macintosh', 'macOS'],
        ['Mac OS X', 'macOS'],
        ['Linux', 'Linux'],
    ];

    public function parse(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return self::FALLBACK;
        }

        $browser = $this->match(self::BROWSERS, $userAgent);
        $os = $this->match(self::OPERATING_SYSTEMS, $userAgent);

        if ($browser === null || $os === null) {
            return self::FALLBACK;
        }

        return "{$browser} on {$os}";
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $patterns
     */
    private function match(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as [$needle, $label]) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
