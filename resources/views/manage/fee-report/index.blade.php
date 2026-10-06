@extends('layouts.app')

@section('content')
    <x-page-header title="Fee Report">
        <x-slot:actions>
            <x-link href="{{ url('/manage/fee-report/export') }}" variant="ghost" size="sm">
                Export CSV
            </x-link>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-bottom: 1.5rem;">
        <table class="ui-table">
            <thead>
                <tr>
                    <th scope="col">Course</th>
                    <th scope="col">Paid enrolments</th>
                    <th scope="col">Total collected (₹)</th>
                    <th scope="col">Unpaid enrolments</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($courses as $course)
                <tr>
                    <td>{{ $course->title }}</td>
                    <td>{{ $course->paid_count }}</td>
                    <td>{{ number_format($course->total_paise / 100, 2) }}</td>
                    <td>{{ $course->unpaid_count }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="color: var(--ink-subtle);">No courses found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($monthly->isNotEmpty())
        <div style="margin-top: 2rem;">
            <h2 class="ui-h2">Monthly collections</h2>

            <div class="overflow-x-auto ui-table-wrap ui-fade" style="margin-top: 1rem;">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th scope="col">Month</th>
                            <th scope="col">Total collected (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monthly as $row)
                            <tr>
                                <td>{{ $row->month }}</td>
                                <td>{{ number_format($row->total_paise / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
