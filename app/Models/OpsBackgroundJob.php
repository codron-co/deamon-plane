<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsBackgroundJob extends Model
{
    use HasUlids;

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

    /**
     * @return array<string, mixed>
     */
    public function toWidget(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
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
