@extends('layouts.app')

@section('content')
    <x-page-header :title="__('import.sheet.heading')" />

    <p class="mb-4 text-sm text-gray-600 print:hidden">{{ __('import.sheet.expires_note') }}</p>

    <div class="mb-4 flex flex-wrap gap-2 print:hidden">
        <a href="{{ url('/users/import/sheet/'.$token.'/download') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
            {{ __('import.sheet.download') }}
        </a>
        <button type="button" onclick="window.print()" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 rounded-md font-medium text-base bg-gray-100 text-gray-800 hover:bg-gray-200">
            {{ __('import.sheet.print') }}
        </button>
        <form method="POST" action="{{ url('/users/import/sheet/'.$token.'/clear') }}">
            @csrf
            <x-button variant="danger">{{ __('import.sheet.clear_now') }}</x-button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('import.sheet.name') }}</th>
                    <th class="py-2 pr-2">{{ __('import.sheet.login') }}</th>
                    <th class="py-2 pr-2">{{ __('import.sheet.password') }}</th>
                    <th class="py-2 pr-2 print:hidden">{{ __('import.sheet.whatsapp') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        $message = __('import.whatsapp_message', [
                            'name' => $row['name'],
                            'institute' => $tenant->name,
                            'url' => url('/'),
                            'login' => $row['login'],
                            'password' => $row['password'],
                        ]);
                        $waUrl = $row['phone'] ? 'https://wa.me/91'.$row['phone'].'?text='.rawurlencode($message) : null;
                    @endphp
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-2">{{ $row['name'] }}</td>
                        <td class="py-2 pr-2">{{ $row['login'] }}</td>
                        <td class="py-2 pr-2 font-mono">{{ $row['password'] }}</td>
                        <td class="py-2 pr-2 print:hidden">
                            @if ($waUrl)
                                <a href="{{ $waUrl }}" class="text-indigo-600 underline">{{ __('import.sheet.whatsapp') }}</a>
                            @endif
                            <button type="button" class="text-indigo-600 underline ml-2" data-copy-text="{{ $message }}" onclick="navigator.clipboard.writeText(this.dataset.copyText)">
                                {{ __('import.sheet.copy') }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
