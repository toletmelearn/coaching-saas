@extends('layouts.app')

@section('content')
    <x-page-header :title="__('import.preview.heading')" />

    <p class="mb-4 text-sm text-gray-700">
        {{ __('import.preview.ok_count', ['count' => $ok]) }}
        &middot;
        {{ __('import.preview.problem_count', ['count' => $problems]) }}
    </p>

    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('import.preview.columns.row') }}</th>
                    <th class="py-2 pr-2">{{ __('import.preview.columns.name') }}</th>
                    <th class="py-2 pr-2">{{ __('import.preview.columns.phone') }}</th>
                    <th class="py-2 pr-2">{{ __('import.preview.columns.email') }}</th>
                    <th class="py-2 pr-2">{{ __('import.preview.columns.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-2">{{ $index + 1 }}</td>
                        <td class="py-2 pr-2">{{ $row['name'] }}</td>
                        <td class="py-2 pr-2">{{ $row['phone'] }}</td>
                        <td class="py-2 pr-2">{{ $row['email'] }}</td>
                        <td class="py-2 pr-2">{{ __('import.status.'.$row['status']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ url('/users/import/confirm') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-button>{{ __('import.preview.confirm', ['count' => $ok]) }}</x-button>
    </form>
@endsection
