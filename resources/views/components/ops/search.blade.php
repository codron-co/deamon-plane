@props([
    'label',
    'value' => '',
    'placeholder' => null,
    'name' => 'q',
])

{{-- Toolbar search box with a leading icon; the list toolbar submits it as you type. --}}
<label {{ $attributes->class(['ops-search', 'plane-search']) }}>
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="4.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="m10.5 10.5 3 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
    <span class="visually-hidden">{{ $label }}</span>
    <input type="search" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $placeholder ?? $label }}" autocomplete="off">
</label>
