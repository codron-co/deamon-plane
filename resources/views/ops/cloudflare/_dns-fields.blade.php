@php
    use App\Services\Cloudflare\CloudflareDnsRecord;

    $canWrite = $canWrite ?? false;
    $useOld = $useOld ?? false;
    $prefix = $prefix ?? 'dns';
    $type = $useOld ? old('type', $type ?? 'A') : ($type ?? 'A');
    $name = $useOld ? old('name', $name ?? '@') : ($name ?? '@');
    $content = $useOld ? old('content', $content ?? '') : ($content ?? '');
    $ttl = $useOld ? old('ttl', $ttl ?? 1) : ($ttl ?? 1);
    $priority = $useOld ? old('priority', $priority ?? '') : ($priority ?? '');
@endphp

<div class="ops-dns-fields">
    <div class="field">
        <label class="field-label" for="{{ $prefix }}-type">{{ __('cloudflare.dns.type') }}</label>
        <select id="{{ $prefix }}-type" class="field-input" name="type" @disabled(! $canWrite) required>
            @foreach (CloudflareDnsRecord::TYPES as $option)
                <option value="{{ $option }}" @selected($type === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label class="field-label" for="{{ $prefix }}-name">{{ __('cloudflare.dns.name') }}</label>
        <input id="{{ $prefix }}-name" class="field-input" type="text" name="name" value="{{ $name }}" maxlength="255" required @disabled(! $canWrite) spellcheck="false" autocomplete="off">
    </div>
    <div class="field">
        <label class="field-label" for="{{ $prefix }}-content">{{ __('cloudflare.dns.content') }}</label>
        <input id="{{ $prefix }}-content" class="field-input" type="text" name="content" value="{{ $content }}" maxlength="2048" required @disabled(! $canWrite) spellcheck="false" autocomplete="off">
    </div>
    <div class="field">
        <label class="field-label" for="{{ $prefix }}-ttl">{{ __('cloudflare.dns.ttl') }}</label>
        <input id="{{ $prefix }}-ttl" class="field-input" type="number" name="ttl" value="{{ $ttl }}" min="1" max="86400" @disabled(! $canWrite)>
    </div>
    <div class="field">
        <label class="field-label" for="{{ $prefix }}-priority">{{ __('cloudflare.dns.priority') }}</label>
        <input id="{{ $prefix }}-priority" class="field-input" type="number" name="priority" value="{{ $priority }}" min="0" max="65535" @disabled(! $canWrite)>
    </div>
</div>
