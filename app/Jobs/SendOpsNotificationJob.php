<?php

namespace App\Jobs;

use App\Models\OpsNotification;
use App\Services\Mail\PlatformOpsMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Delivers one ops_notifications row. SMTP errors are recorded on the row and
 * retried with backoff; after the last try the row is marked failed.
 */
class SendOpsNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $notificationId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PlatformOpsMailer $mailer): void
    {
        $notification = OpsNotification::query()->find($this->notificationId);
        if ($notification === null || $notification->status === OpsNotification::STATUS_SENT) {
            return;
        }

        $notification->increment('attempts');

        try {
            $mailer->deliver($notification);
        } catch (Throwable $exception) {
            $notification->forceFill([
                'last_error' => mb_substr($mailer->safeError($exception), 0, 500),
            ])->save();

            throw $exception;
        }

        $notification->forceFill([
            'status' => OpsNotification::STATUS_SENT,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        OpsNotification::query()
            ->whereKey($this->notificationId)
            ->where('status', '!=', OpsNotification::STATUS_SENT)
            ->update(['status' => OpsNotification::STATUS_FAILED]);
    }
}
