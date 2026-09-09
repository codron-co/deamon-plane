<?php

namespace App\Services\Agent;

final class AgentHealthStatus
{
    public const Ok = 'ok';

    public const Unhealthy = 'unhealthy';

    public const NeedsSecret = 'needs_secret';

    public const Unknown = 'unknown';

    public const Stale = 'stale';
}
