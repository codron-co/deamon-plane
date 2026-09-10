<section class="settings-panel env-defaults" aria-labelledby="env-defaults-heading">
    <h2 id="env-defaults-heading">{{ __('settings.env.title') }}</h2>
    <p class="field-hint">{{ __('settings.env.lede') }}</p>

    <nav class="env-defaults-tabs" aria-label="{{ __('settings.env.tabs') }}" role="tablist" data-ops-tabs>
        @foreach ($envPacks as $pack)
            @php $panelId = 'env-pack-'.$pack->value; @endphp
            <a
                href="#{{ $panelId }}"
                role="tab"
                class="{{ $loop->first ? 'is-active' : '' }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                aria-controls="{{ $panelId }}"
            >{{ $pack->label() }}</a>
        @endforeach
    </nav>

    @foreach ($envPacks as $pack)
        @php
            $panelId = 'env-pack-'.$pack->value;
            $rows = $envDefaults->get($pack->value, collect());
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
            @if (! $loop->first) hidden @endif
            aria-labelledby="env-defaults-heading"
        >
            <form
                method="POST"
                action="{{ route('ops.settings.env.update') }}"
                class="ops-form"
                data-env-defaults
                @if ($canWrite)
                    data-confirm="{{ __('settings.env.confirm', ['pack' => $pack->label()]) }}"
                    data-confirm-title="{{ __('settings.env.confirm_title') }}"
                    data-confirm-label="{{ __('settings.env.save') }}"
                    data-confirm-danger="false"
                @endif
            >
                @csrf
                <input type="hidden" name="pack" value="{{ $pack->value }}">

                <div class="ops-table-wrap env-defaults-table-wrap">
                    <table class="ops-table env-defaults-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('settings.env.columns.key') }}</th>
                                <th scope="col">{{ __('settings.env.columns.kind') }}</th>
                                <th scope="col">{{ __('settings.env.columns.source') }}</th>
                                <th scope="col">{{ __('settings.env.columns.secret') }}</th>
                                @if ($canWrite)
                                    <th scope="col"><span class="visually-hidden">{{ __('settings.env.columns.actions') }}</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody data-env-rows>
                            @foreach ($rows as $index => $row)
                                @include('ops.settings.partials.env-default-row', [
                                    'row' => $row,
                                    'index' => $index,
                                    'envKinds' => $envKinds,
                                    'canWrite' => $canWrite,
                                ])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($canWrite)
                    <template data-env-row-template>
                        @include('ops.settings.partials.env-default-row', [
                            'row' => null,
                            'index' => '__INDEX__',
                            'envKinds' => $envKinds,
                            'canWrite' => true,
                        ])
                    </template>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" data-env-add>{{ __('settings.env.add') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('settings.env.save') }}</button>
                    </div>
                @else
                    <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
                @endif
            </form>

            <div class="env-defaults-developer">
                <h3>{{ __('settings.env.developer') }}</h3>
                <p class="field-hint">{{ __('settings.env.developer_hint') }}</p>
                <pre class="env-defaults-dump" tabindex="0">{{ $dump }}</pre>
            </div>
        </section>
    @endforeach
</section>
