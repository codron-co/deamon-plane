{{-- One-click webmail login as this mailbox. Super Admin only; the controller checks it again. --}}
<form method="POST" action="{{ route('ops.sites.comail.webmail', [$site, $address]) }}" target="_blank" rel="noopener" data-comail-webmail="{{ $address }}">
    @csrf
    <button type="submit" class="btn btn-ghost btn-sm" title="{{ __('comail.mailboxes.webmail_hint') }}">{{ __('comail.mailboxes.webmail') }}</button>
</form>
