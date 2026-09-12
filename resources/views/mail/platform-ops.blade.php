<x-mail::message>
{!! nl2br(e($body)) !!}

<x-slot:subcopy>
{{ __('platform_mail.unsubscribe.footer') }}

<a href="{{ $unsubscribeUrl }}">{{ __('platform_mail.unsubscribe.link') }}</a>
</x-slot:subcopy>
</x-mail::message>
