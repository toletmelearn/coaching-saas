<?php

namespace Tests\Support;

/**
 * Checks rendered HTML against the Phase 9 360px mobile-safety rules (docs/specs/
 * phase-9-brief.md, "Mobile guard tests"): every <table> sits inside an overflow-x-auto
 * wrapper or is hidden below `sm`; no class carries a fixed width above 340px; the
 * viewport meta tag is present. Pure string-in/array-out so it can be unit tested against
 * synthetic HTML without booting the framework.
 */
class MobileSafetyChecker
{
    public const MAX_FIXED_WIDTH_PX = 340;

    /**
     * @return list<string> human-readable violation descriptions; empty when the page is safe.
     */
    public static function violations(string $html): array
    {
        $violations = [];

        if (! str_contains($html, 'name="viewport"')) {
            $violations[] = 'missing viewport meta tag';
        }

        if (str_contains($html, '<table')) {
            $wrapped = preg_match('/overflow-x-auto[^>]*>\s*<table/s', $html) === 1;
            $hiddenBelowSm = preg_match('/class="[^"]*\bhidden\b[^"]*\bsm:(table|block)\b[^"]*"[^>]*>\s*<table/s', $html) === 1
                || preg_match('/<table[^>]*class="[^"]*\bhidden\b[^"]*\bsm:table\b/s', $html) === 1;

            if (! $wrapped && ! $hiddenBelowSm) {
                $violations[] = 'a <table> is neither overflow-x-auto wrapped nor hidden below sm';
            }
        }

        preg_match_all('/class="([^"]*)"/', $html, $classMatches);
        foreach ($classMatches[1] as $classAttr) {
            foreach (explode(' ', $classAttr) as $class) {
                if (preg_match('/^w-\[(\d+)px\]$/', $class, $m) && (int) $m[1] > self::MAX_FIXED_WIDTH_PX) {
                    $violations[] = "fixed width above 340px: {$class}";
                }
            }
        }

        return $violations;
    }

    public static function isSafe(string $html): bool
    {
        return self::violations($html) === [];
    }
}
