<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'channel',
        'trigger',
        'coolify_deployment_uuid',
        'status',
        'commit_sha',
        'started_at',
        'finished_at',
        'log_excerpt',
        'error_message',
        'requested_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'trigger' => DeploymentTrigger::class,
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function shortSha(): string
    {
        if (blank($this->commit_sha)) {
            return '';
        }

        return substr((string) $this->commit_sha, 0, 7);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWidget(): array
    {
        $site = $this->site;
        $active = in_array($this->status, [DeploymentStatus::Queued, DeploymentStatus::InProgress], true);
        $widgetStatus = match ($this->status) {
            DeploymentStatus::Failed => 'failed',
            DeploymentStatus::Cancelled => 'cancelled',
            DeploymentStatus::Finished => 'completed',
            default => 'running',
        };

        $message = filled($this->error_message)
            ? trim((string) $this->error_message)
            : ($this->trigger?->label() ?? '').' · '.($this->status?->label() ?? '');

        return [
            'id' => 'dep-'.$this->id,
            'type' => 'coolify.deployment',
            'title' => $site?->name ?? __('sites.title'),
            'status' => $widgetStatus,
            'progress' => $active ? null : 100,
            'indeterminate' => $active,
            'message' => $message,
            'url' => $site !== null ? route('ops.sites.deployments.show', [$site, $this]) : null,
        ];
    }

    public function durationLabel(): string
    {
        if ($this->started_at === null) {
            return '—';
        }

        // Carbon 3 returns signed diffs by default; duration must stay non-negative.
        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remain = $seconds % 60;

        return $remain === 0 ? $minutes.'m' : $minutes.'m '.$remain.'s';
    }
}
