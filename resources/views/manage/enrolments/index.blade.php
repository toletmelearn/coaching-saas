@extends('layouts.app')

@section('content')
    <x-page-header :title="__('courses.manage.enrolments').': '.$course->title" />

    @if (session('enrolment_summary'))
        <p class="mb-4 rounded-md bg-green-50 border border-green-300 p-3 text-green-800">{{ session('enrolment_summary') }}</p>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <tbody>
            @forelse ($enrolments as $enrolment)
                <tr class="border-b border-gray-100">
                    <td class="py-2 pr-2">{{ $enrolment->user->name }}</td>
                    <td class="py-2 pr-2">{{ __('courses.manage.enrolment_statuses.'.$enrolment->status->value) }}</td>
                    <td class="py-2 pr-2">{{ $enrolment->starts_at?->timezone('Asia/Kolkata')->format('d M Y') }}</td>
                    <td class="py-2 pr-2">{{ $enrolment->ends_at ? $enrolment->ends_at->timezone('Asia/Kolkata')->format('d M Y') : __('courses.manage.no_expiry') }}</td>
                    <td class="py-2 pr-2">{{ $enrolment->payment_note }}</td>
                    <td class="py-2 pr-2">
                        @if ($enrolment->status->value === 'active')
                            <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/revoke') }}" onsubmit="return confirm('{{ __('courses.manage.confirm_action') }}')">
                                @csrf
                                <x-button variant="danger" class="!min-h-[36px] !py-0 !px-2 text-sm">{{ __('courses.manage.revoke') }}</x-button>
                            </form>
                        @else
                            <form method="POST" action="{{ url('/manage/enrolments/'.$enrolment->id.'/reenrol') }}">
                                @csrf
                                <x-button variant="secondary" class="!min-h-[36px] !py-0 !px-2 text-sm">{{ __('courses.manage.reenrol') }}</x-button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="py-2">{{ __('courses.manage.no_enrolments_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $enrolments->links() }}

    <h2 class="text-lg font-semibold mt-6 mb-2">{{ __('courses.manage.enrol_students') }}</h2>

    <form method="GET" action="{{ url('/manage/courses/'.$course->id.'/enrolments') }}" class="mb-4 flex gap-2">
        <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('courses.manage.search_students') }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-base">
        <x-button type="submit" variant="secondary">{{ __('courses.manage.search_students') }}</x-button>
    </form>

    <form method="POST" action="{{ url('/manage/courses/'.$course->id.'/enrolments') }}">
        @csrf

        <div class="mb-4">
            @forelse ($students as $student)
                <x-checkbox name="user_ids[]" :value="$student->id" :label="$student->name.' ('.($student->email ?? $student->phone).')'" />
            @empty
                <p class="text-gray-500">{{ __('courses.manage.no_students_found') }}</p>
            @endforelse
        </div>

        <x-field name="starts_at" type="date" :label="__('courses.manage.starts_at')" :value="now()->toDateString()" />
        <x-field name="ends_at" type="date" :label="__('courses.manage.ends_at')" :value="null" />
        <x-field name="payment_note" :label="__('courses.manage.payment_note')" :value="null" />

        <x-button>{{ __('courses.manage.enrol_students') }}</x-button>
    </form>
@endsection
