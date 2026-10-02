<?php

namespace App\Enums;

/**
 * How much an operator cares about a site. Stored as a number so the list can
 * sort by it; requests and URLs use the lowercase key.
 */
enum SiteImportance: int
{
    case Normal = 0;
    case Important = 1;
    case Critical = 2;

    public function key(): string
    {
        return strtolower($this->name);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $importance) => $importance->key(), self::cases());
    }

    public static function fromKey(string $key): ?self
    {
        foreach (self::cases() as $importance) {
            if ($importance->key() === $key) {
                return $importance;
            }
        }

        return null;
    }

    public function label(): string
    {
        return (string) __('sites.importance.levels.'.$this->key());
    }

    /** Anything above normal: the set the "important sites" tile counts. */
    public function isFlagged(): bool
    {
        return $this !== self::Normal;
    }
}
