<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('ops.deploy_status.'.$this->value);
    }
}
