@extends('layouts.app')

@section('content')
    {{-- Hero --}}
    <section class="text-center py-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-3">{{ __('platform.home.hero.heading') }}</h1>
        <p class="text-gray-600 mb-6">{{ __('platform.home.hero.subheading') }}</p>
        <a href="#demo-form" class="inline-flex items-center justify-center min-h-[44px] px-6 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
            {{ __('platform.home.hero.cta') }}
        </a>
    </section>

    {{-- Feature blocks --}}
    <section class="py-8 border-t border-gray-200">
        <h2 class="text-xl font-semibold text-gray-900 mb-6 text-center">{{ __('platform.home.features.heading') }}</h2>
        <div class="grid grid-cols-1 gap-6">
            @foreach (['courses', 'notes', 'video', 'logins', 'enrolment', 'dashboard'] as $feature)
                <div class="rounded-md border border-gray-200 p-4">
                    <h3 class="font-semibold text-gray-900 mb-1">{{ __('platform.home.features.'.$feature.'.title') }}</h3>
                    <p class="text-sm text-gray-600">{{ __('platform.home.features.'.$feature.'.body') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section class="py-8 border-t border-gray-200">
        <h2 class="text-xl font-semibold text-gray-900 mb-6 text-center">{{ __('platform.home.how_it_works.heading') }}</h2>
        <div class="grid grid-cols-1 gap-6">
            @foreach (['step_1', 'step_2', 'step_3'] as $index => $step)
                <div class="flex gap-3">
                    <span class="flex-shrink-0 w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center font-semibold">{{ $index + 1 }}</span>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-1">{{ __('platform.home.how_it_works.'.$step.'.title') }}</h3>
                        <p class="text-sm text-gray-600">{{ __('platform.home.how_it_works.'.$step.'.body') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Pricing --}}
    <section class="py-8 border-t border-gray-200 text-center">
        <h2 class="text-xl font-semibold text-gray-900 mb-2">{{ __('platform.home.pricing.heading') }}</h2>
        <span class="inline-block rounded-full px-3 py-1 text-sm bg-gray-200 text-gray-700">{{ __('platform.home.pricing.body') }}</span>
    </section>

    {{-- Demo request form --}}
    <section id="demo-form" class="py-8 border-t border-gray-200">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">{{ __('platform.home.demo_form.heading') }}</h2>

        @if (session('demo_request_submitted'))
            <div class="mb-6 rounded-md border-2 border-green-500 bg-green-50 p-4">
                <p>{{ __('platform.home.demo_form.success') }}</p>
            </div>
        @else
            @if ($errors->any())
                <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/demo-requests') }}">
                @csrf

                {{-- Honeypot: hidden from real visitors via CSS; a bot that fills every field will fill this too. --}}
                <div style="position: absolute; left: -9999px;" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                </div>

                <x-field name="name" :label="__('platform.home.demo_form.name')" :value="old('name')" required />
                <x-field name="phone" :label="__('platform.home.demo_form.phone')" :value="old('phone')" required />
                <x-field name="email" :label="__('platform.home.demo_form.email')" :value="old('email')" />
                <x-field name="institute_name" :label="__('platform.home.demo_form.institute_name')" :value="old('institute_name')" required />
                <x-field name="city" :label="__('platform.home.demo_form.city')" :value="old('city')" required />
                <x-field name="message" type="textarea" :label="__('platform.home.demo_form.message')" :value="old('message')" />

                <x-button>{{ __('platform.home.demo_form.submit') }}</x-button>
            </form>
        @endif
    </section>

    {{-- Footer --}}
    <footer class="py-6 border-t border-gray-200 text-center text-sm text-gray-500">
        {{ config('app.name', 'Coaching SaaS') }} — {{ __('platform.home.footer.text') }}
    </footer>
@endsection
