@php
    /** @var \App\Models\Site $site */
    $readonly = $readonly ?? false;
    $channelLocked = $channelLocked ?? false;
    $slugLocked = $channelLocked;
    $currentChannel = old('channel', $site->channel?->value ?? 'main');
    $currentDomain = old('domain', $site->primary_domain);
    $serverUuid = old('coolify_server_uuid', $site->coolify_server_uuid);
@endphp

@if ($errors->any())
    <p class="ops-alert" role="alert">Fix the highlighted fields. Nothing was saved.</p>
@endif

<div class="field">
    <label class="field-label" for="site_slug">Slug</label>
    <p class="field-hint">Stable identifier. Lowercase letters, numbers, hyphens.</p>
    <input
        id="site_slug"
        class="field-input"
        type="text"
        name="slug"
        value="{{ old('slug', $site->slug) }}"
        autocomplete="off"
        maxlength="64"
        @required(!$readonly && ! $slugLocked)
        @readonly($readonly || $slugLocked)
    >
    @error('slug') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_name">Name</label>
    <p class="field-hint">Shown in the fleet list and Coolify app name later.</p>
    <input
        id="site_name"
        class="field-input"
        type="text"
        name="name"
        value="{{ old('name', $site->name) }}"
        maxlength="255"
        @required(! $readonly)
        @readonly($readonly)
    >
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_domain">Domain</label>
    <p class="field-hint">Primary hostname. Desired state only — Coolify bind is Task 4.</p>
    <input
        id="site_domain"
        class="field-input"
        type="text"
        name="domain"
        value="{{ $currentDomain }}"
        autocomplete="off"
        maxlength="255"
        placeholder="shop.example.com"
        @required(! $readonly)
        @readonly($readonly)
    >
    @error('domain') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_channel">Channel</label>
    <p class="field-hint">Git branch allowlist. Switch on a live site is Task 5.</p>
    <select
        id="site_channel"
        class="field-input"
        name="channel"
        @required(! $readonly && ! $channelLocked)
        @disabled($readonly || $channelLocked)
    >
        @foreach ($channels as $channel)
            <option value="{{ $channel }}" @selected($currentChannel === $channel)>{{ $channel }}</option>
        @endforeach
    </select>
    @if ($channelLocked)
        <input type="hidden" name="channel" value="{{ $site->channel?->value }}">
    @endif
    @error('channel') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_server">Server UUID</label>
    <p class="field-hint">Optional Coolify server. Empty uses the default from Settings.</p>
    <input
        id="site_server"
        class="field-input"
        type="text"
        name="coolify_server_uuid"
        value="{{ $serverUuid }}"
        autocomplete="off"
        maxlength="64"
        spellcheck="false"
        @readonly($readonly)
    >
    @error('coolify_server_uuid') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_notes">Notes</label>
    <p class="field-hint">Internal ops notes. Never put secrets here.</p>
    <textarea
        id="site_notes"
        class="field-input field-textarea"
        name="notes"
        rows="4"
        maxlength="5000"
        @readonly($readonly)
    >{{ old('notes', $site->notes) }}</textarea>
    @error('notes') <p class="field-error">{{ $message }}</p> @enderror
</div>

@if ($site->exists)
    <div class="field">
        <span class="field-label">Status</span>
        <p class="field-hint">Stays draft until provision (Task 4). Not editable here.</p>
        <p class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->value }}</p>
    </div>
@endif
