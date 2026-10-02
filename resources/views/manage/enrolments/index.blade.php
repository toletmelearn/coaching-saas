@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.enrolments').': '.$course->title">
        <x-slot:actions>
            <x-link href="{{ url('/manage/courses/'.$course->id) }}" variant="ghost">
                <x-icon name="arrow-left" :size="16" />
                {{ __('courses.manage.edit') }}
            </x-link>
        </x-slot:actions>
    </x-page-header>

    @if (session('enrolment_summary'))
        <x-alert tone="success">{{ session('enrolment_summary') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert tone="danger">
            <ul style="margin: 0; padding-left: 1.125rem; list-style: disc;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('users.columns.name') }}</th>
                    <th scope="col">{{ __('users.columns.status') }}</th>
                    <th scope="col">{{ __('courses.manage.starts_at') }}</th>
                    <th scope="col">{{ __('courses.manage.ends_at') }}</th>
                    <th scope="col">{{ __('courses.manage.payment_note') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('users.columns.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($enrolments as $enrolment)
                <tr>
                    <td style="font-weight: 650;">{{ $enrolment->user->name }}</td>
                    <td>
                        <x-badge :tone="$enrolment->status->value === 'active' ? 'success' : 'danger'">
                            {{ __('courses.manage.enrolment_statuses.'.$enrolment->status->value) }}
                        </x-badge>
                    </td>
                    <td>{{ $enrolment->starts_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                    <td>{{ $enrolment->ends_at ? $enrolment->ends_at->timezone('Asia/Kolkata')->format('d M Y') : __('courses.manage.no_expiry') }}</td>
                    <td>{{ $enrolment->payment_note }}</td>
                    <td>
                        @if ($enrolment->status->value === 'active')
                            <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/revoke') }}" onsubmit="return confirm(@js(__('courses.manage.confirm_revoke')))">
                                @csrf
                                <x-button variant="danger" size="sm">{{ __('courses.manage.revoke') }}</x-button>
                            </form>
                        @else
                            <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/reenrol') }}">
                                @csrf
                                <x-button variant="secondary" size="sm">{{ __('courses.manage.reenrol') }}</x-button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="color: var(--ink-subtle);">{{ __('courses.manage.no_enrolments_yet') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $enrolments->links() }}

    <div class="ui-card ui-rise" style="padding: 1.25rem; margin-top: 1.5rem;">
        <div class="ui-section-title">
            <h2 class="ui-h2">{{ __('courses.manage.enrol_students') }}</h2>
        </div>

        <form method="GET" action="{{ url('/manage/courses/'.$course->id.'/enrolments') }}" style="display: flex; gap: 0.625rem; margin-bottom: 1.25rem;">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('courses.manage.search_students') }}" class="ui-input" style="min-height: 44px;">
            <x-button type="submit" variant="secondary">
                <x-icon name="users" :size="16" />
                {{ __('courses.manage.search_students') }}
            </x-button>
        </form>

        <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/enrolments') }}">
            @csrf

            <div class="ui-inset" style="padding: 0.5rem 0.875rem; margin-bottom: 1.25rem;">
                @forelse ($students as $student)
                    <x-checkbox name="user_ids[]" :value="$student->id" :label="$student->name.' ('.($student->email ?? $student->phone).')'" />
                @empty
                    <p class="ui-subtle" style="margin: 0.75rem 0;">{{ __('courses.manage.no_students_found') }}</p>
                @endforelse
            </div>

            <div style="display: grid; gap: 0 1.25rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));">
                <x-field name="starts_at" type="date" :label="__('courses.manage.starts_at')" :value="now()->toDateString()" />
                <x-field name="ends_at" type="date" :label="__('courses.manage.ends_at')" :value="optional(app(\App\Support\TenantContext::class)->get()->academic_year_end)->toDateString()" />
                <x-field name="payment_note" :label="__('courses.manage.payment_note')" :value="null" />
            </div>

            <x-button>
                <x-icon name="plus" :size="17" />
                {{ __('courses.manage.enrol_students') }}
            </x-button>
        </form>
    </div>
@endsection
