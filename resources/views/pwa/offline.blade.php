@extends('layouts.app')

@section('content')
    <x-page-header :title="$tenant->name" />

    <p class="text-base text-gray-700">{{ __('settings.offline.message') }}</p>
@endsection
