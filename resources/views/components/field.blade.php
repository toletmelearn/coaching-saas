@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'hint' => null])

@php
    $hasError = $errors->has($name);
    // aria-invalid + aria-describedby give assistive tech the same signal the red
    // border gives sighted users — the error must not be colour-only.
    $describedBy = $hasError ? $name.'-error' : ($hint ? $name.'-hint' : null);
@endphp

<div class="mb-5">
    <label for="{{ $name }}" class="ui-label">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" style="color: var(--brand-ink)">*</span>
        @endif
    </label>

    @if ($type === 'textarea')
        <textarea
            name="{{ $name }}"
            id="{{ $name }}"
            @if ($required) required @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->merge(['class' => 'ui-input']) }}
        >{{ old($name, $value) }}</textarea>
    @else
        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $name }}"
            @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
            @if ($required) required @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->merge(['class' => 'ui-input']) }}
        >
    @endif

    @if ($hint)
        <p id="{{ $name }}-hint" class="ui-help">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="ui-error">
            <x-icon name="help" :size="15" />
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
