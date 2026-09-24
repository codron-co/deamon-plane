<?php

namespace App\Models;

use App\Enums\ThemeInstallationStatus;
use Database\Factories\SiteThemeInstallationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteThemeInstallation extends Model
{
    /** @use HasFactory<SiteThemeInstallationFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'theme_id',
        'ref',
        'pinned_sha',
        'previous_pinned_sha',
        'is_active',
        'auto_update',
        'status',
        'last_error',
        'customized_files',
        'pending_sync_after_deploy',
        'last_sync_task_id',
        'updated_from_webhook_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_update' => 'boolean',
            'pending_sync_after_deploy' => 'boolean',
            'status' => ThemeInstallationStatus::class,
            'updated_from_webhook_at' => 'datetime',
            'customized_files' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Active installs whose pinned commit trails the catalog head (or was never
     * pinned) — the same rule as the Sites `theme=outdated` filter.
     *
     * @param  Builder<SiteThemeInstallation>  $query
     * @return Builder<SiteThemeInstallation>
     */
    public function scopeBehindCatalog(Builder $query): Builder
    {
        return $query
            ->where('site_theme_installations.is_active', true)
            ->whereHas('theme', static function (Builder $themes): void {
                $themes->whereNotNull('themes.latest_sha')
                    ->where(static function (Builder $behind): void {
                        $behind->whereNull('site_theme_installations.pinned_sha')
                            ->orWhereColumn('themes.latest_sha', '!=', 'site_theme_installations.pinned_sha');
                    });
            });
    }
}
