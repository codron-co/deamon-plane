@php
    /** @var \App\Models\Site $site */
    $canSwitchChannel = $canSwitchChannel ?? false;
    $canForceChannel = $canForceChannel ?? false;
    $channelSwitchInProgress = $channelSwitchInProgress ?? false;
    $channelSwitchTargets = $channelSwitchTargets ?? [];
    $currentChannel = $site->channel?->value ?? 'main';
    $isDowngradeFromMain = $currentChannel === 'main';
    $defaultTarget = old('channel', $site->desired_channel?->value ?? ($channelSwitchTargets[0] ?? ''));
    $volumeNote = 'Switch = Coolify PATCH git_branch + deploy. MySQL, Redis, themes, and storage volumes stay on this app. Never DELETE the Coolify application (delete_volumes defaults to true).';
@endphp

@if ($channelSwitchInProgress)
    <section class="ops-panel" aria-labelledby="channel-switch-heading">
        <h2 id="channel-switch-heading">Channel switch</h2>
        <p class="ops-flash" role="status">
            Switching to <strong>{{ $site->desired_channel?->value ?? 'the target channel' }}</strong>.
            Coolify is redeploying. Volumes stay attached.
        </p>
        <p class="field-hint">{{ $volumeNote }}</p>
    </section>
@elseif ($canSwitchChannel)
    <section class="ops-panel" aria-labelledby="channel-switch-heading">
        <h2 id="channel-switch-heading">Channel switch</h2>
        <p>Current channel is <span class="channel-chip">{{ $currentChannel }}</span>. Allowlist: main · beta · alpha.</p>
        <p class="field-hint">{{ $volumeNote }} Take a customer DB/volume backup reminder before leaving main. Failures need a manual channel rollback from this form — the app is never recreated.</p>

        <form
            method="POST"
            action="{{ route('ops.sites.channel', $site) }}"
            class="ops-form"
            @if ($isDowngradeFromMain)
                data-confirm="Leave main for {{ $defaultTarget !== '' ? $defaultTarget : 'beta/alpha' }}? This redeploys the existing Coolify app. Volumes (MySQL, Redis, themes, storage) persist. The application is not deleted."
                data-confirm-title="Leave main?"
                data-confirm-label="Switch channel"
            @endif
        >
            @csrf
            <input type="hidden" name="confirmed" value="0">

            <div class="field">
                <label class="field-label" for="site_switch_channel">Target channel</label>
                <p class="field-hint">
                    @if ($isDowngradeFromMain)
                        Leaving main needs the confirm modal. Coolify only changes git_branch.
                    @else
                        Switching to main runs a version gate when last health has deamon_version. Missing health does not block.
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
                    <span class="field-label">Force</span>
                    <p class="field-hint">Bypass the version gate. Super Admin only. Audited.</p>
                    <input type="hidden" name="force" value="0">
                    <label class="field-check">
                        <input type="checkbox" name="force" value="1" @checked(old('force'))>
                        Force switch to main
                    </label>
                </div>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Switch channel</button>
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
                const sync = () => {
                    form.dataset.confirm = 'Leave main for ' + select.value + '? This redeploys the existing Coolify app. Volumes (MySQL, Redis, themes, storage) persist. The application is not deleted.';
                };
                select.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif
@endif
