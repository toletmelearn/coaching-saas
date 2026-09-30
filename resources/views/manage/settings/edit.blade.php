@extends('layouts.app')

@section('content')
    <x-page-header :title="__('settings.heading')" />

    @if (session('status'))
        <div class="mb-4 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/manage/settings') }}" class="mb-8">
        @csrf
        @method('PATCH')

        <x-field name="name" :label="__('settings.fields.name')" :value="$tenant->name" required />
        <x-field name="contact_phone" :label="__('settings.fields.contact_phone')" :value="$tenant->contact_phone" />
        <x-field name="contact_email" type="email" :label="__('settings.fields.contact_email')" :value="$tenant->contact_email" />

        <div class="mb-4">
            <span class="block text-sm font-medium text-gray-700 mb-2">{{ __('settings.fields.theme_color') }}</span>
            <div class="flex flex-wrap gap-3">
                @foreach (config('coaching.theme_presets', []) as $preset)
                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="theme_color"
                            value="{{ $preset }}"
                            class="sr-only peer"
                            @checked(old('theme_color', $tenant->theme_color) === $preset)
                        >
                        <span
                            class="block h-10 w-10 rounded-full border-2 border-transparent peer-checked:border-gray-900"
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
        />
        <p class="-mt-3 mb-4 text-sm text-gray-500">{{ __('settings.academic_year_end_hint') }}</p>

        <x-button>{{ __('settings.save') }}</x-button>
    </form>

    <div class="border-t border-gray-200 pt-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">{{ __('settings.logo.heading') }}</h2>

        <div class="mb-4">
            <p class="text-sm text-gray-600 mb-2">{{ __('settings.logo.current') }}</p>
            <img src="/branding/logo?v={{ $tenant->branding_version }}" alt="" class="h-16 w-16 rounded object-contain border border-gray-200">
            @unless ($tenant->logo_path)
                <p class="mt-2 text-sm text-gray-500">{{ __('settings.logo.none') }}</p>
            @endunless
        </div>

        <form method="POST" action="{{ url('/manage/settings/logo') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2 mb-2">
            @csrf
            <div>
                <label for="logo" class="block text-sm font-medium text-gray-700 mb-1">{{ __('settings.logo.upload') }}</label>
                <input type="file" name="logo" id="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="block text-base">
            </div>
            <x-button>{{ __('settings.logo.upload') }}</x-button>
        </form>
        <p class="text-sm text-gray-500 mb-4">{{ __('settings.logo.hint') }}</p>

        @if ($tenant->logo_path)
            <form method="POST" action="{{ url('/manage/settings/logo') }}" onsubmit="return confirm(@js(__('settings.logo.confirm_remove')))">
                @csrf
                @method('DELETE')
                <x-button variant="danger">{{ __('settings.logo.remove') }}</x-button>
            </form>
        @endif
    </div>
@endsection
