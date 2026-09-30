@extends('layouts.app')

@section('content')
    <x-page-header :title="__('help.heading')" />

    <div class="space-y-6">
        @foreach (__('help.sections') as $key => $label)
            <div class="border-b border-gray-200 pb-4">
                <h2 class="font-semibold text-gray-900 mb-1">{{ $label }}</h2>
                <p class="text-sm text-gray-700">{{ __('help.body.'.$key) }}</p>
            </div>
        @endforeach
    </div>
@endsection
