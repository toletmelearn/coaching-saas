@props(['name', 'size' => 18, 'strokeWidth' => 1.75])

@php
    /*
     | One hand-drawn outline icon set (24×24 grid, 1.75 stroke, round caps) so the
     | product never mixes icon families — and never falls back to emoji. Paths are
     | intentionally simple geometry rather than a vendored library: there is no
     | icon package in package.json, and pulling one in for eight glyphs would be
     | a worse trade than drawing them.
     */
    $paths = [
        'home' => 'M3 10.6 12 3.2l9 7.4V20a1 1 0 0 1-1 1h-5.2v-6.2H9.2V21H4a1 1 0 0 1-1-1v-9.4Z',
        'book' => 'M4 5.2A1.2 1.2 0 0 1 5.2 4H10a3 3 0 0 1 2 5.2V20a3 3 0 0 0-2-.9H5.2A1.2 1.2 0 0 1 4 17.9V5.2Zm16 0A1.2 1.2 0 0 0 18.8 4H14a3 3 0 0 0-2 5.2V20a3 3 0 0 1 2-.9h4.8a1.2 1.2 0 0 0 1.2-1.2V5.2Z',
        'users' => 'M9 11.2a3.6 3.6 0 1 0 0-7.2 3.6 3.6 0 0 0 0 7.2ZM2.6 20.4a6.4 6.4 0 0 1 12.8 0M16.8 4.4a3.4 3.4 0 0 1 0 6.6M18.4 20.4a6.5 6.5 0 0 0-1.8-4.5',
        'settings' => 'M4 7.5h6.5M14.5 7.5H20M4 16.5h3.5M11.5 16.5H20',
        'help' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm-2.4-11.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4M12 17.2h.01',
        'logout' => 'M15 12H3.6m0 0 3.9-3.9M3.6 12l3.9 3.9M10.4 4.2H17a2 2 0 0 1 2 2v11.6a2 2 0 0 1-2 2h-6.6',
        'arrow-right' => 'M4.5 12h14m0 0-5.4-5.4M18.5 12l-5.4 5.4',
        'arrow-left' => 'M19.5 12h-14m0 0 5.4-5.4M5.5 12l5.4 5.4',
        'plus' => 'M12 5v14M5 12h14',
        'check' => 'M4.8 12.6 9.6 17.4 19.2 6.6',
        'sparkle' => 'M12 3.2l1.9 5.1 5.1 1.9-5.1 1.9L12 17.2l-1.9-5.1L5 10.2l5.1-1.9L12 3.2ZM18.6 16.4l.8 2.1 2.1.8-2.1.8-.8 2.1-.8-2.1-2.1-.8 2.1-.8.8-2.1Z',
        'chart' => 'M4 20V9.6M10 20V4.4M16 20v-6.8M4 20h16',
        'play' => 'M8.4 5.6 18 12l-9.6 6.4V5.6Z',
        'shield' => 'M12 3.4 4.8 6.2v5.2c0 4.3 3 8.3 7.2 9.2 4.2-.9 7.2-4.9 7.2-9.2V6.2L12 3.4Z',
        'mail' => 'M4 7.2 12 12.6l8-5.4M5.4 6h13.2A1.4 1.4 0 0 1 20 7.4v9.2a1.4 1.4 0 0 1-1.4 1.4H5.4A1.4 1.4 0 0 1 4 16.6V7.4A1.4 1.4 0 0 1 5.4 6Z',
        'phone' => 'M7.6 4.4h8.8a1.6 1.6 0 0 1 1.6 1.6v12a1.6 1.6 0 0 1-1.6 1.6H7.6A1.6 1.6 0 0 1 6 18V6a1.6 1.6 0 0 1 1.6-1.6ZM10.4 17.2h3.2',
        'document' => 'M13.8 3.6H7.4A1.6 1.6 0 0 0 5.8 5.2v13.6a1.6 1.6 0 0 0 1.6 1.6h9.2a1.6 1.6 0 0 0 1.6-1.6V8.6l-4.8-5Zm0 0v5h4.8M9 13.4h6M9 16.6h4',
        'inbox' => 'M4 13.4 6.6 5.8A1.6 1.6 0 0 1 8.1 4.7h7.8a1.6 1.6 0 0 1 1.5 1.1L20 13.4v4.4a1.6 1.6 0 0 1-1.6 1.6H5.6A1.6 1.6 0 0 1 4 17.8v-4.4Zm0 0h4.4l1.2 2.4h4.8l1.2-2.4H20',
        'pulse' => 'M3 12h4.2l2.4-6.4L13.6 18l2.5-6H21',
        'archive' => 'M3.6 7.4h16.8M5.4 7.4V5.8A1.4 1.4 0 0 1 6.8 4.4h10.4a1.4 1.4 0 0 1 1.4 1.4v1.6M6.6 7.4v11a1.6 1.6 0 0 0 1.6 1.6h7.6a1.6 1.6 0 0 0 1.6-1.6v-11M10 11.2h4',
        'terminal' => 'M5 7.2 9.6 11.8 5 16.4M12.6 16.4H19',
        'list' => 'M9.2 7h10.4M9.2 12h10.4M9.2 17h10.4M4.6 7h.02M4.6 12h.02M4.6 17h.02',
    ];

    $path = $paths[$name] ?? $paths['sparkle'];
    $box = (string) $size;
@endphp

<svg
    {{ $attributes->merge([
        'width' => $box,
        'height' => $box,
        'viewBox' => '0 0 24 24',
        'fill' => 'none',
        'stroke' => 'currentColor',
        'stroke-width' => (string) $strokeWidth,
        'stroke-linecap' => 'round',
        'stroke-linejoin' => 'round',
        'aria-hidden' => 'true',
        'focusable' => 'false',
    ]) }}
>
    <path d="{{ $path }}" />
</svg>
