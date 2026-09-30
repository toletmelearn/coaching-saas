@extends('layouts.app')

@section('content')
    <x-page-header :title="__('devices.heading').' — '.$targetUser->name" />

    @if (session('status'))
        <div class="mb-4 rounded-md border border-green-300 bg-green-50 p-3 text-sm">{{ session('status') }}</div>
    @endif

    @if ($switchingOften)
        <div class="mb-4 rounded-md border-2 border-amber-400 bg-amber-50 p-3 text-sm font-medium">
            {{ __('devices.switching_often') }}
        </div>
    @endif

    <div class="mb-4">
        <form method="POST" action="{{ url('/users/'.$targetUser->id.'/devices/sign-out-all') }}" onsubmit="return confirm(@js(__('devices.actions.confirm_sign_out_all')))">
            @csrf
            <x-button variant="danger">{{ __('devices.actions.sign_out_all') }}</x-button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('devices.columns.label') }}</th>
                    <th class="py-2 pr-2">{{ __('devices.columns.first_seen') }}</th>
                    <th class="py-2 pr-2">{{ __('devices.columns.last_seen') }}</th>
                    <th class="py-2 pr-2">{{ __('devices.columns.status') }}</th>
                    <th class="py-2 pr-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($devices as $device)
                <tr class="border-b border-gray-100">
                    <td class="py-2 pr-2">{{ $device->label }}</td>
                    <td class="py-2 pr-2">{{ $device->first_seen_at?->diffForHumans() }}</td>
                    <td class="py-2 pr-2">{{ $device->last_seen_at?->diffForHumans() }}</td>
                    <td class="py-2 pr-2">
                        @if ($device->revoked_at === null)
                            {{ __('devices.status.active') }}
                        @else
                            {{ __('devices.status.signed_out') }} &middot; {{ __('devices.reasons.'.$device->revoked_reason->value) }}
                        @endif
                    </td>
                    <td class="py-2 pr-2">
                        @if ($device->revoked_at === null)
                            <form method="POST" action="{{ url('/users/'.$targetUser->id.'/devices/'.$device->id.'/sign-out') }}" onsubmit="return confirm(@js(__('devices.actions.confirm_sign_out_one')))">
                                @csrf
                                <x-button variant="secondary">{{ __('devices.actions.sign_out_one') }}</x-button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="py-2" colspan="5">{{ __('devices.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
