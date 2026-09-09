<?php

namespace App\Services\Agent;

final class AgentHealthReason
{
    public const Timeout = 'timeout';

    public const BadSignature = 'bad_signature';

    public const QueueUnhealthy = 'queue_unhealthy';

    public const HttpError = 'http_error';

    public const NeedsSecret = 'needs_secret';

    public const NoBaseUrl = 'no_base_url';
}
