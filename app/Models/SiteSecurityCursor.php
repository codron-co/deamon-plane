<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the poll of one site stands: the CMS cursor, the last answer and the
 * counters block (24h / 7d, top IPs) the site sent with it.
 */
class SiteSecurityCursor extends Model
{
    protected $fillable = [
        'site_id',
        'cursor',
        'supported',
        'module_enabled',
        'last_polled_at',
        'last_success_at',
        'consecutive_failures',
        'last_error',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'supported' => 'boolean',
            'module_enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }
}
