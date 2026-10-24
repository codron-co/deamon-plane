<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plane'in bir Cloudflare hesabında açtığı Turnstile widget'ı. Aynı moddaki
 * siteler alan adlarını (en çok 10, ücretsiz plan) bir widget'ta paylaşır.
 */
class TurnstileWidget extends Model
{
    use HasUlids;

    /** Cloudflare ücretsiz plan sınırları. */
    public const MAX_DOMAINS = 10;

    public const MAX_WIDGETS_PER_ACCOUNT = 20;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cloudflare_setting_id',
        'mode',
        'name',
        'sitekey',
        'secret',
        'domains',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'domains' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CloudflareSetting, $this>
     */
    public function cloudflareAccount(): BelongsTo
    {
        return $this->belongsTo(CloudflareSetting::class, 'cloudflare_setting_id');
    }

    /**
     * @return list<string>
     */
    public function domainList(): array
    {
        return is_array($this->domains) ? array_values(array_filter($this->domains, 'is_string')) : [];
    }
}
