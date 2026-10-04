@extends('layouts.app')

@section('content')
    <x-page-header :title="__('help.heading')" />

    <div class="ui-fade" style="display: grid; gap: 1rem;">
        @php
            // The original sections live under help.sections/help.body; the Phase
            // 12.1 live-class trio lives in its own help.live_classes group. Both
            // are folded into one ordered map here so every section — old or new —
            // renders through exactly the markup below.
            $sections = __('help.sections');
            $bodies = __('help.body');

            foreach (array_keys(__('help.live_classes.sections')) as $liveKey) {
                $sections['live_classes.'.$liveKey] = __('help.live_classes.sections.'.$liveKey);
                $bodies['live_classes.'.$liveKey] = __('help.live_classes.body.'.$liveKey);
            }

            // Phase 15 — a plain-language section about student data, folded in
            // with the same additive pattern as the live-classes trio above.
            $sections['consents'] = __('consents.help_title');
            $bodies['consents'] = __('consents.help_body');
        @endphp
        @foreach ($sections as $key => $label)
            <section class="ui-card" style="padding: 1.125rem 1.25rem;">
                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                    <span class="ui-empty-icon" style="flex: none;"><x-icon name="help" :size="17" /></span>
                    <div class="min-w-0">
                        <h2 class="ui-h2" style="margin-bottom: 0.25rem;">{{ $label }}</h2>
                        <p style="margin: 0; color: var(--ink-muted); font-size: 0.9375rem;">{{ $bodies[$key] }}</p>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
@endsection
