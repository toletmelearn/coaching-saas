@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.audit.heading')" :subtitle="__('platform.admin.audit.intro')">
        <x-slot:actions>
            <form method="GET" action="{{ url('/admin/audit') }}" class="flex flex-wrap items-center gap-2">
                <label for="audit-action" class="sr-only">{{ __('platform.admin.audit.filter_action') }}</label>
                <select name="action" id="audit-action" class="rounded-md border border-gray-300 px-3 py-2">
                    <option value="">{{ __('platform.admin.audit.all_actions') }}</option>
                    @foreach ($actions as $option)
                        <option value="{{ $option }}" @selected($action === $option)>{{ str_replace('_', ' ', $option) }}</option>
                    @endforeach
                </select>
                <x-button type="submit" variant="secondary">{{ __('platform.admin.audit.apply') }}</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if ($logs->isEmpty())
        <p class="rounded-md border border-gray-200 p-4 text-sm text-gray-600">{{ __('platform.admin.audit.empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600">
                        <th class="py-2 pr-3 font-medium">{{ __('platform.admin.audit.time') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('platform.admin.audit.admin') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('platform.admin.audit.action') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('platform.admin.audit.target') }}</th>
                        <th class="py-2 font-medium">{{ __('platform.admin.audit.ip') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap py-2 pr-3 text-gray-600">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="py-2 pr-3">
                                @if ($log->admin !== null)
                                    {{ $log->admin->name }} <span class="text-gray-500">({{ $log->admin->email }})</span>
                                @else
                                    <span class="text-gray-500">{{ __('platform.admin.audit.unknown_admin', ['id' => $log->admin_id]) }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 font-medium">{{ str_replace('_', ' ', $log->action) }}</td>
                            <td class="py-2 pr-3 font-mono text-xs break-all">
                                @if ($log->target_type !== null)
                                    {{ $log->target_type }}{{ $log->target_id !== null ? ' #'.$log->target_id : '' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 font-mono text-xs text-gray-600">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $logs->links() }}
    @endif
@endsection
