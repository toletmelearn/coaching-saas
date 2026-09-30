@extends('layouts.app')

@section('content')
    <x-page-header :title="$tenant->name" />

    @if (session('temporary_password'))
        <div class="mb-6 rounded-md border-2 border-green-500 bg-green-50 p-4">
            @if (session('login_url'))
                <p class="font-medium">{{ __('platform.admin.institutes.created.login_url') }}: <a href="{{ session('login_url') }}">{{ session('login_url') }}</a></p>
            @endif
            @if (session('temporary_password_for'))
                <p class="font-medium">{{ __('users.temporary_password_for', session('temporary_password_for')) }}</p>
            @endif
            <p class="text-sm text-gray-700 mb-1">{{ __('platform.admin.institutes.created.temporary_password') }}</p>
            <p class="text-2xl font-mono font-bold tracking-wide">{{ session('temporary_password') }}</p>
            <p class="text-sm text-red-700 mt-2">{{ __('platform.admin.institutes.created.warning') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php $primaryDomain = $tenant->domains->firstWhere('is_primary', true) ?? $tenant->domains->first(); @endphp

    <dl class="mb-6 grid grid-cols-1 gap-2 text-sm">
        <div><dt class="inline font-medium">{{ __('platform.admin.institutes.show.status') }}:</dt> <dd class="inline">{{ $tenant->status->value }}</dd></div>
        <div><dt class="inline font-medium">{{ __('platform.admin.institutes.show.domain') }}:</dt> <dd class="inline">{{ $primaryDomain?->domain }}</dd></div>
        <div><dt class="inline font-medium">{{ __('platform.admin.institutes.show.students') }}:</dt> <dd class="inline">{{ $students }}</dd></div>
        <div><dt class="inline font-medium">{{ __('platform.admin.institutes.show.courses') }}:</dt> <dd class="inline">{{ $courses }}</dd></div>
        <div><dt class="inline font-medium">{{ __('platform.admin.institutes.show.created_at') }}:</dt> <dd class="inline">{{ $tenant->created_at->toDateString() }}</dd></div>
    </dl>

    <h2 class="text-lg font-semibold mb-2">{{ __('platform.admin.institutes.show.owners') }}</h2>
    <ul class="mb-6 divide-y divide-gray-100">
        @forelse ($owners as $owner)
            <li class="py-2">{{ $owner->name }} — {{ $owner->email ?? $owner->phone }}</li>
        @empty
            <li class="py-2 text-gray-500">{{ __('platform.admin.institutes.show.no_owner') }}</li>
        @endforelse
    </ul>

    <div class="flex flex-wrap gap-2 mb-6">
        @if ($tenant->status->value === 'suspended')
            <form method="POST" action="{{ url('/admin/institutes/'.$tenant->id.'/reactivate') }}" onsubmit="return confirm(@js(__('platform.admin.institutes.show.confirm_reactivate')))">
                @csrf
                <x-button>{{ __('platform.admin.institutes.show.reactivate') }}</x-button>
            </form>
        @else
            <form method="POST" action="{{ url('/admin/institutes/'.$tenant->id.'/suspend') }}" onsubmit="return confirm(@js(__('platform.admin.institutes.show.confirm_suspend')))">
                @csrf
                <x-button variant="danger">{{ __('platform.admin.institutes.show.suspend') }}</x-button>
            </form>
        @endif

        @if ($owners->count() > 1)
            <form method="POST" action="{{ url('/admin/institutes/'.$tenant->id.'/reset-owner-password') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <label for="owner_id" class="sr-only">{{ __('platform.admin.institutes.show.choose_owner') }}</label>
                <select name="owner_id" id="owner_id" class="rounded-md border border-gray-300 px-3 py-2 min-h-[44px]">
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}">{{ $owner->name }} — {{ $owner->email ?? $owner->phone }}</option>
                    @endforeach
                </select>
                <x-button variant="secondary">{{ __('platform.admin.institutes.show.reset_owner_password') }}</x-button>
            </form>
        @elseif ($owners->count() === 1)
            <form method="POST" action="{{ url('/admin/institutes/'.$tenant->id.'/reset-owner-password') }}">
                @csrf
                <x-button variant="secondary">{{ __('platform.admin.institutes.show.reset_owner_password') }}</x-button>
            </form>
        @endif
    </div>
@endsection
