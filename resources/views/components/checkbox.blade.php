@props(['name', 'label', 'checked' => false, 'value' => '1'])

<label for="{{ $name }}" class="flex items-center gap-3 py-2 min-h-[44px] cursor-pointer select-none">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ $value }}"
        @checked(old($name, $checked))
        {{ $attributes->merge(['class' => 'h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500']) }}
    >
    <span class="text-base">{{ $label }}</span>
</label>
