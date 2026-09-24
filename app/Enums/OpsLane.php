<?php

namespace App\Enums;

/**
 * Queue lanes. Each has its own worker (docker/supervisor/supervisord.conf) so
 * a 15-minute sweep never delays a deploy poll or a health check.
 */
enum OpsLane: string
{
    /** Deploy polls, diagnosis, provision, channel switch, alerts. */
    case Critical = 'critical';

    /** Everything without a lane. */
    case Default = 'default';

    /** Agent health checks and app-health inspections. */
    case Health = 'health';

    /** Jobs that may run for minutes; on their own connection (retry_after). */
    case Long = 'long';
}
