@extends('layouts.app')

@section('content')
    <div class="ui-rise" style="max-width: 34rem;">
        <span class="ui-kicker">{{ __('platform.admin.nav.dashboard') }}</span>
        <h1 class="ui-h1">{{ __('platform.suspended.heading') }}</h1>
        <p class="ui-sub">{{ __('platform.suspended.message') }}</p>
    </div>
@endsection
