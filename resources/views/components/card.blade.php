@props(['title' => null, 'padding' => true])

<div {{ $attributes->merge(['class' => 'ui-card '.($padding ? 'p-4 sm:p-5' : '')]) }}>
    @isset($title)
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ $title }}</h2>
            @isset($actions)
                <div class="flex flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endisset

    {{ $slot }}
</div>
