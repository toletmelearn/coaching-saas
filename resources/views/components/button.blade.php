@props(['variant' => 'primary', 'type' => 'submit', 'size' => 'md'])

@php
    // `class` is merged last so callers can still add `w-full` or a margin without
    // having to repeat the base set. Order matters only for CSS output here, not
    // for correctness — utilities and `.ui-btn-*` live in different layers.
    $base = match ($variant) {
        'secondary' => 'ui-btn ui-btn-secondary',
        'ghost' => 'ui-btn ui-btn-ghost',
        'danger' => 'ui-btn ui-btn-danger',
        default => 'ui-btn ui-btn-primary',
    };

    if ($size === 'sm') {
        $base .= ' ui-btn-sm';
    }
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge(['class' => $base]) }}
>{{ $slot }}</button>
