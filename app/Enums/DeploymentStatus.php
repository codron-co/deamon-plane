<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    /**
     * Held in Plane: the per-host build cap was full when the operator asked, so
     * nothing was sent to Coolify yet. Holds no build slot; `WaitingDeployDispatcher`
     * starts it FIFO once one frees.
     */
    case Waiting = 'waiting';
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        if ($this === self::Waiting) {
            return __('site_ops.queue.status');
        }

        return __('ops.deploy_status.'.$this->value);
    }
}
