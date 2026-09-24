<?php

namespace App\Enums;

/**
 * What a completed `workflow_run` means for the commit it tested.
 */
enum CiRunVerdict: string
{
    /** Green, and the commit is still the recorded branch head. */
    case Promote = 'promote';

    /** failure / cancelled / timed_out / …: nothing deploys. */
    case Red = 'red';

    /** Green, but a newer push moved the branch; that push has its own run. */
    case Superseded = 'superseded';

    /** Green, but Plane never saw a push for this branch, so it cannot prove the commit is the head. */
    case HeadUnknown = 'head_unknown';
}
