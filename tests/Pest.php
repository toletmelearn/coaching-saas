<?php

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

// toHaveAtLeast / toHaveAtMost — not in Pest 4.7.x core; added here as thin wrappers.
expect()->extend('toHaveAtLeast', function (int $count, string $message = '') {
    return expect(count((array) $this->value))->toBeGreaterThanOrEqual($count, $message);
});
expect()->extend('toHaveAtMost', function (int $count, string $message = '') {
    return expect(count((array) $this->value))->toBeLessThanOrEqual($count, $message);
});

// Phase 5 video tests talk to Bunny Stream exclusively through Http::fake(); a real
// network call escaping a fake() setup must fail loudly, not silently hit the internet.
pest()->beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature/Video');

pest()->beforeEach(function () {
    Http::preventStrayRequests();
})->in('Unit/Support/Video');

function inTenant(Tenant $tenant, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($tenant, $fn);
}

/**
 * Simulate the boundary between two real, separate HTTP requests: clears every
 * guard's in-memory cached user (as a fresh production process would have), while
 * leaving session data untouched. Call this between two $this->post()/get() calls
 * in the same test whenever the second call must resolve auth fresh from the
 * session (through the guard's user provider) rather than reusing whatever the
 * first call already resolved and cached.
 */
function freshRequestCycle(): void
{
    app('auth')->forgetGuards();
}

/**
 * Writes $content to a real temp file and wraps it as an UploadedFile, so import
 * tests exercise the same file-based validation rules (mimes, content sniffing)
 * a real multipart upload would hit — a Symfony UploadedFile backed by a string
 * has no real file to sniff.
 */
function csvUploadFile(string $content, string $filename = 'students.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $content);

    return new UploadedFile($path, $filename, 'text/csv', null, true);
}
