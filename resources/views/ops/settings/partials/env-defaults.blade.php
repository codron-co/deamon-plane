<section
    class="settings-panel env-defaults"
    aria-labelledby="env-defaults-heading"
    data-settings-section
    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('env', $envKeyHaystack ?? '') }}"
>
    <h2 id="env-defaults-heading">{{ __('settings.env.title') }}</h2>
    <p class="field-hint">{{ __('settings.env.lede') }}</p>
    <p class="field-hint">{{ __('settings.env.search_hint') }}</p>

    @if ($canWrite)
        <form method="POST" action="{{ route('ops.settings.env.sync') }}" class="form-actions env-defaults-sync-all">
            @csrf
            <button type="submit" class="btn btn-secondary">{{ __('settings.env.sync_all') }}</button>
            <span class="field-hint">{{ __('settings.env.sync_hint') }}</span>
        </form>
    @endif

    <nav class="env-defaults-tabs" aria-label="{{ __('settings.env.tabs') }}" role="tablist" data-ops-tabs>
        @foreach ($envChannels as $channel)
            @php $panelId = 'env-channel-'.$channel->value; @endphp
            <a
                href="#{{ $panelId }}"
                role="tab"
                class="{{ $loop->first ? 'is-active' : '' }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                aria-controls="{{ $panelId }}"
            >{{ __('settings.env.channels.'.$channel->value) }}</a>
        @endforeach
    </nav>

    @foreach ($envChannels as $channel)
        @php
            $panelId = 'env-channel-'.$channel->value;
            $rows = $envDefaults->get($channel->value, collect());
            $source = $envSources->get($channel->value);
            $dump = $rows->map(static function ($row): string {
                $kind = $row->kind?->value ?? '';
                $secret = $row->is_secret ? ' secret' : '';

                return '# kind='.$kind.$secret."\n".$row->key.'='.$row->developerValue();
            })->implode("\n\n");
        @endphp
        <section
            id="{{ $panelId }}"
            class="env-defaults-pack"
            role="tabpanel"
            data-ops-panel
            data-env-channel="{{ $channel->value }}"
            @if (! $loop->first) hidden @endif
            aria-labelledby="env-defaults-heading"
        >
            <div class="env-defaults-source">
                <h3>{{ __('settings.env.source.title') }}</h3>
                @if ($source !== null && $source->isSynced())
                    <p class="field-hint">
                        @if ($source->sourceUrl())
                            <a href="{{ $source->sourceUrl() }}" target="_blank" rel="noopener">{{ __('settings.env.source.file', ['repo' => $source->repo_full_name, 'branch' => $channel->value, 'path' => $source->path]) }}</a>
                        @else
                            {{ __('settings.env.source.file', ['repo' => $source->repo_full_name, 'branch' => $channel->value, 'path' => $source->path]) }}
                        @endif
                        @if ($source->shortSha())
                            · <code>{{ __('settings.env.source.commit', ['sha' => $source->shortSha()]) }}</code>
                        @endif
                        · {{ __('settings.env.source.rows', ['count' => (int) $source->row_count]) }}
                        · {{ __('settings.env.source.fetched_at', ['at' => $source->fetched_at?->diffForHumans()]) }}
                    </p>
                @else
                    <p class="field-hint">{{ __('settings.env.source.never') }}</p>
                @endif
                @if ($source !== null && filled($source->last_error))
                    <p class="field-hint status-error" data-env-source-error>{{ __('settings.env.source.error', ['error' => $source->last_error]) }}</p>
                @endif

                @if ($canWrite)
                    <form
                        method="POST"
                        action="{{ route('ops.settings.env.sync') }}"
                        class="form-actions"
                        data-confirm="{{ __('settings.env.confirm', ['branch' => $channel->value]) }}"
                        data-confirm-title="{{ __('settings.env.confirm_title') }}"
                        data-confirm-label="{{ __('settings.env.sync') }}"
                        data-confirm-danger="false"
                    >
                        @csrf
                        <input type="hidden" name="channel" value="{{ $channel->value }}">
                        <button type="submit" class="btn btn-primary">{{ __('settings.env.sync') }}</button>
                    </form>
                @else
                    <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
                @endif
            </div>

            <div class="ops-table-wrap env-defaults-table-wrap">
                <table class="ops-table env-defaults-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('settings.env.columns.key') }}</th>
                            <th scope="col">{{ __('settings.env.columns.kind') }}</th>
                            <th scope="col">{{ __('settings.env.columns.source') }}</th>
                            <th scope="col">{{ __('settings.env.columns.secret') }}</th>
                        </tr>
                    </thead>
                    <tbody data-env-rows>
                        @foreach ($rows as $row)
                            @include('ops.settings.partials.env-default-row', ['row' => $row])
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="field-hint env-defaults-search-empty" data-env-search-empty hidden>{{ __('settings.env.search_empty') }}</p>

            <div class="env-defaults-developer">
                <h3>{{ __('settings.env.developer') }}</h3>
                <p class="field-hint">{{ __('settings.env.developer_hint') }}</p>
                <pre class="env-defaults-dump" tabindex="0">{{ $dump }}</pre>
            </div>
        </section>
    @endforeach
</section>
