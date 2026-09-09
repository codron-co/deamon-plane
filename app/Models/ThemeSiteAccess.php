<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeSiteAccess extends Model
{
    protected $table = 'theme_site_access';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'theme_id',
        'site_id',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
