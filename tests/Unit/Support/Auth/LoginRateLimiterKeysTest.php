<?php

use App\Support\LoginRateLimiter;

/**
 * Rate-limit cache keys carry an HMAC of the login identifier, never the identifier itself, so a
 * cache dump holds no emails or phone numbers. The HMAC is stable for one identifier and differs
 * between identifiers.
 */
function p16Key(string $method, mixed ...$args): string
{
    $reflection = new ReflectionMethod(LoginRateLimiter::class, $method);

    return (string) $reflection->invoke(null, ...$args);
}

test('the identifier-scoped rate-limit key contains no plain email or phone (positive control: the key is stable for one identifier)', function () {
    $first = p16Key('identifierKey', 1, 'Student.Person@Example.com');
    $again = p16Key('identifierKey', 1, 'student.person@example.com');

    expect($first)->not->toContain('student.person')
        ->and($first)->not->toContain('example.com')
        ->and($first)->toBe($again);
});

test('the IP-scoped rate-limit key contains no plain phone number (positive control: a different phone gives a different key)', function () {
    $phoneKey = p16Key('ipKey', 1, '9876543210', '127.0.0.1');
    $otherKey = p16Key('ipKey', 1, '9123456780', '127.0.0.1');

    expect($phoneKey)->not->toContain('9876543210')
        ->and($phoneKey)->not->toBe($otherKey);
});
