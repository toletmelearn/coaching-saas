@php($rowUser = $rowUser)
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
