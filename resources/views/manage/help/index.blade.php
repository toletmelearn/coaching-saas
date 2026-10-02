@extends('layouts.app')

@section('content')
    <x-page-header :title="__('help.heading')" />

    <div class="ui-fade" style="display: grid; gap: 1rem;">
        @foreach (__('help.sections') as $key => $label)
            <section class="ui-card" style="padding: 1.125rem 1.25rem;">
                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                    <span class="ui-empty-icon" style="flex: none;"><x-icon name="help" :size="17" /></span>
                    <div class="min-w-0">
                        <h2 class="ui-h2" style="margin-bottom: 0.25rem;">{{ $label }}</h2>
                        <p style="margin: 0; color: var(--ink-muted); font-size: 0.9375rem;">{{ __('help.body.'.$key) }}</p>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
@endsection
