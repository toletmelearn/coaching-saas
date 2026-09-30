@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.create.heading')" />

    <form method="POST" action="{{ url('/users') }}">
        @csrf

        <x-field name="name" :label="__('users.create.name')" required />
        <x-field name="email" type="email" :label="__('users.create.email')" />
        <x-field name="phone" :label="__('users.create.phone')" />

        <div class="mb-4">
            <label for="role" class="block text-sm font-medium text-gray-700 mb-1">{{ __('users.create.role') }}</label>
            <select name="role" id="role" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base">
                <option value="student">{{ __('users.roles.student') }}</option>
                <option value="staff">{{ __('users.roles.staff') }}</option>
                <option value="owner">{{ __('users.roles.owner') }}</option>
            </select>
        </div>

        <x-button>{{ __('users.create.submit') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a href="{{ url('/users/import') }}" class="text-indigo-600 underline">{{ __('import.nav_label') }}</a>
    </p>
@endsection
