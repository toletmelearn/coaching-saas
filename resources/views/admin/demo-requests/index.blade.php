@extends('layouts.app')

@section('content')
    <x-page-header :title="__('platform.admin.demo_requests.heading')" />

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.name') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.phone') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.email') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.institute_name') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.city') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.status') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.created_at') }}</th>
                    <th class="py-2 pr-2">{{ __('platform.admin.demo_requests.columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($demoRequests as $demoRequest)
                <tr class="border-b border-gray-100">
                    <td class="py-2 pr-2">{{ $demoRequest->name }}</td>
                    <td class="py-2 pr-2">{{ $demoRequest->phone }}</td>
                    <td class="py-2 pr-2">{{ $demoRequest->email }}</td>
                    <td class="py-2 pr-2">{{ $demoRequest->institute_name }}</td>
                    <td class="py-2 pr-2">{{ $demoRequest->city }}</td>
                    <td class="py-2 pr-2">{{ __('platform.admin.demo_requests.statuses.'.$demoRequest->status->value) }}</td>
                    <td class="py-2 pr-2">{{ $demoRequest->created_at->toDateString() }}</td>
                    <td class="py-2 pr-2">
                        @if ($demoRequest->status->value === 'new')
                            <form method="POST" action="{{ url('/admin/demo-requests/'.$demoRequest->id.'/mark-contacted') }}">
                                @csrf
                                <x-button variant="secondary" class="!min-h-[36px] !py-0 !px-3 text-sm">{{ __('platform.admin.demo_requests.mark_contacted') }}</x-button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="py-2" colspan="8">{{ __('platform.admin.demo_requests.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $demoRequests->links() }}
@endsection
