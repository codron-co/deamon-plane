<?php

namespace App\Services\Agent;

final class AgentHealthReason
{
    public const Timeout = 'timeout';

    public const BadSignature = 'bad_signature';

    public const QueueUnhealthy = 'queue_unhealthy';

    public const HttpError = 'http_error';

    public const RateLimited = 'rate_limited';

    public const NeedsSecret = 'needs_secret';

    public const NoBaseUrl = 'no_base_url';

    /**
     * The CMS answered 200 with a web page: it registers /internal/control/v1/* only when
     * CONTROL_PLANE_AGENT_SECRET is set, so the request fell through to the site frontend.
     */
    public const AgentNotRegistered = 'agent_not_registered';

    /**
     * The proxy answered with the CodRon placeholder page: no running container claims
     * this host. The app crashed (restart: no) or never started; the CMS is not involved.
     */
    public const ProxyFallback = 'proxy_fallback';
}
