<?php

namespace App\Models;

use Database\Factories\RedisServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A Redis server shared by many CMS sites (one DB pair, one ACL user and one key
 * prefix per site). Plane holds an admin login to create site users, read memory
 * and clear one site's keys; sites connect with their own ACL user.
 */
class RedisServer extends Model
{
    /** @use HasFactory<RedisServerFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'host',
        'port',
        'admin_username',
        'admin_password',
        'is_default',
        'notes',
        'last_probe_at',
        'last_probe_payload',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'admin_password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'admin_password' => 'encrypted',
            'is_default' => 'boolean',
            'last_probe_at' => 'datetime',
            'last_probe_payload' => 'array',
        ];
    }

    /**
     * Sites holding a DB pair on this server (shared now, or reserved after a move back).
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->orderBy('created_at')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        return is_array($this->last_probe_payload) ? $this->last_probe_payload : [];
    }

    /**
     * Keys per DB number from the last probe.
     *
     * @return array<int, int>
     */
    public function probedKeyspace(): array
    {
        $rows = $this->probe()['keyspace'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $db => $row) {
            if (is_numeric($db) && is_array($row)) {
                $out[(int) $db] = (int) ($row['keys'] ?? 0);
            }
        }

        return $out;
    }
}
