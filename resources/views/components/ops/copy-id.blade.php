@props([
    'value' => null,
    'label',
])

@php
    $filled = filled($value);
@endphp
<div {{ $attributes->class('ops-copy-id') }}>
    <dt>{{ $label }}</dt>
    <dd>
        @if ($filled)
            <code>{{ $value }}</code>
            <button
                type="button"
                class="btn btn-ghost btn-sm"
                data-copy-value="{{ $value }}"
                data-copied-label="{{ __('sites.detail.copied') }}"
                aria-label="{{ __('sites.detail.copy_named', ['label' => $label]) }}"
            >{{ __('sites.detail.copy') }}</button>
        @else
            <code>{{ __('ops.none') }}</code>
        @endif
    </dd>
</div>
