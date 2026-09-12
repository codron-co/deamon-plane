<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsBackgroundJob extends Model
{
    use HasUlids;

    /**
     * Job type -> `ops.jobs.*` copy key. The widget shows this label so an
     * operator can tell a sync from a deploy without opening the row.
     *
     * @var array<string, string>
     */
    private const KIND_KEYS = [
        'sites.live_sync' => 'live_sync',
        'sites.coolify_sync' => 'coolify_sync',
        'coolify.inventory_sync' => 'inventory_sync',
        'themes.catalog_sync' => 'catalog_sync',
        'sites.bulk_channel' => 'bulk_channel',
        'sites.bulk_compose' => 'bulk_compose',
        'sites.bulk_auto_deploy' => 'bulk_auto_deploy',
        'sites.bulk_deploy' => 'bulk_deploy',
        'sites.bulk_follow_head' => 'bulk_follow_head',
        'sites.bulk_pin' => 'bulk_pin',
        'sites.bulk_app_health_fix' => 'bulk_app_health_fix',
        'sites.bulk_publish_status' => 'bulk_publish_status',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'title',
        'status',
        'progress',
        'message',
        'payload',
        'result',
        'actor_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'progress' => 'integer',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function kindLabel(): string
    {
        $key = self::KIND_KEYS[$this->type] ?? null;

        if ($key === null) {
            return trim((string) $this->title) !== '' ? (string) $this->title : (string) $this->type;
        }

        return __('ops.jobs.'.$key);
    }

    /**
     * What the job acts on: an explicit subject from the payload, otherwise how
     * many sites the sweep covers.
     */
    public function subjectLabel(): string
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject !== '') {
            return $subject;
        }

        $siteIds = $payload['site_ids'] ?? null;
        if (is_array($siteIds) && $siteIds !== []) {
            return trans_choice('ops.jobs.subject_sites', count($siteIds), ['count' => count($siteIds)]);
        }

        return '';
    }

    public function statusLabel(): string
    {
        $status = in_array($this->status, ['queued', 'running', 'completed', 'failed', 'cancelled'], true)
            ? $this->status
            : 'completed';

        return __('ops.jobs.status.'.$status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWidget(): array
    {
        $kind = $this->kindLabel();
        $subject = $this->subjectLabel();
        $detail = trim((string) $this->message);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'kind_label' => $kind,
            'subject' => $subject !== '' ? $subject : null,
            'title' => $subject !== '' ? $kind.' · '.$subject : $kind,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'detail' => $detail !== '' ? $detail : null,
            'progress' => (int) $this->progress,
            'message' => $this->message,
            'result' => $this->result,
            'actions' => [
                'cancel' => false,
                'force_start' => false,
                'dismiss' => in_array($this->status, ['completed', 'failed', 'cancelled'], true),
            ],
        ];
    }

    public function markRunning(?string $message = null): void
    {
        $this->forceFill([
            'status' => 'running',
            'progress' => max(1, (int) $this->progress),
            'message' => $message ?? $this->message,
        ])->save();
    }

    public function updateProgress(int $progress, ?string $message = null): void
    {
        $this->forceFill([
            'progress' => max(0, min(100, $progress)),
            'message' => $message ?? $this->message,
        ])->save();
    }

    public function markCompleted(string $message, ?array $result = null): void
    {
        $this->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'message' => $message,
            'result' => $result,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => 'failed',
            'message' => $message,
        ])->save();
    }
}
