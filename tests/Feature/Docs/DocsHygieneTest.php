<?php

/**
 * Phase 16 §4 — documentation hygiene. Hard-coded test/phase counts rot the moment a test is
 * added, so README and PROJECT_BRIEF must point at `composer test` instead, and the README
 * must carry the CI badge for .github/workflows/ci.yml.
 */
function p16Doc(string $relative): string
{
    $path = base_path($relative);
    expect(file_exists($path))->toBeTrue("{$relative} must exist");

    return (string) file_get_contents($path);
}

test('README points at composer test and has no hard-coded test counts (positive control: the command is documented)', function () {
    $readme = p16Doc('README.md');

    expect($readme)->toContain('composer test');
    expect($readme)->not->toMatch('/\b\d[\d,]*\s+(?:tests?|passed)\b/');
});

test('PROJECT_BRIEF has no hard-coded test counts (positive control: it still documents composer test)', function () {
    $brief = p16Doc('PROJECT_BRIEF.md');

    expect($brief)->toContain('composer test');
    expect($brief)->not->toMatch('/\b\d[\d,]*\s+(?:tests?|passed)\b/');
});

test('README carries the CI badge for the ci.yml workflow (positive control: the README exists and is non-empty)', function () {
    $readme = p16Doc('README.md');

    expect(strlen($readme))->toBeGreaterThan(100);
    expect($readme)->toContain('actions/workflows/ci.yml/badge.svg');
});
