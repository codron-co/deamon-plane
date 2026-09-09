<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }
}
