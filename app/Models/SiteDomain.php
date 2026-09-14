<?php

namespace App\Models;

use App\Services\Cloudflare\CloudflareHostname;
use Database\Factories\SiteDomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDomain extends Model
{
    /** @use HasFactory<SiteDomainFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'domain',
        'is_primary',
        'is_www',
        'is_temporary',
        'coolify_domain_id',
        'verified_at',
        'cloudflare_zone_id',
        'cloudflare_zone_status',
        'cloudflare_nameservers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_www' => 'boolean',
            'is_temporary' => 'boolean',
            'verified_at' => 'datetime',
            'cloudflare_nameservers' => 'array',
        ];
    }

    /**
     * True when this host lives on its own Cloudflare zone (a different apex than
     * the site primary) and that zone is still waiting for registrar nameservers.
     */
    public function zonePending(): bool
    {
        return filled($this->cloudflare_zone_id)
            && ! CloudflareHostname::zoneIsReady($this->cloudflare_zone_status);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The same two filters the Domains toolbar posts, so `all=1` re-resolves
     * the set the operator was looking at rather than a second, wider query.
     *
     * @param  Builder<SiteDomain>  $query
     * @return Builder<SiteDomain>
     */
    public function scopeMatchingListFilters(Builder $query, string $search, bool $unbound): Builder
    {
        $search = trim($search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where('domain', 'like', $like);
        }

        if ($unbound) {
            $query->whereNull('verified_at')->where('is_temporary', false);
        }

        return $query;
    }

    /**
     * Leftover hosts the bulk clearer may delete. Primary, temporary, and
     * Coolify-bound rows stay — the unbound filter still lists primaries.
     */
    public function isLeftoverClearable(): bool
    {
        return $this->verified_at === null
            && $this->is_primary === false
            && $this->is_temporary === false;
    }
}
