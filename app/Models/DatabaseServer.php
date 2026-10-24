<?php

namespace App\Models;

use Database\Factories\DatabaseServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A MySQL server shared by many CMS sites (one database + one user per site).
 * Plane holds an admin login to create site databases and to read sizes; site
 * queries from the console run as the site's own user, never as admin.
 */
class DatabaseServer extends Model
{
    /** @use HasFactory<DatabaseServerFactory> */
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
     * Databases from the last probe, keyed by schema name.
     *
     * @return array<string, array{size_mb: float, tables: int}>
     */
    public function probedDatabases(): array
    {
        $payload = is_array($this->last_probe_payload) ? $this->last_probe_payload : [];
        $rows = is_array($payload['databases'] ?? null) ? $payload['databases'] : [];

        $out = [];
        foreach ($rows as $name => $row) {
            if (! is_string($name) || ! is_array($row)) {
                continue;
            }
            $out[$name] = [
                'size_mb' => (float) ($row['size_mb'] ?? 0),
                'tables' => (int) ($row['tables'] ?? 0),
            ];
        }

        return $out;
    }
}
