@php
    /** @var \App\Models\Site $site */
    $canSwitchChannel = $canSwitchChannel ?? false;
    $canForceChannel = $canForceChannel ?? false;
    $channelSwitchInProgress = $channelSwitchInProgress ?? false;
    $channelSwitchTargets = $channelSwitchTargets ?? [];
    $currentChannel = $site->channel?->value ?? 'main';
    $isDowngradeFromMain = $currentChannel === 'main';
    $defaultTarget = old('channel', $site->desired_channel?->value ?? ($channelSwitchTargets[0] ?? ''));
    $volumeNote = __('sites.channel_switch.volume_note');
    $channelHint = $isDowngradeFromMain
        ? __('sites.channel_switch.hint_leave_main').' '.__('sites.channel_switch.backup_hint')
        : __('sites.channel_switch.hint_to_main').' '.__('sites.channel_switch.backup_hint');
    $channelConfirm = $isDowngradeFromMain
        ? __('sites.channel_switch.confirm_leave', ['target' => $defaultTarget !== '' ? $defaultTarget : 'beta/alpha'])
        : __('sites.channel_switch.confirm_switch', ['site' => $site->name, 'target' => $defaultTarget !== '' ? $defaultTarget : 'main']);
    $channelConfirmTitle = $isDowngradeFromMain
        ? __('sites.channel_switch.confirm_title')
        : __('sites.channel_switch.confirm_switch_title');
    $channelConfirmTemplate = $isDowngradeFromMain
        ? __('sites.channel_switch.confirm_leave', ['target' => '__TARGET__'])
        : __('sites.channel_switch.confirm_switch', ['site' => $site->name, 'target' => '__TARGET__']);
@endphp

@if ($channelSwitchInProgress)
    <article class="site-card site-operation" aria-labelledby="channel-switch-heading">
        <div class="site-card-head">
            <h3 id="channel-switch-heading">{{ __('sites.channel_switch.title') }} <button class="site-hint" type="button" aria-label="{{ $channelHint }}"><span aria-hidden="true">i</span><span role="tooltip">{{ $channelHint }}</span></button></h3>
            <span class="status-chip">{{ $site->desired_channel?->value ?? $currentChannel }}</span>
        </div>
        <p class="ops-flash" role="status">
            {{ __('sites.channel_switch.in_progress', ['channel' => $site->desired_channel?->value ?? '']) }}
        </p>
        <p class="field-hint">{{ $volumeNote }}</p>
    </article>
@elseif ($canSwitchChannel)
    <article class="site-card site-operation" aria-labelledby="channel-switch-heading">
        <div class="site-card-head">
            <h3 id="channel-switch-heading">{{ __('sites.channel_switch.title') }} <button class="site-hint" type="button" aria-label="{{ $channelHint }}"><span aria-hidden="true">i</span><span role="tooltip">{{ $channelHint }}</span></button></h3>
            <span class="branch-chip">{{ $currentChannel }}</span>
        </div>
        <p class="field-hint">{{ $volumeNote }}</p>

        <form
            method="POST"
            action="{{ route('ops.sites.channel', $site) }}"
            class="ops-form"
            data-confirm="{{ $channelConfirm }}"
            data-confirm-title="{{ $channelConfirmTitle }}"
            data-confirm-label="{{ __('sites.channel_switch.confirm_label') }}"
        >
            @csrf
            <input type="hidden" name="confirmed" value="0">

            <div class="site-operation-line">
                <div class="field">
                    <label class="field-label" for="site_switch_channel">{{ __('sites.channel_switch.target') }}</label>
                    <select
                        id="site_switch_channel"
                        class="field-input"
                        name="channel"
                        required
                    >
                        @foreach ($channelSwitchTargets as $target)
                            <option value="{{ $target }}" @selected($defaultTarget === $target)>{{ $target }}</option>
                        @endforeach
                    </select>
                    @error('channel') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary">{{ __('sites.channel_switch.submit') }}</button>
            </div>

            @if ($canForceChannel && $currentChannel !== 'main')
                <div class="field">
                    <span class="field-label">{{ __('sites.channel_switch.force') }}</span>
                    <p class="field-hint">{{ __('sites.channel_switch.force_hint') }}</p>
                    <input type="hidden" name="force" value="0">
                    <label class="field-check">
                        <input type="checkbox" name="force" value="1" @checked(old('force'))>
                        {{ __('sites.channel_switch.force_label') }}
                    </label>
                </div>
            @endif
        </form>
    </article>
    <script>
        (() => {
            const select = document.getElementById('site_switch_channel');
            const form = select?.form;
            if (! select || ! form || ! form.hasAttribute('data-confirm')) {
                return;
            }
            const template = @json($channelConfirmTemplate);
            const sync = () => {
                form.dataset.confirm = template.replace('__TARGET__', select.value);
            };
            select.addEventListener('change', sync);
            sync();
        })();
    </script>
@endif
