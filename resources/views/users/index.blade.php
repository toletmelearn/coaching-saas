@extends('layouts.app')

@section('content')
    <x-page-header :title="__('users.index.heading')">
        <x-slot:actions>
            @can('create', \App\Models\User::class)
                <x-link href="{{ url('/users/import') }}">
                    <x-icon name="document" :size="17" />
                    {{ __('users.index.import') }}
                </x-link>
                <x-link href="{{ url('/users/create') }}" variant="primary">
                    <x-icon name="plus" :size="17" />
                    {{ __('users.index.new') }}
                </x-link>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if (session('temporary_password'))
        <div class="ui-alert ui-alert-success ui-rise">
            <x-icon name="sparkle" :size="18" style="margin-top: 2px; flex: none;" />
            <div style="width: 100%;">
                @if (session('temporary_password_for'))
                    <p style="margin: 0 0 0.375rem; font-weight: 650;">{{ __('users.temporary_password_for', session('temporary_password_for')) }}</p>
                @endif
                <p style="margin: 0 0 0.25rem; font-size: 0.875rem;">{{ __('users.temporary_password') }}</p>
                <p class="ui-mono" style="margin: 0; font-size: 1.625rem; font-weight: 700; letter-spacing: 0.06em; word-break: break-all;">{{ session('temporary_password') }}</p>
                <p style="margin: 0.625rem 0 0; font-size: 0.875rem; font-weight: 600;">{{ __('users.temporary_password_share_warning') }}</p>
            </div>
        </div>
    @endif

    {{-- Below sm: one card per person. From sm up: the table. Both render the same
         users/_actions partial so the action set can never drift between the two.
         `display` must come from the class list, never an inline style: an inline
         declaration would outrank `sm:hidden` and show both renderings at once. --}}
    <div class="sm:hidden ui-fade grid gap-3">
        @forelse ($users as $rowUser)
            <div class="ui-card" style="padding: 1rem;">
                <a href="{{ url('/users/'.$rowUser->id) }}" class="ui-h2" style="text-decoration: none; display: block;">{{ $rowUser->name }}</a>

                <div style="display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.5rem;">
                    <x-badge tone="brand">{{ __('users.roles.'.$rowUser->role->value) }}</x-badge>
                    <x-badge :tone="$rowUser->status->value === 'active' ? 'success' : 'warning'">{{ __('users.statuses.'.$rowUser->status->value) }}</x-badge>
                </div>

                <div style="margin-top: 0.625rem; font-size: 0.875rem; color: var(--ink-muted);">
                    @if ($rowUser->phone)
                        <div style="display: flex; align-items: center; gap: 0.375rem;">
                            <x-icon name="phone" :size="14" />{{ $rowUser->phone }}
                        </div>
                    @endif
                    @if ($rowUser->email)
                        <div style="display: flex; align-items: center; gap: 0.375rem;">
                            <x-icon name="mail" :size="14" />{{ $rowUser->email }}
                        </div>
                    @endif
                </div>

                @if ($rowUser->role->value === 'student')
                    <div class="ui-subtle" style="margin-top: 0.5rem;">
                        {{ __('users.devices.count', ['count' => $rowUser->active_device_count]) }}
                        —
                        {{ $rowUser->last_device_active_at
                            ? __('users.devices.last_active', ['time' => \Illuminate\Support\Carbon::parse($rowUser->last_device_active_at)->diffForHumans()])
                            : __('users.devices.never_active') }}
                    </div>
                @endif

                <div style="margin-top: 0.75rem;">
                    @include('users._actions', ['rowUser' => $rowUser])
                </div>
            </div>
        @empty
            <div class="ui-empty" style="border: 0;">
                <span class="ui-empty-icon"><x-icon name="users" :size="20" /></span>
                <p class="ui-h2" style="margin: 0;">{{ __('users.index.empty') }}</p>
            </div>
        @endforelse
    </div>

    <div class="hidden sm:block overflow-x-auto ui-table-wrap ui-fade">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('users.columns.name') }}</th>
                    <th scope="col">{{ __('users.columns.phone') }}</th>
                    <th scope="col">{{ __('users.columns.email') }}</th>
                    <th scope="col">{{ __('users.columns.role') }}</th>
                    <th scope="col">{{ __('users.columns.status') }}</th>
                    <th scope="col">{{ __('devices.heading') }}</th>
                    <th scope="col">{{ __('users.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($users as $rowUser)
                <tr>
                    <td><a href="{{ url('/users/'.$rowUser->id) }}" class="ui-link">{{ $rowUser->name }}</a></td>
                    <td>{{ $rowUser->phone }}</td>
                    <td>{{ $rowUser->email }}</td>
                    <td><x-badge tone="brand">{{ __('users.roles.'.$rowUser->role->value) }}</x-badge></td>
                    <td><x-badge :tone="$rowUser->status->value === 'active' ? 'success' : 'warning'">{{ __('users.statuses.'.$rowUser->status->value) }}</x-badge></td>
                    <td>
                        @if ($rowUser->role->value === 'student')
                            <div>{{ __('users.devices.count', ['count' => $rowUser->active_device_count]) }}</div>
                            <div class="ui-subtle">
                                {{ $rowUser->last_device_active_at
                                    ? __('users.devices.last_active', ['time' => \Illuminate\Support\Carbon::parse($rowUser->last_device_active_at)->diffForHumans()])
                                    : __('users.devices.never_active') }}
                            </div>
                            <a href="{{ url('/users/'.$rowUser->id.'/devices') }}" class="ui-link" style="font-size: 0.8125rem;">{{ __('users.devices.manage_link') }}</a>
                        @endif
                    </td>
                    <td>
                        @include('users._actions', ['rowUser' => $rowUser])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="color: var(--ink-subtle);">{{ __('users.index.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
@endsection
