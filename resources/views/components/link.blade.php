@props(['href', 'variant' => 'secondary', 'size' => 'md'])

@php
    // Anchors need the same visual contract as <x-button>: an <a> is a link that
    // looks like a button, a <button> is an action that looks like a button, and
    // neither is ever a bare coloured word.
    $base = match ($variant) {
        'primary' => 'ui-btn ui-btn-primary',
        'ghost' => 'ui-btn ui-btn-ghost',
        'danger' => 'ui-btn ui-btn-danger',
        default => 'ui-btn ui-btn-secondary',
    };

    if ($size === 'sm') {
        $base .= ' ui-btn-sm';
    }
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $base]) }}>{{ $slot }}</a>
