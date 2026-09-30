@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.index.heading')">
        <x-slot:actions>
            @can('create', \App\Models\User::class)
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

    <div class="overflow-x-auto">
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
                        <div class="flex flex-col gap-2">
                            @can('resetPassword', $rowUser)
                                <form method="POST" action="{{ url('/users/'.$rowUser->id.'/reset-password') }}">
                                    @csrf
                                    <x-button variant="secondary" class="w-full">{{ __('users.actions.reset_password') }}</x-button>
                                </form>
                            @endcan

                            @if ($rowUser->status->value === 'active')
                                @can('disable', $rowUser)
                                    <form method="POST" action="{{ url('/users/'.$rowUser->id.'/disable') }}" onsubmit="return confirm(@js(__('users.actions.confirm_disable')))">
                                        @csrf
                                        <x-button variant="danger" class="w-full">{{ __('users.actions.disable') }}</x-button>
                                    </form>
                                @endcan
                            @else
                                @can('enable', $rowUser)
                                    <form method="POST" action="{{ url('/users/'.$rowUser->id.'/enable') }}">
                                        @csrf
                                        <x-button variant="secondary" class="w-full">{{ __('users.actions.enable') }}</x-button>
                                    </form>
                                @endcan
                            @endif
                        </div>
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
