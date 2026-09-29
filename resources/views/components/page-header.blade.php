@props(['title'])

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
    <h1 class="text-xl font-semibold text-gray-900">{{ $title }}</h1>

    @isset($actions)
        <div class="flex gap-2">{{ $actions }}</div>
    @endisset
</div>
