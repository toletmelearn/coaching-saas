@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.institutes.heading')">
        <x-slot:actions>
            <a href="{{ url('/admin/institutes/create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-indigo-600 text-white hover:bg-indigo-700">
                {{ __('platform.admin.institutes.new') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ url('/admin/institutes') }}" class="mb-4">
        <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('platform.admin.institutes.search_placeholder') }}" class="w-full rounded-md border border-gray-300 px-3 py-2 min-h-[44px]">
    </form>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.name') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.domain') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.status') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.owner') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.students') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.courses') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.institutes.columns.created_at') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($tenants as $tenant)
                @php $primaryDomain = $tenant->domains->firstWhere('is_primary', true) ?? $tenant->domains->first(); @endphp
                <tr class="border-b border-gray-100">
                    <td class="py-2 pr-2"><a href="{{ url('/admin/institutes/'.$tenant->id) }}">{{ $tenant->name }}</a></td>
                    <td class="py-2 pr-2">{{ $primaryDomain?->domain }}</td>
                    <td class="py-2 pr-2">{{ $tenant->status->value }}</td>
                    <td class="py-2 pr-2">{{ $owners[$tenant->id] ?? '—' }}</td>
                    <td class="py-2 pr-2">{{ $counts[$tenant->id]['students'] ?? 0 }}</td>
                    <td class="py-2 pr-2">{{ $counts[$tenant->id]['courses'] ?? 0 }}</td>
                    <td class="py-2 pr-2">{{ $tenant->created_at->toDateString() }}</td>
                </tr>
            @empty
                <tr>
                    <td class="py-2" colspan="7">{{ __('platform.admin.institutes.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $tenants->links() }}
@endsection
