@props(['title', 'icon' => 'inbox'])

<div class="ui-empty ui-fade">
    <span class="ui-empty-icon"><x-icon :name="$icon" :size="20" /></span>
    <p class="ui-h2">{{ $title }}</p>
    @isset($action)
        <div class="mt-2">{{ $action }}</div>
    @endisset
</div>
