@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false])

<div class="mb-4">
    <label for="{{ $name }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>

    @if ($type === 'textarea')
        <textarea
            name="{{ $name }}"
            id="{{ $name }}"
            @if ($required) required @endif
            {{ $attributes->merge(['class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-base focus:border-indigo-500 focus:ring-indigo-500']) }}
        >{{ old($name, $value) }}</textarea>
    @else
        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $name }}"
            @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
            @if ($required) required @endif
            {{ $attributes->merge(['class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-base focus:border-indigo-500 focus:ring-indigo-500']) }}
        >
    @endif

    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
