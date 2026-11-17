@php
    /** @var \App\Models\Site $site */
    /** @var \App\Services\Agent\ModuleAgentResult $modulesResult */
    /** @var list<array{key: string, label: string, modules: list<array<string, mixed>>}> $moduleGroups */
    $canToggleModules = $canToggleModules ?? false;
@endphp
{{-- Fetched into #modules by public/js/ops-lazy-panels.js; toggles are handled by public/js/sites-modules.js. No <script>: injected HTML does not run it. --}}
@if ($modulesResult->needsSecret)
    <p class="ops-alert ops-alert-warning" role="status">{{ $modulesResult->safeMessage }}</p>
@elseif ($modulesResult->unsupported)
    <p class="site-note" role="status">{{ $modulesResult->safeMessage }}</p>
@elseif (! $modulesResult->ok)
    <p class="ops-alert" role="alert">
        {{ $modulesResult->safeMessage }}
        <button type="button" class="btn btn-secondary btn-sm" data-lazy-retry>{{ __('site_modules.retry') }}</button>
    </p>
@elseif ($moduleGroups === [])
    <p class="site-note">{{ __('site_modules.empty') }}</p>
@else
    <div
        class="site-modules"
        data-site-modules
        data-modules-url="{{ route('ops.sites.modules.update', $site) }}"
        data-working-label="{{ __('site_modules.working') }}"
        data-error-label="{{ __('site_modules.errors.request_failed') }}"
    >
        @unless ($canToggleModules)
            <p class="site-note">{{ __('site_modules.readonly') }}</p>
        @endunless
        <p class="site-modules-status" role="status" aria-live="polite" data-modules-status hidden></p>

        @foreach ($moduleGroups as $group)
            <article class="site-card site-modules-group" aria-labelledby="site-modules-group-{{ $group['key'] }}">
                <div class="site-card-head">
                    <h3 id="site-modules-group-{{ $group['key'] }}">{{ $group['label'] }}</h3>
                </div>
                <ul class="site-modules-list">
                    @foreach ($group['modules'] as $module)
                        @php
                            $slug = (string) $module['slug'];
                            $name = (string) $module['name'];
                            $isEnabled = (bool) $module['enabled'];
                            $isInstalled = (bool) $module['installed'];
                            $isLocked = (bool) $module['locked'];
                            $requires = (array) ($module['requires'] ?? []);
                            $rowId = 'site-module-'.\Illuminate\Support\Str::slug($slug);
                        @endphp
                        <li class="site-modules-row" data-module-row="{{ $slug }}">
                            <div class="site-modules-text">
                                <span class="site-modules-name" id="{{ $rowId }}-name">
                                    {{ $name }}
                                    @if ($isLocked)
                                        <span class="status-chip" title="{{ __('site_modules.locked_hint') }}">{{ __('site_modules.locked') }}</span>
                                    @elseif (! $isInstalled)
                                        <span class="status-chip" data-module-not-installed>{{ __('site_modules.not_installed') }}</span>
                                    @endif
                                </span>
                                @if (filled($module['tagline'] ?? null))
                                    <span class="site-modules-tagline">{{ $module['tagline'] }}</span>
                                @endif
                                @if ($requires !== [])
                                    <span class="site-modules-tagline">{{ __('site_modules.requires', ['list' => implode(', ', $requires)]) }}</span>
                                @endif
                                <span class="site-modules-error" role="alert" data-module-error hidden></span>
                            </div>
                            <button
                                type="button"
                                class="site-switch"
                                role="switch"
                                aria-checked="{{ $isEnabled ? 'true' : 'false' }}"
                                aria-labelledby="{{ $rowId }}-name"
                                title="{{ $isLocked ? __('site_modules.locked_hint') : __('site_modules.toggle_label', ['name' => $name]) }}"
                                data-module-toggle
                                data-module-slug="{{ $slug }}"
                                data-module-installed="{{ $isInstalled ? 'true' : 'false' }}"
                                data-module-locked="{{ $isLocked ? 'true' : 'false' }}"
                                data-confirm-disable-title="{{ __('site_modules.confirm.disable_title') }}"
                                data-confirm-disable-message="{{ __('site_modules.confirm.disable_message', ['name' => $name]) }}"
                                data-confirm-disable-label="{{ __('site_modules.confirm.disable_label') }}"
                                data-confirm-enable-title="{{ __('site_modules.confirm.enable_title') }}"
                                data-confirm-enable-message="{{ __('site_modules.confirm.enable_message', ['name' => $name]) }}"
                                data-confirm-enable-label="{{ __('site_modules.confirm.enable_label') }}"
                                @disabled(! $canToggleModules || ($isLocked && $isEnabled))
                            ><span class="site-switch-thumb" aria-hidden="true"></span></button>
                        </li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>
@endif
