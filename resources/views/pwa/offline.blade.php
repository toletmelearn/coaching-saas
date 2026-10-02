@extends('layouts.app')

@section('content')
    <div class="ui-rise" style="max-width: 34rem;">
        <x-page-header :title="$tenant->name" :subtitle="__('settings.offline.message')" />

        <div class="ui-panel" style="padding: 1rem 1.125rem; display: flex; gap: 0.75rem; align-items: center;">
            <span class="ui-empty-icon" style="width: 36px; height: 36px;"><x-icon name="sparkle" :size="17" /></span>
            <p style="margin: 0; font-size: 0.9375rem; color: var(--ink-muted);">{{ __('settings.offline.message') }}</p>
        </div>
    </div>
@endsection
