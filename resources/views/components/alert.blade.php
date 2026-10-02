@props(['tone' => 'info'])

@php
    $tint = match ($tone) {
        'success' => 'ui-alert-success',
        'warning' => 'ui-alert-warning',
        'danger' => 'ui-alert-danger',
        default => 'ui-alert-info',
    };
@endphp

<div role="{{ $tone === 'danger' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'ui-alert '.$tint]) }}>
    <x-icon :name="$tone === 'success' ? 'check' : ($tone === 'danger' ? 'help' : 'sparkle')" :size="18" style="margin-top: 1px; flex: none;" />
    <div>{{ $slot }}</div>
</div>
