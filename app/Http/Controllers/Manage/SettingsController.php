<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Branding\LogoUploadService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function show(): View
    {
        $tenant = $this->tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        return view('manage.settings.edit', ['tenant' => $tenant]);
    }

    public function update(Request $request): RedirectResponse
    {
        // The tenant acted on is always the one resolved from the request's own
        // hostname — never anything the client could supply (id, tenant_id, ...).
        $tenant = $this->tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        $presets = config('coaching.theme_presets', []);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'theme_color' => ['sometimes', 'string', 'regex:/^#[0-9a-f]{6}$/i', 'in:'.implode(',', $presets)],
            'academic_year_end' => ['nullable', 'date', 'before_or_equal:'.now()->addYears(3)->toDateString()],
            'max_devices_per_student' => ['sometimes', 'integer', 'between:1,3'],
        ], [
            'name.required' => __('settings.validation.name_required'),
            'name.max' => __('settings.validation.name_max'),
            'contact_email.email' => __('settings.validation.email_invalid'),
            'theme_color.regex' => __('settings.validation.theme_color_invalid'),
            'theme_color.in' => __('settings.validation.theme_color_invalid'),
            'academic_year_end.date' => __('settings.validation.academic_year_end_invalid'),
            'academic_year_end.before_or_equal' => __('settings.validation.academic_year_end_too_far'),
            'max_devices_per_student.between' => __('settings.validation.max_devices_per_student_invalid'),
        ]);

        $data = $validator->validate();

        // 'nullable' skips further rule checks on an empty string, but validate()
        // still hands the empty string back rather than null — normalize each
        // optional field explicitly before it reaches forceFill.
        $blankToNull = fn (?string $value): ?string => ($value === null || $value === '') ? null : $value;

        $phone = $blankToNull($data['contact_phone'] ?? null);

        $tenant->forceFill([
            'name' => $data['name'],
            'contact_phone' => $phone !== null ? User::normalizePhone($phone) : null,
            'contact_email' => $blankToNull($data['contact_email'] ?? null),
            'theme_color' => $data['theme_color'] ?? $tenant->theme_color,
            'academic_year_end' => $blankToNull($data['academic_year_end'] ?? null),
            'max_devices_per_student' => $data['max_devices_per_student'] ?? $tenant->max_devices_per_student,
        ])->save();

        return redirect('/manage/settings')->with('status', __('settings.saved'));
    }

    public function storeLogo(Request $request, LogoUploadService $logos): RedirectResponse
    {
        $tenant = $this->tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        $maxKb = (int) config('coaching.max_logo_mb', 2) * 1024;

        $validator = Validator::make($request->all(), [
            // No svg here, deliberately — see SECURITY.md ("no SVG" for logos).
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.$maxKb],
        ], [
            'logo.required' => __('settings.logo_errors.required'),
            'logo.mimes' => __('settings.logo_errors.format'),
            'logo.max' => __('settings.logo_errors.too_large', ['max' => config('coaching.max_logo_mb', 2)]),
        ]);

        if ($validator->fails()) {
            return redirect('/manage/settings')->withErrors($validator);
        }

        $file = $validator->validated()['logo'];

        if ($dimensionError = $logos->dimensionError($file)) {
            return redirect('/manage/settings')->withErrors(['logo' => $dimensionError]);
        }

        $oldPath = $tenant->logo_path;
        $newPath = $logos->store($tenant->id, $file);

        $tenant->forceFill([
            'logo_path' => $newPath,
            'branding_version' => $tenant->branding_version + 1,
        ])->save();

        // Only delete the old file after the new one is committed, so a failure
        // partway through never leaves the tenant with no logo file at all.
        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect('/manage/settings')->with('status', __('settings.saved'));
    }

    public function destroyLogo(): RedirectResponse
    {
        $tenant = $this->tenantContext->get();
        Gate::authorize('manageSettings', $tenant);

        $oldPath = $tenant->logo_path;

        $tenant->forceFill([
            'logo_path' => null,
            'branding_version' => $tenant->branding_version + 1,
        ])->save();

        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect('/manage/settings')->with('status', __('settings.saved'));
    }
}
