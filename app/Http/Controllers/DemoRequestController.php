<?php

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class DemoRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Honeypot: a field hidden from real visitors via CSS that a scripted bot filling
        // every field will still populate. Silently "succeed" — no error, nothing stored —
        // so the bot has no signal to adapt to.
        if ($request->filled('website')) {
            return redirect('/')->with('demo_request_submitted', true);
        }

        $key = 'demo-request:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429);
        }

        RateLimiter::hit($key, 3600);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'institute_name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => __('platform.home.demo_form.errors.name_required'),
            'phone.required' => __('platform.home.demo_form.errors.phone_required'),
            'institute_name.required' => __('platform.home.demo_form.errors.institute_name_required'),
            'city.required' => __('platform.home.demo_form.errors.city_required'),
        ]);

        $data['phone'] = User::normalizePhone($data['phone']);

        $demoRequest = new DemoRequest($data);
        $demoRequest->forceFill(['ip_address' => $request->ip()])->save();

        return redirect('/')->with('demo_request_submitted', true);
    }
}
