<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMailboxRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'local_part',
        'domain',
        'note',
        'requester_kind',
        'requester_ref',
        'status',
        'fulfilled_mailbox_id',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function email(): string
    {
        return $this->local_part.'@'.$this->domain;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @return array{id: string, email: string, local_part: string, domain: string, note: string|null, requester_kind: string, requester_ref: string|null, status: string, fulfilled_mailbox_id: string|null, created_at: string|null}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => (string) $this->id,
            'email' => $this->email(),
            'local_part' => $this->local_part,
            'domain' => $this->domain,
            'note' => $this->note,
            'requester_kind' => $this->requester_kind,
            'requester_ref' => $this->requester_ref,
            'status' => $this->status,
            'fulfilled_mailbox_id' => $this->fulfilled_mailbox_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
