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
@endphp

@if ($channelSwitchInProgress)
    <section class="ops-panel" aria-labelledby="channel-switch-heading">
        <h2 id="channel-switch-heading">{{ __('sites.channel_switch.title') }}</h2>
        <p class="ops-flash" role="status">
            {{ __('sites.channel_switch.in_progress', ['channel' => $site->desired_channel?->value ?? '']) }}
        </p>
        <p class="field-hint">{{ $volumeNote }}</p>
    </section>
@elseif ($canSwitchChannel)
    <section class="ops-panel" aria-labelledby="channel-switch-heading">
        <h2 id="channel-switch-heading">{{ __('sites.channel_switch.title') }}</h2>
        <p>{{ __('sites.channel_switch.current', ['channel' => $currentChannel]) }}</p>
        <p class="field-hint">{{ $volumeNote }} {{ __('sites.channel_switch.backup_hint') }}</p>

        <form
            method="POST"
            action="{{ route('ops.sites.channel', $site) }}"
            class="ops-form"
            @if ($isDowngradeFromMain)
                data-confirm="{{ __('sites.channel_switch.confirm_leave', ['target' => $defaultTarget !== '' ? $defaultTarget : 'beta/alpha']) }}"
                data-confirm-title="{{ __('sites.channel_switch.confirm_title') }}"
                data-confirm-label="{{ __('sites.channel_switch.confirm_label') }}"
            @endif
        >
            @csrf
            <input type="hidden" name="confirmed" value="0">

            <div class="field">
                <label class="field-label" for="site_switch_channel">{{ __('sites.channel_switch.target') }}</label>
                <p class="field-hint">
                    @if ($isDowngradeFromMain)
                        {{ __('sites.channel_switch.hint_leave_main') }}
                    @else
                        {{ __('sites.channel_switch.hint_to_main') }}
                    @endif
                </p>
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

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('sites.channel_switch.submit') }}</button>
            </div>
        </form>
    </section>
    @if ($isDowngradeFromMain)
        <script>
            (() => {
                const select = document.getElementById('site_switch_channel');
                const form = select?.form;
                if (! select || ! form || ! form.hasAttribute('data-confirm')) {
                    return;
                }
                const template = @json(__('sites.channel_switch.confirm_leave', ['target' => '__TARGET__']));
                const sync = () => {
                    form.dataset.confirm = template.replace('__TARGET__', select.value);
                };
                select.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif
@endif
