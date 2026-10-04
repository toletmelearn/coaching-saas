@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.create.heading')" />

    {{-- Phase 15 — the DPDP consent notice. It sits above every field so the
         guardian's agreement is what the rest of the form is built around, and
         the version label records exactly which wording was shown. --}}
    <div class="ui-alert" style="margin-bottom: 1.25rem;">
        <div>
            <p class="ui-h2" style="margin: 0 0 0.375rem;">{{ __('consents.notice_title') }}</p>
            <p style="margin: 0 0 0.5rem; color: var(--ink-muted);">{{ __('consents.notice_body') }}</p>
            <p class="ui-subtle" style="margin: 0;">{{ __('consents.notice_version_label') }}: {{ $noticeVersion }}</p>
        </div>
    </div>

    <form method="POST" action="{{ url('/users') }}">
        @csrf

        <x-field name="name" :label="__('users.create.name')" required />
        <x-field name="email" type="email" :label="__('users.create.email')" />
        <x-field name="phone" :label="__('users.create.phone')" />

        <div class="mb-4">
            <label for="role" class="block text-sm font-medium text-gray-700 mb-1">{{ __('users.create.role') }}</label>
            <select name="role" id="role" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base">
                <option value="student">{{ __('users.roles.student') }}</option>
                <option value="staff">{{ __('users.roles.staff') }}</option>
                <option value="owner">{{ __('users.roles.owner') }}</option>
            </select>
        </div>

        {{-- Phase 15 — guardian details are required for role=student. --}}
        <h2 class="ui-h2" style="margin: 1.5rem 0 0.75rem;">{{ __('consents.guardian_heading') }}</h2>

        <x-field name="guardian_name" :label="__('consents.fields.guardian_name')" required />
        <x-field name="guardian_relationship" :label="__('consents.fields.guardian_relationship')" required />
        <x-field name="guardian_phone" :label="__('consents.fields.guardian_phone')" required />
        <x-field name="guardian_email" type="email" :label="__('consents.fields.guardian_email')" />

        {{-- One tick per purpose, each with its plain-language description.
             All four are required — the server refuses anything less. --}}
        <fieldset class="mb-5" style="border: 0; padding: 0; margin-inline: 0;">
            <legend class="ui-label" style="margin-bottom: 0.5rem;">{{ __('consents.purposes_heading') }}</legend>

            @foreach ($purposes as $purpose)
                <div style="margin-bottom: 0.625rem;">
                    <x-checkbox
                        name="consents[]"
                        :value="$purpose->value"
                        :label="__('consents.purposes.'.$purpose->value)"
                        required
                    />
                    <p class="ui-subtle" style="margin: 0.125rem 0 0 1.5rem;">{{ __('consents.purpose_descriptions.'.$purpose->value) }}</p>
                </div>
            @endforeach

            @php
                $consentErrors = collect($errors->keys())
                    ->filter(fn (string $key) => $key === 'consents' || str_starts_with($key, 'consents.'))
                    ->flatMap(fn (string $key) => $errors->get($key));
            @endphp

            @if ($consentErrors->isNotEmpty())
                <p class="ui-error" role="alert">{{ $consentErrors->first() }}</p>
            @endif
        </fieldset>

        <div class="mb-5">
            <label for="consent_method" class="block text-sm font-medium text-gray-700 mb-1">{{ __('consents.fields.method') }}</label>
            <select name="consent_method" id="consent_method" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base" required>
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(old('consent_method') === $method->value)>
                        {{ __('consents.methods.'.$method->value) }}
                    </option>
                @endforeach
            </select>
            @error('consent_method')
                <p class="ui-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <x-button>{{ __('users.create.submit') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a href="{{ url('/users/import') }}" class="text-indigo-600 underline">{{ __('import.nav_label') }}</a>
    </p>
@endsection
