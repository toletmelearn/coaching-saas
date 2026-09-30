@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.index.heading')">
        <x-slot:actions>
            @can('create', \App\Models\User::class)
                <a href="{{ url('/users/import') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
                    {{ __('users.index.import') }}
                </a>
                <a href="{{ url('/users/create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
                    {{ __('users.index.new') }}
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('temporary_password'))
        <div class="mb-6 rounded-md border-2 border-green-500 bg-green-50 p-4">
            @if (session('temporary_password_for'))
                <p class="font-medium">{{ __('users.temporary_password_for', session('temporary_password_for')) }}</p>
            @endif
            <p class="text-sm text-gray-700 mb-1">{{ __('users.temporary_password') }}</p>
            <p class="text-2xl font-mono font-bold tracking-wide">{{ session('temporary_password') }}</p>
            <p class="text-sm text-red-700 mt-2">{{ __('users.temporary_password_share_warning') }}</p>
        </div>
    @endif

    {{-- Below sm: one card per person. From sm up: the table. Both render the same
         users/_actions partial so the action set can never drift between the two. --}}
    <div class="sm:hidden space-y-3">
        @forelse ($users as $rowUser)
            <div class="rounded-md border border-gray-200 p-3">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <a href="{{ url('/users/'.$rowUser->id) }}" class="font-medium">{{ $rowUser->name }}</a>
                </div>
                <div class="flex gap-2 mb-2 text-xs">
                    <span class="px-2 py-0.5 rounded-full bg-gray-100">{{ __('users.roles.'.$rowUser->role->value) }}</span>
                    <span class="px-2 py-0.5 rounded-full bg-gray-100">{{ __('users.statuses.'.$rowUser->status->value) }}</span>
                </div>
                @if ($rowUser->phone)
                    <div class="text-sm text-gray-700">{{ $rowUser->phone }}</div>
                @endif
                @if ($rowUser->email)
                    <div class="text-sm text-gray-700">{{ $rowUser->email }}</div>
                @endif
                @if ($rowUser->role->value === 'student')
                    <div class="text-xs text-gray-500 mt-1 mb-2">
                        {{ __('users.devices.count', ['count' => $rowUser->active_device_count]) }}
                        —
                        {{ $rowUser->last_device_active_at
                            ? __('users.devices.last_active', ['time' => \Illuminate\Support\Carbon::parse($rowUser->last_device_active_at)->diffForHumans()])
                            : __('users.devices.never_active') }}
                    </div>
                @endif
                <div class="mt-2">
                    @include('users._actions', ['rowUser' => $rowUser])
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('users.index.empty') }}</p>
        @endforelse
    </div>

    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('users.columns.name') }}</th>
                    <th class="py-2 pr-2">{{ __('users.columns.phone') }}</th>
                    <th class="py-2 pr-2">{{ __('users.columns.email') }}</th>
                    <th class="py-2 pr-2">{{ __('users.columns.role') }}</th>
                    <th class="py-2 pr-2">{{ __('users.columns.status') }}</th>
                    <th class="py-2 pr-2">{{ __('devices.heading') }}</th>
                    <th class="py-2 pr-2">{{ __('users.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($users as $rowUser)
                <tr class="border-b border-gray-100">
                    <td class="py-2 pr-2"><a href="{{ url('/users/'.$rowUser->id) }}">{{ $rowUser->name }}</a></td>
                    <td class="py-2 pr-2">{{ $rowUser->phone }}</td>
                    <td class="py-2 pr-2">{{ $rowUser->email }}</td>
                    <td class="py-2 pr-2">{{ __('users.roles.'.$rowUser->role->value) }}</td>
                    <td class="py-2 pr-2">{{ __('users.statuses.'.$rowUser->status->value) }}</td>
                    <td class="py-2 pr-2">
                        @if ($rowUser->role->value === 'student')
                            <div>{{ __('users.devices.count', ['count' => $rowUser->active_device_count]) }}</div>
                            <div class="text-gray-500">
                                {{ $rowUser->last_device_active_at
                                    ? __('users.devices.last_active', ['time' => \Illuminate\Support\Carbon::parse($rowUser->last_device_active_at)->diffForHumans()])
                                    : __('users.devices.never_active') }}
                            </div>
                            <a href="{{ url('/users/'.$rowUser->id.'/devices') }}" class="text-indigo-600 underline">{{ __('users.devices.manage_link') }}</a>
                        @endif
                    </td>
                    <td class="py-2 pr-2">
                        @include('users._actions', ['rowUser' => $rowUser])
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="py-2" colspan="7">{{ __('users.index.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
@endsection
