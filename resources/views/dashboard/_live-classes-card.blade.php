{{--
    The "Live classes" card shared by both dashboards (Phase 12.1).

    Expects:
      $liveNowClasses      Collection of classes running right now (live status)
      $startingSoonClasses Collection of classes starting inside the announcement window
      $manage               true for the owner/staff dashboard — adds the link to
                            the cross-course list and swaps in the owner copy

    One three-state summary: live now, next inside the window, or nothing
    scheduled. The whole card disappears while coaching.live_classes_enabled is
    false, keeping Phase 12's "the dashboard widget renders nothing" guarantee
    for a deployment that has not switched the feature on.
--}}
@if ((bool) config('coaching.live_classes_enabled'))
    @php
        $liveNowClass = $liveNowClasses->first();
        $nextClass = $startingSoonClasses->first();
    @endphp

    <div class="ui-panel ui-fade" style="padding: 1.125rem 1.25rem; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <span class="ui-empty-icon" style="flex: none;"><x-icon name="play" :size="18" /></span>
            <span class="ui-h2" style="margin: 0;">{{ __('live_classes.section_title') }}</span>
            @if ($manage)
                <span style="flex: 1;"></span>
                <x-link href="{{ url('/manage/live-classes') }}" variant="ghost" size="sm">
                    {{ __('live_classes.card.view_all') }}
                </x-link>
            @endif
        </div>

        <div style="margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between;">
            <div class="min-w-0">
                @if ($liveNowClass)
                    <p style="margin: 0; font-weight: 650;">
                        {{ __('live_classes.card.live_now', ['title' => $liveNowClass->title]) }}
                    </p>
                    <p class="ui-subtle" style="margin-top: 0.125rem;">{{ $liveNowClass->course?->title }}</p>
                @elseif ($nextClass)
                    @php
                        // A start time is announced as a clock time when it lands
                        // today, and carries its date otherwise — a class tomorrow
                        // must not read as if it were this afternoon.
                        $nextAt = $nextClass->starts_at->isToday()
                            ? $nextClass->starts_at->format('g:i A')
                            : $nextClass->starts_at->format('D d M, g:i A');
                    @endphp
                    <p style="margin: 0; font-weight: 650;">
                        {{ $manage
                            ? __('live_classes.card.next', ['title' => $nextClass->title, 'time' => $nextAt])
                            : __('live_classes.card.next_student', ['title' => $nextClass->title, 'time' => $nextAt]) }}
                    </p>
                    <p class="ui-subtle" style="margin-top: 0.125rem;">{{ $nextClass->course?->title }}</p>
                @else
                    <p style="margin: 0;">
                        {{ $manage ? __('live_classes.card.empty_owner') : __('live_classes.card.empty_student') }}
                    </p>
                @endif
            </div>

            {{-- One action, the same one the matching list would offer: Join while
                 something is running, the class page (with its countdown) while it
                 is only scheduled, nothing when there is nothing to join. --}}
            @if ($liveNowClass)
                <x-link href="{{ url('/live-classes/'.$liveNowClass->id.'/join') }}" variant="primary" size="sm">
                    {{ __('live_classes.join') }}
                </x-link>
            @elseif ($nextClass)
                <x-link href="{{ url('/live-classes/'.$nextClass->id) }}" size="sm">
                    {{ __('live_classes.upcoming') }}
                </x-link>
            @endif
        </div>
    </div>
@endif
