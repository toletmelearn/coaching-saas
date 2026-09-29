@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.institutes.create.heading')" />

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/admin/institutes') }}">
        @csrf

        <x-field name="name" :label="__('platform.admin.institutes.create.name')" :value="old('name')" required />
        <x-field name="subdomain" :label="__('platform.admin.institutes.create.subdomain')" :value="old('subdomain')" required />
        <x-field name="owner_name" :label="__('platform.admin.institutes.create.owner_name')" :value="old('owner_name')" required />
        <x-field name="owner_email" :label="__('platform.admin.institutes.create.owner_email')" :value="old('owner_email')" />
        <x-field name="owner_phone" :label="__('platform.admin.institutes.create.owner_phone')" :value="old('owner_phone')" />

        <x-button>{{ __('platform.admin.institutes.create.submit') }}</x-button>
    </form>
@endsection
