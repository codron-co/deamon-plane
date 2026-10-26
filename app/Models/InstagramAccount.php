<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Instagram account connected to a site through the Plane OAuth broker. Only the
 * app-scoped user id and username are kept (never the token), so Meta's deauthorize and
 * data deletion callbacks can find the site that holds the data.
 */
class InstagramAccount extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'ig_user_id',
        'username',
        'connected_at',
        'disconnected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disconnected_at');
    }

    public static function recordConnection(Site $site, string $igUserId, ?string $username): void
    {
        $now = now();

        // A site holds one account at a time: connecting another replaces the previous token.
        static::query()
            ->where('site_id', $site->id)
            ->where('ig_user_id', '!=', $igUserId)
            ->whereNull('disconnected_at')
            ->update(['disconnected_at' => $now]);

        static::query()->updateOrCreate(
            ['site_id' => $site->id, 'ig_user_id' => $igUserId],
            ['username' => $username !== '' ? $username : null, 'connected_at' => $now, 'disconnected_at' => null],
        );
    }
}
