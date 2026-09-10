<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMailBinding extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'hostinger_order_id',
        'mail_domain',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
