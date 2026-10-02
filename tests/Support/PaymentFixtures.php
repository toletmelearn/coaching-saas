<?php

namespace Tests\Support;

use App\Enums\PaymentStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the Phase 10 manual-UPI payment tests.
 *
 * Lives in tests/Support (PSR-4 as Tests\Support\*) rather than in a test file so that
 * every file under tests/Feature/Payments can build the same tenant/enrolment shape
 * without declaring a global function twice — Pest loads each test file in the same
 * process, so two files declaring the same helper name would be a fatal redeclaration.
 *
 * Note that setup() deliberately does NOT set courses.fee_paise: only the amount and
 * upload tests care about a fee, and keeping the fee out of the shared fixture means a
 * missing fee_paise migration surfaces exactly where it matters instead of taking every
 * payments test down with it.
 */
class PaymentFixtures
{
    /** ₹499.00 in paise — the fee a fixture course carries when a test asks for one. */
    public const FEE_PAISE = 49900;

    /**
     * Creates a tenant with a real subdomain (resolution is host-based, so a test that
     * never gives the tenant a domain can't reach any tenant route at all).
     */
    public static function tenant(string $domain = 'tenant-a.coaching.test'): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->domains()->create(['domain' => $domain, 'type' => 'subdomain']);

        return $tenant;
    }

    /**
     * An owner, a staff member, a student, a published course and that student's active
     * enrolment in it — all inside the tenant's own scope.
     *
     * @param  array<string, mixed>  $courseAttributes
     * @return array{owner: User, staff: User, student: User, course: Course, enrolment: Enrolment}
     */
    public static function people(Tenant $tenant, array $courseAttributes = []): array
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($courseAttributes) {
            $owner = User::factory()->owner()->create();
            $staff = User::factory()->staff()->create();
            $student = User::factory()->student()->create();

            $course = Course::factory()->published()->create($courseAttributes);
            $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

            return compact('owner', 'staff', 'student', 'course', 'enrolment');
        });
    }

    /**
     * The whole fixture in one call: tenant + subdomain + people.
     *
     * @param  array<string, mixed>  $courseAttributes
     * @return array{tenant: Tenant, domain: string, owner: User, staff: User, student: User, course: Course, enrolment: Enrolment}
     */
    public static function setup(array $courseAttributes = [], string $domain = 'tenant-a.coaching.test'): array
    {
        $tenant = self::tenant($domain);

        return ['tenant' => $tenant, 'domain' => $domain] + self::people($tenant, $courseAttributes);
    }

    /**
     * A second student + active enrolment in an existing course — a queue or a
     * cross-student test needs more than one of them.
     *
     * @return array{student: User, enrolment: Enrolment}
     */
    public static function extraEnrolment(Tenant $tenant, Course $course): array
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($course) {
            $student = User::factory()->student()->create();
            $enrolment = Enrolment::factory()->for($course)->for($student, 'user')->active()->create();

            return compact('student', 'enrolment');
        });
    }

    /**
     * A Payment created the way production creates one: the guarded columns go through
     * forceFill() (as the action class does), only upi_reference/screenshot_path are
     * ever mass-assignable.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function payment(Tenant $tenant, Enrolment $enrolment, array $overrides = []): Payment
    {
        $attributes = [
            'enrolment_id' => $enrolment->id,
            'amount_paise' => self::FEE_PAISE,
            'screenshot_path' => 'tenants/'.$tenant->id.'/payments/'.Str::random(32).'.png',
            'status' => PaymentStatus::Pending,
            'submitted_at' => now(),
        ] + $overrides;

        return app(TenantContext::class)->runAs($tenant, function () use ($attributes) {
            $payment = new Payment;
            $payment->forceFill($attributes);
            $payment->save();

            return $payment;
        });
    }

    /**
     * Writes a real, decodable image to disk and wraps it as an UploadedFile — the
     * dimensions/format have to be exact for the bound checks, which
     * UploadedFile::fake()->image() doesn't give us. Same approach as
     * fakeImageUpload() in LogoUploadTest.
     */
    public static function image(int $width, int $height, string $format = 'png', ?string $originalName = null): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 79, 70, 229));

        $path = tempnam(sys_get_temp_dir(), 'payment').'.'.$format;

        match ($format) {
            'png' => imagepng($image, $path),
            'jpg', 'jpeg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
            'gif' => imagegif($image, $path),
        };

        imagedestroy($image);

        return new UploadedFile(
            $path,
            $originalName ?? 'screenshot.'.$format,
            mime_content_type($path) ?: null,
            null,
            true
        );
    }

    /**
     * A non-image payload (SVG, PDF, arbitrary bytes) as a real file-backed upload, so
     * the mimes rule sniffs actual content rather than a declared MIME type.
     */
    public static function file(string $contents, string $originalName, ?string $mime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'payment');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $originalName, $mime, null, true);
    }

    /**
     * A signed screenshot URL. Called outside a real HTTP request there is no request
     * to infer the host from, so URL::temporarySignedRoute() would fall back to APP_URL
     * and sign the wrong host — guaranteed 403 once the test then requests the tenant's
     * own subdomain. Pin the root to the tenant first and restore it after; mirrors
     * signedStreamUrl() in FakeStreamRouteTest.
     */
    public static function signedScreenshotUrl(string $domain, int|string $paymentId, DateTimeInterface $expiration): string
    {
        URL::forceRootUrl("http://{$domain}");

        try {
            return URL::temporarySignedRoute('payments.screenshot', $expiration, ['payment' => $paymentId]);
        } finally {
            URL::forceRootUrl((string) config('app.url'));
        }
    }
}
