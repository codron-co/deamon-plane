@php
    $comailSize = static fn (float $mb): string => $mb >= 1024
        ? number_format($mb / 1024, 1, ',', '.').' GB'
        : number_format($mb, $mb > 0 && $mb < 10 ? 1 : 0, ',', '.').' MB';
@endphp
<span data-comail-mailbox-usage>{{ $comailSize($mailbox['used_bytes'] / 1048576) }} / {{ $mailbox['quota_mb'] > 0 ? $comailSize((float) $mailbox['quota_mb']) : __('ops.none') }}</span>
