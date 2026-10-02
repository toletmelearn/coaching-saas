@props(['tone' => 'neutral'])

@php
    $tint = match ($tone) {
        'brand' => 'ui-badge-brand',
        'success' => 'ui-badge-success',
        'warning' => 'ui-badge-warning',
        'danger' => 'ui-badge-danger',
        default => 'ui-badge-neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => 'ui-badge '.$tint]) }}>{{ $slot }}</span>
