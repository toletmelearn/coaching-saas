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

    // The input must stay the first thing inside the label: EnrolmentUiTest matches
    // `/<label for="…"[^>]*>\s*<input type="checkbox" name="…" id="…" value="…"/`
    // to prove each label points at its own uniquely-identified input. Never add
    // markup between the label opening tag and this input.
@endphp

<label for="{{ $fieldId }}" class="ui-check-label">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $fieldId }}"
        value="{{ $value }}"
        @checked($isChecked)
        {{ $attributes->merge(['class' => 'ui-check']) }}
    >
    <span class="ui-check-text">{{ $label }}</span>
</label>
