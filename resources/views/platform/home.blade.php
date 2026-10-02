@extends('layouts.app')

@section('content')
    {{-- Hero --}}
    <section class="ui-rise" style="text-align: center; padding: 2.5rem 0 2rem;">
        <span class="ui-kicker">{{ config('app.name', 'Coaching SaaS') }}</span>
        <h1 class="ui-h1 is-centered" style="font-size: clamp(1.875rem, 1.4rem + 2.2vw, 3rem); margin-top: 0.5rem;">
            {{ __('platform.home.hero.heading') }}
        </h1>
        <p class="ui-sub" style="margin-inline: auto; margin-top: 0.875rem; font-size: 1.0625rem;">
            {{ __('platform.home.hero.subheading') }}
        </p>
        <a href="#demo-form" class="ui-btn ui-btn-primary" style="margin-top: 1.5rem;">
            {{ __('platform.home.hero.cta') }}
            <x-icon name="arrow-right" :size="17" />
        </a>
    </section>

    {{-- Feature blocks --}}
    <section style="padding-block: 2rem; border-top: 1px solid var(--line);">
        <div class="ui-section-title" style="justify-content: center;">
            <h2 class="ui-h1 is-centered" style="font-size: 1.375rem;">{{ __('platform.home.features.heading') }}</h2>
        </div>
        <div class="ui-fade" style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));">
            @foreach (['courses', 'notes', 'video', 'logins', 'enrolment', 'dashboard'] as $feature)
                <div class="ui-card" style="padding: 1.125rem;">
                    <span class="ui-empty-icon" style="margin-bottom: 0.75rem;">
                        <x-icon :name="$feature === 'video' ? 'play' : ($feature === 'logins' ? 'shield' : ($feature === 'dashboard' ? 'chart' : 'book'))" :size="17" />
                    </span>
                    <h3 class="ui-h2" style="margin-bottom: 0.25rem;">{{ __('platform.home.features.'.$feature.'.title') }}</h3>
                    <p style="margin: 0; font-size: 0.9375rem; color: var(--ink-muted);">{{ __('platform.home.features.'.$feature.'.body') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- How it works --}}
    <section style="padding-block: 2rem; border-top: 1px solid var(--line);">
        <div class="ui-section-title" style="justify-content: center;">
            <h2 class="ui-h1 is-centered" style="font-size: 1.375rem;">{{ __('platform.home.how_it_works.heading') }}</h2>
        </div>
        <div class="ui-fade" style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));">
            @foreach (['step_1', 'step_2', 'step_3'] as $index => $step)
                <div class="ui-card" style="padding: 1.125rem; display: flex; gap: 0.875rem; align-items: flex-start;">
                    <span class="ui-brand-mark" aria-hidden="true" style="flex: none;">{{ $index + 1 }}</span>
                    <div class="min-w-0">
                        <h3 class="ui-h2" style="margin-bottom: 0.25rem;">{{ __('platform.home.how_it_works.'.$step.'.title') }}</h3>
                        <p style="margin: 0; font-size: 0.9375rem; color: var(--ink-muted);">{{ __('platform.home.how_it_works.'.$step.'.body') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Pricing --}}
    <section style="padding-block: 2rem; border-top: 1px solid var(--line); text-align: center;">
        <h2 class="ui-h1 is-centered" style="font-size: 1.375rem; margin-bottom: 0.75rem;">{{ __('platform.home.pricing.heading') }}</h2>
        <x-badge tone="brand" style="font-size: 0.875rem; padding: 0.5rem 1rem;">{{ __('platform.home.pricing.body') }}</x-badge>
    </section>

    {{-- Demo request form --}}
    <section id="demo-form" style="padding-block: 2rem; border-top: 1px solid var(--line);">
        <div class="ui-section-title">
            <h2 class="ui-h1" style="font-size: 1.375rem;">{{ __('platform.home.demo_form.heading') }}</h2>
        </div>

        @if (session('demo_request_submitted'))
            <x-alert tone="success">{{ __('platform.home.demo_form.success') }}</x-alert>
        @else
            @if ($errors->any())
                <x-alert tone="danger">
                    <ul style="margin: 0; padding-left: 1.125rem; list-style: disc;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <div class="ui-card ui-rise" style="padding: 1.25rem;">
                <form method="POST" action="{{ url('/demo-requests') }}">
                    @csrf

                    {{-- Honeypot: hidden from real visitors via CSS; a bot that fills every field will fill this too. --}}
                    <div style="position: absolute; left: -9999px;" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div style="display: grid; gap: 0 1.25rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));">
                        <x-field name="name" :label="__('platform.home.demo_form.name')" :value="old('name')" required />
                        <x-field name="phone" :label="__('platform.home.demo_form.phone')" :value="old('phone')" required />
                        <x-field name="email" type="email" :label="__('platform.home.demo_form.email')" :value="old('email')" />
                        <x-field name="institute_name" :label="__('platform.home.demo_form.institute_name')" :value="old('institute_name')" required />
                        <x-field name="city" :label="__('platform.home.demo_form.city')" :value="old('city')" required />
                    </div>
                    <x-field name="message" type="textarea" :label="__('platform.home.demo_form.message')" :value="old('message')" />

                    <x-button class="w-full">{{ __('platform.home.demo_form.submit') }}</x-button>
                </form>
            </div>
        @endif
    </section>

    {{-- Footer --}}
    <footer style="padding-block: 1.5rem; border-top: 1px solid var(--line); text-align: center; font-size: 0.875rem; color: var(--ink-subtle);">
        {{ config('app.name', 'Coaching SaaS') }} — {{ __('platform.home.footer.text') }}
    </footer>
@endsection
