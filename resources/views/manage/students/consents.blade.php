@extends('layouts.app')

@section('content')
    <x-page-header :kicker="__('consents.list_heading')" :title="$student->name">
        <x-slot:actions>
            <x-link href="{{ url('/users/'.$student->id) }}" variant="ghost">
                <x-icon name="arrow-left" :size="16" />
                {{ __('users.index.heading') }}
            </x-link>
            <x-link href="{{ url('/manage/students/'.$student->id.'/data') }}" variant="ghost">
                {{ __('consents.data_heading') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left border-b border-gray-300">
                    <th class="py-2 pr-2">{{ __('consents.purpose_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.status_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.method_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.granted_at_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.recorded_by_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.withdrawn_at_label') }}</th>
                    <th class="py-2 pr-2">{{ __('consents.withdrawn_reason_label') }}</th>
                    <th class="py-2 pr-2">—</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($consents as $consent)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 pr-2">{{ __('consents.purposes.'.$consent->purpose) }}</td>
                        <td class="py-2 pr-2">
                            <x-badge :tone="$consent->withdrawn_at !== null ? 'warning' : 'success'">
                                {{ $consent->withdrawn_at !== null ? __('consents.status_withdrawn') : __('consents.status_granted') }}
                            </x-badge>
                        </td>
                        <td class="py-2 pr-2">{{ __('consents.methods.'.$consent->method) }}</td>
                        <td class="py-2 pr-2">{{ $consent->granted_at?->format('d M Y H:i') }}</td>
                        <td class="py-2 pr-2">{{ $consent->recorder?->name }}</td>
                        <td class="py-2 pr-2">{{ $consent->withdrawn_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="py-2 pr-2">{{ $consent->withdrawn_reason ?? '—' }}</td>
                        <td class="py-2 pr-2">
                            @unless ($consent->withdrawn_at !== null)
                                <x-link href="{{ url('/manage/students/'.$student->id.'/consents/'.$consent->id.'/withdraw') }}" size="sm">
                                    {{ __('consents.withdraw_submit') }}
                                </x-link>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-3" colspan="8" style="color: var(--ink-muted);">—</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Record a consent for one purpose, optionally refreshing the guardian
         details in the same submission (Phase 15). --}}
    <h2 class="ui-h2" style="margin: 1.5rem 0 0.75rem;">{{ __('consents.record_heading') }}</h2>

    <form method="POST" action="{{ url('/manage/students/'.$student->id.'/consents') }}">
        @csrf

        <div class="mb-5">
            <label for="purpose" class="ui-label">{{ __('consents.purpose_label') }}</label>
            <select name="purpose" id="purpose" class="ui-input" required>
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose->value }}" @selected(old('purpose') === $purpose->value)>
                        {{ __('consents.purposes.'.$purpose->value) }}
                    </option>
                @endforeach
            </select>
            @error('purpose')
                <p class="ui-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-5">
            <label for="consent_method" class="ui-label">{{ __('consents.fields.method') }}</label>
            <select name="consent_method" id="consent_method" class="ui-input" required>
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(old('consent_method') === $method->value)>
                        {{ __('consents.methods.'.$method->value) }}
                    </option>
                @endforeach
            </select>
            @error('consent_method')
                <p class="ui-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <h3 class="ui-h2" style="margin: 1rem 0 0.75rem; font-size: 1rem;">{{ __('consents.guardian_heading') }}</h3>

        <x-field name="guardian_name" :label="__('consents.fields.guardian_name')" :value="old('guardian_name', $student->guardian_name)" />
        <x-field name="guardian_relationship" :label="__('consents.fields.guardian_relationship')" :value="old('guardian_relationship', $student->guardian_relationship)" />
        <x-field name="guardian_phone" :label="__('consents.fields.guardian_phone')" :value="old('guardian_phone', $student->guardian_phone)" />
        <x-field name="guardian_email" type="email" :label="__('consents.fields.guardian_email')" :value="old('guardian_email', $student->guardian_email)" />

        <x-button>{{ __('consents.record_submit') }}</x-button>
    </form>
@endsection
