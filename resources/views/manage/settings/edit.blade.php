@extends('layouts.app')

@section('content')
    <x-page-header :title="__('settings.heading')" />

    @if (session('status'))
        <x-alert tone="success">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert tone="danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </x-alert>
    @endif

    <form method="POST" action="{{ url('/manage/settings') }}" class="ui-card ui-rise" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        @csrf
        @method('PATCH')

        <x-field name="name" :label="__('settings.fields.name')" :value="$tenant->name" required />
        <x-field name="contact_phone" :label="__('settings.fields.contact_phone')" :value="$tenant->contact_phone" />
        <x-field name="contact_email" type="email" :label="__('settings.fields.contact_email')" :value="$tenant->contact_email" />
        <x-field name="upi_id" :label="__('settings.fields.upi_id')" :value="$tenant->upi_id" :hint="__('settings.fields.upi_id_hint')" />

        <div class="mb-5">
            <span class="ui-label">{{ __('settings.fields.theme_color') }}</span>
            <div class="flex flex-wrap gap-3">
                @foreach (config('coaching.theme_presets', []) as $preset)
                    <label class="ui-swatch" title="{{ $preset }}">
                        <input
                            type="radio"
                            name="theme_color"
                            value="{{ $preset }}"
                            class="sr-only peer"
                            @checked(old('theme_color', $tenant->theme_color) === $preset)
                        >
                        <span
                            class="ui-swatch-dot"
                            style="background-color: {{ $preset }}"
                        ></span>
                    </label>
                @endforeach
            </div>
        </div>

        <x-field
            name="academic_year_end"
            type="date"
            :label="__('settings.fields.academic_year_end')"
            :value="optional($tenant->academic_year_end)->toDateString()"
            :hint="__('settings.academic_year_end_hint')"
        />

        <div class="mb-5">
            <label for="max_devices_per_student" class="ui-label">{{ __('settings.fields.max_devices_per_student') }}</label>
            <select name="max_devices_per_student" id="max_devices_per_student" class="ui-input">
                @foreach ([1, 2, 3] as $option)
                    <option value="{{ $option }}" @selected(old('max_devices_per_student', $tenant->max_devices_per_student) == $option)>{{ $option }}</option>
                @endforeach
            </select>
            <p class="ui-help">{{ __('settings.max_devices_per_student_hint') }}</p>
        </div>

        <x-button>{{ __('settings.save') }}</x-button>
    </form>

    <div class="ui-card ui-rise" style="padding: 1.25rem;">
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('settings.logo.heading') }}</h2>
        </div>

        <div class="ui-inset" style="padding: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 1rem;">
            <img src="/branding/logo?v={{ $tenant->branding_version }}" alt="" class="ui-brand-mark" style="width: 64px; height: 64px;">
            <div>
                <p class="ui-subtle" style="margin: 0;">{{ __('settings.logo.current') }}</p>
                @unless ($tenant->logo_path)
                    <p class="ui-subtle" style="margin: 0.25rem 0 0;">{{ __('settings.logo.none') }}</p>
                @endunless
            </div>
        </div>

        <form method="POST" action="{{ url('/manage/settings/logo') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" style="margin-bottom: 0.75rem;">
            @csrf
            <div class="flex-1" style="min-width: 12rem;">
                <label for="logo" class="ui-label">{{ __('settings.logo.upload') }}</label>
                <input type="file" name="logo" id="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="ui-input" style="padding-block: 0.5rem;">
            </div>
            <x-button>{{ __('settings.logo.upload') }}</x-button>
        </form>
        <p class="ui-help" style="margin-bottom: 1rem;">{{ __('settings.logo.hint') }}</p>

        @if ($tenant->logo_path)
            <form method="POST" action="{{ url('/manage/settings/logo') }}" onsubmit="return confirm(@js(__('settings.logo.confirm_remove')))">
                @csrf
                @method('DELETE')
                <x-button variant="danger">{{ __('settings.logo.remove') }}</x-button>
            </form>
        @endif
    </div>
@endsection
