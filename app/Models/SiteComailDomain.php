<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's mail domain on the shared CoMail (mailcow) server.
 */
class SiteComailDomain extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'domain',
        'is_primary',
        'status',
        'mailcow_synced_at',
        'dkim_selector',
        'dkim_txt',
        'mx_active_at',
        'dns_checked_at',
        'dns_check',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'mailcow_synced_at' => 'datetime',
            'mx_active_at' => 'datetime',
            'dns_checked_at' => 'datetime',
            'dns_check' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function mxActive(): bool
    {
        return $this->mx_active_at !== null;
    }

    /**
     * Counts of the last DNS check: ['ok' => n, 'total' => n].
     *
     * @return array{ok: int, total: int}
     */
    public function dnsSummary(): array
    {
        $records = is_array($this->dns_check['records'] ?? null) ? $this->dns_check['records'] : [];
        $ok = 0;
        foreach ($records as $record) {
            if (is_array($record) && ($record['status'] ?? null) === 'ok') {
                $ok++;
            }
        }

        return ['ok' => $ok, 'total' => count($records)];
    }
}
