@extends('layouts.app')

@section('content')
    <x-page-header :kicker="$student->name" :title="__('consents.data_heading')">
        <x-slot:actions>
            <x-link href="{{ url('/manage/students/'.$student->id.'/consents') }}" variant="ghost">
                <x-icon name="arrow-left" :size="16" />
                {{ __('consents.list_heading') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    {{-- Export: the DPDP right to access — everything this student's record
         holds, as a JSON download scoped to this one student. --}}
    <div class="ui-panel ui-fade" style="padding: 1.125rem 1.25rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
        <span class="ui-h2">{{ __('consents.export_button') }}</span>
        <x-link href="{{ url('/manage/students/'.$student->id.'/data/export') }}" variant="primary" size="sm">
            <x-icon name="document" :size="15" />
            {{ __('consents.export_button') }}
        </x-link>
    </div>

    {{-- Erasure: irreversible, so the confirmation is the student's own name. --}}
    <h2 class="ui-h2" style="margin: 0 0 0.75rem;">{{ __('consents.erase_heading') }}</h2>

    <x-alert tone="danger" style="margin-bottom: 1rem;">
        {{ __('consents.erase_warning') }}
    </x-alert>

    <form method="POST" action="{{ url('/manage/students/'.$student->id.'/data') }}">
        @csrf
        @method('DELETE')

        <x-field name="confirmation" :label="__('consents.fields.confirmation')" required />

        <x-button variant="danger">{{ __('consents.erase_submit') }}</x-button>
    </form>
@endsection
