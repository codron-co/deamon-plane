@extends('layouts.ops')

@section('title', __('themes.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWriteGit && $hasGithubApp)
        <form method="POST" action="{{ route('ops.themes.git.connect-another') }}" data-ops-native>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('themes.git.connect_another') }}</button>
        </form>
    @elseif ($canWriteGit && $appUrlIsPublic)
        <form method="POST" action="{{ route('ops.themes.git.connect') }}" data-ops-native>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('themes.git.connect') }}</button>
        </form>
    @endif
    @if ($canSync)
        <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @include('ops.themes.partials.connections')

    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('themes.kicker') }}</span>
            <h2>{{ __('themes.catalog') }} <button class="site-hint" type="button" aria-label="{{ __('themes.lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.lede') }}</span></button></h2>
        </div>
    </div>

    <div class="sites-page" data-ops-list>
        @if (($summary ?? null) !== null)
            @php
                $visibilityTone = [
                    'public_catalog' => 'success',
                    'allowlist' => 'accent',
                    'private' => 'neutral',
                ];
                $visibilityIcon = [
                    'public_catalog' => 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM2 8h12M8 2c1.7 1.8 2.5 3.8 2.5 6S9.7 12.2 8 14c-1.7-1.8-2.5-3.8-2.5-6S6.3 3.8 8 2Z',
                    'allowlist' => 'M3 4.5h10M3 8h10M3 11.5h6M11.5 10.5l1.2 1.2 2-2.2',
                    'private' => 'M4.5 7V5.5a3.5 3.5 0 0 1 7 0V7M3.5 7h9v6.5h-9V7Z',
                ];
            @endphp
            <x-ops.metrics :label="__('themes.summary.label')" :columns="6">
                <x-ops.metric
                    :label="__('themes.summary.total')"
                    :value="$summary['total']"
                    :href="route('ops.themes')"
                    icon="M2.5 4.5 8 2l5.5 2.5L8 7 2.5 4.5ZM2.5 8 8 10.5 13.5 8M2.5 11.5 8 14l5.5-2.5"
                    data-ops-list-view="summary-total"
                />
                @foreach ($visibilities as $option)
                    <x-ops.metric
                        :label="$option->label()"
                        :value="$summary['visibility'][$option->value] ?? 0"
                        :tone="$visibilityTone[$option->value] ?? 'neutral'"
                        :href="route('ops.themes', ['visibility' => $option->value])"
                        :icon="$visibilityIcon[$option->value] ?? null"
                        :detail="$summary['total'] > 0 ? __('sites.summary.of_total', ['count' => $summary['visibility'][$option->value] ?? 0, 'total' => $summary['total']]) : null"
                        data-ops-list-view="summary-{{ $option->value }}"
                    />
                @endforeach
                <x-ops.metric
                    :label="__('themes.summary.installs')"
                    :value="$summary['installs']"
                    tone="accent"
                    :href="route('ops.sites', ['theme' => 'git'])"
                    icon="M2.5 13.5V5.2L8 2.5l5.5 2.7v8.3H2.5Zm3-.5v-4h5v4"
                    data-themes-summary="installs"
                />
                <x-ops.metric
                    :label="__('themes.summary.outdated')"
                    :value="$summary['outdated']"
                    :tone="$summary['outdated'] > 0 ? 'warning' : 'success'"
                    :href="route('ops.sites', ['theme' => 'outdated'])"
                    icon="M8 4.5V8l2.5 1.5M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12Z"
                    :detail="$summary['total'] > 0 ? __('sites.summary.of_total', ['count' => $summary['outdated'], 'total' => $summary['total']]) : null"
                    data-themes-summary="outdated"
                />
            </x-ops.metrics>
        @endif

        <section class="plane-workspace">
            <div class="ops-list-toolbar-row plane-toolbar">
                <form method="GET" action="{{ route('ops.themes') }}" id="themes-list-filters" class="ops-list-toolbar" data-ops-list-toolbar>
                    <x-ops.search :label="__('themes.search')" :value="$search" :placeholder="__('themes.search_placeholder')" />
                </form>

                @php
                    $visibilityOptions = [];
                    foreach ($visibilities as $option) {
                        $visibilityOptions[$option->value] = [
                            'label' => $option->label(),
                            'count' => $summary['visibility'][$option->value] ?? null,
                        ];
                    }
                @endphp
                <x-ops.segment
                    name="visibility"
                    form="themes-list-filters"
                    :label="__('themes.filter_visibility')"
                    :all-label="__('themes.all_visibilities')"
                    :value="$visibility"
                    :options="$visibilityOptions"
                />

                {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>{{ __('ops.actions.clear') }}</a>
            </div>

            <div class="ops-list-region" data-ops-list-region>
                @include('ops.themes._region')
            </div>
        </section>
    </div>
@endsection
