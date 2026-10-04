@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.backups.heading')" :subtitle="__('platform.admin.backups.intro')">
        <x-slot:actions>
            <form method="POST" action="{{ url('/admin/backups/run') }}">
                @csrf
                <x-button type="submit">{{ __('platform.admin.backups.run') }}</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if (session('backup_output'))
        <details class="mb-6 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('platform.admin.backups.output') }}</summary>
            <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap">{{ session('backup_output') }}</pre>
        </details>
    @endif

    @if ($files->isEmpty())
        <p class="rounded-md border border-gray-200 p-4 text-sm text-gray-600">{{ __('platform.admin.backups.empty') }}</p>
    @else
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-gray-600">
                    <th class="py-2 pr-3 font-medium">{{ __('platform.admin.backups.file') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('platform.admin.backups.size') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('platform.admin.backups.modified') }}</th>
                    <th class="py-2 font-medium">{{ __('platform.admin.demo_requests.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($files as $file)
                    <tr>
                        <td class="py-2 pr-3 font-mono text-xs break-all">{{ $file['name'] }}</td>
                        <td class="py-2 pr-3">{{ number_format($file['size'] / 1024 / 1024, 1) }} MB</td>
                        <td class="py-2 pr-3">{{ \Illuminate\Support\Carbon::createFromTimestamp($file['modified'])->format('Y-m-d H:i') }}</td>
                        <td class="py-2">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ url('/admin/backups/download?file='.urlencode($file['name'])) }}" class="ui-btn ui-btn-ghost ui-btn-sm">{{ __('platform.admin.backups.download') }}</a>
                                <form method="POST" action="{{ url('/admin/backups/delete') }}" onsubmit="return confirm(@js(__('platform.admin.backups.confirm_delete')))">
                                    @csrf
                                    <input type="hidden" name="file" value="{{ $file['name'] }}">
                                    <x-button type="submit" variant="danger" size="sm">{{ __('platform.admin.backups.delete') }}</x-button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
