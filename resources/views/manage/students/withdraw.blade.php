@extends('layouts.app')

@section('content')
    <x-page-header :kicker="$student->name" :title="__('consents.withdraw_heading')" />

    <div class="ui-card" style="padding: 1.125rem 1.25rem; margin-bottom: 1.25rem;">
        <p class="ui-h2" style="margin: 0 0 0.375rem;">{{ __('consents.purposes.'.$consent->purpose) }}</p>
        <p class="ui-subtle" style="margin: 0;">
            {{ __('consents.granted_at_label') }}: {{ $consent->granted_at?->format('d M Y H:i') }}
        </p>
    </div>

    <form method="POST" action="{{ url('/manage/students/'.$student->id.'/consents/'.$consent->id.'/withdraw') }}">
        @csrf

        <x-field name="reason" type="textarea" :label="__('consents.fields.reason')" required />

        <x-button>{{ __('consents.withdraw_submit') }}</x-button>
    </form>
@endsection
