@extends('layouts.app')

@section('content')
    <x-page-header :title="__('admin_2fa.setup.heading')" />

    <p class="mb-4 text-sm text-gray-700">{{ __('admin_2fa.setup.help') }}</p>

    <p class="mb-4 text-sm"><span class="text-gray-600">{{ __('admin_2fa.setup.secret') }}:</span>
        <code class="font-mono break-all">{{ $secret }}</code></p>

    <p class="mb-4 text-xs text-gray-500 break-all"><a href="{{ $uri }}" class="text-indigo-600 underline">{{ $uri }}</a></p>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/two-factor/setup') }}">
        @csrf
        @if ($replacing)
            <p class="mb-4 text-sm text-gray-700">{{ __('admin_2fa.setup.replace_help') }}</p>
            <x-field name="current_code" :label="__('admin_2fa.setup.current_code')" required />
        @endif
        <x-field name="code" :label="__('admin_2fa.challenge.heading')" required />
        <x-button>{{ __('admin_2fa.setup.submit') }}</x-button>
    </form>
@endsection
