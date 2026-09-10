<?php

namespace App\Services\Cloudflare;

use App\Models\Site;

class PreviewHostname
{
    /**
     * @var list<string>
     */
    private const ADJECTIVES = [
        'amber', 'brisk', 'calm', 'cedar', 'coral', 'crisp', 'ember', 'fern',
        'flint', 'gentle', 'golden', 'ivory', 'jade', 'lunar', 'maple', 'noble',
        'olive', 'pearl', 'quiet', 'river', 'silver', 'swift', 'timber', 'velvet',
        'vivid', 'willow',
    ];

    /**
     * @var list<string>
     */
    private const NOUNS = [
        'orchard', 'meadow', 'lantern', 'pebble', 'canyon', 'blossom', 'grove',
        'haven', 'islet', 'loft', 'mosaic', 'prairie', 'quarry', 'ridge', 'spruce',
        'terrace', 'valley', 'wharf', 'harbor', 'current',
    ];

    public function allocate(string $wildcardZone): string
    {
        $zone = CloudflareHostname::normalize($wildcardZone);
        if ($zone === '' || ! str_contains($zone, '.')) {
            throw new CloudflareApiException(__('cloudflare.errors.wildcard_unavailable', ['domain' => $wildcardZone ?: '—']), 422);
        }

        for ($i = 0; $i < 40; $i++) {
            $host = $this->pair().'.'.$zone;
            if (! $this->taken($host)) {
                return $host;
            }
        }

        $host = $this->pair().'-'.substr(bin2hex(random_bytes(2)), 0, 4).'.'.$zone;

        return $this->taken($host) ? 'site-'.substr(bin2hex(random_bytes(3)), 0, 6).'.'.$zone : $host;
    }

    private function pair(): string
    {
        return self::ADJECTIVES[array_rand(self::ADJECTIVES)].'-'.self::NOUNS[array_rand(self::NOUNS)];
    }

    private function taken(string $host): bool
    {
        return Site::query()->where('primary_domain', $host)->exists()
            || Site::query()->whereHas('domains', static fn ($query) => $query->where('domain', $host))->exists();
    }
}
