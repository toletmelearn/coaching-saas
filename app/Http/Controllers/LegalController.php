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

        return view('legal.privacy', [
            'noticeVersion' => Consent::NOTICE_VERSION,
            'tenant' => $tenant,
        ]);
    }

    public function terms(): View
    {
        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->get() : null;

        return view('legal.terms', [
            'tenant' => $tenant,
        ]);
    }
}
