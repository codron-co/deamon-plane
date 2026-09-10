<?php

namespace App\Models;

use App\Services\Cloudflare\CloudflareDnsTemplate;
use Database\Factories\CloudflareDnsDefaultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CloudflareDnsDefault extends Model
{
    /** @use HasFactory<CloudflareDnsDefaultFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'content',
        'ttl',
        'priority',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public static function seedBuiltin(bool $force = false): void
    {
        if (! $force && static::query()->exists()) {
            return;
        }

        if ($force) {
            static::query()->delete();
        }

        $order = 0;
        foreach (CloudflareDnsTemplate::builtin() as $record) {
            static::query()->create([
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => $record['ttl'] ?? 1,
                'priority' => $record['priority'] ?? null,
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * @return list<array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}>
     */
    public static function templateRecords(): array
    {
        static::seedBuiltin();

        return static::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static function (self $row): array {
                $record = [
                    'type' => strtoupper((string) $row->type),
                    'name' => (string) $row->name,
                    'content' => (string) $row->content,
                    'ttl' => (int) ($row->ttl ?: 1),
                    'proxied' => false,
                ];

                if ($row->priority !== null) {
                    $record['priority'] = (int) $row->priority;
                }

                return $record;
            })
            ->all();
    }

    public static function nextSortOrder(): int
    {
        return (int) static::query()->max('sort_order') + 1;
    }
}
