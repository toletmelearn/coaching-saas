<?php

use App\Support\Admin\EnvFile;
use Dotenv\Dotenv;

/**
 * Batch 1 (D): a value written through the .env editor must come back byte-for-byte when the real
 * Dotenv parser loads the file. Double-quoted values are interpolated by Dotenv, so "$" must not
 * reach it unescaped.
 */
function b1RoundTrip(string $value): string
{
    // Loaded the way Laravel loads .env (Dotenv::createMutable + load(), which interpolates
    // ${VAR}), not Dotenv::parse(), which does not interpolate and would hide the bug.
    $dir = sys_get_temp_dir().'/b1-env-'.uniqid('', true);
    mkdir($dir);
    $path = $dir.'/.env';
    file_put_contents($path, "APP_NAME=Coaching\n");
    $_ENV['APP_KEY'] = 'base64:fixture-app-key';

    try {
        (new EnvFile($path))->set('MAIL_PASSWORD', $value);

        unset($_ENV['MAIL_PASSWORD']);
        Dotenv::createMutable($dir)->load();

        return (string) $_ENV['MAIL_PASSWORD'];
    } finally {
        unset($_ENV['MAIL_PASSWORD'], $_ENV['APP_KEY']);
        @unlink($path);
        @rmdir($dir);
    }
}

test('an SMTP password containing a dollar sign round-trips exactly', function () {
    expect(b1RoundTrip('pa$$word1'))->toBe('pa$$word1');
});

test('a value containing ${APP_KEY} is not expanded by the parser', function () {
    expect(b1RoundTrip('x${APP_KEY}y'))->toBe('x${APP_KEY}y');
});

test('a value with a bare $VAR reference to a defined variable is not expanded by the parser', function () {
    expect(b1RoundTrip('pre$APP_KEY'))->toBe('pre$APP_KEY');
});

test('a value with an apostrophe and a dollar sign round-trips exactly', function () {
    expect(b1RoundTrip("it's \$ough"))->toBe("it's \$ough");
});

test('quotes, backslashes, spaces and hash signs round-trip exactly', function () {
    $value = 'he said "hi" \\ path # not a comment';

    expect(b1RoundTrip($value))->toBe($value);
});

test('positive control: a plain value round-trips', function () {
    expect(b1RoundTrip('plainvalue123'))->toBe('plainvalue123');
});
