<?php

namespace App\Models;

use App\Enums\ThemeInstallationStatus;
use Database\Factories\SiteThemeInstallationFactory;
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
        'is_active',
        'auto_update',
        'status',
        'last_error',
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
            'status' => ThemeInstallationStatus::class,
            'updated_from_webhook_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
