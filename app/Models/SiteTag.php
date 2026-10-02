<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A fleet-wide label an operator puts on sites (customer group, sector, "VIP").
 * Plane metadata only: tags never reach a site.
 */
class SiteTag extends Model
{
    use HasUlids;

    public const MAX = 50;

    public const NAME_MAX = 32;

    public const DEFAULT_COLOR = 'gray';

    /**
     * Palette keys; the hue lives in CSS (`.site-tag.is-<key>`).
     *
     * @var list<string>
     */
    public const COLORS = ['gray', 'blue', 'green', 'amber', 'red', 'purple', 'teal', 'pink'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
    ];

    /**
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_tag_assignments');
    }

    public function colorKey(): string
    {
        return in_array($this->color, self::COLORS, true) ? (string) $this->color : self::DEFAULT_COLOR;
    }

    /**
     * Case-insensitive lookup in PHP: the database lower() does not fold Turkish
     * letters the same way on every driver, and there are at most MAX tags.
     */
    public static function findByName(string $name, ?string $exceptId = null): ?self
    {
        $needle = mb_strtolower(trim($name));

        return self::query()->get()->first(
            static fn (self $tag): bool => mb_strtolower($tag->name) === $needle && $tag->id !== $exceptId,
        );
    }
}
