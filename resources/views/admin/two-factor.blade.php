@extends('layouts.app')

@section('content')
    <x-page-header :title="__('admin_2fa.challenge.heading')" />

    <p class="mb-4 text-sm text-gray-700">{{ __('admin_2fa.challenge.help') }}</p>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/two-factor') }}">
        @csrf
        <x-field name="code" :label="__('admin_2fa.challenge.heading')" required />
        <x-button>{{ __('admin_2fa.challenge.submit') }}</x-button>
    </form>
@endsection
