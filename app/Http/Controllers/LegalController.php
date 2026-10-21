<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Support\TenantContext;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacy(): View
    {
        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;

        $noticeVersion = Consent::NOTICE_VERSION;
        $noticePath = resource_path('legal/notices/'.$noticeVersion.'.md');
        $noticeText = is_file($noticePath) ? file_get_contents($noticePath) : null;

        return view('legal.privacy', compact('noticeVersion', 'noticeText', 'tenant'));
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
}
