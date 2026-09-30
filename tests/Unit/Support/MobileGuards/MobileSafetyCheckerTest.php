<?php

use Tests\Support\MobileSafetyChecker;

function wrapPage(string $body): string
{
    return '<!doctype html><html><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        ."<body>{$body}</body></html>";
}

// === Bad HTML is flagged ===

test('an unwrapped table with no responsive hiding is flagged', function () {
    $html = wrapPage('<table class="w-full"><tr><td>Row</td></tr></table>');

    $violations = MobileSafetyChecker::violations($html);

    expect($violations)->toContain('a <table> is neither overflow-x-auto wrapped nor hidden below sm');
});

test('a class with a fixed width above 340px is flagged', function () {
    $html = wrapPage('<div class="w-[400px]">Too wide</div>');

    $violations = MobileSafetyChecker::violations($html);

    expect($violations)->toContain('fixed width above 340px: w-[400px]');
});

test('a missing viewport meta tag is flagged', function () {
    $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>No viewport</body></html>';

    $violations = MobileSafetyChecker::violations($html);

    expect($violations)->toContain('missing viewport meta tag');
});

test('a page with all three problems reports all three violations', function () {
    $html = '<!doctype html><html><head><meta charset="utf-8"></head>'
        .'<body><div class="w-[500px]"></div><table><tr><td>x</td></tr></table></body></html>';

    $violations = MobileSafetyChecker::violations($html);

    expect($violations)->toHaveCount(3);
});

// === Good HTML is not flagged (positive controls for each rule) ===

test('a table wrapped in overflow-x-auto is not flagged', function () {
    $html = wrapPage('<div class="overflow-x-auto"><table class="w-full"><tr><td>Row</td></tr></table></div>');

    expect(MobileSafetyChecker::violations($html))->toBe([]);
});

test('a table hidden below sm is not flagged', function () {
    $html = wrapPage('<table class="hidden sm:table w-full"><tr><td>Row</td></tr></table>');

    expect(MobileSafetyChecker::violations($html))->toBe([]);
});

test('a width class of exactly 340px is not flagged (boundary)', function () {
    $html = wrapPage('<div class="w-[340px]"></div>');

    expect(MobileSafetyChecker::violations($html))->toBe([]);
});

test('a page with no tables, no fixed widths, and a viewport meta is fully safe', function () {
    $html = wrapPage('<div class="max-w-3xl mx-auto px-4">Fine</div>');

    expect(MobileSafetyChecker::isSafe($html))->toBeTrue();
});
