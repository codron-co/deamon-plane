@php
    /** @var string $ip */
@endphp
{{-- One-click fleet block for an address seen in a list. Confirmed in the in-page dialog, never confirm(). --}}
<form method="POST" action="{{ route('ops.security.block') }}" class="security-inline-form" data-ops-pending
    data-confirm="{{ __('security.blocks.block_confirm', ['ip' => $ip]) }}"
    data-confirm-title="{{ __('security.blocks.block_confirm_title') }}"
    data-confirm-label="{{ __('security.blocks.block_button') }}">
    @csrf
    <input type="hidden" name="ip" value="{{ $ip }}">
    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('security.blocks.block_button') }}</button>
</form>
