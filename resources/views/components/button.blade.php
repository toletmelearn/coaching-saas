@props(['variant' => 'primary', 'type' => 'submit'])

@php
    $classes = match ($variant) {
        'secondary' => 'bg-gray-100 text-gray-800 hover:bg-gray-200 focus:ring-gray-400',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
        default => 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500',
    };
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge(['class' => "inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base focus:outline-none focus:ring-2 focus:ring-offset-2 $classes"]) }}
>{{ $slot }}</button>
