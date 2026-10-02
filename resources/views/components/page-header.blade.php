@props(['title', 'subtitle' => null, 'kicker' => null, 'description' => null])

{{-- The one and only page heading. Kept deliberately thin so every screen opens with
     the same type scale, kicker and action placement — the most visible signal that
     the product was designed rather than assembled. Every slot used below must be
     declared in @props: an undeclared attribute stays in $attributes and is never
     extracted as a variable, so `@isset($kicker)` would silently be false. --}}
<header class="ui-page-head ui-rise">
    <div class="min-w-0">
        @if ($kicker)
            <span class="ui-kicker">{{ $kicker }}</span>
        @endif

        <h1 class="ui-h1">{{ $title }}</h1>

        @if ($subtitle)
            <p class="ui-sub">{{ $subtitle }}</p>
        @endif

        @if ($description)
            <p class="ui-sub">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</header>
