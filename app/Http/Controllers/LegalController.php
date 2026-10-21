<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacy(Request $request): View
    {
        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;
        $fiduciary = $tenant !== null ? $tenant->name : '[the coaching institute]';
        $platform = config('app.name', 'Coaching SaaS');

        $noticeVersion = Consent::NOTICE_VERSION;
        $locale = $this->resolvedLocale($request, $noticeVersion);
        $raw = $this->loadNoticeText($noticeVersion, $locale);
        $noticeText = $raw !== null ? $this->fillPlaceholders($raw, $fiduciary, $platform) : null;
        $hasHindi = is_file(resource_path('legal/notices/'.$noticeVersion.'.hi.md'));
        $dataCategories = config('privacy.data_categories', []);

        return view('legal.privacy', compact('noticeVersion', 'noticeText', 'tenant', 'locale', 'hasHindi', 'dataCategories'));
    }

    public function privacyArchive(Request $request, string $version): View
    {
        if (! preg_match('/^v\d+\.\d+-\d{4}-\d{2}-\d{2}$/', $version)) {
            abort(404);
        }

        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;
        $fiduciary = $tenant !== null ? $tenant->name : '[the coaching institute]';
        $platform = config('app.name', 'Coaching SaaS');

        $locale = $this->resolvedLocale($request, $version);
        $raw = $this->loadNoticeText($version, $locale);

        if ($raw === null) {
            abort(404);
        }

        $noticeText = $this->fillPlaceholders($raw, $fiduciary, $platform);
        $hasHindi = is_file(resource_path('legal/notices/'.$version.'.hi.md'));

        return view('legal.privacy-archive', compact('version', 'noticeText', 'locale', 'hasHindi', 'tenant'));
    }

    public function platformTerms(): View
    {
        return view('legal.platform-terms');
    }

    public function studentTerms(): View
    {
        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;

        return view('legal.student-terms', compact('tenant'));
    }

    public function consentWithdrawInfo(): View
    {
        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;

        return view('legal.consent-withdraw', compact('tenant'));
    }

    private function fillPlaceholders(string $text, string $fiduciary, string $platform): string
    {
        return str_replace(['{INSTITUTE_NAME}', '{PLATFORM_NAME}'], [$fiduciary, $platform], $text);
    }

    private function resolvedLocale(Request $request, string $version): string
    {
        $requested = $request->query('lang', 'en');

        if ($requested !== 'en' && is_file(resource_path('legal/notices/'.$version.'.'.$requested.'.md'))) {
            return $requested;
        }

        return 'en';
    }

    private function loadNoticeText(string $version, string $locale): ?string
    {
        if ($locale !== 'en') {
            $path = resource_path('legal/notices/'.$version.'.'.$locale.'.md');
            if (is_file($path)) {
                return file_get_contents($path) ?: null;
            }
        }

        $path = resource_path('legal/notices/'.$version.'.md');

        return is_file($path) ? (file_get_contents($path) ?: null) : null;
    }
}
