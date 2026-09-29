@props(['name', 'label', 'checked' => false, 'value' => '1', 'id' => null])

@php
    $isArrayName = str_ends_with($name, '[]');
    $baseName = $isArrayName ? substr($name, 0, -2) : $name;

    $fieldId = $id ?? 'checkbox-'.\Illuminate\Support\Str::slug($baseName.'-'.$value);

    if ($isArrayName) {
        // old($baseName) is null only when the form hasn't been submitted before (no
        // validation failure to redisplay) — in that case fall back to the $checked prop.
        // Once it *has* been submitted, an unchecked box simply isn't in the old array, so
        // membership is the correct "was this one ticked" test either way.
        $oldValues = old($baseName);
        $isChecked = $oldValues !== null
            ? in_array((string) $value, array_map('strval', (array) $oldValues), true)
            : $checked;
    } else {
        $isChecked = old($name, $checked);
    }
@endphp

<label for="{{ $fieldId }}" class="flex items-center gap-3 py-2 min-h-[44px] cursor-pointer select-none">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $fieldId }}"
        value="{{ $value }}"
        @checked($isChecked)
        {{ $attributes->merge(['class' => 'h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500']) }}
    >
    <span class="text-base">{{ $label }}</span>
</label>
